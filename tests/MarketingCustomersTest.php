<?php
/**
 * Marketing lists that draw from WPForms entries, WooCommerce customers, or both.
 *
 * @group woocommerce
 */
class MarketingCustomersTest extends MLP_TestCase {

	/** @var WC_Product_Simple */
	private $product;

	public function set_up() {
		parent::set_up();
		$this->require_woocommerce();

		wp_set_current_user( 0 ); // Visitors unsubscribing are not logged in.
		$this->product = $this->make_simple_product();
	}

	public function tear_down() {
		$_GET    = array();
		$_SERVER = array_diff_key( $_SERVER, array( 'REQUEST_METHOD' => true ) );
		parent::tear_down();
	}

	private function make_mixed_list( $form_id, $name = 'Everyone' ) {
		return $this->make_marketing_list(
			$form_id,
			$name,
			array(
				'include_customers' => 1,
				'wc_mode'           => 'all_customers',
			)
		);
	}

	private function make_customers_only_list( $name = 'Customers' ) {
		$id = MLP_Lists::save(
			0,
			$name,
			'marketing',
			array(
				'include_customers' => 1,
				'wc_mode'           => 'all_customers',
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	public function test_a_mixed_list_holds_form_subscribers_and_customers_once_each() {
		$form_id = $this->make_form();
		$this->make_entry( $form_id, 'Sue', 'sue@example.com' );
		$this->make_entry( $form_id, 'Alice', 'alice@example.com' );
		$this->make_order( 'alice@example.com', 'Alice', $this->product, 'completed' );
		$this->make_order( 'bob@example.com', 'Bob', $this->product, 'processing' );

		$list_id = $this->make_mixed_list( $form_id );

		$this->assertSame( 'alice@example.com,bob@example.com,sue@example.com', $this->refresh( $list_id ) );
		$this->assertSame( 3, MLP_Lists::get( $list_id )->member_count );
	}

	public function test_customers_are_only_included_when_asked_for() {
		$form_id = $this->make_form();
		$this->make_entry( $form_id, 'Sue', 'sue@example.com' );
		$this->make_order( 'bob@example.com', 'Bob', $this->product, 'completed' );

		$form_only = $this->make_marketing_list( $form_id );

		$this->assertSame( 'sue@example.com', $this->refresh( $form_only ) );
	}

	public function test_a_list_needs_a_form_or_customers() {
		$result = MLP_Lists::save( 0, 'Nothing', 'marketing', array() );
		$this->assertWPError( $result );

		$this->assertIsInt( $this->make_customers_only_list() );
	}

	public function test_a_customers_only_list_is_sourced_without_a_form() {
		$this->make_order( 'bob@example.com', 'Bob', $this->product, 'completed' );
		$list_id = $this->make_customers_only_list();

		$this->assertSame( 'bob@example.com', $this->refresh( $list_id ) );
		$this->assertSame( 'WooCommerce: all customers', MLP_Lists::describe_source( MLP_Lists::get( $list_id ) ) );
	}

	public function test_a_customer_who_unsubscribes_is_not_added_back_by_the_next_refresh() {
		$form_id = $this->make_form();
		$this->make_entry( $form_id, 'Sue', 'sue@example.com' );
		$this->make_order( 'bob@example.com', 'Bob', $this->product, 'completed' );
		$list_id = $this->make_mixed_list( $form_id );
		$this->refresh( $list_id );

		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $list_id ), 'bob@example.com' );
		$this->assertSame( 'sue@example.com', $this->emails( $list_id ) );

		MLP_Refresher::refresh_all();

		$this->assertSame( 'sue@example.com', $this->emails( $list_id ) );
	}

	public function test_unsubscribing_applies_to_every_marketing_list_and_form() {
		$form_a = $this->make_form( 'Form A' );
		$form_b = $this->make_form( 'Form B' );
		$this->make_entry( $form_a, 'Sue', 'sue@example.com' );
		$this->make_entry( $form_b, 'Sue', 'sue@example.com' );
		$this->make_entry( $form_b, 'Tom', 'tom@example.com' );
		$list_a = $this->make_marketing_list( $form_a, 'A' );
		$list_b = $this->make_marketing_list( $form_b, 'B' );
		$this->refresh( $list_a );
		$this->refresh( $list_b );

		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $list_a ), 'sue@example.com' );

		$this->assertSame( '', $this->emails( $list_a ) );
		$this->assertSame( 'tom@example.com', $this->emails( $list_b ) );
		$this->assertSame( 0, $this->entry_count( 'sue@example.com' ) );
		$this->assertSame( 'tom@example.com', $this->refresh( $list_b ) );
	}

	public function test_product_lists_still_include_an_unsubscribed_customer() {
		$this->make_order( 'bob@example.com', 'Bob', $this->product, 'completed' );
		$marketing = $this->make_customers_only_list();
		$product   = $this->make_product_list( array( 'wc_mode' => 'all_customers' ) );
		$this->refresh( $marketing );

		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $marketing ), 'bob@example.com' );

		$this->assertSame( '', $this->refresh( $marketing ) );
		$this->assertSame( 'bob@example.com', $this->refresh( $product ) );
	}

	public function test_a_customer_who_signs_up_through_the_form_again_is_back_on_the_list() {
		$form_id = $this->make_form();
		$this->make_order( 'bob@example.com', 'Bob', $this->product, 'completed' );
		$list_id = $this->make_mixed_list( $form_id );
		$this->refresh( $list_id );
		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $list_id ), 'bob@example.com' );

		$this->make_entry( $form_id, 'Bob', 'bob@example.com' );

		$this->assertSame( 'bob@example.com', $this->refresh( $list_id ) );
	}

	public function test_the_unsubscribe_link_works_after_its_list_was_deleted() {
		$this->make_order( 'bob@example.com', 'Bob', $this->product, 'completed' );
		$deleted = $this->make_customers_only_list( 'Old' );
		$kept    = $this->make_customers_only_list( 'Current' );
		$this->refresh( $kept );
		$token = MLP_Unsubscribe::token( $deleted, 'bob@example.com' );
		MLP_Lists::delete( $deleted );

		$_GET['mlp_unsubscribe']   = $deleted;
		$_GET['e']                 = rtrim( strtr( base64_encode( 'bob@example.com' ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$_GET['t']                 = $token;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		MLP_Unsubscribe::handle_request();
		ob_get_clean();
		$this->assertSame( 'bob@example.com', $this->emails( $kept ), 'A GET only asks for confirmation.' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		ob_start();
		MLP_Unsubscribe::handle_request();
		ob_get_clean();

		$this->assertSame( '', $this->emails( $kept ) );
		$this->assertSame( '', $this->refresh( $kept ) );
	}

	public function test_a_failing_form_does_not_prune_the_customers_already_on_the_list() {
		$form_id = $this->make_form();
		$this->make_entry( $form_id, 'Sue', 'sue@example.com' );
		$this->make_order( 'bob@example.com', 'Bob', $this->product, 'completed' );
		$list_id = $this->make_mixed_list( $form_id );
		$this->refresh( $list_id );

		wp_trash_post( $form_id );
		$result = MLP_Refresher::refresh_list( MLP_Lists::get( $list_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'bob@example.com,sue@example.com', $this->emails( $list_id ) );
	}

	public function test_the_suppression_record_is_a_hash_not_the_address() {
		global $wpdb;
		$list_id = $this->make_customers_only_list();
		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $list_id ), 'Bob@Example.com' );

		$rows = $wpdb->get_results( 'SELECT * FROM ' . MLP_DB::table( 'suppressions' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->assertCount( 1, $rows );
		$this->assertSame( MLP_Suppressions::hash( 'bob@example.com' ), $rows[0]->email_hash );
		$this->assertStringNotContainsStringIgnoringCase( 'bob@example.com', wp_json_encode( $rows ) );
	}
}
