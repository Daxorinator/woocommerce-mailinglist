<?php
/**
 * PHPUnit bootstrap.
 *
 * All schema is created here, once, before any test runs. The WordPress test
 * case turns CREATE TABLE into CREATE TEMPORARY TABLE while a test is open, and
 * temporary tables are invisible to the SHOW TABLES check the plugin performs.
 */

$mlp_plugin_dir = dirname( __DIR__ );
$mlp_tests_dir  = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : $mlp_plugin_dir . '/vendor/wp-phpunit/wp-phpunit';

// The test library reads this constant (not an environment variable) to find its config.
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

// Let the polyfills package provide PHPUnit compatibility for the WP test library.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $mlp_plugin_dir . '/vendor/yoast/phpunit-polyfills' );

require_once $mlp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $mlp_plugin_dir ) {
		$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( ! getenv( 'MLP_SKIP_WOOCOMMERCE' ) && file_exists( $woocommerce ) ) {
			require_once $woocommerce;
		}

		require_once __DIR__ . '/stubs/wpforms.php';
		require_once __DIR__ . '/stubs/wcs.php';
		require_once $mlp_plugin_dir . '/mailinglist-plugin.php';
	}
);

require_once $mlp_tests_dir . '/includes/bootstrap.php';

global $wpdb;

// The plugin's own tables (also created by its plugins_loaded upgrade check).
MLP_DB::install();

// Fixture standing in for WPForms Pro's entry storage: only the columns the plugin reads.
$mlp_entries = $wpdb->prefix . 'wpforms_entries';
$wpdb->query( "DROP TABLE IF EXISTS {$mlp_entries}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
	"CREATE TABLE {$mlp_entries} (
		entry_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		form_id bigint(20) unsigned NOT NULL,
		status varchar(30) NOT NULL DEFAULT '',
		fields longtext NOT NULL,
		date datetime NOT NULL,
		PRIMARY KEY  (entry_id),
		KEY form_id (form_id)
	) {$wpdb->get_charset_collate()}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

if ( class_exists( 'WC_Install' ) ) {
	WC_Install::install();
}

// Base class for the plugin's tests; needs WP_UnitTestCase, which exists once WordPress is loaded.
require_once __DIR__ . '/MLP_TestCase.php';
