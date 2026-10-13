<?php
defined( 'ABSPATH' ) || exit;

/**
 * Mailing list storage.
 *
 * A list has a type which fixes its source:
 *  - marketing: WPForms entries, recipients can unsubscribe.
 *  - product:   WooCommerce data, legal / product notices, no unsubscribe.
 */
class MLP_Lists {

	const TYPE_MARKETING = 'marketing';
	const TYPE_PRODUCT   = 'product';

	public static function types() {
		return array(
			self::TYPE_MARKETING => __( 'Marketing Email', 'mailinglist-plugin' ),
			self::TYPE_PRODUCT   => __( 'Product Email', 'mailinglist-plugin' ),
		);
	}

	public static function type_label( $type ) {
		$types = self::types();
		return isset( $types[ $type ] ) ? $types[ $type ] : $type;
	}

	/**
	 * @param int $id List ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MLP_DB::table( 'lists' ) . ' WHERE id = %d', $id ) );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param string $type Optional type filter.
	 * @return object[]
	 */
	public static function all( $type = '' ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . MLP_DB::table( 'lists' );
		if ( $type ) {
			$sql .= $wpdb->prepare( ' WHERE type = %s', $type );
		}
		$sql .= ' ORDER BY name ASC, id ASC';
		return array_map( array( __CLASS__, 'hydrate' ), (array) $wpdb->get_results( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function hydrate( $row ) {
		$config            = json_decode( (string) $row->config, true );
		$row->config       = is_array( $config ) ? $config : array();
		$row->id           = (int) $row->id;
		$row->member_count = (int) $row->member_count;
		return $row;
	}

	/**
	 * Reduce raw source configuration to the keys valid for the list type.
	 *
	 * @param string $type List type.
	 * @param array  $raw  Unslashed raw input.
	 * @return array
	 */
	public static function sanitize_config( $type, $raw ) {
		if ( self::TYPE_MARKETING === $type ) {
			return array(
				'form_id'     => isset( $raw['form_id'] ) ? absint( $raw['form_id'] ) : 0,
				'email_field' => isset( $raw['email_field'] ) && '' !== $raw['email_field'] ? (string) absint( $raw['email_field'] ) : '',
				'name_field'  => isset( $raw['name_field'] ) && '' !== $raw['name_field'] ? (string) absint( $raw['name_field'] ) : '',
			);
		}

		$modes = array_keys( MLP_Source_WooCommerce::modes() );
		$mode  = isset( $raw['wc_mode'] ) && in_array( $raw['wc_mode'], $modes, true ) ? $raw['wc_mode'] : 'all_customers';

		$order_statuses = array();
		if ( isset( $raw['order_statuses'] ) && is_array( $raw['order_statuses'] ) ) {
			$order_statuses = array_values( array_intersect( array_map( 'sanitize_key', $raw['order_statuses'] ), array_keys( MLP_Source_WooCommerce::order_statuses() ) ) );
		}
		if ( ! $order_statuses ) {
			$order_statuses = array( 'processing', 'completed' );
		}

		$sub_statuses = array();
		if ( isset( $raw['subscription_statuses'] ) && is_array( $raw['subscription_statuses'] ) ) {
			$sub_statuses = array_values( array_intersect( array_map( 'sanitize_key', $raw['subscription_statuses'] ), array_keys( MLP_Source_WooCommerce::subscription_statuses() ) ) );
		}
		if ( ! $sub_statuses ) {
			$sub_statuses = array( 'active' );
		}

		return array(
			'wc_mode'               => $mode,
			'product_ids'           => MLP_Source_WooCommerce::product_ids( $raw ),
			'order_statuses'        => $order_statuses,
			'subscription_statuses' => $sub_statuses,
			'include_registered'    => empty( $raw['include_registered'] ) ? 0 : 1,
		);
	}

	/**
	 * Create or update a list.
	 *
	 * @param int    $id     Existing list ID, or 0 to create.
	 * @param string $name   List name.
	 * @param string $type   List type.
	 * @param array  $config Raw source configuration.
	 * @return int|WP_Error List ID.
	 */
	public static function save( $id, $name, $type, $config ) {
		global $wpdb;

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'mlp_name', __( 'Please give the mailing list a name.', 'mailinglist-plugin' ) );
		}
		if ( ! array_key_exists( $type, self::types() ) ) {
			return new WP_Error( 'mlp_type', __( 'Please choose an email type.', 'mailinglist-plugin' ) );
		}

		$config = self::sanitize_config( $type, (array) $config );

		if ( self::TYPE_MARKETING === $type && ! $config['form_id'] ) {
			return new WP_Error( 'mlp_form', __( 'Please choose the WPForms form to source email addresses from.', 'mailinglist-plugin' ) );
		}
		if ( self::TYPE_PRODUCT === $type && 'purchased_product' === $config['wc_mode'] && ! $config['product_ids'] ) {
			return new WP_Error( 'mlp_product', __( 'Please choose the products whose purchasers should be on this list.', 'mailinglist-plugin' ) );
		}

		$data = array(
			'name'   => $name,
			'type'   => $type,
			'config' => wp_json_encode( $config ),
		);

		if ( $id ) {
			$wpdb->update( MLP_DB::table( 'lists' ), $data, array( 'id' => $id ) );
			return (int) $id;
		}

		$data['created_at'] = mlp_now();
		$wpdb->insert( MLP_DB::table( 'lists' ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function delete( $id ) {
		global $wpdb;
		MLP_Campaigns::cancel_for_list( $id );
		$wpdb->delete( MLP_DB::table( 'members' ), array( 'list_id' => $id ) );
		$wpdb->delete( MLP_DB::table( 'lists' ), array( 'id' => $id ) );
	}

	/**
	 * The WPForms form a marketing list is sourced from.
	 *
	 * @param object $list List.
	 * @return int 0 for product lists.
	 */
	public static function form_id( $list ) {
		if ( self::TYPE_MARKETING !== $list->type ) {
			return 0;
		}
		return isset( $list->config['form_id'] ) ? (int) $list->config['form_id'] : 0;
	}

	/**
	 * Human readable description of where a list's addresses come from.
	 *
	 * @param object $list List.
	 * @return string
	 */
	public static function describe_source( $list ) {
		if ( self::TYPE_MARKETING === $list->type ) {
			return MLP_Source_WPForms::describe( $list->config );
		}
		return MLP_Source_WooCommerce::describe( $list->config );
	}

	public static function count_members( $list_id, $search = '' ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MLP_DB::table( 'members' ) . ' WHERE list_id = %d', $list_id );
		if ( '' !== $search ) {
			$sql .= $wpdb->prepare( ' AND email LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get_members( $list_id, $per_page = 50, $page = 1, $search = '' ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM ' . MLP_DB::table( 'members' ) . ' WHERE list_id = %d', $list_id );
		if ( '' !== $search ) {
			$sql .= $wpdb->prepare( ' AND email LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}
		$sql .= $wpdb->prepare( ' ORDER BY email ASC LIMIT %d OFFSET %d', $per_page, max( 0, ( $page - 1 ) * $per_page ) );
		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
