<?php
defined( 'ABSPATH' ) || exit;

/**
 * Email source: all entries submitted to a WPForms form.
 *
 * Entries are only stored in the database by WPForms Pro (wp_wpforms_entries),
 * so this source reports an error when that table is missing.
 */
class MLP_Source_WPForms {

	/** Entry statuses that never represent a completed, genuine submission. */
	const EXCLUDED_STATUSES = array( 'spam', 'trash', 'partial', 'abandoned' );

	public static function is_available() {
		return function_exists( 'wpforms' );
	}

	public static function entries_table() {
		global $wpdb;
		return $wpdb->prefix . 'wpforms_entries';
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	public static function has_entry_storage() {
		return self::table_exists( self::entries_table() );
	}

	/**
	 * @return WP_Post[]
	 */
	public static function get_forms() {
		return get_posts(
			array(
				'post_type'        => 'wpforms',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * Fields of a form that could hold an email address or a name.
	 *
	 * @param int $form_id Form ID.
	 * @return array[] Each: id, label, type.
	 */
	public static function get_form_fields( $form_id ) {
		$form = get_post( $form_id );
		if ( ! $form || 'wpforms' !== $form->post_type ) {
			return array();
		}

		$data = function_exists( 'wpforms_decode' ) ? wpforms_decode( $form->post_content ) : json_decode( $form->post_content, true );
		if ( empty( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
			return array();
		}

		$fields = array();
		foreach ( $data['fields'] as $field ) {
			if ( ! isset( $field['id'], $field['type'] ) || ! in_array( $field['type'], array( 'email', 'name', 'text' ), true ) ) {
				continue;
			}
			$fields[] = array(
				'id'    => (string) $field['id'],
				'type'  => $field['type'],
				'label' => isset( $field['label'] ) && '' !== $field['label'] ? $field['label'] : sprintf( 'Field #%s', $field['id'] ),
			);
		}
		return $fields;
	}

	public static function describe( $config ) {
		$form_id = isset( $config['form_id'] ) ? (int) $config['form_id'] : 0;
		$form    = $form_id ? get_post( $form_id ) : null;
		if ( ! $form || 'wpforms' !== $form->post_type ) {
			/* translators: %d: form ID */
			return sprintf( __( 'WPForms form #%d (missing)', 'mailinglist-plugin' ), $form_id );
		}
		/* translators: 1: form title, 2: form ID */
		return sprintf( __( 'WPForms entries: %1$s (#%2$d)', 'mailinglist-plugin' ), $form->post_title, $form_id );
	}

	/**
	 * Pull the email (and optional name) out of an entry's decoded fields.
	 *
	 * @param array  $fields      Decoded entry fields, keyed by field ID.
	 * @param string $email_field Field ID, or '' to use the first email field.
	 * @param string $name_field  Field ID, or '' for none.
	 * @return array [ email, name ] — email is '' when the entry has no valid address.
	 */
	private static function extract( $fields, $email_field, $name_field ) {
		$email = '';

		if ( '' !== $email_field ) {
			if ( isset( $fields[ $email_field ]['value'] ) ) {
				$email = mlp_normalize_email( $fields[ $email_field ]['value'] );
			}
		} else {
			foreach ( $fields as $field ) {
				if ( isset( $field['type'], $field['value'] ) && 'email' === $field['type'] ) {
					$email = mlp_normalize_email( $field['value'] );
					if ( $email ) {
						break;
					}
				}
			}
		}

		$name = '';
		if ( '' !== $name_field && isset( $fields[ $name_field ]['value'] ) && is_string( $fields[ $name_field ]['value'] ) ) {
			$name = sanitize_text_field( $fields[ $name_field ]['value'] );
		}

		return array( $email, $name );
	}

	/**
	 * Emit every address found in the form's entries.
	 *
	 * @param array    $config Source configuration.
	 * @param callable $emit   function( $email, $name, $source_ref ).
	 * @return true|WP_Error
	 */
	public static function fetch( $config, $emit ) {
		global $wpdb;

		$form_id = isset( $config['form_id'] ) ? (int) $config['form_id'] : 0;
		if ( ! $form_id ) {
			return new WP_Error( 'mlp_no_form', __( 'No WPForms form is selected for this list.', 'mailinglist-plugin' ) );
		}
		if ( ! self::has_entry_storage() ) {
			return new WP_Error( 'mlp_no_entries', __( 'WPForms entry storage was not found. Storing form entries requires WPForms Pro to be active.', 'mailinglist-plugin' ) );
		}
		$form = get_post( $form_id );
		if ( ! $form || 'wpforms' !== $form->post_type || 'trash' === $form->post_status ) {
			return new WP_Error( 'mlp_form_missing', __( 'The WPForms form this list is sourced from no longer exists.', 'mailinglist-plugin' ) );
		}

		$email_field = isset( $config['email_field'] ) ? (string) $config['email_field'] : '';
		$name_field  = isset( $config['name_field'] ) ? (string) $config['name_field'] : '';
		$table       = self::entries_table();
		$last_id     = 0;
		$per_page    = 500;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT entry_id, status, fields, date FROM {$table} WHERE form_id = %d AND entry_id > %d ORDER BY entry_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$form_id,
					$last_id,
					$per_page
				)
			);

			foreach ( (array) $rows as $row ) {
				$last_id = (int) $row->entry_id;

				if ( in_array( (string) $row->status, self::EXCLUDED_STATUSES, true ) ) {
					continue;
				}
				$fields = json_decode( (string) $row->fields, true );
				if ( ! is_array( $fields ) ) {
					continue;
				}

				list( $email, $name ) = self::extract( $fields, $email_field, $name_field );
				if ( ! $email ) {
					continue;
				}

				call_user_func( $emit, $email, $name, 'entry:' . $row->entry_id );
			}
		} while ( count( (array) $rows ) === $per_page );

		return true;
	}

	/**
	 * IDs of every entry of a form that was submitted with the given email address.
	 *
	 * @param int   $form_id    Form ID.
	 * @param string $email     Normalised email.
	 * @param int[] $candidates Extra entry IDs to check (covers values LIKE cannot match).
	 * @return int[]
	 */
	public static function find_entry_ids( $form_id, $email, $candidates = array() ) {
		global $wpdb;

		if ( ! $form_id || ! $email || ! self::has_entry_storage() ) {
			return array();
		}

		$table = self::entries_table();
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_id, fields FROM {$table} WHERE form_id = %d AND fields LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$form_id,
				'%' . $wpdb->esc_like( $email ) . '%'
			)
		);

		$candidates = array_filter( array_map( 'absint', (array) $candidates ) );
		if ( $candidates ) {
			$in   = implode( ',', $candidates );
			$rows = array_merge(
				$rows,
				(array) $wpdb->get_results(
					$wpdb->prepare( "SELECT entry_id, fields FROM {$table} WHERE form_id = %d AND entry_id IN ({$in})", $form_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				)
			);
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$fields = json_decode( (string) $row->fields, true );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( isset( $field['value'] ) && is_string( $field['value'] ) && strtolower( trim( $field['value'] ) ) === $email ) {
					$ids[ (int) $row->entry_id ] = (int) $row->entry_id;
					break;
				}
			}
		}
		return array_values( $ids );
	}

	/**
	 * WPForms' entry handler, across the API rename in WPForms 1.8.x.
	 *
	 * @return object|null
	 */
	private static function entry_handler() {
		if ( ! function_exists( 'wpforms' ) ) {
			return null;
		}
		$wpforms = wpforms();
		foreach ( array( 'obj', 'get' ) as $method ) {
			if ( is_object( $wpforms ) && method_exists( $wpforms, $method ) ) {
				$handler = $wpforms->$method( 'entry' );
				if ( is_object( $handler ) && method_exists( $handler, 'delete' ) ) {
					return $handler;
				}
			}
		}
		return null;
	}

	/**
	 * Delete every entry of a form submitted with the given email address.
	 *
	 * @param int   $form_id    Form ID.
	 * @param string $email     Normalised email.
	 * @param int[] $candidates Extra entry IDs known to belong to this address.
	 * @return int Number of entries removed.
	 */
	public static function delete_entries( $form_id, $email, $candidates = array() ) {
		global $wpdb;

		$ids = self::find_entry_ids( $form_id, $email, $candidates );
		if ( ! $ids ) {
			return 0;
		}

		$table   = self::entries_table();
		$handler = self::entry_handler();
		$removed = 0;

		foreach ( $ids as $entry_id ) {
			if ( $handler ) {
				// 'cap' => false: the visitor unsubscribing is not a logged-in user with delete rights.
				$handler->delete( $entry_id, array( 'cap' => false ) );
			}

			// Fall back to removing the rows directly if WPForms did not.
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM {$table} WHERE entry_id = %d", $entry_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $exists ) {
				$wpdb->delete( $table, array( 'entry_id' => $entry_id ) );
				foreach ( array( 'wpforms_entry_fields', 'wpforms_entry_meta' ) as $related ) {
					$related = $wpdb->prefix . $related;
					if ( self::table_exists( $related ) ) {
						$wpdb->delete( $related, array( 'entry_id' => $entry_id ) );
					}
				}
			}
			++$removed;
		}

		return $removed;
	}
}
