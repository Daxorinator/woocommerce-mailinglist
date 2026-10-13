<?php
defined( 'ABSPATH' ) || exit;

/**
 * Background delivery. Emails are sent in small batches so bulk sends do not
 * time out a request or exceed the SMTP server's rate limits. Uses Action
 * Scheduler when available (bundled with WooCommerce and WPForms), otherwise WP-Cron.
 */
class MLP_Queue {

	const HOOK         = 'mlp_process_queue';
	const GROUP        = 'mlp';
	const LOCK_OPTION  = 'mlp_queue_lock';
	const LOCK_TIMEOUT = 600;

	/** A row stuck in "sending" this long means the request died mid-send. */
	const STALE_AFTER = 900;

	private static function use_action_scheduler() {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' ) && did_action( 'action_scheduler_init' );
	}

	/**
	 * Schedule the next batch.
	 *
	 * @param int  $delay Seconds from now.
	 * @param bool $force Schedule even if a run already appears scheduled (used from inside a run).
	 */
	public static function schedule( $delay = 0, $force = false ) {
		$when = time() + max( 0, (int) $delay );

		if ( self::use_action_scheduler() ) {
			if ( $force || false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
				as_schedule_single_action( $when, self::HOOK, array(), self::GROUP );
			}
			return;
		}

		if ( $force || ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $when, self::HOOK );
		}
	}

	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function has_pending() {
		global $wpdb;
		$queue     = MLP_DB::table( 'queue' );
		$campaigns = MLP_DB::table( 'campaigns' );
		return (bool) $wpdb->get_var( "SELECT q.id FROM {$queue} q INNER JOIN {$campaigns} c ON c.id = q.campaign_id WHERE q.status = 'pending' AND c.status = 'sending' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Make sure a run is scheduled if anything is waiting to be sent.
	 */
	public static function maybe_schedule() {
		if ( self::has_pending() ) {
			self::schedule( 0 );
		}
	}

	private static function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}
		$locked_at = (int) get_option( self::LOCK_OPTION );
		if ( $locked_at && time() - $locked_at < self::LOCK_TIMEOUT ) {
			return false;
		}
		update_option( self::LOCK_OPTION, time(), false );
		return true;
	}

	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Send one batch.
	 */
	public static function process() {
		global $wpdb;

		if ( ! self::acquire_lock() ) {
			return;
		}

		$queue      = MLP_DB::table( 'queue' );
		$campaigns  = MLP_DB::table( 'campaigns' );
		$started    = microtime( true );
		$batch_size = (int) MLP_Settings::get( 'batch_size' );
		$touched    = array();

		// A send that died part-way may or may not have gone out. Mark it failed
		// rather than risk a duplicate; "Retry failed" re-sends it on request.
		$stale = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT campaign_id FROM {$queue} WHERE status = 'sending' AND claimed_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER )
			)
		);
		if ( $stale ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$queue} SET status = 'failed', error = %s WHERE status = 'sending' AND claimed_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					__( 'Sending was interrupted; delivery of this email is unconfirmed.', 'mailinglist-plugin' ),
					gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER )
				)
			);
			foreach ( $stale as $campaign_id ) {
				$touched[ (int) $campaign_id ] = true;
			}
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.* FROM {$queue} q INNER JOIN {$campaigns} c ON c.id = q.campaign_id WHERE q.status = 'pending' AND c.status = 'sending' ORDER BY q.id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$batch_size
			)
		);

		$campaign_cache = array();
		$list_cache     = array();

		foreach ( $rows as $row ) {
			// Leave headroom under typical 30s execution limits; the rest goes in the next run.
			if ( microtime( true ) - $started > 20 ) {
				break;
			}

			$campaign_id = (int) $row->campaign_id;

			// Atomic claim: only one process can move a row out of "pending".
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$queue} SET status = 'sending', claimed_at = %s WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					mlp_now(),
					$row->id
				)
			);
			if ( ! $claimed ) {
				continue;
			}
			$touched[ $campaign_id ] = true;

			if ( ! isset( $campaign_cache[ $campaign_id ] ) ) {
				$campaign_cache[ $campaign_id ] = MLP_Campaigns::get( $campaign_id );
			}
			$campaign = $campaign_cache[ $campaign_id ];

			if ( ! $campaign || 'sending' !== $campaign->status ) {
				self::finish_row( $row->id, 'cancelled' );
				continue;
			}

			$list_id = (int) $campaign->list_id;
			if ( ! array_key_exists( $list_id, $list_cache ) ) {
				$list_cache[ $list_id ] = MLP_Lists::get( $list_id );
			}
			$list = $list_cache[ $list_id ];

			$result = MLP_Mailer::send( $campaign, $list, $row->email, $row->name );

			if ( is_wp_error( $result ) ) {
				self::finish_row( $row->id, 'failed', $result->get_error_message() );
			} else {
				self::finish_row( $row->id, 'sent' );
			}
		}

		// Every campaign still marked as sending is re-checked, not just those handled above:
		// its last waiting recipients may have been removed elsewhere (e.g. by unsubscribing).
		foreach ( (array) $wpdb->get_col( "SELECT id FROM {$campaigns} WHERE status = 'sending'" ) as $campaign_id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$touched[ (int) $campaign_id ] = true;
		}
		foreach ( array_keys( $touched ) as $campaign_id ) {
			MLP_Campaigns::recount( $campaign_id );
		}

		self::release_lock();

		if ( self::has_pending() ) {
			self::schedule( (int) MLP_Settings::get( 'batch_interval' ), true );
		}
	}

	private static function finish_row( $id, $status, $error = null ) {
		global $wpdb;
		$wpdb->update(
			MLP_DB::table( 'queue' ),
			array(
				'status'  => $status,
				'error'   => $error,
				'sent_at' => 'sent' === $status ? mlp_now() : null,
			),
			array( 'id' => $id )
		);
	}
}
