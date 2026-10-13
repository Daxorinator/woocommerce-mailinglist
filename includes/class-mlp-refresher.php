<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds list membership from each list's source. Runs daily via WP-Cron,
 * when a list is saved, on demand, and immediately before a campaign is sent.
 */
class MLP_Refresher {

	const CRON_HOOK = 'mlp_daily_refresh';

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function refresh_all() {
		foreach ( MLP_Lists::all() as $list ) {
			self::refresh_list( $list );
		}
		// Safety net in case a send was interrupted and its follow-up run was lost.
		MLP_Queue::maybe_schedule();
	}

	/**
	 * Sync a list's members with its source.
	 *
	 * If the source fails (e.g. its plugin is deactivated) the existing members
	 * are kept and the error is recorded against the list.
	 *
	 * @param object $list List.
	 * @return int|WP_Error Member count.
	 */
	public static function refresh_list( $list ) {
		global $wpdb;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'admin' );

		$table   = MLP_DB::table( 'members' );
		$run     = uniqid( '', true );
		$now     = mlp_now();
		$list_id = (int) $list->id;
		$buffer  = new MLP_Member_Buffer( $list_id, $run, $now );

		$emit = function ( $email, $name, $ref ) use ( $buffer ) {
			$buffer->add( $email, $name, $ref );
		};

		if ( MLP_Lists::TYPE_MARKETING === $list->type ) {
			$result = true;

			if ( MLP_Lists::form_id( $list ) ) {
				$result = MLP_Source_WPForms::fetch( $list->config, $emit );
			}

			if ( MLP_Lists::includes_customers( $list ) ) {
				// Customers who unsubscribed are skipped here. Only this source is filtered: unsubscribing
				// deletes the person's form entries, so an entry that exists is a fresh opt-in and stays.
				$suppressed  = MLP_Suppressions::all();
				$emit_orders = function ( $email, $name, $ref ) use ( $emit, $suppressed ) {
					if ( ! isset( $suppressed[ MLP_Suppressions::hash( $email ) ] ) ) {
						call_user_func( $emit, $email, $name, $ref );
					}
				};
				$customers   = MLP_Source_WooCommerce::fetch( $list->config, $emit_orders );
				$result      = is_wp_error( $result ) ? $result : $customers;
			}
		} else {
			$result = MLP_Source_WooCommerce::fetch( $list->config, $emit );
		}
		$buffer->flush();

		if ( is_wp_error( $result ) ) {
			$wpdb->update( MLP_DB::table( 'lists' ), array( 'last_error' => $result->get_error_message() ), array( 'id' => $list_id ) );
			return $result;
		}

		// Anything not seen in this run is no longer in the source.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE list_id = %d AND seen_run <> %s", $list_id, $run ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$count = MLP_Lists::count_members( $list_id );
		$wpdb->update(
			MLP_DB::table( 'lists' ),
			array(
				'member_count'   => $count,
				'last_refreshed' => $now,
				'last_error'     => null,
			),
			array( 'id' => $list_id )
		);

		do_action( 'mlp_list_refreshed', $list_id, $count );

		return $count;
	}
}
