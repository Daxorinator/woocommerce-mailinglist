<?php
class MailerTest extends MLP_TestCase {

	private function campaign( $type, $body, $format = 'visual', $subject = 'Hello {name}' ) {
		return (object) array(
			'id'          => 0,
			'type'        => $type,
			'subject'     => $subject,
			'body'        => $body,
			'body_format' => $format,
		);
	}

	private function marketing_list() {
		$form_id = $this->make_form();
		return MLP_Lists::get( $this->make_marketing_list( $form_id ) );
	}

	public function test_visual_content_is_paragraphed_and_merge_tags_are_replaced() {
		$campaign = $this->campaign( 'marketing', "Hi {name},\n\nSee {site_name}." );

		list( $subject, $html ) = MLP_Mailer::render( $campaign, 'ann@example.com', 'Ann <b>', 'http://example.org/u' );

		$this->assertSame( 'Hello Ann <b>', $subject, 'subject is plain text' );
		$this->assertStringContainsString( '<p>Hi Ann &lt;b&gt;,</p>', $html, 'values are escaped in HTML' );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $html );
	}

	public function test_marketing_email_always_gets_an_unsubscribe_link() {
		$campaign = $this->campaign( 'marketing', 'No link in here.' );

		list( , $html, $text ) = MLP_Mailer::render( $campaign, 'ann@example.com', 'Ann', 'http://example.org/u?x=1' );

		$this->assertStringContainsString( '<a href="http://example.org/u?x=1">Unsubscribe</a>', $html );
		$this->assertStringContainsString( 'http://example.org/u?x=1', $text );
	}

	public function test_footer_is_not_duplicated_when_the_content_places_the_link() {
		$campaign = $this->campaign( 'marketing', 'Bye {unsubscribe_link}' );

		list( , $html ) = MLP_Mailer::render( $campaign, 'ann@example.com', 'Ann', 'http://example.org/u' );

		$this->assertSame( 1, substr_count( $html, 'http://example.org/u' ) );
	}

	public function test_a_footer_without_the_link_still_ends_up_with_one() {
		MLP_Settings::update( array( 'footer' => 'Sent by us.' ) );
		$campaign = $this->campaign( 'marketing', 'Hello' );

		list( , $html ) = MLP_Mailer::render( $campaign, 'ann@example.com', 'Ann', 'http://example.org/u' );

		$this->assertStringContainsString( 'Sent by us.', $html );
		$this->assertStringContainsString( 'http://example.org/u', $html );
	}

	public function test_product_email_never_contains_unsubscribe_content() {
		$campaign = $this->campaign( 'product', 'Recall notice {unsubscribe_link} {unsubscribe_url}' );

		list( , $html ) = MLP_Mailer::render( $campaign, 'alice@example.com', 'Alice', 'http://example.org/u' );

		$this->assertStringNotContainsStringIgnoringCase( 'unsubscribe', $html );
		$this->assertStringNotContainsString( 'http://example.org/u', $html );
	}

	public function test_a_complete_html_document_is_sent_without_a_second_wrapper() {
		$doc      = '<html><head><style>p{color:red}</style></head><body><p>Recall for {name}</p></body></html>';
		$campaign = $this->campaign( 'product', $doc, 'html' );

		list( , $html ) = MLP_Mailer::render( $campaign, 'alice@example.com', 'Alice', '' );

		$this->assertSame( 1, substr_count( strtolower( $html ), '<html' ) );
		$this->assertStringContainsString( '<style>p{color:red}</style>', $html );
		$this->assertStringContainsString( 'Recall for Alice', $html );
	}

	public function test_raw_html_fragment_is_not_run_through_wpautop() {
		$campaign = $this->campaign( 'product', '<table><tr><td>Cell</td></tr></table>', 'html' );

		list( , $html ) = MLP_Mailer::render( $campaign, 'alice@example.com', 'Alice', '' );

		$this->assertStringContainsString( '<table><tr><td>Cell</td></tr></table>', $html );
		$this->assertStringNotContainsString( '<p><table', $html );
	}

	public function test_marketing_send_carries_one_click_unsubscribe_headers_and_a_text_part() {
		$list = $this->marketing_list();
		MLP_Settings::update( array( 'reply_to' => 'reply@example.com' ) );

		$result = MLP_Mailer::send( $this->campaign( 'marketing', 'Hi {name}' ), $list, 'ann@example.com', 'Ann' );

		$this->assertTrue( $result );
		$mail = $this->sent_mail()[0];
		$this->assertSame( 'ann@example.com', $mail['to'] );
		$this->assertSame( 'Hello Ann', $mail['subject'] );
		$this->assertMatchesRegularExpression( '~List-Unsubscribe: <[^>]*mlp_unsubscribe=' . $list->id . '&e=[\w-]+&t=[0-9a-f]{64}>~', $mail['headers'] );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $mail['headers'] );
		$this->assertStringContainsString( 'Reply-To: reply@example.com', $mail['headers'] );
		$this->assertStringContainsString( 'multipart/alternative', $mail['headers'] );
		$this->assertStringContainsString( 'text/plain', $mail['body'] );
		$this->assertStringContainsString( 'text/html', $mail['body'] );
	}

	public function test_product_send_has_no_unsubscribe_header() {
		MLP_Mailer::send( $this->campaign( 'product', 'Recall' ), null, 'alice@example.com', 'Alice' );

		$mail = $this->sent_mail()[0];
		$this->assertStringNotContainsStringIgnoringCase( 'unsubscribe', $mail['raw'] );
	}

	public function test_test_sends_are_marked_and_carry_no_live_unsubscribe_link() {
		$result = MLP_Mailer::send( $this->campaign( 'marketing', 'Hi', 'visual', 'Subject' ), null, 'me@example.com', 'Me', true );

		$this->assertTrue( $result );
		$mail = $this->sent_mail()[0];
		$this->assertSame( '[TEST] Subject', $mail['subject'] );
		$this->assertStringNotContainsString( 'List-Unsubscribe', $mail['headers'] );
		$this->assertStringNotContainsString( 'mlp_unsubscribe=', $mail['body'] );
	}

	public function test_marketing_send_without_a_list_fails_instead_of_sending_a_dead_link() {
		$result = MLP_Mailer::send( $this->campaign( 'marketing', 'Hi' ), null, 'ann@example.com', 'Ann' );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->sent_mail() );
	}

	public function test_mailer_failures_surface_the_mailers_error() {
		// An unusable recipient makes PHPMailer throw, which wp_mail() reports as a failure.
		$result = MLP_Mailer::send( $this->campaign( 'product', 'Recall' ), null, 'not-an-email', 'Nobody' );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Invalid address', $result->get_error_message() );
	}
}
