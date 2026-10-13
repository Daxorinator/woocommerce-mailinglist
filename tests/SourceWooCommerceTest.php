<?php
/**
 * @group woocommerce
 */
class SourceWooCommerceTest extends MLP_TestCase {

	public function set_up() {
		parent::set_up();
		$this->require_woocommerce();
	}

	public function test_all_customers_excludes_cancelled_orders_and_can_include_registered_accounts() {
		$product = $this->make_simple_product();
		$this->make_order( 'Alice@Example.com', 'Alice', $product, 'completed' );
		$this->make_order( 'bob@example.com', 'Bob', $product, 'processing' );
		$this->make_order( 'carol@example.com', 'Carol', $product, 'cancelled' );
		wp_insert_user(
			array(
				'user_login' => 'eve',
				'user_email' => 'eve@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			)
		);

		$with    = $this->make_product_list(
			array(
				'wc_mode'            => 'all_customers',
				'include_registered' => 1,
			)
		);
		$without = $this->make_product_list( array( 'wc_mode' => 'all_customers' ) );

		$this->assertSame( 'alice@example.com,bob@example.com,eve@example.com', $this->refresh( $with ) );
		$this->assertSame( 'alice@example.com,bob@example.com', $this->refresh( $without ) );
	}

	public function test_purchasers_of_a_simple_product_ignore_refund_records_and_unlisted_statuses() {
		$product = $this->make_simple_product();
		$order   = $this->make_order( 'alice@example.com', 'Alice', $product, 'completed' );
		$this->make_order( 'carol@example.com', 'Carol', $product, 'cancelled' );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 1,
			)
		);

		$default = $this->make_product_list(
			array(
				'wc_mode'    => 'purchased_product',
				'product_id' => $product->get_id(),
			)
		);
		$custom  = $this->make_product_list(
			array(
				'wc_mode'        => 'purchased_product',
				'product_id'     => $product->get_id(),
				'order_statuses' => array( 'completed', 'cancelled' ),
			)
		);

		$this->assertSame( 'alice@example.com', $this->refresh( $default ) );
		$this->assertSame( 'alice@example.com,carol@example.com', $this->refresh( $custom ) );
	}

	public function test_purchasers_of_a_variable_product_cover_all_variations_or_just_one() {
		list( $parent, $variations ) = $this->make_variable_product();
		$this->make_order( 'bob@example.com', 'Bob', $variations[0], 'processing' );
		$this->make_order( 'dave@example.com', 'Dave', $variations[1], 'completed' );

		$whole = $this->make_product_list(
			array(
				'wc_mode'    => 'purchased_product',
				'product_id' => $parent->get_id(),
			)
		);
		$one   = $this->make_product_list(
			array(
				'wc_mode'    => 'purchased_product',
				'product_id' => $variations[0]->get_id(),
			)
		);

		$this->assertSame( 'bob@example.com,dave@example.com', $this->refresh( $whole ) );
		$this->assertSame( 'bob@example.com', $this->refresh( $one ) );
	}

	public function test_a_purchased_product_list_requires_a_product() {
		$this->assertWPError( MLP_Lists::save( 0, 'x', 'product', array( 'wc_mode' => 'purchased_product' ) ) );
	}

	public function test_subscribers_by_product_variation_or_any_subscription() {
		list( $parent, $variations ) = $this->make_variable_product();
		$simple                      = $this->make_simple_product();

		$bob   = $this->make_order( 'bob@example.com', 'Bob', $variations[0], 'processing' );
		$dave  = $this->make_order( 'dave@example.com', 'Dave', $variations[1], 'completed' );
		$carol = $this->make_order( 'carol@example.com', 'Carol', $variations[1], 'completed' );
		$alice = $this->make_order( 'alice@example.com', 'Alice', $simple, 'completed' );

		$GLOBALS['mlp_test_subscriptions'] = array(
			array(
				'status'       => 'active',
				'product_id'   => $parent->get_id(),
				'variation_id' => $variations[0]->get_id(),
				'order'        => $bob,
			),
			array(
				'status'       => 'active',
				'product_id'   => $parent->get_id(),
				'variation_id' => $variations[1]->get_id(),
				'order'        => $dave,
			),
			array(
				'status'       => 'cancelled',
				'product_id'   => $parent->get_id(),
				'variation_id' => $variations[1]->get_id(),
				'order'        => $carol,
			),
			array(
				'status'       => 'active',
				'product_id'   => $simple->get_id(),
				'variation_id' => 0,
				'order'        => $alice,
			),
		);

		$product   = $this->make_product_list(
			array(
				'wc_mode'    => 'active_subscribers',
				'product_id' => $parent->get_id(),
			)
		);
		$variation = $this->make_product_list(
			array(
				'wc_mode'    => 'active_subscribers',
				'product_id' => $variations[0]->get_id(),
			)
		);
		$any       = $this->make_product_list( array( 'wc_mode' => 'active_subscribers' ) );

		$this->assertSame( 'bob@example.com,dave@example.com', $this->refresh( $product ) );
		$this->assertSame( 'bob@example.com', $this->refresh( $variation ) );
		$this->assertSame( 'alice@example.com,bob@example.com,dave@example.com', $this->refresh( $any ) );
	}

	public function test_refresh_drops_customers_who_no_longer_match() {
		$product = $this->make_simple_product();
		$order   = $this->make_order( 'dave@example.com', 'Dave', $product, 'completed' );
		$this->make_order( 'bob@example.com', 'Bob', $product, 'completed' );
		$list_id = $this->make_product_list(
			array(
				'wc_mode'    => 'purchased_product',
				'product_id' => $product->get_id(),
			)
		);
		$this->assertSame( 'bob@example.com,dave@example.com', $this->refresh( $list_id ) );

		$order->set_status( 'cancelled' );
		$order->save();
		MLP_Refresher::refresh_all();

		$this->assertSame( 'bob@example.com', $this->emails( $list_id ) );
	}
}
