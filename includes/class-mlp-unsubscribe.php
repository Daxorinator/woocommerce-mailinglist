<?php
defined( 'ABSPATH' ) || exit;

/**
 * Unsubscribe links for marketing emails.
 *
 * Unsubscribing deletes the recipient's WPForms entries from the form the list
 * is sourced from, and logs the address so it stays off every list built from
 * that form even if an entry could not be removed. Product (WooCommerce) lists
 * never get a link and cannot be unsubscribed from.
 */
class MLP_Unsubscribe {

	private static function secret() {
		$secret = get_option( 'mlp_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 64, false );
			update_option( 'mlp_secret', $secret, false );
		}
		return $secret;
	}

	public static function token( $list_id, $email ) {
		return hash_hmac( 'sha256', (int) $list_id . '|' . strtolower( $email ), self::secret() );
	}

	/**
	 * Signed unsubscribe URL for one recipient of one list.
	 *
	 * @param int    $list_id List ID.
	 * @param string $email   Recipient.
	 * @return string
	 */
	public static function url( $list_id, $email ) {
		return add_query_arg(
			array(
				'mlp_unsubscribe' => (int) $list_id,
				'e'               => rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				't'               => self::token( $list_id, $email ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Unsubscribed addresses for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return array email => UTC datetime of the unsubscribe.
	 */
	public static function get_suppressed( $form_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT email, created_at FROM ' . MLP_DB::table( 'unsubscribes' ) . ' WHERE form_id = %d', $form_id ) );
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row->email ] = $row->created_at;
		}
		return $map;
	}

	public static function is_suppressed( $form_id, $email ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MLP_DB::table( 'unsubscribes' ) . ' WHERE form_id = %d AND email = %s', $form_id, $email ) );
	}

	public static function remove_suppression( $form_id, $email ) {
		global $wpdb;
		$wpdb->delete(
			MLP_DB::table( 'unsubscribes' ),
			array(
				'form_id' => $form_id,
				'email'   => $email,
			)
		);
	}

	/**
	 * Unsubscribe an address from a marketing list.
	 *
	 * @param object $list  List.
	 * @param string $email Recipient.
	 * @return int|WP_Error Number of WPForms entries removed.
	 */
	public static function unsubscribe( $list, $email ) {
		global $wpdb;

		$email = mlp_normalize_email( $email );
		if ( ! $email ) {
			return new WP_Error( 'mlp_email', __( 'Invalid email address.', 'mailinglist-plugin' ) );
		}
		if ( MLP_Lists::TYPE_MARKETING !== $list->type ) {
			return new WP_Error( 'mlp_not_marketing', __( 'It is not possible to unsubscribe from this type of email.', 'mailinglist-plugin' ) );
		}

		$form_id = MLP_Lists::form_id( $list );
		$members = MLP_DB::table( 'members' );

		// Every marketing list sourced from the same form loses this address.
		$list_ids = array();
		foreach ( MLP_Lists::all( MLP_Lists::TYPE_MARKETING ) as $other ) {
			if ( MLP_Lists::form_id( $other ) === $form_id ) {
				$list_ids[] = (int) $other->id;
			}
		}
		$in = implode( ',', $list_ids ? $list_ids : array( (int) $list->id ) );

		$candidates = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT source_ref FROM {$members} WHERE email = %s AND list_id IN ({$in})", $email ) ) as $ref ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 0 === strpos( $ref, 'entry:' ) ) {
				$candidates[] = (int) substr( $ref, 6 );
			}
		}

		$removed = MLP_Source_WPForms::delete_entries( $form_id, $email, $candidates );

		// Logged after the entries are deleted: a later form submission is newer than this and re-subscribes.
		self::remove_suppression( $form_id, $email );
		$wpdb->insert(
			MLP_DB::table( 'unsubscribes' ),
			array(
				'email'           => $email,
				'form_id'         => $form_id,
				'list_id'         => (int) $list->id,
				'entries_removed' => $removed,
				'created_at'      => mlp_now(),
			)
		);

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$members} WHERE email = %s AND list_id IN ({$in})", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $list_ids as $list_id ) {
			$wpdb->update( MLP_DB::table( 'lists' ), array( 'member_count' => MLP_Lists::count_members( $list_id ) ), array( 'id' => $list_id ) );
		}

		// Drop the address from anything still waiting to be sent to those lists.
		$queue     = MLP_DB::table( 'queue' );
		$campaigns = MLP_DB::table( 'campaigns' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$queue} SET status = 'unsubscribed' WHERE email = %s AND status = 'pending' AND campaign_id IN ( SELECT id FROM {$campaigns} WHERE type = 'marketing' AND list_id IN ({$in}) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email
			)
		);

		do_action( 'mlp_unsubscribed', $email, $list, $removed );

		return $removed;
	}

	/**
	 * Front-end endpoint: ?mlp_unsubscribe={list}&e={email}&t={token}
	 *
	 * GET shows a confirmation button (so link scanners cannot unsubscribe people);
	 * POST performs the unsubscribe, which also serves RFC 8058 one-click requests
	 * sent by mail providers to the List-Unsubscribe header URL.
	 */
	public static function handle_request() {
		if ( empty( $_GET['mlp_unsubscribe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- authenticated by the signed token.
		$list_id = absint( $_GET['mlp_unsubscribe'] );
		$encoded = isset( $_GET['e'] ) ? sanitize_text_field( wp_unslash( $_GET['e'] ) ) : '';
		$token   = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
		$email   = mlp_normalize_email( (string) base64_decode( strtr( $encoded, '-_', '+/' ), true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		// phpcs:enable

		if ( ! $email || ! $token || ! hash_equals( self::token( $list_id, $email ), $token ) ) {
			self::render_page( __( 'Invalid link', 'mailinglist-plugin' ), __( 'This unsubscribe link is invalid or incomplete.', 'mailinglist-plugin' ), 400 );
		}

		$list = MLP_Lists::get( $list_id );
		if ( ! $list ) {
			self::render_page( __( 'Unsubscribed', 'mailinglist-plugin' ), __( 'This mailing list no longer exists. You will not receive any further emails from it.', 'mailinglist-plugin' ) );
		}
		if ( MLP_Lists::TYPE_MARKETING !== $list->type ) {
			self::render_page( __( 'Cannot unsubscribe', 'mailinglist-plugin' ), __( 'It is not possible to unsubscribe from this type of email.', 'mailinglist-plugin' ), 400 );
		}

		if ( $is_post ) {
			self::unsubscribe( $list, $email );
			self::render_page(
				__( 'Unsubscribed', 'mailinglist-plugin' ),
				/* translators: %s: email address */
				sprintf( __( '%s has been unsubscribed and will no longer receive these emails.', 'mailinglist-plugin' ), $email )
			);
		}

		$form = sprintf(
			'<form method="post" action="%s"><button type="submit" name="mlp_confirm" value="1">%s</button></form>',
			esc_url( self::url( $list_id, $email ) ),
			esc_html__( 'Unsubscribe', 'mailinglist-plugin' )
		);
		self::render_page(
			__( 'Unsubscribe', 'mailinglist-plugin' ),
			/* translators: %s: email address */
			sprintf( __( 'Stop sending marketing emails to %s?', 'mailinglist-plugin' ), $email ),
			200,
			$form
		);
	}

	/**
	 * Output a minimal standalone page and stop.
	 *
	 * @param string $title   Heading.
	 * @param string $message Message (plain text).
	 * @param int    $status  HTTP status.
	 * @param string $extra   Trusted extra HTML.
	 */
	private static function render_page( $title, $message, $status = 200, $extra = '' ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $title . ' – ' . get_bloginfo( 'name' ) ); ?></title>
<style>
body{margin:0;padding:48px 16px;background:#f5f5f5;color:#222;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;}
.mlp-box{max-width:480px;margin:0 auto;padding:32px;background:#fff;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.12);text-align:center;}
h1{margin:0 0 12px;font-size:22px;}
p{margin:0 0 20px;word-break:break-word;}
button{padding:10px 24px;border:0;border-radius:4px;background:#b32d2e;color:#fff;font-size:16px;cursor:pointer;}
a{color:#2271b1;}
</style>
</head>
<body>
<div class="mlp-box">
	<h1><?php echo esc_html( $title ); ?></h1>
	<p><?php echo esc_html( $message ); ?></p>
		<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<p style="margin:20px 0 0;font-size:14px;"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a></p>
</div>
</body>
</html>
		<?php
		exit;
	}
}
