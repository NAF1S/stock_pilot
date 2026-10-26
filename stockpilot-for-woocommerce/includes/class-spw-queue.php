<?php
/**
 * Reliable outbound queue: dedup, claim, backoff, dead-letter.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Queue {

	/**
	 * Insert a queue row unless this event was already sent (sync log) or is
	 * already queued.
	 *
	 * @param array $args entity_id, event_id, event_type, payload, date_modified
	 */
	public static function enqueue( array $args ) {
		global $wpdb;

		$event_id = $args['event_id'];

		$already_sent = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM " . SPW_Plugin::log_table() . " WHERE event_id = %s LIMIT 1",
			$event_id
		) );

		if ( $already_sent ) {
			return;
		}

		$queued = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM " . SPW_Plugin::queue_table() . " WHERE event_id = %s AND status IN ('pending','sending') LIMIT 1",
			$event_id
		) );

		if ( $queued ) {
			return;
		}

		$wpdb->insert(
			SPW_Plugin::queue_table(),
			array(
				'payload_id'   => self::uuid(),
				'entity_id'    => $args['entity_id'],
				'event_id'     => $event_id,
				'event_type'   => $args['event_type'],
				'payload'      => $args['payload'],
				'nonce'        => bin2hex( random_bytes( 16 ) ),
				'status'       => 'pending',
				'max_attempts' => 12,
				'next_attempt_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Drain the queue within a wall-clock budget.
	 *
	 * On shared hosting a single PHP request must finish quickly, so instead of
	 * claiming one large batch we claim small batches and stop at the deadline.
	 * The next cron hit (every 5 minutes) continues where this run left off.
	 *
	 * @param int $budget_seconds Maximum time to spend sending.
	 * @return array { sent, failed, remaining }
	 */
	public static function process( $budget_seconds = 12 ) {
		$summary = array( 'sent' => 0, 'failed' => 0, 'remaining' => 0 );

		if ( ! SPW_Settings::instance()->is_connected() ) {
			return $summary;
		}

		$deadline = microtime( true ) + max( 1, (int) $budget_seconds );

		while ( microtime( true ) < $deadline ) {
			$rows = self::claim( 10 );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $index => $row ) {
				if ( microtime( true ) >= $deadline ) {
					// Hand the untouched tail of this batch back for the next run.
					self::release( array_slice( $rows, $index ) );
					break 2;
				}

				$result = self::send_row( $row );

				if ( 'sent' === $result ) {
					$summary['sent']++;
				} elseif ( 'dead' === $result ) {
					$summary['failed']++;
				}
			}
		}

		$summary['remaining'] = self::pending_count();

		return $summary;
	}

	/**
	 * Claim up to $limit due rows with a lock so overlapping runs can't
	 * double-send the same event.
	 *
	 * Order matters: expired locks are reclaimed BEFORE claiming, otherwise
	 * rows stranded by a previous fatal would be skipped until the next run.
	 *
	 * @param int $limit
	 * @return array Rows
	 */
	public static function claim( $limit ) {
		global $wpdb;
		$table = SPW_Plugin::queue_table();
		$now   = current_time( 'mysql' );
		$lock  = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 120 );

		// 1) Reclaim rows whose lock expired (previous run died mid-send).
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status='pending', locked_until=NULL
				 WHERE status='sending' AND (locked_until IS NULL OR locked_until < %s)",
				$now
			)
		);

		// 2) Claim due rows. attempts < max_attempts keeps exhausted rows out.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status='sending', locked_until=%s
				 WHERE status='pending'
				   AND attempts < max_attempts
				   AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
				 LIMIT %d",
				$lock,
				$now,
				$limit
			)
		);

		// 3) Return exactly the rows this run locked.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE status='sending' AND locked_until = %s LIMIT %d",
				$lock,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Re-queue dead rows so they get another chance (e.g. after a portal
	 * outage). Returns the number of rows re-queued.
	 *
	 * @return int
	 */
	public static function retry_dead() {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . SPW_Plugin::queue_table() . "
				 SET status='pending', attempts=0, locked_until=NULL,
				     next_attempt_at=%s, last_error=NULL
				 WHERE status='dead'",
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Return claimed-but-unsent rows to 'pending' (used when the time budget
	 * is exhausted mid-batch).
	 *
	 * @param array $rows Queue rows.
	 */
	public static function release( array $rows ) {
		global $wpdb;

		$ids = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['id'] ) ) {
				$ids[] = (int) $row['id'];
			}
		}

		if ( empty( $ids ) ) {
			return;
		}

		$list = implode( ',', $ids ); // integers only — safe to inline.
		$wpdb->query(
			"UPDATE " . SPW_Plugin::queue_table() . "
			 SET status='pending', locked_until=NULL
			 WHERE status='sending' AND id IN ($list)"
		);
	}

	/**
	 * Send one row and update its state based on the portal response.
	 *
	 * @return string 'sent' | 'dead' | 'retry'
	 */
	private static function send_row( array $row ) {
		$response = SPW_Sender::send(
			$row['event_type'],
			$row['event_id'],
			$row['nonce'],
			$row['payload']
		);

		$code = isset( $response['code'] ) ? (int) $response['code'] : 0;

		if ( 200 === $code || 409 === $code ) {
			// 409 = duplicate already processed by the portal — drop cleanly.
			self::mark_sent( $row, $response );
			return 'sent';
		}

		if ( 422 === $code ) {
			self::mark_dead( $row, 'Portal rejected payload (422): ' . substr( (string) $response['body'], 0, 200 ) );
			return 'dead';
		}

		if ( 401 === $code || 403 === $code ) {
			// Key revoked or invalid — disable the connection and notify.
			SPW_Settings::instance()->save( array( 'disabled' => 'yes' ) );
			self::mark_dead( $row, 'Portal auth failed (' . $code . '). Connection disabled.' );
			SPW_Admin::notify( 'StockPilot connection disabled', $response['body'] );
			return 'dead';
		}

		self::retry( $row, 'HTTP ' . $code . ': ' . substr( (string) $response['body'], 0, 200 ) );

		return 'retry';
	}

	private static function mark_sent( array $row, array $response ) {
		global $wpdb;
		$table = SPW_Plugin::queue_table();

		$wpdb->update( $table, array( 'status' => 'done' ), array( 'id' => $row['id'] ), array( '%s' ), array( '%d' ) );

		$wpdb->replace(
			SPW_Plugin::log_table(),
			array(
				'event_id'      => $row['event_id'],
				'entity_type'   => 0 === strpos( $row['event_type'], 'prod' ) ? 'product' : 'order',
				'entity_id'     => $row['entity_id'],
				'payload_hash'  => hash( 'sha256', $row['payload'] ),
				'status'        => 'sent',
				'date_modified' => isset( $row['date_modified'] ) ? $row['date_modified'] : current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function retry( array $row, $error ) {
		global $wpdb;
		$table = SPW_Plugin::queue_table();

		$attempts = (int) $row['attempts'] + 1;
		$max      = (int) $row['max_attempts'];

		if ( $attempts >= $max ) {
			$wpdb->update(
				$table,
				array( 'status' => 'dead', 'attempts' => $attempts, 'last_error' => $error ),
				array( 'id' => $row['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			SPW_Admin::notify( 'StockPilot sync failing', $error );
			return;
		}

		$delay   = min( 600, 15 * pow( 2, $attempts - 1 ) ) + wp_rand( 0, 5 );
		$next_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $delay );

		$wpdb->update(
			$table,
			array(
				'status'          => 'pending',
				'attempts'        => $attempts,
				'last_error'      => $error,
				'next_attempt_at' => $next_at,
				'locked_until'    => null,
			),
			array( 'id' => $row['id'] ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function mark_dead( array $row, $error ) {
		global $wpdb;
		$wpdb->update(
			SPW_Plugin::queue_table(),
			array( 'status' => 'dead', 'last_error' => $error ),
			array( 'id' => $row['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/** @return int Count of rows currently pending/sending. */
	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . SPW_Plugin::queue_table() . " WHERE status IN ('pending','sending')"
		);
	}

	/** @return string|null Timestamp of the last successful delivery. */
	public static function last_push_at() {
		global $wpdb;
		return $wpdb->get_var( "SELECT MAX(pushed_at) FROM " . SPW_Plugin::log_table() );
	}

	/** @return string|null Created-at of the oldest pending/sending row. */
	public static function oldest_pending_at() {
		global $wpdb;
		return $wpdb->get_var(
			"SELECT MIN(created_at) FROM " . SPW_Plugin::queue_table() . " WHERE status IN ('pending','sending')"
		);
	}

	/**
	 * True when events are waiting and nothing has been delivered recently.
	 *
	 * @param int $seconds Staleness threshold.
	 * @return bool
	 */
	public static function is_stale( $seconds = 600 ) {
		if ( self::pending_count() < 1 ) {
			return false;
		}

		$last = self::last_push_at();
		if ( empty( $last ) ) {
			return true;
		}

		return ( current_time( 'timestamp' ) - strtotime( $last ) ) > (int) $seconds;
	}

	/** @return string RFC4122 v4 UUID. */
	private static function uuid() {
		$data = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
