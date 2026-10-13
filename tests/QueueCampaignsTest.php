<?php
class QueueCampaignsTest extends MLP_TestCase {

	/** @var int */
	private $form_id;

	/** @var int */
	private $list_id;

	public function set_up() {
		parent::set_up();

		$this->form_id = $this->make_form();
		foreach ( array( 'ann', 'ben', 'cat' ) as $person ) {
			$this->make_entry( $this->form_id, ucfirst( $person ), $person . '@example.com' );
		}
		$this->list_id = $this->make_marketing_list( $this->form_id );
		$this->refresh( $this->list_id );
	}

	private function draft( $overrides = array() ) {
		$id = MLP_Campaigns::save(
			0,
			array_merge(
				array(
					'list_id'     => $this->list_id,
					'subject'     => 'Hello {name}',
					'body'        => 'Hi {name}',
					'body_format' => 'visual',
				),
				$overrides
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	private function recipients( $mail_list ) {
		return wp_list_pluck( $mail_list, 'to' );
	}

	public function test_start_queues_every_member_after_refreshing_the_list() {
		$this->make_entry( $this->form_id, 'Dan', 'dan@example.com' ); // Added since the last refresh.
		$id = $this->draft();

		$started = MLP_Campaigns::start( $id );

		$this->assertIsArray( $started );
		$this->assertSame( 4, $started['total'] );
		$this->assertSame( 'sending', MLP_Campaigns::get( $id )->status );
		$this->assertNotFalse( wp_next_scheduled( MLP_Queue::HOOK ) );
	}

	public function test_a_campaign_cannot_be_started_twice() {
		$id = $this->draft();
		MLP_Campaigns::start( $id );

		$this->assertWPError( MLP_Campaigns::start( $id ) );
		$this->assertSame( 3, MLP_Campaigns::get( $id )->total );
	}

	public function test_empty_subject_content_or_list_blocks_sending() {
		$this->assertWPError( MLP_Campaigns::start( $this->draft( array( 'subject' => '  ' ) ) ) );
		$this->assertWPError( MLP_Campaigns::start( $this->draft( array( 'body' => '<p> </p>' ) ) ) );
		$this->assertWPError( MLP_Campaigns::start( $this->draft( array( 'list_id' => 0 ) ) ) );
	}

	public function test_batches_respect_the_configured_size_and_complete_the_campaign() {
		MLP_Settings::update( array( 'batch_size' => 2 ) );
		$id = $this->draft();
		MLP_Campaigns::start( $id );

		$this->run_queue();
		$this->assertCount( 2, $this->sent_mail() );
		$this->assertSame( 'sending', MLP_Campaigns::get( $id )->status );

		$this->run_queue();
		$campaign = MLP_Campaigns::get( $id );
		$this->assertCount( 3, $this->sent_mail() );
		$this->assertSame( 'sent', $campaign->status );
		$this->assertSame( 3, (int) $campaign->sent );
		$this->assertSame( 0, (int) $campaign->failed );
		$this->assertEqualSets( array( 'ann@example.com', 'ben@example.com', 'cat@example.com' ), $this->recipients( $this->sent_mail() ) );
	}

	public function test_a_second_run_cannot_start_while_one_is_in_progress() {
		$id = $this->draft();
		MLP_Campaigns::start( $id );
		update_option( MLP_Queue::LOCK_OPTION, time() );

		$this->run_queue();

		$this->assertCount( 0, $this->sent_mail() );
		delete_option( MLP_Queue::LOCK_OPTION );
	}

	public function test_an_interrupted_send_is_marked_failed_not_sent_again() {
		global $wpdb;
		$id = $this->draft();
		MLP_Campaigns::start( $id );
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'UPDATE ' . MLP_DB::table( 'queue' ) . " SET status = 'sending', claimed_at = %s WHERE email = 'ann@example.com'",
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			)
		);

		$this->run_queue();

		$this->assertNotContains( 'ann@example.com', $this->recipients( $this->sent_mail() ) );
		$failed = MLP_Campaigns::get_failed( $id );
		$this->assertCount( 1, $failed );
		$this->assertSame( 'ann@example.com', $failed[0]->email );
	}

	public function test_failures_are_recorded_and_can_be_retried() {
		$this->make_entry( $this->form_id, 'Bad', 'bad@example.com' );
		$id = $this->draft();
		MLP_Campaigns::start( $id );

		// Fail only for one address by making PHPMailer reject it at send time.
		$fail = function ( $phpmailer ) {
			$to = $phpmailer->getToAddresses();
			if ( 'bad@example.com' === $to[0][0] ) {
				$phpmailer->clearAllRecipients();
			}
		};
		add_action( 'phpmailer_init', $fail, 999 );
		$this->run_queue();
		remove_action( 'phpmailer_init', $fail, 999 );

		$campaign = MLP_Campaigns::get( $id );
		$this->assertSame( 'sent', $campaign->status );
		$this->assertSame( 1, (int) $campaign->failed );

		$this->assertSame( 1, MLP_Campaigns::retry_failed( $id ) );
		$this->run_queue();

		$campaign = MLP_Campaigns::get( $id );
		$this->assertSame( 0, (int) $campaign->failed );
		$this->assertSame( 4, (int) $campaign->sent );
	}

	public function test_cancel_stops_remaining_recipients() {
		MLP_Settings::update( array( 'batch_size' => 1 ) );
		$id = $this->draft();
		MLP_Campaigns::start( $id );
		$this->run_queue();

		$this->assertTrue( MLP_Campaigns::cancel( $id ) );
		$this->run_queue( 2 );

		$campaign = MLP_Campaigns::get( $id );
		$this->assertSame( 'cancelled', $campaign->status );
		$this->assertCount( 1, $this->sent_mail() );
	}

	public function test_a_campaign_completes_when_its_last_waiting_recipient_unsubscribes() {
		MLP_Settings::update( array( 'batch_size' => 2 ) );
		$id = $this->draft();
		MLP_Campaigns::start( $id );
		$this->run_queue();

		$waiting = array_diff( array( 'ann@example.com', 'ben@example.com', 'cat@example.com' ), $this->recipients( $this->sent_mail() ) );
		MLP_Unsubscribe::unsubscribe( MLP_Lists::get( $this->list_id ), reset( $waiting ) );
		$this->run_queue();

		$campaign = MLP_Campaigns::get( $id );
		$this->assertSame( 'sent', $campaign->status );
		$this->assertCount( 2, $this->sent_mail() );
	}

	public function test_deleting_a_list_cancels_campaigns_still_sending() {
		$id = $this->draft();
		MLP_Campaigns::start( $id );

		MLP_Lists::delete( $this->list_id );

		$this->assertSame( 'cancelled', MLP_Campaigns::get( $id )->status );
		$this->assertNull( MLP_Lists::get( $this->list_id ) );
	}

	public function test_sending_batches_use_action_scheduler_when_available() {
		global $wpdb;
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'actionscheduler_actions' ) ) ) {
			$this->markTestSkipped( 'Action Scheduler tables are not available.' );
		}
		remove_filter( 'mlp_use_action_scheduler', '__return_false' );
		add_filter( 'mlp_use_action_scheduler', '__return_true' );

		MLP_Queue::schedule( 0 );

		$this->assertNotFalse( as_next_scheduled_action( MLP_Queue::HOOK, array(), MLP_Queue::GROUP ) );
		MLP_Queue::unschedule();
	}
}
