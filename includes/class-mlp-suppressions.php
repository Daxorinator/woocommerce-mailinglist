<?php
defined( 'ABSPATH' ) || exit;

/**
 * Marketing opt-outs for addresses sourced from WooCommerce.
 *
 * Unsubscribing deletes a WPForms entry, so the source forgets the person. A
 * WooCommerce order cannot be deleted, so without a record the next refresh
 * would add the customer straight back. Only a keyed hash of the address is
 * stored. It is used to skip WooCommerce-sourced addresses on marketing lists
 * and is never consulted for product lists.
 */
class MLP_Suppressions {

	/**
	 * @param string $email Address.
	 * @return string
	 */
	public static function hash( $email ) {
		return hash_hmac( 'sha256', 'suppress|' . mlp_normalize_email( $email ), (string) get_option( 'mlp_secret' ) );
	}

	/**
	 * @param string $email Address.
	 */
	public static function add( $email ) {
		global $wpdb;

		$email = mlp_normalize_email( $email );
		if ( ! $email ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . MLP_DB::table( 'suppressions' ) . ' (email_hash, created_at) VALUES (%s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::hash( $email ),
				mlp_now()
			)
		);
	}

	/**
	 * Every suppressed hash, as a lookup table. Load once per refresh.
	 *
	 * @return array<string, true>
	 */
	public static function all() {
		global $wpdb;
		$hashes = (array) $wpdb->get_col( 'SELECT email_hash FROM ' . MLP_DB::table( 'suppressions' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_fill_keys( $hashes, true );
	}
}
