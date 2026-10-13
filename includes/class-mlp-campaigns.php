<?php
defined( 'ABSPATH' ) || exit;

/**
 * Campaigns: one email (subject + content) sent to one mailing list.
 *
 * Statuses: draft → sending → sent, or cancelled.
 */
class MLP_Campaigns {

	public static function statuses() {
		return array(
			'draft'     => __( 'Draft', 'mailinglist-plugin' ),
			'sending'   => __( 'Sending', 'mailinglist-plugin' ),
			'sent'      => __( 'Sent', 'mailinglist-plugin' ),
			'cancelled' => __( 'Cancelled', 'mailinglist-plugin' ),
		);
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MLP_DB::table( 'campaigns' ) . ' WHERE id = %d', $id ) );
	}

	public static function all( $limit = 200 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MLP_DB::table( 'campaigns' ) . ' ORDER BY id DESC LIMIT %d', $limit ) );
	}

	/**
	 * Create or update a draft.
	 *
	 * @param int   $id   Existing draft ID, or 0.
	 * @param array $data list_id, subject, body, body_format (already sanitised).
	 * @return int|WP_Error Campaign ID.
	 */
	public static function save( $id, $data ) {
		global $wpdb;

		$list = MLP_Lists::get( (int) $data['list_id'] );
		$row  = array(
			'list_id'     => $list ? $list->id : 0,
			'list_name'   => $list ? $list->name : '',
			'type'        => $list ? $list->type : MLP_Lists::TYPE_MARKETING,
			'subject'     => $data['subject'],
			'body'        => $data['body'],
			'body_format' => 'html' === $data['body_format'] ? 'html' : 'visual',
		);

		if ( $id ) {
			$existing = self::get( $id );
			if ( ! $existing || 'draft' !== $existing->status ) {
				return new WP_Error( 'mlp_not_draft', __( 'Only draft emails can be edited.', 'mailinglist-plugin' ) );
			}
			$wpdb->update( MLP_DB::table( 'campaigns' ), $row, array( 'id' => $id ) );
			return (int) $id;
		}

		$row['status']     = 'draft';
		$row['created_by'] = get_current_user_id();
		$row['created_at'] = mlp_now();
		$wpdb->insert( MLP_DB::table( 'campaigns' ), $row );
		return (int) $wpdb->insert_id;
	}

	public static function duplicate( $id ) {
		$campaign = self::get( $id );
		if ( ! $campaign ) {
			return new WP_Error( 'mlp_missing', __( 'Email not found.', 'mailinglist-plugin' ) );
		}
		return self::save(
			0,
			array(
				'list_id'     => $campaign->list_id,
				'subject'     => $campaign->subject,
				'body'        => $campaign->body,
				'body_format' => $campaign->body_format,
			)
		);
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( MLP_DB::table( 'queue' ), array( 'campaign_id' => $id ) );
		$wpdb->delete( MLP_DB::table( 'campaigns' ), array( 'id' => $id ) );
	}

	/**
	 * Queue a draft for delivery to every current member of its list.
	 *
	 * The list is refreshed from its source first so the recipients are current.
	 *
	 * @param int $id Campaign ID.
	 * @return array|WP_Error [ total, warning ].
	 */
	public static function start( $id ) {
		global $wpdb;

		$campaign = self::get( $id );
		if ( ! $campaign || 'draft' !== $campaign->status ) {
			return new WP_Error( 'mlp_not_draft', __( 'This email has already been sent or queued.', 'mailinglist-plugin' ) );
		}
		$has_content = '' !== trim( wp_strip_all_tags( $campaign->body, true ) ) || false !== stripos( $campaign->body, '<img' );
		if ( '' === trim( $campaign->subject ) || ! $has_content ) {
			return new WP_Error( 'mlp_empty', __( 'The email needs a subject and some content before it can be sent.', 'mailinglist-plugin' ) );
		}
		$list = MLP_Lists::get( $campaign->list_id );
		if ( ! $list ) {
			return new WP_Error( 'mlp_no_list', __( 'Please choose a mailing list to send to.', 'mailinglist-plugin' ) );
		}

		$warning = '';
		$refresh = MLP_Refresher::refresh_list( $list );
		if ( is_wp_error( $refresh ) ) {
			/* translators: %s: error message */
			$warning = sprintf( __( 'The list could not be refreshed before sending (%s), so the recipients from its last successful refresh were used.', 'mailinglist-plugin' ), $refresh->get_error_message() );
		}

		// Claim the draft first so a double submit cannot queue it twice.
		$claimed = $wpdb->update(
			MLP_DB::table( 'campaigns' ),
			array(
				'status'     => 'sending',
				'type'       => $list->type,
				'list_name'  => $list->name,
				'started_at' => mlp_now(),
			),
			array(
				'id'     => $id,
				'status' => 'draft',
			)
		);
		if ( ! $claimed ) {
			return new WP_Error( 'mlp_not_draft', __( 'This email has already been sent or queued.', 'mailinglist-plugin' ) );
		}

		$queue   = MLP_DB::table( 'queue' );
		$members = MLP_DB::table( 'members' );
		$total   = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$queue} (campaign_id, email, name, status) SELECT %d, email, name, 'pending' FROM {$members} WHERE list_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id,
				$list->id
			)
		);

		if ( ! $total ) {
			$wpdb->update( MLP_DB::table( 'campaigns' ), array( 'status' => 'draft', 'started_at' => null ), array( 'id' => $id ) );
			$message = __( 'This mailing list has no recipients, so nothing was sent.', 'mailinglist-plugin' );
			return new WP_Error( 'mlp_empty_list', $warning ? $message . ' ' . $warning : $message );
		}

		$wpdb->update( MLP_DB::table( 'campaigns' ), array( 'total' => $total ), array( 'id' => $id ) );
		MLP_Queue::schedule( 0 );

		return array(
			'total'   => $total,
			'warning' => $warning,
		);
	}

	/**
	 * Stop a campaign that is still sending. Emails already sent stay sent.
	 */
	public static function cancel( $id ) {
		global $wpdb;
		$updated = $wpdb->update(
			MLP_DB::table( 'campaigns' ),
			array(
				'status'       => 'cancelled',
				'completed_at' => mlp_now(),
			),
			array(
				'id'     => $id,
				'status' => 'sending',
			)
		);
		if ( $updated ) {
			$wpdb->update(
				MLP_DB::table( 'queue' ),
				array( 'status' => 'cancelled' ),
				array(
					'campaign_id' => $id,
					'status'      => 'pending',
				)
			);
			self::recount( $id );
		}
		return (bool) $updated;
	}

	public static function cancel_for_list( $list_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . MLP_DB::table( 'campaigns' ) . " WHERE list_id = %d AND status = 'sending'", $list_id ) );
		foreach ( (array) $ids as $id ) {
			self::cancel( (int) $id );
		}
	}

	/**
	 * Put failed recipients back in the queue.
	 *
	 * @return int Number of recipients re-queued.
	 */
	public static function retry_failed( $id ) {
		global $wpdb;
		$campaign = self::get( $id );
		if ( ! $campaign || ! in_array( $campaign->status, array( 'sent', 'sending' ), true ) ) {
			return 0;
		}
		$count = (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . MLP_DB::table( 'queue' ) . " SET status = 'pending', error = NULL, claimed_at = NULL WHERE campaign_id = %d AND status = 'failed'",
				$id
			)
		);
		if ( $count ) {
			$wpdb->update(
				MLP_DB::table( 'campaigns' ),
				array(
					'status'       => 'sending',
					'completed_at' => null,
				),
				array( 'id' => $id )
			);
			self::recount( $id );
			MLP_Queue::schedule( 0 );
		}
		return $count;
	}

	/**
	 * Queue row counts by status.
	 *
	 * @return array status => count
	 */
	public static function queue_counts( $id ) {
		global $wpdb;
		$counts = array();
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM ' . MLP_DB::table( 'queue' ) . ' WHERE campaign_id = %d GROUP BY status', $id ) );
		foreach ( (array) $rows as $row ) {
			$counts[ $row->status ] = (int) $row->c;
		}
		return $counts;
	}

	/**
	 * Update the cached sent / failed totals, and mark the campaign as sent once nothing is left to send.
	 */
	public static function recount( $id ) {
		global $wpdb;

		$counts = self::queue_counts( $id );
		$data   = array(
			'sent'   => isset( $counts['sent'] ) ? $counts['sent'] : 0,
			'failed' => isset( $counts['failed'] ) ? $counts['failed'] : 0,
		);
		$wpdb->update( MLP_DB::table( 'campaigns' ), $data, array( 'id' => $id ) );

		if ( empty( $counts['pending'] ) && empty( $counts['sending'] ) ) {
			$wpdb->update(
				MLP_DB::table( 'campaigns' ),
				array(
					'status'       => 'sent',
					'completed_at' => mlp_now(),
				),
				array(
					'id'     => $id,
					'status' => 'sending',
				)
			);
		}
	}

	public static function get_failed( $id, $limit = 100 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT email, error FROM ' . MLP_DB::table( 'queue' ) . " WHERE campaign_id = %d AND status = 'failed' ORDER BY id ASC LIMIT %d", $id, $limit ) );
	}
}
