<?php
/**
 * @group wpforms
 */
class MergeTest extends MLP_TestCase {

	/** @var int */
	private $target_id;

	/** @var int */
	private $source_id;

	public function set_up() {
		parent::set_up();

		$target_form = $this->make_form();
		$this->make_entry( $target_form, 'Ann', 'ann@example.com' );
		$this->target_id = $this->make_marketing_list( $target_form, 'Marketing' );
		$this->refresh( $this->target_id );

		// Any list type can be the source; a second form keeps the fixture free of WooCommerce.
		$source_form = $this->make_form();
		$this->make_entry( $source_form, 'Ann', 'ann@example.com' );
		$this->make_entry( $source_form, 'Ben', 'ben@example.com' );
		$this->make_entry( $source_form, 'Cat', 'cat@example.com' );
		$this->source_id = $this->make_marketing_list( $source_form, 'Source' );
		$this->refresh( $this->source_id );
	}

	public function test_merge_adds_only_missing_addresses() {
		$result = MLP_Lists::merge( $this->source_id, $this->target_id );

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['added'] );
		$this->assertSame( 'ann@example.com,ben@example.com,cat@example.com', $this->emails( $this->target_id ) );
		$this->assertSame( 'ann@example.com,ben@example.com,cat@example.com', $this->emails( $this->source_id ) );
	}

	public function test_merging_twice_creates_no_duplicates() {
		MLP_Lists::merge( $this->source_id, $this->target_id );
		$again = MLP_Lists::merge( $this->source_id, $this->target_id );

		$this->assertSame( 0, $again['added'] );
		$this->assertSame( 3, MLP_Lists::count_members( $this->target_id ) );
		$this->assertSame( 0, MLP_Lists::count_missing( $this->source_id, $this->target_id ) );
	}

	public function test_merged_members_survive_a_refresh_and_keep_their_name() {
		global $wpdb;
		MLP_Lists::merge( $this->source_id, $this->target_id );

		$this->refresh( $this->target_id );

		$this->assertSame( 'ann@example.com,ben@example.com,cat@example.com', $this->emails( $this->target_id ) );
		$name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . MLP_DB::table( 'members' ) . ' WHERE list_id = %d AND email = %s', $this->target_id, 'ben@example.com' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 'Ben', $name );
	}

	public function test_unsubscribing_after_a_merge_only_affects_the_target() {
		MLP_Lists::merge( $this->source_id, $this->target_id );

		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $this->target_id ), 'ben@example.com' );
		$this->refresh( $this->target_id );

		$this->assertSame( 'ann@example.com,cat@example.com', $this->emails( $this->target_id ) );
		$this->assertStringContainsString( 'ben@example.com', $this->refresh( $this->source_id ) );
	}

	public function test_only_a_different_marketing_list_can_be_the_target() {
		$product = $this->make_product_list( array( 'wc_mode' => 'all_customers' ) );

		$this->assertWPError( MLP_Lists::merge( $this->source_id, $product ) );
		$this->assertWPError( MLP_Lists::merge( $this->source_id, $this->source_id ) );
	}

	public function test_a_second_merge_while_one_is_running_is_refused_and_adds_nothing() {
		add_option( MLP_Lists::MERGE_LOCK, time(), '', false );

		$result = MLP_Lists::merge( $this->source_id, $this->target_id );

		$this->assertWPError( $result );
		$this->assertSame( 'ann@example.com', $this->emails( $this->target_id ) );
		delete_option( MLP_Lists::MERGE_LOCK );
		$this->assertNotWPError( MLP_Lists::merge( $this->source_id, $this->target_id ) );
	}
}
