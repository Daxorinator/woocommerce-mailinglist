<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
class MLP_Settings {

	const OPTION = 'mlp_settings';

	public static function defaults() {
		return array(
			'batch_size'     => 20,
			'batch_interval' => 60,
			'from_name'      => '',
			'from_email'     => '',
			'reply_to'       => '',
			'company_name'   => '',
			'address'        => '',
			'footer'         => __( 'You are receiving this email because you signed up at {site_name}. {unsubscribe_link}', 'mailinglist-plugin' ),
			'delete_data'    => 0,
		);
	}

	/**
	 * @param string|null $key Setting key, or null for all settings.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $settings;
		}
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Sanitise and store settings.
	 *
	 * @param array $input Unslashed raw input.
	 */
	public static function update( $input ) {
		$settings = array(
			'batch_size'     => min( 500, max( 1, absint( isset( $input['batch_size'] ) ? $input['batch_size'] : 20 ) ) ),
			'batch_interval' => min( 3600, max( 10, absint( isset( $input['batch_interval'] ) ? $input['batch_interval'] : 60 ) ) ),
			'from_name'      => isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '',
			'from_email'     => isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '',
			'reply_to'       => isset( $input['reply_to'] ) ? sanitize_email( $input['reply_to'] ) : '',
			'company_name'   => isset( $input['company_name'] ) ? sanitize_text_field( $input['company_name'] ) : '',
			'address'        => isset( $input['address'] ) ? sanitize_textarea_field( $input['address'] ) : '',
			'footer'         => isset( $input['footer'] ) ? wp_kses_post( $input['footer'] ) : '',
			'delete_data'    => empty( $input['delete_data'] ) ? 0 : 1,
		);
		update_option( self::OPTION, $settings, false );
	}
}
