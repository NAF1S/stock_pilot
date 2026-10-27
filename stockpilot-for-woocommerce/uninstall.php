<?php
/**
 * Uninstall StockPilot for WooCommerce: drop the custom tables, options and
 * cron schedules. WooCommerce data is untouched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'spw_process_queue' );
wp_clear_scheduled_hook( 'spw_reconcile' );
wp_clear_scheduled_hook( 'spw_flush_traffic' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spw_outbound_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spw_sync_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spw_traffic_rollup" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spw_traffic_visitors" );

delete_option( 'spw_settings' );
delete_option( 'spw_last_order_reconcile' );
delete_option( 'spw_last_product_reconcile' );
delete_option( 'spw_db_version' );
delete_option( 'spw_traffic_last_flush' );
