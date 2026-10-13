<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'mlp_daily_refresh' );
wp_clear_scheduled_hook( 'mlp_process_queue' );

$mlp_settings = (array) get_option( 'mlp_settings', array() );

// Data is kept unless the admin opted in to deleting it under Mailing Lists → Settings.
if ( ! empty( $mlp_settings['delete_data'] ) ) {
	require_once __DIR__ . '/includes/class-mlp-db.php';
	MLP_DB::uninstall();
}
