<?php
/**
 * Admin settings screen: connection config + health + test connection.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Admin {

	/** @var SPW_Admin|null */
	private static $instance = null;

	/** @return SPW_Admin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_init', array( $this, 'maybe_drain' ), 20 );
		add_action( 'admin_notices', array( $this, 'print_notices' ) );
	}

	/**
	 * Opportunistically drain the queue when an admin opens wp-admin.
	 * Rate-limited (once / 30 s) and time-boxed (5 s) so the dashboard never
	 * crawls; the cPanel cron remains the primary drain.
	 */
	public function maybe_drain() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! SPW_Settings::instance()->is_connected() ) {
			return;
		}

		if ( get_transient( 'spw_admin_drain_lock' ) ) {
			return;
		}

		set_transient( 'spw_admin_drain_lock', 1, 30 );

		if ( SPW_Queue::pending_count() < 1 ) {
			return;
		}

		SPW_Queue::process( 5 );
	}

	public function menu() {
		add_menu_page(
			__( 'StockPilot', 'stockpilot-wc' ),
			__( 'StockPilot', 'stockpilot-wc' ),
			'manage_options',
			'stockpilot',
			array( $this, 'render_page' ),
			'dashicons-admin-site-alt3',
			56
		);
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$has_action = isset( $_POST['spw_save'] )
			|| isset( $_POST['spw_sync_products'] )
			|| isset( $_POST['spw_sync_orders'] )
			|| isset( $_POST['spw_sync_orders_force'] )
			|| isset( $_POST['spw_drain_queue'] )
			|| isset( $_POST['spw_retry_dead'] );

		if ( ! $has_action ) {
			return;
		}

		check_admin_referer( 'spw_settings' );

		// Re-queue dead events, then try them.
		if ( isset( $_POST['spw_retry_dead'] ) ) {
			$retried = SPW_Queue::retry_dead();
			$result  = SPW_Queue::process( 12 );
			add_settings_error(
				'spw',
				'spw_retry_dead',
				sprintf(
					/* translators: 1: re-queued count, 2: sent count, 3: remaining count */
					__( 'Re-queued %1$d failed event(s): %2$d sent, %3$d still waiting.', 'stockpilot-wc' ),
					(int) $retried,
					(int) $result['sent'],
					(int) $result['remaining']
				),
				'success'
			);
			return;
		}

		// Drain whatever is already queued — no re-enqueue, no WP-Cron needed.
		if ( isset( $_POST['spw_drain_queue'] ) ) {
			$result = SPW_Queue::process( 12 );
			add_settings_error(
				'spw',
				'spw_drain',
				sprintf(
					/* translators: 1: sent count, 2: remaining count */
					__( 'Queue processed: %1$d sent, %2$d still waiting.', 'stockpilot-wc' ),
					(int) $result['sent'],
					(int) $result['remaining']
				),
				'success'
			);
			return;
		}

		// Manual sync buttons (work even when WP-Cron is disabled).
		if ( isset( $_POST['spw_sync_products'] ) ) {
			SPW_Payload::enqueue_catalog();
			SPW_Queue::process();
			add_settings_error( 'spw', 'spw_sync', __( 'Product catalog sync sent.', 'stockpilot-wc' ), 'success' );
			return;
		}

		if ( isset( $_POST['spw_sync_orders'] ) ) {
			SPW_Cron::sync_orders_now();
			SPW_Queue::process();
			add_settings_error( 'spw', 'spw_sync', __( 'Order sync sent.', 'stockpilot-wc' ), 'success' );
			return;
		}

		if ( isset( $_POST['spw_sync_orders_force'] ) ) {
			// Re-push EVERY order with a fresh event id, so orders that were
			// deleted from the portal (or never made it) are re-imported.
			SPW_Cron::sync_orders_now( true );
			SPW_Queue::process();
			add_settings_error( 'spw', 'spw_sync', __( 'All orders re-synced (forced).', 'stockpilot-wc' ), 'success' );
			return;
		}

		$portal_url = isset( $_POST['spw_portal_url'] ) ? esc_url_raw( wp_unslash( $_POST['spw_portal_url'] ) ) : '';
		$api_key    = isset( $_POST['spw_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['spw_api_key'] ) ) : '';
		$api_secret = isset( $_POST['spw_api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['spw_api_secret'] ) ) : '';

		$save_values = array(
			'portal_url' => untrailingslashit( $portal_url ),
			'api_key'    => $api_key,
			'disabled'   => 'no',
		);

		// If the secret field still shows the masked placeholder, keep the stored secret.
		if ( '' !== $api_secret && 0 !== strpos( $api_secret, '••' ) ) {
			$save_values['api_secret'] = $api_secret;
		}

		SPW_Settings::instance()->save( $save_values );

		$tested = SPW_Sender::test_connection();

		if ( $tested['ok'] ) {
			// First successful connection: push the whole product catalog and any
			// pending events right away, so the portal populates without waiting
			// for WP-Cron.
			SPW_Payload::enqueue_catalog();
			SPW_Queue::process();
		}

		add_settings_error(
			'spw',
			'spw_saved',
			$tested['ok'] ? __( 'Settings saved and connection verified. Product catalog sync sent.', 'stockpilot-wc' ) : __( 'Settings saved, but the connection test failed: ', 'stockpilot-wc' ) . $tested['message'],
			$tested['ok'] ? 'success' : 'error'
		);
	}

	public function print_notices() {
		$queue_dead = $this->dead_count();

		if ( $queue_dead > 0 ) {
			echo '<div class="notice notice-warning"><p>StockPilot: ' . esc_html( $queue_dead ) . ' event(s) failed to sync and are waiting for attention.</p></div>';
		}

		if ( SPW_Settings::instance()->is_connected() ) {
			$pending = SPW_Queue::pending_count();
			$last    = SPW_Queue::last_push_at();
			$stale   = $pending > 0 && ( empty( $last ) || ( current_time( 'timestamp' ) - strtotime( $last ) ) > 10 * MINUTE_IN_SECONDS );

			if ( $stale ) {
				echo '<div class="notice notice-error"><p><strong>StockPilot: ' . esc_html( $pending ) . ' event(s) are waiting and nothing has been delivered for over 10 minutes.</strong> The cPanel cron may not be running. Open the StockPilot page or click <em>Process queue now</em>.</p></div>';
			} elseif ( $pending >= 100 ) {
				echo '<div class="notice notice-warning"><p>StockPilot: a backlog of ' . esc_html( $pending ) . ' events is queued. Open the StockPilot page to drain it.</p></div>';
			}
		}

		if ( SPW_Settings::instance()->is_connected() && ! $this->heartbeat_recent() ) {
			echo '<div class="notice notice-info"><p>StockPilot is configured but the portal has not confirmed a connection yet. Use the Test Connection button.</p></div>';
		}
	}

	public function render_page() {
		$settings = SPW_Settings::instance();
		$all      = $settings->all();
		$status   = $this->status_summary();

		include SPW_DIR . 'admin/views/settings.php';
	}

	/** @return array */
	public function status_summary() {
		global $wpdb;

		return array(
			'connected'        => SPW_Settings::instance()->is_connected(),
			'queue_pending'    => SPW_Queue::pending_count(),
			'oldest_pending'   => SPW_Queue::oldest_pending_at(),
			'dead'             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . SPW_Plugin::queue_table() . " WHERE status='dead'" ),
			'last_sent'        => $wpdb->get_var( "SELECT MAX(pushed_at) FROM " . SPW_Plugin::log_table() ),
			'last_synced'      => get_option( 'spw_last_order_reconcile', '—' ),
			'next_queue_run'   => wp_next_scheduled( 'spw_process_queue' ),
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	private function dead_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . SPW_Plugin::queue_table() . " WHERE status='dead'" );
	}

	private function heartbeat_recent() {
		return ! empty( $this->status_summary()['last_sent'] );
	}

	/** Queued admin notice (e.g. connection disabled, sync failing). */
	public static function notify( $title, $message ) {
		set_transient(
			'spw_admin_notice',
			array( 'title' => $title, 'message' => $message ),
			7 * DAY_IN_SECONDS
		);
	}
}
