<?php
/**
 * @group wpforms
 */
class SourceWPFormsTest extends MLP_TestCase {

	public function test_form_fields_expose_name_and_email_but_not_other_types() {
		$form_id = $this->make_form();

		$fields = MLP_Source_WPForms::get_form_fields( $form_id );

		$this->assertSame( array( '1', '2' ), wp_list_pluck( $fields, 'id' ) );
		$this->assertSame( array( 'name', 'email' ), wp_list_pluck( $fields, 'type' ) );
	}

	public function test_list_collects_each_address_once_and_skips_unusable_entries() {
		$form_id = $this->make_form();
		$this->make_entry( $form_id, 'Ann A', 'ann@example.com' );
		$this->make_entry( $form_id, 'Ann Again', 'ANN@example.com' );
		$this->make_entry( $form_id, 'Ben B', 'ben@example.com' );
		$this->make_entry( $form_id, 'Spam', 'spam@example.com', 'spam' );
		$this->make_entry( $form_id, 'Trash', 'trash@example.com', 'trash' );
		$this->make_entry( $form_id, 'Partial', 'partial@example.com', 'partial' );
		$this->make_entry( $form_id, 'Bad', 'not-an-email' );
		$this->make_entry( $form_id + 999, 'Other form', 'other@example.com' );

		$list_id = $this->make_marketing_list( $form_id );

		$this->assertSame( 'ann@example.com,ben@example.com', $this->refresh( $list_id ) );
		$this->assertSame( 2, MLP_Lists::get( $list_id )->member_count );
	}

	public function test_name_field_is_captured() {
		global $wpdb;
		$form_id = $this->make_form();
		$this->make_entry( $form_id, 'Ben B', 'ben@example.com' );
		$list_id = $this->make_marketing_list( $form_id );

		$this->refresh( $list_id );

		$name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . MLP_DB::table( 'members' ) . ' WHERE list_id = %d', $list_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 'Ben B', $name );
	}

	public function test_a_list_requires_a_form() {
		$this->assertWPError( MLP_Lists::save( 0, 'No form', 'marketing', array() ) );
	}

	public function test_missing_form_is_reported_and_members_are_kept() {
		global $wpdb;
		$form_id = $this->make_form();
		$this->make_entry( $form_id, 'Ann', 'ann@example.com' );
		$list_id = $this->make_marketing_list( $form_id );
		$this->refresh( $list_id );

		$wpdb->update( MLP_DB::table( 'lists' ), array( 'config' => wp_json_encode( array( 'form_id' => 999999 ) ) ), array( 'id' => $list_id ) );
		$result = MLP_Refresher::refresh_list( MLP_Lists::get( $list_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ann@example.com', $this->emails( $list_id ) );
		$this->assertNotEmpty( MLP_Lists::get( $list_id )->last_error );
	}
}
