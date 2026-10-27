<?php
/**
 * Server-side traffic capture: product views, add-to-cart and cart removals.
 *
 * Writes only to plugin-owned rollup/visitor tables. It never writes to a
 * WooCommerce order, product, post-meta or stock function.
 *
 * Volume control: every signal increments a local hourly bucket; a 5-minute
 * cron flushes one traffic.rollup webhook per dirty (product, hour) bucket.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Traffic {

	/** Fixed whitelist — never interpolate request data into a column name. */
	private const COLUMNS = array( 'page_views', 'add_to_cart', 'cart_removals', 'sessions', 'unique_visitors' );

	/** @var SPW_Traffic|null */
	private static $instance = null;

	/** @return SPW_Traffic */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_before_single_product', array( $this, 'on_product_view' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'on_cart_removed' ), 10, 2 );
		add_action( 'spw_flush_traffic', array( $this, 'flush' ) );
	}

	public function on_product_view() {
		if ( ! $this->enabled() || $this->is_bot() || ! $this->is_frontend_get() ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = (int) $product->get_id();

		if ( $product_id <= 0 ) {
			return;
		}

		$this->bump( $product_id, 'page_views' );
		$this->track_visitor( $product_id );
	}

	public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( ! $this->enabled() || $this->is_bot() ) {
			return;
		}

		$product_id = (int) $product_id;

		if ( $product_id > 0 ) {
			$this->bump( $product_id, 'add_to_cart' );
		}
	}

	public function on_cart_removed( $cart_item_key, $cart ) {
		if ( ! $this->enabled() || $this->is_bot() ) {
			return;
		}

		if ( ! is_object( $cart ) || ! isset( $cart->cart_contents[ $cart_item_key ]['product_id'] ) ) {
			return;
		}

		$product_id = (int) $cart->cart_contents[ $cart_item_key ]['product_id'];

		if ( $product_id > 0 ) {
			$this->bump( $product_id, 'cart_removals' );
		}
	}

	/** Flush dirty buckets as one traffic.rollup event each. */
	public function flush() {
		if ( ! SPW_Settings::instance()->is_connected() ) {
			return;
		}

		global $wpdb;

		$table = SPW_Plugin::traffic_rollup_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE dirty = 1 ORDER BY id ASC LIMIT 500", ARRAY_A );

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! SPW_Payload::enqueue_traffic( $row ) ) {
					continue;
				}

				// Only clear the flag if the bucket has not changed since we read
				// it; a concurrent hit leaves dirty=1 for the next flush.
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$table} SET dirty = 0 WHERE id = %d AND updated_at = %s",
					(int) $row['id'],
					$row['updated_at']
				) );
			}
		}

		$this->prune_visitors();
		update_option( 'spw_traffic_last_flush', current_time( 'mysql' ) );
	}

	private function track_visitor( $product_id ) {
		$customer_id = WC()->session->get_customer_id();

		if ( empty( $customer_id ) ) {
			return;
		}

		$day = current_time( 'Y-m-d' );

		// Daily-rotating salt: the same Woo session cannot be joined across days.
		$hash = hash_hmac( 'sha256', (string) $customer_id, wp_salt( 'auth' ) . '|' . $day );

		global $wpdb;

		$table = SPW_Plugin::traffic_visitors_table();

		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (visitor_day, product_id, visitor_hash, created_at)
			 VALUES (%s, %d, %s, %s)",
			$day,
			(int) $product_id,
			$hash,
			current_time( 'mysql' )
		) );

		if ( $wpdb->rows_affected > 0 ) {
			$this->bump( (int) $product_id, 'unique_visitors' );
		}

		$seen = WC()->session->get( 'spw_traffic_seen' );
		$seen = is_array( $seen ) ? $seen : array();
		$key  = $product_id . ':' . $day;

		if ( ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = 1;
			WC()->session->set( 'spw_traffic_seen', $seen );
			$this->bump( (int) $product_id, 'sessions' );
		}
	}

	/**
	 * Increment one counter in the current UTC hour bucket.
	 *
	 * @param int    $product_id
	 * @param string $column Whitelisted counter column.
	 */
	private function bump( $product_id, $column ) {
		if ( ! in_array( $column, self::COLUMNS, true ) ) {
			return;
		}

		global $wpdb;

		$table = SPW_Plugin::traffic_rollup_table();
		$hour  = gmdate( 'Y-m-d H:00:00' );
		$now   = current_time( 'mysql' );

		$sql = "INSERT INTO {$table} (bucket_hour, product_id, {$column}, dirty, updated_at)
				VALUES (%s, %d, 1, 1, %s)
				ON DUPLICATE KEY UPDATE {$column} = {$column} + 1, dirty = 1, updated_at = %s";

		$wpdb->query( $wpdb->prepare( $sql, $hour, (int) $product_id, $now, $now ) );
	}

	private function prune_visitors() {
		global $wpdb;

		$table  = SPW_Plugin::traffic_visitors_table();
		$cutoff = wp_date( 'Y-m-d', current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE visitor_day < %s", $cutoff ) );
	}

	private function enabled() {
		if ( ! SPW_Settings::instance()->is_connected() ) {
			return false;
		}

		if ( 'yes' !== SPW_Settings::instance()->get( 'traffic_enabled' ) ) {
			return false;
		}

		// Consent can be enforced later without touching capture code.
		if ( 'yes' === SPW_Settings::instance()->get( 'traffic_require_consent' )
			&& ! apply_filters( 'wp_has_consent', false, 'statistics' ) ) {
			return false;
		}

		return true;
	}

	private function is_frontend_get() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		return isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	private function is_bot() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		if ( '' === $user_agent ) {
			return true;
		}

		return (bool) preg_match(
			'/(bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|python-requests|curl\/|wget\/|monitoring|uptime)/i',
			$user_agent
		);
	}
}
