<?php
/**
 * Minimal stand-in for WooCommerce Subscriptions' wcs_get_subscriptions().
 *
 * Tests fill $GLOBALS['mlp_test_subscriptions'] with rows of
 * array( 'status' => 'active', 'product_id' => 1, 'variation_id' => 0, 'order' => WC_Order ).
 */

if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
	/**
	 * Subscription products are the IDs listed in $GLOBALS['mlp_test_subscription_products'].
	 */
	class WC_Subscriptions_Product {

		public static function is_subscription( $product ) {
			$id  = is_object( $product ) ? $product->get_id() : (int) $product;
			$ids = isset( $GLOBALS['mlp_test_subscription_products'] ) ? $GLOBALS['mlp_test_subscription_products'] : array();
			if ( in_array( $id, $ids, true ) ) {
				return true;
			}
			$parent = (int) wp_get_post_parent_id( $id );
			return $parent && in_array( $parent, $ids, true );
		}
	}
}

if ( ! class_exists( 'MLP_Stub_Subscription' ) ) {
	/**
	 * A subscription that reads its contact details from a plain order.
	 */
	class MLP_Stub_Subscription {

		/** @var array */
		private $row;

		public function __construct( $row ) {
			$this->row = $row;
		}

		public function has_status( $status ) {
			return in_array( $this->row['status'], (array) $status, true );
		}

		public function __call( $method, $args ) {
			return call_user_func_array( array( $this->row['order'], $method ), $args );
		}
	}
}

if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
	/**
	 * @param array $args Query args as WooCommerce Subscriptions accepts them.
	 * @return MLP_Stub_Subscription[]
	 */
	function wcs_get_subscriptions( $args ) {
		$rows = isset( $GLOBALS['mlp_test_subscriptions'] ) ? $GLOBALS['mlp_test_subscriptions'] : array();
		$out  = array();

		foreach ( $rows as $row ) {
			if ( isset( $args['subscription_status'] ) && $row['status'] !== $args['subscription_status'] ) {
				continue;
			}
			if ( ! empty( $args['product_id'] ) && (int) $row['product_id'] !== (int) $args['product_id'] ) {
				continue;
			}
			if ( ! empty( $args['variation_id'] ) && (int) $row['variation_id'] !== (int) $args['variation_id'] ) {
				continue;
			}
			$out[ $row['order']->get_id() ] = new MLP_Stub_Subscription( $row );
		}

		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		$limit  = isset( $args['subscriptions_per_page'] ) ? (int) $args['subscriptions_per_page'] : -1;

		return -1 === $limit ? array_slice( $out, $offset, null, true ) : array_slice( $out, $offset, $limit, true );
	}
}
