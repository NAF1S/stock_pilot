<?php
/**
 * Captures WooCommerce order + product events and enqueues them for sync.
 * READ-ONLY: never writes order/product data, never touches stock functions,
 * never calls $order->update_status().
 */

defined( 'ABSPATH' ) || exit;

class SPW_Capture {

	/** @var SPW_Capture|null */
	private static $instance = null;

	/** @return SPW_Capture */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Orders
		add_action( 'woocommerce_new_order', array( $this, 'on_order_new' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 1 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'on_order_refunded' ), 10, 1 );
		add_action( 'woocommerce_rest_insert_shop_order', array( $this, 'on_rest_order' ), 10, 1 );

		// Order deletion/trash. WooCommerce's dedicated hooks are not fired by
		// every admin path, and HPOS bypasses the WordPress post hooks, so
		// register both families. The delete event id is deterministic per
		// order + modified time, so duplicate hooks dedupe in the queue.
		add_action( 'woocommerce_trash_order', array( $this, 'on_order_trashed' ), 10, 1 );
		add_action( 'woocommerce_delete_order', array( $this, 'on_order_deleted' ), 10, 1 );

		// Products
		add_action( 'woocommerce_new_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_rest_insert_product_object', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'on_post_trashed' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'on_post_untrashed' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ), 10, 1 );
	}

	public function on_order_new( $order_id ) {
		SPW_Payload::enqueue_order( $order_id, 'new_order' );
	}

	public function on_order_status_changed( $order_id, $old_status, $new_status ) {
		SPW_Payload::enqueue_order( $order_id, "status:$old_status->$new_status" );
	}

	public function on_order_refunded( $order_id ) {
		SPW_Payload::enqueue_order( $order_id, 'refunded' );
	}

	public function on_rest_order( $order ) {
		SPW_Payload::enqueue_order( $order->get_id(), 'rest' );
	}

	public function on_order_trashed( $order_id ) {
		SPW_Payload::enqueue_order_deleted( (int) $order_id, 'trash' );
	}

	public function on_order_deleted( $order_id ) {
		SPW_Payload::enqueue_order_deleted( (int) $order_id, 'delete' );
	}

	public function on_product_saved( $product_id ) {
		if ( $this->is_product( $product_id ) ) {
			SPW_Payload::enqueue_product( (int) $product_id );
		}
	}

	public function on_post_trashed( $post_id ) {
		if ( $this->is_product( $post_id ) ) {
			SPW_Payload::enqueue_product( (int) $post_id );
		} elseif ( $this->is_order( $post_id ) ) {
			SPW_Payload::enqueue_order_deleted( (int) $post_id, 'trash' );
		}
	}

	public function on_post_untrashed( $post_id ) {
		if ( $this->is_product( $post_id ) ) {
			SPW_Payload::enqueue_product( (int) $post_id );
		} elseif ( $this->is_order( $post_id ) ) {
			// Force a fresh event id: the order may be re-reserved after being
			// restored, even if its status/date_modified did not change.
			SPW_Payload::enqueue_order( (int) $post_id, 'untrashed', true );
		}
	}

	public function on_post_deleted( $post_id ) {
		if ( $this->is_product( $post_id ) ) {
			SPW_Payload::enqueue_product( (int) $post_id, true );
		} elseif ( $this->is_order( $post_id ) ) {
			SPW_Payload::enqueue_order_deleted( (int) $post_id, 'delete' );
		}
	}

	private function is_product( $post_id ) {
		return 'product' === get_post_type( $post_id );
	}

	private function is_order( $post_id ) {
		return in_array( get_post_type( $post_id ), array( 'shop_order', 'shop_order_placehold' ), true );
	}
}
