<?php
class AdminTest extends MLP_TestCase {

	/** @var int */
	private $admin_id;

	public function set_up() {
		parent::set_up();

		require_once MLP_DIR . 'includes/class-mlp-admin.php';
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		add_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
	}

	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	public function throw_on_redirect( $location ) {
		throw new RuntimeException( 'REDIRECT:' . $location );
	}

	private function post_as( $action, $data, $nonce_action = null ) {
		$_POST    = $data;
		$_REQUEST = $data;
		if ( null !== $nonce_action ) {
			$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce( $nonce_action );
		}
		$_POST    = wp_slash( $_POST );
		$_REQUEST = $_POST;

		try {
			do_action( 'admin_post_' . $action );
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	private function ajax( $action, $data ) {
		$_POST    = wp_slash( array_merge( $data, array( '_ajax_nonce' => wp_create_nonce( 'mlp_ajax' ) ) ) );
		$_REQUEST = $_POST;

		ob_start();
		try {
			do_action( 'wp_ajax_' . $action );
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		return json_decode( ob_get_clean(), true );
	}

	/**
	 * @dataProvider admin_post_actions
	 */
	public function test_admin_post_actions_require_the_capability( $action ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );
		$this->post_as( $action, array(), $action );
	}

	/**
	 * @dataProvider admin_post_actions
	 */
	public function test_admin_post_actions_require_a_valid_nonce( $action ) {
		$this->expectException( WPDieException::class );
		$this->post_as( $action, array() );
	}

	public function admin_post_actions() {
		return array(
			'save list'       => array( 'mlp_save_list' ),
			'delete list'     => array( 'mlp_delete_list' ),
			'refresh list'    => array( 'mlp_refresh_list' ),
			'save campaign'   => array( 'mlp_save_campaign' ),
			'campaign action' => array( 'mlp_campaign_action' ),
			'save settings'   => array( 'mlp_save_settings' ),
		);
	}

	public function test_ajax_actions_reject_a_bad_nonce() {
		$_POST    = array( '_ajax_nonce' => 'nope' );
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );
		do_action( 'wp_ajax_mlp_preview' );
	}

	public function test_saving_a_marketing_list_refreshes_it() {
		$form_id = $this->make_form();
		$this->make_entry( $form_id, 'Ann', 'ann@example.com' );

		$redirect = $this->post_as(
			'mlp_save_list',
			array(
				'list_id' => 0,
				'name'    => 'My list',
				'type'    => 'marketing',
				'config'  => array(
					'form_id'     => $form_id,
					'email_field' => '2',
				),
			),
			'mlp_save_list'
		);

		$this->assertStringContainsString( 'page=mlp-lists', $redirect );
		$lists = MLP_Lists::all();
		$this->assertCount( 1, $lists );
		$this->assertSame( 'marketing', $lists[0]->type );
		$this->assertSame( 1, $lists[0]->member_count );
	}

	public function test_saving_a_list_without_a_name_is_rejected() {
		$redirect = $this->post_as(
			'mlp_save_list',
			array(
				'name' => '',
				'type' => 'marketing',
			),
			'mlp_save_list'
		);

		$this->assertStringContainsString( 'page=mlp-list-edit', $redirect );
		$this->assertCount( 0, MLP_Lists::all() );
	}

	public function test_saving_a_draft_keeps_html_and_stores_its_format() {
		$list_id = $this->make_product_list( array( 'wc_mode' => 'all_customers' ) );

		$redirect = $this->post_as(
			'mlp_save_campaign',
			array(
				'campaign_id'   => 0,
				'list_id'       => $list_id,
				'subject'       => 'Recall',
				'body_format'   => 'html',
				'mlp_body_html' => '<p style="color:red">It\'s a "test"</p>',
				'mlp_save'      => '1',
			),
			'mlp_save_campaign'
		);

		$this->assertStringContainsString( 'page=mlp-compose&campaign=', $redirect );
		$campaigns = MLP_Campaigns::all();
		$this->assertCount( 1, $campaigns );
		$this->assertSame( 'draft', $campaigns[0]->status );
		$this->assertSame( 'html', $campaigns[0]->body_format );
		$this->assertSame( 'product', $campaigns[0]->type );
		$this->assertSame( '<p style="color:red">It\'s a "test"</p>', $campaigns[0]->body );
	}

	public function test_preview_returns_rendered_subject_and_html() {
		$list_id = $this->make_marketing_list( $this->make_form() );

		$response = $this->ajax(
			'mlp_preview',
			array(
				'list_id'         => $list_id,
				'subject'         => 'Hi {name}',
				'body_format'     => 'visual',
				'mlp_body_visual' => '<strong>Bold</strong>',
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'Hi ', $response['data']['subject'] );
		$this->assertStringContainsString( '<strong>Bold</strong>', $response['data']['html'] );
		$this->assertStringContainsString( 'Unsubscribe', $response['data']['html'] );
	}

	public function test_preview_of_a_product_email_has_no_unsubscribe_content() {
		$list_id = $this->make_product_list( array( 'wc_mode' => 'all_customers' ) );

		$response = $this->ajax(
			'mlp_preview',
			array(
				'list_id'       => $list_id,
				'subject'       => 'Recall',
				'body_format'   => 'html',
				'mlp_body_html' => '<p>Recall</p>',
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertStringNotContainsStringIgnoringCase( 'unsubscribe', $response['data']['html'] );
	}

	public function test_send_test_requires_a_valid_address_and_subject() {
		$list_id = $this->make_product_list( array( 'wc_mode' => 'all_customers' ) );
		$base    = array(
			'list_id'         => $list_id,
			'body_format'     => 'visual',
			'mlp_body_visual' => 'x',
		);

		$bad_address = $this->ajax(
			'mlp_send_test',
			array_merge(
				$base,
				array(
					'subject' => 'S',
					'to'      => 'nope',
				)
			)
		);
		$no_subject  = $this->ajax(
			'mlp_send_test',
			array_merge(
				$base,
				array(
					'subject' => '',
					'to'      => 'me@example.com',
				)
			)
		);

		$this->assertFalse( $bad_address['success'] );
		$this->assertFalse( $no_subject['success'] );
		$this->assertCount( 0, $this->sent_mail() );
	}

	public function test_send_test_sends_one_marked_email() {
		$list_id = $this->make_product_list( array( 'wc_mode' => 'all_customers' ) );

		$response = $this->ajax(
			'mlp_send_test',
			array(
				'list_id'         => $list_id,
				'subject'         => 'Recall',
				'body_format'     => 'visual',
				'mlp_body_visual' => 'x',
				'to'              => 'me@example.com',
			)
		);

		$this->assertTrue( $response['success'] );
		$mail = $this->sent_mail();
		$this->assertCount( 1, $mail );
		$this->assertSame( '[TEST] Recall', $mail[0]['subject'] );
	}
}
