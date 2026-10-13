<?php
/**
 * Plugin Name:       Bulk Mailing Lists
 * Description:       Assemble mailing lists from WPForms entries (marketing) or WooCommerce data (product / legal notices) and send bulk emails through wp_mail(), so delivery uses WP Mail SMTP.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * License:           MIT
 * Text Domain:       mailinglist-plugin
 */

defined( 'ABSPATH' ) || exit;

define( 'MLP_VERSION', '1.0.0' );
define( 'MLP_FILE', __FILE__ );
define( 'MLP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MLP_URL', plugin_dir_url( __FILE__ ) );

require_once MLP_DIR . 'includes/class-mlp-db.php';
require_once MLP_DIR . 'includes/class-mlp-settings.php';
require_once MLP_DIR . 'includes/class-mlp-lists.php';
require_once MLP_DIR . 'includes/class-mlp-source-wpforms.php';
require_once MLP_DIR . 'includes/class-mlp-source-woocommerce.php';
require_once MLP_DIR . 'includes/class-mlp-suppressions.php';
require_once MLP_DIR . 'includes/class-mlp-member-buffer.php';
require_once MLP_DIR . 'includes/class-mlp-refresher.php';
require_once MLP_DIR . 'includes/class-mlp-unsubscribe.php';
require_once MLP_DIR . 'includes/class-mlp-mailer.php';
require_once MLP_DIR . 'includes/class-mlp-campaigns.php';
require_once MLP_DIR . 'includes/class-mlp-queue.php';

/**
 * Capability required to manage lists and send email.
 *
 * @return string
 */
function mlp_capability() {
	return apply_filters( 'mlp_capability', 'manage_options' );
}

/**
 * Current time as a UTC MySQL datetime. All plugin timestamps are stored in UTC.
 *
 * @return string
 */
function mlp_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

/**
 * Format a stored UTC datetime in the site's timezone.
 *
 * @param string|null $utc UTC MySQL datetime.
 * @return string
 */
function mlp_format_date( $utc ) {
	if ( empty( $utc ) || '0000-00-00 00:00:00' === $utc ) {
		return '—';
	}
	return get_date_from_gmt( $utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
}

/**
 * Normalise an email address for storage and comparison.
 *
 * @param mixed $email Raw value.
 * @return string Lower-cased valid email, or '' if invalid.
 */
function mlp_normalize_email( $email ) {
	if ( ! is_string( $email ) ) {
		return '';
	}
	$email = strtolower( trim( $email ) );
	if ( '' === $email || strlen( $email ) > 180 || ! is_email( $email ) ) {
		return '';
	}
	return $email;
}

register_activation_hook( __FILE__, 'mlp_activate' );
register_deactivation_hook( __FILE__, 'mlp_deactivate' );

function mlp_activate() {
	MLP_DB::install();
	MLP_Refresher::schedule();
}

function mlp_deactivate() {
	wp_clear_scheduled_hook( MLP_Refresher::CRON_HOOK );
	MLP_Queue::unschedule();
}

add_action( 'plugins_loaded', 'mlp_init' );

function mlp_init() {
	MLP_DB::maybe_upgrade();

	add_action( 'init', array( 'MLP_Refresher', 'schedule' ) );
	add_action( MLP_Refresher::CRON_HOOK, array( 'MLP_Refresher', 'refresh_all' ) );
	add_action( MLP_Queue::HOOK, array( 'MLP_Queue', 'process' ) );
	add_action( 'init', array( 'MLP_Unsubscribe', 'handle_request' ) );

	if ( is_admin() ) {
		require_once MLP_DIR . 'includes/class-mlp-admin.php';
		MLP_Admin::init();
	}
}
