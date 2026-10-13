<?php
/**
 * Schema changes are DDL, which the WP test case would turn into temporary
 * tables; these tests switch that off and clean up after themselves.
 *
 * @group install
 */
class InstallTest extends MLP_TestCase {

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	private function table_exists( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $name ) );
	}

	public function test_the_four_plugin_tables_exist() {
		foreach ( array( 'mlp_lists', 'mlp_members', 'mlp_campaigns', 'mlp_queue' ) as $table ) {
			$this->assertTrue( $this->table_exists( $table ), "$table should exist" );
		}
	}

	public function test_no_unsubscribe_log_table_is_created() {
		$this->assertFalse( $this->table_exists( 'mlp_unsubscribes' ) );
	}

	public function test_upgrading_from_schema_one_drops_the_unsubscribe_log() {
		global $wpdb;
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}mlp_unsubscribes (id int NOT NULL, email varchar(180) NOT NULL)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( MLP_DB::VERSION_OPTION, '1' );

		MLP_DB::maybe_upgrade();

		$this->assertFalse( $this->table_exists( 'mlp_unsubscribes' ) );
		$this->assertSame( MLP_DB::VERSION, get_option( MLP_DB::VERSION_OPTION ) );
	}

	public function test_uninstall_keeps_data_unless_the_admin_opted_in() {
		MLP_Settings::update( array( 'delete_data' => 0 ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'mailinglist-plugin/mailinglist-plugin.php' );
		}

		require MLP_DIR . 'uninstall.php';

		$this->assertTrue( $this->table_exists( 'mlp_lists' ) );
		$this->assertSame( MLP_DB::VERSION, get_option( MLP_DB::VERSION_OPTION ) );
	}
}
