<?php
defined( 'ABSPATH' ) || exit;

/**
 * Renders and sends a single email.
 *
 * Sending goes through wp_mail(), so WP Mail SMTP (or any other plugin that
 * configures wp_mail / PHPMailer) handles the actual delivery.
 */
class MLP_Mailer {

	/** @var string|null Error captured from wp_mail_failed during the current send. */
	private static $last_error = null;

	/** @var string Plain-text alternative for the current send. */
	private static $alt_body = '';

	public static function merge_tags() {
		return array(
			'{name}'             => __( 'Recipient name (may be empty)', 'mailinglist-plugin' ),
			'{email}'            => __( 'Recipient email address', 'mailinglist-plugin' ),
			'{site_name}'        => __( 'Site title', 'mailinglist-plugin' ),
			'{site_url}'         => __( 'Site address', 'mailinglist-plugin' ),
			'{unsubscribe_link}' => __( 'Unsubscribe link (Marketing Email only)', 'mailinglist-plugin' ),
			'{unsubscribe_url}'  => __( 'Unsubscribe address, for your own link (Marketing Email only)', 'mailinglist-plugin' ),
		);
	}

	/**
	 * Build the subject and HTML for one recipient.
	 *
	 * Marketing emails always carry an unsubscribe link: if the content does not
	 * place one with a merge tag, the footer from the settings is appended.
	 *
	 * @param object      $campaign  Needs subject, body, body_format, type.
	 * @param string      $email     Recipient.
	 * @param string      $name      Recipient name.
	 * @param string|null $unsub_url Unsubscribe URL, required for marketing emails.
	 * @return array [ subject, html, text ]
	 */
	public static function render( $campaign, $email, $name, $unsub_url = null ) {
		$is_marketing = MLP_Lists::TYPE_MARKETING === $campaign->type;
		$unsub_url    = $is_marketing ? (string) $unsub_url : '';
		$site_name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$unsub_link = $unsub_url
			? '<a href="' . esc_url( $unsub_url ) . '">' . esc_html__( 'Unsubscribe', 'mailinglist-plugin' ) . '</a>'
			: '';

		$html_tags = array(
			'{name}'             => esc_html( $name ),
			'{email}'            => esc_html( $email ),
			'{site_name}'        => esc_html( $site_name ),
			'{site_url}'         => esc_url( home_url( '/' ) ),
			'{unsubscribe_link}' => $unsub_link,
			'{unsubscribe_url}'  => esc_url( $unsub_url ),
		);

		$body = (string) $campaign->body;
		if ( 'html' !== $campaign->body_format ) {
			$body = wpautop( $body );
		}

		$has_unsub = false !== strpos( $body, '{unsubscribe_link}' ) || false !== strpos( $body, '{unsubscribe_url}' );
		$footer    = '';
		if ( $is_marketing && ! $has_unsub ) {
			$footer = (string) MLP_Settings::get( 'footer' );
			if ( false === strpos( $footer, '{unsubscribe_link}' ) && false === strpos( $footer, '{unsubscribe_url}' ) ) {
				$footer = trim( $footer . ' {unsubscribe_link}' );
			}
			$footer = '<p style="margin-top:32px;padding-top:16px;border-top:1px solid #dddddd;font-size:12px;line-height:1.5;color:#777777;">' . $footer . '</p>';
		}

		$body   = strtr( $body, $html_tags );
		$footer = strtr( $footer, $html_tags );

		$subject = strtr(
			(string) $campaign->subject,
			array(
				'{name}'             => $name,
				'{email}'            => $email,
				'{site_name}'        => $site_name,
				'{site_url}'         => home_url( '/' ),
				'{unsubscribe_link}' => '',
				'{unsubscribe_url}'  => '',
			)
		);

		if ( false !== stripos( $body, '<html' ) ) {
			// A complete document was supplied: leave it alone apart from placing the footer.
			$html = false !== stripos( $body, '</body>' )
				? preg_replace( '~</body>~i', $footer . '</body>', $body, 1 )
				: $body . $footer;
		} else {
			$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $subject ) . '</title></head>'
				. '<body style="margin:0;padding:0;background-color:#f5f5f5;">'
				. '<div style="max-width:640px;margin:0 auto;padding:24px;background-color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#222222;">'
				. self::header_html( $site_name )
				. $body . $footer
				. self::site_footer_html( $site_name )
				. '</div></body></html>';
		}

		$html = apply_filters( 'mlp_email_html', $html, $campaign, $email );

		return array( $subject, $html, self::html_to_text( $html ) );
	}

	/**
	 * Centered logo header for the default wrapper.
	 *
	 * Uses the theme's custom logo, falling back to the site title as text.
	 *
	 * @param string $site_name Site title.
	 * @return string
	 */
	private static function header_html( $site_name ) {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$src     = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		$src     = apply_filters( 'mlp_email_logo_url', $src ? $src : '' );

		if ( $src ) {
			$inner = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $site_name ) . '" style="display:inline-block;max-width:200px;max-height:80px;width:auto;height:auto;border:0;">';
		} else {
			$inner = '<span style="font-size:22px;font-weight:bold;color:#222222;">' . esc_html( $site_name ) . '</span>';
		}

		return '<div style="text-align:center;padding-bottom:20px;margin-bottom:24px;border-bottom:1px solid #dddddd;">'
			. '<a href="' . esc_url( home_url( '/' ) ) . '" style="text-decoration:none;">' . $inner . '</a></div>';
	}

	/**
	 * Standard footer: copyright, company name, website and postal address.
	 *
	 * Company name and address come from the plugin settings, falling back to
	 * the site title and the WooCommerce store address.
	 *
	 * @param string $site_name Site title.
	 * @return string
	 */
	private static function site_footer_html( $site_name ) {
		$company = trim( (string) MLP_Settings::get( 'company_name' ) );
		$address = trim( (string) MLP_Settings::get( 'address' ) );

		if ( '' === $company ) {
			$company = $site_name;
		}
		if ( '' === $address ) {
			$address = self::store_address();
		}

		$lines   = array();
		$lines[] = esc_html(
			sprintf(
				/* translators: 1: year, 2: company name */
				__( '© %1$s %2$s', 'mailinglist-plugin' ),
				wp_date( 'Y' ),
				$company
			)
		);
		$lines[] = '<a href="' . esc_url( home_url( '/' ) ) . '" style="color:#777777;">' . esc_html( preg_replace( '~^https?://~i', '', untrailingslashit( home_url() ) ) ) . '</a>';
		if ( '' !== $address ) {
			$lines[] = nl2br( esc_html( $address ), false );
		}

		return '<div style="margin-top:32px;padding-top:16px;border-top:1px solid #dddddd;text-align:center;font-size:12px;line-height:1.6;color:#777777;">'
			. implode( '<br>', $lines )
			. '</div>';
	}

	/**
	 * The WooCommerce store address as a single line, or '' if not set.
	 *
	 * @return string
	 */
	private static function store_address() {
		$parts = array(
			get_option( 'woocommerce_store_address' ),
			get_option( 'woocommerce_store_address_2' ),
			get_option( 'woocommerce_store_city' ),
		);

		// Stored as "CC" or "CC:STATE", e.g. "IE:GA" for Ireland - Galway.
		$location = explode( ':', (string) get_option( 'woocommerce_default_country' ) );
		$country  = $location[0];
		$state    = isset( $location[1] ) ? $location[1] : '';

		if ( '' !== $country && function_exists( 'WC' ) && WC()->countries ) {
			$states    = WC()->countries->get_states( $country );
			$countries = WC()->countries->get_countries();
			if ( '' !== $state && is_array( $states ) && isset( $states[ $state ] ) ) {
				$state = $states[ $state ];
			}
			if ( isset( $countries[ $country ] ) ) {
				$country = $countries[ $country ];
			}
		}

		$parts[] = $state;
		$parts[] = $country;
		$parts[] = get_option( 'woocommerce_store_postcode' );

		$parts = array_filter( array_map( 'trim', array_map( 'strval', $parts ) ) );
		return implode( ', ', $parts );
	}

	/**
	 * Plain-text alternative, which improves deliverability of HTML email.
	 *
	 * @param string $html HTML document.
	 * @return string
	 */
	private static function html_to_text( $html ) {
		$text = preg_replace( '~<(head|style|script)\b[^>]*>.*?</\1>~is', '', $html );
		$text = preg_replace( '~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', '$2 ($1)', $text );
		$text = preg_replace( '~<br\s*/?>~i', "\n", $text );
		$text = preg_replace( '~</(p|div|h[1-6]|li|tr|table)>~i', "\n\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "~[ \t]+~", ' ', $text );
		$text = preg_replace( "~ ?\n ?~", "\n", $text );
		return trim( preg_replace( "~\n{3,}~", "\n\n", $text ) );
	}

	/**
	 * Send one campaign email.
	 *
	 * @param object      $campaign Campaign (or an object with the same fields).
	 * @param object|null $list     The campaign's list; required for marketing emails.
	 * @param string      $email    Recipient.
	 * @param string      $name     Recipient name.
	 * @param bool        $is_test  Test sends get a subject prefix and an inert unsubscribe link.
	 * @return true|WP_Error
	 */
	public static function send( $campaign, $list, $email, $name = '', $is_test = false ) {
		$is_marketing = MLP_Lists::TYPE_MARKETING === $campaign->type;
		$unsub_url    = '';

		if ( $is_marketing ) {
			if ( $is_test ) {
				$unsub_url = home_url( '/#unsubscribe-link-inactive-in-test-emails' );
			} elseif ( ! $list ) {
				return new WP_Error( 'mlp_no_list', __( 'The mailing list no longer exists, so no unsubscribe link can be generated.', 'mailinglist-plugin' ) );
			} else {
				$unsub_url = MLP_Unsubscribe::url( $list->id, $email );
			}
		}

		list( $subject, $html, $text ) = self::render( $campaign, $email, $name, $unsub_url );

		if ( $is_test ) {
			$subject = '[TEST] ' . $subject;
		}

		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		$settings = MLP_Settings::get();

		// WP Mail SMTP may override From if "Force From Email" is enabled there.
		if ( $settings['from_email'] ) {
			$from_name = $settings['from_name'] ? $settings['from_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$headers[] = sprintf( 'From: %s <%s>', str_replace( array( '"', '<', '>', "\r", "\n" ), '', $from_name ), $settings['from_email'] );
		}
		if ( $settings['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $settings['reply_to'];
		}
		if ( $is_marketing && ! $is_test ) {
			// Mail clients read this for their own "Unsubscribe" button. It needs an SMTP mailer:
			// PHPMailer's mail() transport encodes headers over 63 characters, which clients cannot parse.
			$headers[] = 'List-Unsubscribe: <' . $unsub_url . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		$headers = apply_filters( 'mlp_email_headers', $headers, $campaign, $email );

		self::$last_error = null;
		self::$alt_body   = $text;
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
		add_action( 'phpmailer_init', array( __CLASS__, 'set_alt_body' ), 999 );

		$sent = wp_mail( $email, $subject, $html, $headers );

		remove_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
		remove_action( 'phpmailer_init', array( __CLASS__, 'set_alt_body' ), 999 );
		self::$alt_body = '';

		if ( ! $sent ) {
			return new WP_Error( 'mlp_send_failed', self::captured_error() );
		}
		return true;
	}

	/**
	 * The error wp_mail_failed reported during the last send, or a generic one.
	 *
	 * @return string
	 */
	private static function captured_error() {
		return self::$last_error ? self::$last_error : __( 'wp_mail() reported a failure.', 'mailinglist-plugin' );
	}

	public static function capture_error( $error ) {
		if ( is_wp_error( $error ) ) {
			self::$last_error = $error->get_error_message();
		}
	}

	public static function set_alt_body( $phpmailer ) {
		if ( '' !== self::$alt_body && is_object( $phpmailer ) ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
	}
}
