<?php
/**
 * Core plugin singleton: wiring, tables, schedules.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Plugin {

	const QUEUE_TABLE = 'spw_outbound_queue';
	const LOG_TABLE   = 'spw_sync_log';
	const TRAFFIC_ROLLUP_TABLE   = 'spw_traffic_rollup';
	const TRAFFIC_VISITORS_TABLE = 'spw_traffic_visitors';
	const OPTIONS_KEY = 'spw_options';

	/** Bump to run install_tables() on existing installs after an update. */
	const DB_VERSION = '2';

	/** @var SPW_Plugin|null */
	private static $instance = null;

	/** @return SPW_Plugin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Load every plugin class. Must be callable from the activation hook too
	 * (before WooCommerce is available), which is why it is not just in the
	 * constructor.
	 */
	public static function load_classes() {
		require_once SPW_DIR . 'includes/class-spw-encryption.php';
		require_once SPW_DIR . 'includes/class-spw-settings.php';
		require_once SPW_DIR . 'includes/class-spw-payload.php';
		require_once SPW_DIR . 'includes/class-spw-queue.php';
		require_once SPW_DIR . 'includes/class-spw-sender.php';
		require_once SPW_DIR . 'includes/class-spw-capture.php';
		require_once SPW_DIR . 'includes/class-spw-traffic.php';
		require_once SPW_DIR . 'includes/class-spw-cron.php';
		require_once SPW_DIR . 'admin/class-spw-admin.php';
	}

	private function __construct() {
		self::load_classes();

		SPW_Settings::instance();
		SPW_Capture::instance();
		SPW_Traffic::instance();
		SPW_Cron::instance();
		SPW_Admin::instance();

		// Self-heal hooks live here (not at file load) so they only run when
		// WooCommerce is active and the dependent classes are loaded.
		add_action( 'init', array( __CLASS__, 'maybe_upgrade_tables' ), 5 );
		add_action( 'init', array( __CLASS__, 'ensure_schedules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_self_heal' ) );
	}

	/** @return string */
	public static function queue_table() {
		global $wpdb;
		return $wpdb->prefix . self::QUEUE_TABLE;
	}

	/** @return string */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . self::LOG_TABLE;
	}

	/** @return string */
	public static function traffic_rollup_table() {
		global $wpdb;
		return $wpdb->prefix . self::TRAFFIC_ROLLUP_TABLE;
	}

	/** @return string */
	public static function traffic_visitors_table() {
		global $wpdb;
		return $wpdb->prefix . self::TRAFFIC_VISITORS_TABLE;
	}

	/** Create/update the plugin tables on existing installs after an update. */
	public static function maybe_upgrade_tables() {
		if ( self::DB_VERSION === get_option( 'spw_db_version' ) ) {
			return;
		}

		self::install_tables();
		update_option( 'spw_db_version', self::DB_VERSION );
	}

	/** Create the custom tables. */
	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$queue = "CREATE TABLE {$wpdb->prefix}" . self::QUEUE_TABLE . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			payload_id CHAR(36) NOT NULL,
			entity_id VARCHAR(64) NULL,
			event_id VARCHAR(64) NOT NULL,
			event_type VARCHAR(32) NOT NULL,
			payload LONGTEXT NOT NULL,
			nonce VARCHAR(64) NOT NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts INT UNSIGNED NOT NULL DEFAULT 12,
			next_attempt_at DATETIME NULL,
			locked_until DATETIME NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			last_error TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY payload_id (payload_id),
			KEY event_id (event_id),
			KEY status_next (status, next_attempt_at),
			KEY entity (event_type, entity_id)
		) $charset;";

		$log = "CREATE TABLE {$wpdb->prefix}" . self::LOG_TABLE . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(64) NOT NULL,
			entity_type VARCHAR(16) NOT NULL,
			entity_id VARCHAR(64) NULL,
			payload_hash CHAR(64) NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'sent',
			pushed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modified DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY entity (entity_type, entity_id)
		) $charset;";

		$traffic = "CREATE TABLE {$wpdb->prefix}" . self::TRAFFIC_ROLLUP_TABLE . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bucket_hour DATETIME NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			page_views INT UNSIGNED NOT NULL DEFAULT 0,
			add_to_cart INT UNSIGNED NOT NULL DEFAULT 0,
			cart_removals INT UNSIGNED NOT NULL DEFAULT 0,
			orders INT UNSIGNED NOT NULL DEFAULT 0,
			units INT UNSIGNED NOT NULL DEFAULT 0,
			sessions INT UNSIGNED NOT NULL DEFAULT 0,
			unique_visitors INT UNSIGNED NOT NULL DEFAULT 0,
			dirty TINYINT(1) NOT NULL DEFAULT 1,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket (bucket_hour, product_id),
			KEY dirty (dirty)
		) $charset;";

		$visitors = "CREATE TABLE {$wpdb->prefix}" . self::TRAFFIC_VISITORS_TABLE . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			visitor_day DATE NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			visitor_hash CHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY visitor (visitor_day, product_id, visitor_hash),
			KEY visitor_day (visitor_day)
		) $charset;";

		dbDelta( $queue );
		dbDelta( $log );
		dbDelta( $traffic );
		dbDelta( $visitors );
	}

	public static function install_schedules() {
		if ( ! wp_next_scheduled( 'spw_process_queue' ) ) {
			wp_schedule_event( time() + 60, 'spw_every_minute', 'spw_process_queue' );
		}
		if ( ! wp_next_scheduled( 'spw_reconcile' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'spw_reconcile' );
		}
		if ( ! wp_next_scheduled( 'spw_flush_traffic' ) ) {
			wp_schedule_event( time() + 300, 'spw_every_five_minutes', 'spw_flush_traffic' );
		}
	}

	public static function clear_schedules() {
		wp_clear_scheduled_hook( 'spw_process_queue' );
		wp_clear_scheduled_hook( 'spw_reconcile' );
		wp_clear_scheduled_hook( 'spw_flush_traffic' );
	}

	/**
	 * Re-register cron events if they went missing.
	 *
	 * Activation is the only place schedules were created before, so a failed
	 * activation, a plugin update, or a wiped cron array left the queue with
	 * no drain event — which looks exactly like "reinstall fixes it, then it
	 * breaks again". This runs cheaply on every request (the cron option is
	 * autoloaded) and is idempotent.
	 */
	public static function ensure_schedules() {
		self::install_schedules();
	}

	/**
	 * Shared-hosting safety net: if events are waiting and delivery is stale,
	 * poke wp-cron.php without blocking the request.
	 *
	 * `DISABLE_WP_CRON` only stops WordPress from spawning cron automatically;
	 * a direct request to wp-cron.php still runs due events. Rate-limited to
	 * once every 5 minutes so it cannot stampede.
	 */
	public static function maybe_self_heal() {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		if ( get_transient( 'spw_self_heal_lock' ) ) {
			return;
		}

		set_transient( 'spw_self_heal_lock', 1, 5 * MINUTE_IN_SECONDS );

		if ( ! SPW_Settings::instance()->is_connected() ) {
			return;
		}

		if ( SPW_Queue::pending_count() < 1 ) {
			return;
		}

		if ( ! SPW_Queue::is_stale( 10 * MINUTE_IN_SECONDS ) ) {
			return;
		}

		wp_remote_get(
			add_query_arg( 'doing_wp_cron', time(), home_url( 'wp-cron.php' ) ),
			array(
				'blocking'  => false,
				'timeout'   => 0.01,
				'sslverify' => true,
			)
		);
	}

	/** On activation, push the full product catalog to seed the portal. */
	public static function queue_catalog_sync() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		SPW_Payload::enqueue_catalog();
	}

	/** Add the custom 1-minute cron interval. */
	public static function register_interval( $schedules ) {
		$schedules['spw_every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute', 'stockpilot-wc' ),
		);
		$schedules['spw_every_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'stockpilot-wc' ),
		);
		return $schedules;
	}
}
add_filter( 'cron_schedules', array( 'SPW_Plugin', 'register_interval' ) );
