<?php
/**
 * Minimal stand-in for the parts of WPForms Pro the plugin touches.
 */

if ( ! class_exists( 'MLP_Stub_Entry_Handler' ) ) {
	/**
	 * Mirrors WPForms' entry handler: delete() checks a capability unless told not to.
	 */
	class MLP_Stub_Entry_Handler {

		/** @var int[] Entry IDs deleted through the handler. */
		public $deleted = array();

		/**
		 * @param int   $row_id Entry ID.
		 * @param array $args   Supports 'cap' (false to skip the capability check).
		 * @return bool
		 */
		public function delete( $row_id = 0, $args = array() ) {
			global $wpdb;

			if ( ! isset( $args['cap'] ) ) {
				$args['cap'] = 'delete_entry';
			}
			if ( ! empty( $args['cap'] ) && ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			$this->deleted[] = (int) $row_id;
			return (bool) $wpdb->delete( $wpdb->prefix . 'wpforms_entries', array( 'entry_id' => $row_id ) );
		}
	}

	class MLP_Stub_WPForms {

		/** @var MLP_Stub_Entry_Handler */
		public $entry;

		public function __construct() {
			$this->entry = new MLP_Stub_Entry_Handler();
		}

		public function obj( $name ) {
			return 'entry' === $name ? $this->entry : null;
		}
	}
}

if ( ! function_exists( 'wpforms' ) ) {
	/**
	 * @return MLP_Stub_WPForms
	 */
	function wpforms() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new MLP_Stub_WPForms();
		}
		return $instance;
	}
}

add_action(
	'init',
	function () {
		if ( ! post_type_exists( 'wpforms' ) ) {
			register_post_type( 'wpforms', array( 'public' => false ) );
		}
	}
);
