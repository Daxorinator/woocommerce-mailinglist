<?php
// Constants and optional-plugin functions that exist at runtime but not in the analysed tree.
define( 'MLP_VERSION', '0' );
define( 'MLP_FILE', __FILE__ );
define( 'MLP_DIR', '' );
define( 'MLP_URL', '' );
define( 'WP_UNINSTALL_PLUGIN', 'mailinglist-plugin/uninstall.php' );

// Optional-plugin functions the plugin calls only after checking they exist.
if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
	/**
	 * @param array $args Query args.
	 * @return WC_Order[] WooCommerce Subscriptions returns WC_Subscription objects, which extend WC_Order.
	 */
	function wcs_get_subscriptions( $args ) {
		return array();
	}
}
if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	/**
	 * @return int|bool
	 */
	function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
		return false;
	}
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
		return 0;
	}
	function as_unschedule_all_actions( $hook = '', $args = array(), $group = '' ) {
	}
}
