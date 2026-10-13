<?php
defined( 'ABSPATH' ) || exit;

/**
 * Email source: WooCommerce customers, purchasers of a product, or subscribers
 * to a subscription product / variation (WooCommerce Subscriptions).
 */
class MLP_Source_WooCommerce {

	public static function is_available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' );
	}

	public static function subscriptions_available() {
		return function_exists( 'wcs_get_subscriptions' );
	}

	public static function modes() {
		return array(
			'all_customers'      => __( 'All customers', 'mailinglist-plugin' ),
			'purchased_product'  => __( 'All customers who have purchased a product', 'mailinglist-plugin' ),
			'active_subscribers' => __( 'All subscribers to a subscription product', 'mailinglist-plugin' ),
		);
	}

	/**
	 * Order statuses, keyed without the "wc-" prefix.
	 *
	 * @return array
	 */
	public static function order_statuses() {
		$statuses = array();
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $key => $label ) {
				$statuses[ 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key ] = $label;
			}
		}
		if ( ! $statuses ) {
			$statuses = array(
				'processing' => __( 'Processing', 'mailinglist-plugin' ),
				'completed'  => __( 'Completed', 'mailinglist-plugin' ),
			);
		}
		return $statuses;
	}

	public static function subscription_statuses() {
		return array(
			'active'         => __( 'Active', 'mailinglist-plugin' ),
			'pending-cancel' => __( 'Pending cancellation', 'mailinglist-plugin' ),
			'on-hold'        => __( 'On hold', 'mailinglist-plugin' ),
		);
	}

	/**
	 * Display name for a product or variation ID.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	public static function product_name( $product_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			/* translators: %d: product ID */
			return sprintf( __( 'Product #%d (deleted)', 'mailinglist-plugin' ), $product_id );
		}
		return wp_strip_all_tags( $product->get_formatted_name() );
	}

	private static function is_variation( $product_id ) {
		return 'product_variation' === get_post_type( $product_id );
	}

	public static function describe( $config ) {
		$mode       = isset( $config['wc_mode'] ) ? $config['wc_mode'] : 'all_customers';
		$product_id = isset( $config['product_id'] ) ? (int) $config['product_id'] : 0;

		if ( 'purchased_product' === $mode ) {
			/* translators: %s: product name */
			return sprintf( __( 'WooCommerce: customers who purchased %s', 'mailinglist-plugin' ), self::product_name( $product_id ) );
		}
		if ( 'active_subscribers' === $mode ) {
			if ( ! $product_id ) {
				return __( 'WooCommerce: subscribers to any subscription product', 'mailinglist-plugin' );
			}
			/* translators: %s: product name */
			return sprintf( __( 'WooCommerce: subscribers to %s', 'mailinglist-plugin' ), self::product_name( $product_id ) );
		}
		return __( 'WooCommerce: all customers', 'mailinglist-plugin' );
	}

	/**
	 * @param array    $config Source configuration.
	 * @param callable $emit   function( $email, $name, $source_ref ).
	 * @return true|WP_Error
	 */
	public static function fetch( $config, $emit ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'mlp_no_wc', __( 'WooCommerce is not active.', 'mailinglist-plugin' ) );
		}

		$mode = isset( $config['wc_mode'] ) ? $config['wc_mode'] : 'all_customers';

		if ( 'purchased_product' === $mode ) {
			return self::fetch_purchasers( $config, $emit );
		}
		if ( 'active_subscribers' === $mode ) {
			return self::fetch_subscribers( $config, $emit );
		}
		return self::fetch_all_customers( $config, $emit );
	}

	/**
	 * Emit the billing contact of an order or subscription.
	 *
	 * @param WC_Order $order Order or subscription.
	 * @param callable $emit  Emit callback.
	 * @param string   $ref   Source reference.
	 */
	private static function emit_order( $order, $emit, $ref ) {
		$email = mlp_normalize_email( $order->get_billing_email() );
		$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		if ( ! $email && $order->get_customer_id() ) {
			$user = get_userdata( $order->get_customer_id() );
			if ( $user ) {
				$email = mlp_normalize_email( $user->user_email );
				$name  = $name ? $name : $user->display_name;
			}
		}
		if ( $email ) {
			call_user_func( $emit, $email, $name, $ref );
		}
	}

	private static function order_statuses_from( $config ) {
		$statuses = isset( $config['order_statuses'] ) && is_array( $config['order_statuses'] ) ? $config['order_statuses'] : array();
		return $statuses ? $statuses : array( 'processing', 'completed' );
	}

	private static function fetch_all_customers( $config, $emit ) {
		$statuses = self::order_statuses_from( $config );
		$per_page = 200;
		$page     = 1;

		do {
			$ids = wc_get_orders(
				array(
					'type'    => 'shop_order',
					'status'  => $statuses,
					'limit'   => $per_page,
					'paged'   => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);
			foreach ( (array) $ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order && 'shop_order' === $order->get_type() ) {
					self::emit_order( $order, $emit, 'order:' . $order_id );
				}
			}
			++$page;
		} while ( count( (array) $ids ) === $per_page );

		if ( ! empty( $config['include_registered'] ) ) {
			$page = 1;
			do {
				$users = get_users(
					array(
						'role'    => 'customer',
						'number'  => 500,
						'paged'   => $page,
						'orderby' => 'ID',
						'fields'  => array( 'ID', 'user_email', 'display_name' ),
					)
				);
				foreach ( $users as $user ) {
					$email = mlp_normalize_email( $user->user_email );
					if ( $email ) {
						call_user_func( $emit, $email, $user->display_name, 'user:' . $user->ID );
					}
				}
				++$page;
			} while ( count( $users ) === 500 );
		}

		return true;
	}

	/**
	 * Customers with an order containing the product. Choosing a variable product
	 * matches every variation; choosing a variation matches only that variation.
	 */
	private static function fetch_purchasers( $config, $emit ) {
		global $wpdb;

		$product_id = isset( $config['product_id'] ) ? (int) $config['product_id'] : 0;
		if ( ! $product_id ) {
			return new WP_Error( 'mlp_no_product', __( 'No product is selected for this list.', 'mailinglist-plugin' ) );
		}

		$statuses = self::order_statuses_from( $config );
		$meta_key = self::is_variation( $product_id ) ? '_variation_id' : '_product_id';
		$last_id  = 0;
		$per_page = 500;

		// The order item tables are shared by legacy and HPOS order storage.
		do {
			$order_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT oi.order_id
					FROM {$wpdb->prefix}woocommerce_order_items oi
					INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
					WHERE oi.order_item_type = 'line_item' AND oim.meta_key = %s AND oim.meta_value = %s AND oi.order_id > %d
					ORDER BY oi.order_id ASC LIMIT %d",
					$meta_key,
					(string) $product_id,
					$last_id,
					$per_page
				)
			);

			foreach ( (array) $order_ids as $order_id ) {
				$last_id = (int) $order_id;
				$order   = wc_get_order( $order_id );
				// Skips refunds and subscriptions, which also carry line items.
				if ( $order && 'shop_order' === $order->get_type() && $order->has_status( $statuses ) ) {
					self::emit_order( $order, $emit, 'order:' . $order_id );
				}
			}
		} while ( count( (array) $order_ids ) === $per_page );

		return true;
	}

	private static function fetch_subscribers( $config, $emit ) {
		if ( ! self::subscriptions_available() ) {
			return new WP_Error( 'mlp_no_wcs', __( 'WooCommerce Subscriptions is not active.', 'mailinglist-plugin' ) );
		}

		$product_id = isset( $config['product_id'] ) ? (int) $config['product_id'] : 0;
		$statuses   = isset( $config['subscription_statuses'] ) && is_array( $config['subscription_statuses'] ) && $config['subscription_statuses']
			? $config['subscription_statuses']
			: array( 'active' );
		$per_page   = 100;

		foreach ( $statuses as $status ) {
			$offset = 0;
			do {
				$args = array(
					'subscriptions_per_page' => $per_page,
					'offset'                 => $offset,
					'subscription_status'    => $status,
					'orderby'                => 'start_date',
					'order'                  => 'ASC',
				);
				if ( $product_id ) {
					$args[ self::is_variation( $product_id ) ? 'variation_id' : 'product_id' ] = $product_id;
				}

				$subscriptions = (array) wcs_get_subscriptions( $args );
				foreach ( $subscriptions as $subscription ) {
					if ( is_object( $subscription ) && $subscription->has_status( $status ) ) {
						self::emit_order( $subscription, $emit, 'subscription:' . $subscription->get_id() );
					}
				}
				$offset += $per_page;
			} while ( count( $subscriptions ) === $per_page );
		}

		return true;
	}
}
