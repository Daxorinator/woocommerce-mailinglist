<?php
/**
 * Shared fixtures for the plugin's tests.
 */
abstract class MLP_TestCase extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		$GLOBALS['mlp_test_subscriptions'] = array();

		// Queue tests drive the WP-Cron path; the Action Scheduler path has its own test.
		add_filter( 'mlp_use_action_scheduler', '__return_false' );

		// WP Mail SMTP sends over SMTP, which is also what keeps long List-Unsubscribe headers intact.
		add_action( 'phpmailer_init', array( $this, 'use_smtp_transport' ) );
		add_filter( 'mlp_unsubscribe_exit', '__return_false' );

		reset_phpmailer_instance();
		MLP_Settings::update( array() );
		wpforms()->entry->deleted = array();
	}

	public function use_smtp_transport( $phpmailer ) {
		$phpmailer->Mailer = 'smtp';
	}

	/* ---- WPForms fixtures ---- */

	/**
	 * @return int Form ID.
	 */
	protected function make_form( $title = 'Newsletter' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'wpforms',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => wp_slash(
					wp_json_encode(
						array(
							'fields' => array(
								1 => array(
									'id'    => 1,
									'type'  => 'name',
									'label' => 'Name',
								),
								2 => array(
									'id'    => 2,
									'type'  => 'email',
									'label' => 'Email',
								),
								3 => array(
									'id'    => 3,
									'type'  => 'textarea',
									'label' => 'Message',
								),
							),
						)
					)
				),
			)
		);
	}

	/**
	 * @return int Entry ID.
	 */
	protected function make_entry( $form_id, $name, $email, $status = '', $date = null ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wpforms_entries',
			array(
				'form_id' => $form_id,
				'status'  => $status,
				'date'    => $date ? $date : gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'fields'  => wp_json_encode(
					array(
						1 => array(
							'name'  => 'Name',
							'value' => $name,
							'id'    => 1,
							'type'  => 'name',
						),
						2 => array(
							'name'  => 'Email',
							'value' => $email,
							'id'    => 2,
							'type'  => 'email',
						),
					)
				),
			)
		);

		return (int) $wpdb->insert_id;
	}

	protected function entry_count( $email ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpforms_entries WHERE fields LIKE %s", '%' . $wpdb->esc_like( $email ) . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/* ---- Lists ---- */

	protected function make_marketing_list( $form_id, $name = 'Newsletter list', $config = array() ) {
		$id = MLP_Lists::save(
			0,
			$name,
			'marketing',
			array_merge(
				array(
					'form_id'    => $form_id,
					'name_field' => '1',
				),
				$config
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	protected function make_product_list( $config, $name = 'Product list' ) {
		$id = MLP_Lists::save( 0, $name, 'product', $config );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Refresh a list and return its members as a sorted, comma separated string.
	 */
	protected function refresh( $list_id ) {
		$result = MLP_Refresher::refresh_list( MLP_Lists::get( $list_id ) );
		$this->assertNotWPError( $result );
		return $this->emails( $list_id );
	}

	protected function emails( $list_id ) {
		global $wpdb;
		$emails = $wpdb->get_col( $wpdb->prepare( 'SELECT email FROM ' . MLP_DB::table( 'members' ) . ' WHERE list_id = %d ORDER BY email', $list_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return implode( ',', $emails );
	}

	/* ---- WooCommerce fixtures ---- */

	protected function require_woocommerce() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed in this environment.' );
		}
	}

	protected function make_simple_product( $name = 'Product A' ) {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( 10 );
		$product->save();
		return $product;
	}

	/**
	 * @return array [ WC_Product_Variable, WC_Product_Variation[] ]
	 */
	protected function make_variable_product( $name = 'Product Z', $variants = array( 'Y', 'W' ) ) {
		$parent = new WC_Product_Variable();
		$parent->set_name( $name );
		$parent->save();

		$variations = array();
		foreach ( $variants as $variant ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_regular_price( 5 );
			$variation->set_attributes( array( 'size' => $variant ) );
			$variation->save();
			$variations[] = $variation;
		}

		return array( $parent, $variations );
	}

	protected function make_order( $email, $first_name, $product, $status ) {
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_billing_email( $email );
		$order->set_billing_first_name( $first_name );
		$order->set_billing_last_name( 'Tester' );
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	/* ---- Mail ---- */

	/**
	 * Messages captured by the WP test suite's mock PHPMailer, decoded for assertions.
	 *
	 * @return array[] Each: to, subject, headers (unfolded), body (quoted-printable decoded), raw.
	 */
	protected function sent_mail() {
		$messages = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			$headers = preg_replace( "~\r?\n[ \t]+~", ' ', $mail['header'] );
			$subject = '';
			if ( preg_match( '~^Subject: (.*)$~mi', $headers, $match ) ) {
				$subject = trim( $match[1] );
			}
			$messages[] = array(
				'to'      => $mail['to'][0][0],
				'subject' => $subject,
				'headers' => $headers,
				'body'    => quoted_printable_decode( $mail['body'] ),
				'raw'     => $mail['header'] . "\n" . $mail['body'],
			);
		}
		return $messages;
	}

	/**
	 * Run one queue batch (several, if $times is given).
	 */
	protected function run_queue( $times = 1 ) {
		for ( $i = 0; $i < $times; $i++ ) {
			MLP_Queue::process();
		}
	}
}
