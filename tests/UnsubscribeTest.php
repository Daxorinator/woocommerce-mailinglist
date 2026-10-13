<?php
class UnsubscribeTest extends MLP_TestCase {

	/** @var int */
	private $form_id;

	/** @var int */
	private $list_id;

	public function set_up() {
		parent::set_up();

		wp_set_current_user( 0 ); // Visitors unsubscribing are not logged in.

		$this->form_id = $this->make_form();
		$this->make_entry( $this->form_id, 'Ann', 'ann@example.com' );
		$this->make_entry( $this->form_id, 'Ann again', 'ANN@example.com' );
		$this->make_entry( $this->form_id, 'Ben', 'ben@example.com' );
		$this->list_id = $this->make_marketing_list( $this->form_id );
		$this->refresh( $this->list_id );
	}

	public function tear_down() {
		$_GET    = array();
		$_SERVER = array_diff_key( $_SERVER, array( 'REQUEST_METHOD' => true ) );
		parent::tear_down();
	}

	private function request( $method, $list_id, $email, $token = null ) {
		$_GET['mlp_unsubscribe'] = $list_id;
		$_GET['e']               = rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$_GET['t']               = null === $token ? MLP_Unsubscribe::token( $list_id, $email ) : $token;
		$_SERVER['REQUEST_METHOD'] = $method;

		ob_start();
		MLP_Unsubscribe::handle_request();
		return ob_get_clean();
	}

	public function test_token_is_bound_to_list_and_address() {
		$token = MLP_Unsubscribe::token( 1, 'ann@example.com' );

		$this->assertSame( $token, MLP_Unsubscribe::token( 1, 'ANN@example.com' ) );
		$this->assertNotSame( $token, MLP_Unsubscribe::token( 2, 'ann@example.com' ) );
		$this->assertNotSame( $token, MLP_Unsubscribe::token( 1, 'ben@example.com' ) );
	}

	public function test_get_shows_confirmation_and_changes_nothing() {
		$html = $this->request( 'GET', $this->list_id, 'ann@example.com' );

		$this->assertStringContainsString( 'Stop sending marketing emails to ann@example.com?', $html );
		$this->assertStringContainsString( '<form method="post"', $html );
		$this->assertSame( 'ann@example.com,ben@example.com', $this->emails( $this->list_id ) );
		$this->assertSame( 2, $this->entry_count( 'ann@example.com' ) );
	}

	public function test_post_deletes_every_entry_for_the_address() {
		$html = $this->request( 'POST', $this->list_id, 'ann@example.com' );

		$this->assertStringContainsString( 'ann@example.com has been unsubscribed', $html );
		$this->assertSame( 0, $this->entry_count( 'ann@example.com' ) );
		$this->assertSame( 1, $this->entry_count( 'ben@example.com' ) );
		$this->assertSame( 'ben@example.com', $this->emails( $this->list_id ) );
		$this->assertCount( 2, wpforms()->entry->deleted, 'both case variants go through the WPForms handler' );
	}

	public function test_one_click_post_without_confirmation_field_also_works() {
		$_POST['List-Unsubscribe'] = 'One-Click';

		$this->request( 'POST', $this->list_id, 'ben@example.com' );

		$this->assertSame( 0, $this->entry_count( 'ben@example.com' ) );
		unset( $_POST['List-Unsubscribe'] );
	}

	public function test_tampered_token_is_rejected() {
		$html = $this->request( 'POST', $this->list_id, 'ann@example.com', str_repeat( 'a', 64 ) );

		$this->assertStringContainsString( 'invalid or incomplete', $html );
		$this->assertSame( 2, $this->entry_count( 'ann@example.com' ) );
	}

	public function test_product_lists_cannot_be_unsubscribed_from() {
		$list_id = $this->make_product_list( array( 'wc_mode' => 'all_customers' ) );

		$html = $this->request( 'POST', $list_id, 'alice@example.com' );

		$this->assertStringContainsString( 'not possible to unsubscribe', $html );
		$this->assertWPError( MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $list_id ), 'alice@example.com' ) );
	}

	public function test_other_lists_on_the_same_form_lose_the_address_and_counts_update() {
		$second = $this->make_marketing_list( $this->form_id, 'Second list' );
		$this->refresh( $second );

		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $this->list_id ), 'ann@example.com' );

		$this->assertSame( 'ben@example.com', $this->emails( $second ) );
		$this->assertSame( 1, MLP_Lists::get( $second )->member_count );
	}

	public function test_pending_emails_to_the_address_are_removed_from_the_queue() {
		global $wpdb;
		$campaign_id = MLP_Campaigns::save(
			0,
			array(
				'list_id'     => $this->list_id,
				'subject'     => 'Hello',
				'body'        => 'Body',
				'body_format' => 'visual',
			)
		);
		MLP_Campaigns::start( $campaign_id );

		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $this->list_id ), 'ann@example.com' );

		$queued = $wpdb->get_col( $wpdb->prepare( 'SELECT email FROM ' . MLP_DB::table( 'queue' ) . ' WHERE campaign_id = %d', $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( array( 'ben@example.com' ), $queued );
	}

	public function test_nothing_records_who_unsubscribed() {
		global $wpdb;

		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $this->list_id ), 'ann@example.com' );

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'mlp_unsubscribes' ) ) );

		// No plugin table or option may contain the address any more.
		foreach ( array( 'lists', 'members', 'campaigns', 'queue' ) as $table ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . MLP_DB::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->assertStringNotContainsString( 'ann@example.com', wp_json_encode( $rows ), "$table must not keep the address" );
		}
		$this->assertStringNotContainsString( 'ann@example.com', wp_json_encode( get_option( 'mlp_settings' ) ) );
	}

	public function test_submitting_the_form_again_puts_the_person_back_on_the_list() {
		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $this->list_id ), 'ann@example.com' );
		$this->assertSame( 'ben@example.com', $this->refresh( $this->list_id ) );

		$this->make_entry( $this->form_id, 'Ann new', 'ann@example.com' );

		$this->assertSame( 'ann@example.com,ben@example.com', $this->refresh( $this->list_id ) );
	}
}
