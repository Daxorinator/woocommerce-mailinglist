<?php
class RefresherTest extends MLP_TestCase {

	public function test_daily_refresh_is_scheduled() {
		MLP_Refresher::schedule();

		$this->assertNotFalse( wp_next_scheduled( MLP_Refresher::CRON_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( MLP_Refresher::CRON_HOOK ) );
	}

	public function test_refresh_all_picks_up_new_entries_and_drops_removed_ones() {
		global $wpdb;
		$form_id = $this->make_form();
		$ann     = $this->make_entry( $form_id, 'Ann', 'ann@example.com' );
		$this->make_entry( $form_id, 'Ben', 'ben@example.com' );
		$list_id = $this->make_marketing_list( $form_id );
		$this->assertSame( 'ann@example.com,ben@example.com', $this->refresh( $list_id ) );

		$wpdb->delete( $wpdb->prefix . 'wpforms_entries', array( 'entry_id' => $ann ) );
		$this->make_entry( $form_id, 'Cat', 'cat@example.com' );
		MLP_Refresher::refresh_all();

		$this->assertSame( 'ben@example.com,cat@example.com', $this->emails( $list_id ) );
		$list = MLP_Lists::get( $list_id );
		$this->assertSame( 2, $list->member_count );
		$this->assertNotEmpty( $list->last_refreshed );
		$this->assertEmpty( $list->last_error );
	}

	public function test_refreshing_twice_does_not_duplicate_members() {
		$form_id = $this->make_form();
		$this->make_entry( $form_id, 'Ann', 'ann@example.com' );
		$list_id = $this->make_marketing_list( $form_id );

		$this->refresh( $list_id );
		$this->refresh( $list_id );

		$this->assertSame( 1, MLP_Lists::count_members( $list_id ) );
	}

	public function test_large_lists_are_inserted_in_batches() {
		$form_id = $this->make_form();
		for ( $i = 0; $i < 450; $i++ ) {
			$this->make_entry( $form_id, "Person $i", "person$i@example.com" );
		}
		$list_id = $this->make_marketing_list( $form_id );

		$this->refresh( $list_id );

		$this->assertSame( 450, MLP_Lists::count_members( $list_id ) );
	}
}
