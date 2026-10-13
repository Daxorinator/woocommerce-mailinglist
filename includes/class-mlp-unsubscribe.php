<?php
defined( 'ABSPATH' ) || exit;

/**
 * Unsubscribe links for marketing emails.
 *
 * Unsubscribing opts the address out of marketing email as a whole: its WPForms
 * entries are deleted from every form a marketing list uses, and a keyed hash
 * of the address is recorded so WooCommerce customers are not re-added by the
 * next refresh (see MLP_Suppressions). Nothing else about the person is kept.
 * Product lists never get a link, cannot be unsubscribed from, and ignore the
 * suppression record.
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
	 * Opt an address out of marketing email.
	 *
	 * @param object|null $list  The list the link came from, or null when it has since been deleted.
	 * @param string      $email Recipient.
	 * @return int|WP_Error Number of WPForms entries removed.
	 */
	public static function unsubscribe( $list, $email ) {
		global $wpdb;

		$email = mlp_normalize_email( $email );
		if ( ! $email ) {
			return new WP_Error( 'mlp_email', __( 'Invalid email address.', 'mailinglist-plugin' ) );
		}
		if ( $list && MLP_Lists::TYPE_MARKETING !== $list->type ) {
			return new WP_Error( 'mlp_not_marketing', __( 'It is not possible to unsubscribe from this type of email.', 'mailinglist-plugin' ) );
		}

		MLP_Suppressions::add( $email );

		$members = MLP_DB::table( 'members' );

		// The address leaves every marketing list, and its entries every form those lists use.
		$list_ids = array();
		$by_form  = array();
		foreach ( MLP_Lists::all( MLP_Lists::TYPE_MARKETING ) as $other ) {
			$list_ids[] = (int) $other->id;
			$form_id    = MLP_Lists::form_id( $other );
			if ( $form_id ) {
				$by_form[ $form_id ][] = (int) $other->id;
			}
		}
		$in = implode( ',', $list_ids ? $list_ids : array( 0 ) );

		$removed = 0;
		foreach ( $by_form as $form_id => $form_list_ids ) {
			$form_in    = implode( ',', $form_list_ids );
			$candidates = array();
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT source_ref FROM {$members} WHERE email = %s AND list_id IN ({$form_in})", $email ) ) as $ref ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( 0 === strpos( $ref, 'entry:' ) ) {
					$candidates[] = (int) substr( $ref, 6 );
				}
			}
			$removed += MLP_Source_WPForms::delete_entries( (int) $form_id, $email, $candidates );
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$members} WHERE email = %s AND list_id IN ({$in})", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $list_ids as $list_id ) {
			$wpdb->update( MLP_DB::table( 'lists' ), array( 'member_count' => MLP_Lists::count_members( $list_id ) ), array( 'id' => $list_id ) );
		}

		// Drop the address from every marketing email still waiting to be sent. The rows are
		// deleted rather than marked, so the delivery log does not record who unsubscribed.
		$queue     = MLP_DB::table( 'queue' );
		$campaigns = MLP_DB::table( 'campaigns' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$queue} WHERE email = %s AND status = 'pending' AND campaign_id IN ( SELECT id FROM {$campaigns} WHERE type = 'marketing' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			return;
		}

		// A deleted list's link still works: the address is opted out of marketing email regardless.
		$list = MLP_Lists::get( $list_id );
		if ( $list && MLP_Lists::TYPE_MARKETING !== $list->type ) {
			self::render_page( __( 'Cannot unsubscribe', 'mailinglist-plugin' ), __( 'It is not possible to unsubscribe from this type of email.', 'mailinglist-plugin' ), 400 );
			return;
		}

		if ( $is_post ) {
			self::unsubscribe( $list, $email );
			self::render_page(
				__( 'Unsubscribed', 'mailinglist-plugin' ),
				/* translators: %s: email address */
				sprintf( __( '%s has been unsubscribed and will no longer receive these emails.', 'mailinglist-plugin' ), $email )
			);
			return;
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
		if ( ! headers_sent() ) {
			status_header( $status );
			nocache_headers();
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
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

		/**
		 * Whether to stop the request after the page is output. Tests return false.
		 *
		 * @param bool $exit Default true.
		 */
		if ( apply_filters( 'mlp_unsubscribe_exit', true ) ) {
			exit;
		}
	}
}
