<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and their form / AJAX handlers.
 */
class MLP_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );

		foreach ( array( 'save_list', 'delete_list', 'refresh_list', 'save_campaign', 'campaign_action', 'delete_unsubscribe', 'save_settings' ) as $action ) {
			add_action( 'admin_post_mlp_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		foreach ( array( 'form_fields', 'preview', 'send_test' ) as $action ) {
			add_action( 'wp_ajax_mlp_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function action_url( $action, $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'mlp_' . $action ), $args ), admin_url( 'admin-post.php' ) ), 'mlp_' . $action );
	}

	private static function is_plugin_page() {
		return isset( $_GET['page'] ) && 0 === strpos( sanitize_key( $_GET['page'] ), 'mlp-' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private static function guard( $nonce_action ) {
		if ( ! current_user_can( mlp_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'mailinglist-plugin' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function notice( $message, $type = 'success' ) {
		set_transient(
			'mlp_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			120
		);
	}

	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	public static function render_notice() {
		if ( ! self::is_plugin_page() ) {
			return;
		}
		$key    = 'mlp_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info' ),
			esc_html( $notice['message'] )
		);
	}

	private static function type_badge( $type ) {
		return sprintf( '<span class="mlp-badge mlp-badge-%s">%s</span>', esc_attr( $type ), esc_html( MLP_Lists::type_label( $type ) ) );
	}

	/**
	 * Email content from a submitted composer form.
	 *
	 * @return array [ body, format ]
	 */
	private static function read_body() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce checked by caller; HTML is sanitised below.
		$format = isset( $_POST['body_format'] ) && 'html' === $_POST['body_format'] ? 'html' : 'visual';
		$field  = 'html' === $format ? 'mlp_body_html' : 'mlp_body_visual';
		$body   = isset( $_POST[ $field ] ) ? (string) wp_unslash( $_POST[ $field ] ) : '';
		// phpcs:enable

		// Users without unfiltered_html (e.g. non-super-admins on multisite) get post-level filtering.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$body = wp_kses_post( $body );
		}
		return array( $body, $format );
	}

	/* ---------------------------------------------------------------------
	 * Menu and assets
	 * ------------------------------------------------------------------- */

	public static function menu() {
		$cap = mlp_capability();
		add_menu_page( __( 'Mailing Lists', 'mailinglist-plugin' ), __( 'Mailing Lists', 'mailinglist-plugin' ), $cap, 'mlp-lists', array( __CLASS__, 'page_lists' ), 'dashicons-email-alt', 56 );
		add_submenu_page( 'mlp-lists', __( 'Mailing Lists', 'mailinglist-plugin' ), __( 'All Lists', 'mailinglist-plugin' ), $cap, 'mlp-lists', array( __CLASS__, 'page_lists' ) );
		add_submenu_page( 'mlp-lists', __( 'Add Mailing List', 'mailinglist-plugin' ), __( 'Add List', 'mailinglist-plugin' ), $cap, 'mlp-list-edit', array( __CLASS__, 'page_list_edit' ) );
		add_submenu_page( 'mlp-lists', __( 'Send Email', 'mailinglist-plugin' ), __( 'Send Email', 'mailinglist-plugin' ), $cap, 'mlp-compose', array( __CLASS__, 'page_compose' ) );
		add_submenu_page( 'mlp-lists', __( 'Emails', 'mailinglist-plugin' ), __( 'Emails', 'mailinglist-plugin' ), $cap, 'mlp-campaigns', array( __CLASS__, 'page_campaigns' ) );
		add_submenu_page( 'mlp-lists', __( 'Unsubscribes', 'mailinglist-plugin' ), __( 'Unsubscribes', 'mailinglist-plugin' ), $cap, 'mlp-unsubscribes', array( __CLASS__, 'page_unsubscribes' ) );
		add_submenu_page( 'mlp-lists', __( 'Mailing List Settings', 'mailinglist-plugin' ), __( 'Settings', 'mailinglist-plugin' ), $cap, 'mlp-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets() {
		if ( ! self::is_plugin_page() ) {
			return;
		}
		$page = sanitize_key( $_GET['page'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_enqueue_style( 'mlp-admin', MLP_URL . 'assets/admin.css', array(), MLP_VERSION );
		wp_enqueue_script( 'mlp-admin', MLP_URL . 'assets/admin.js', array( 'jquery' ), MLP_VERSION, true );

		$data = array(
			'nonce' => wp_create_nonce( 'mlp_ajax' ),
			'lists' => array(),
			'i18n'  => array(
				'autoDetect'    => __( 'Auto-detect (first email field)', 'mailinglist-plugin' ),
				'none'          => __( 'None', 'mailinglist-plugin' ),
				'noLists'       => __( 'No lists of this type yet', 'mailinglist-plugin' ),
				/* translators: 1: recipient count, 2: list name */
				'confirmSend'   => __( 'Send this email to %1$s recipient(s) on "%2$s"? This cannot be undone.', 'mailinglist-plugin' ),
				'working'       => __( 'Working…', 'mailinglist-plugin' ),
				'error'         => __( 'Something went wrong. Please try again.', 'mailinglist-plugin' ),
				'hintMarketing' => __( 'Sent to a list sourced from WPForms entries. An unsubscribe link is always included; unsubscribing removes the recipient\'s form entry.', 'mailinglist-plugin' ),
				'hintProduct'   => __( 'Sent to a list sourced from WooCommerce data, for legal / product notices such as recalls. Recipients cannot unsubscribe.', 'mailinglist-plugin' ),
			),
		);

		if ( 'mlp-compose' === $page ) {
			foreach ( MLP_Lists::all() as $list ) {
				$data['lists'][] = array(
					'id'    => $list->id,
					'name'  => $list->name,
					'type'  => $list->type,
					'count' => $list->member_count,
				);
			}
			$data['codeEditor'] = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
		}

		if ( 'mlp-list-edit' === $page && wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}

		wp_localize_script( 'mlp-admin', 'MLP', $data );
	}

	/* ---------------------------------------------------------------------
	 * Lists
	 * ------------------------------------------------------------------- */

	public static function page_lists() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['members'] ) ) {
			self::page_members( absint( $_GET['members'] ) );
			return;
		}
		// phpcs:enable

		$lists = MLP_Lists::all();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Mailing Lists', 'mailinglist-plugin' ); ?></h1>
			<a href="<?php echo esc_url( self::url( 'mlp-list-edit' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add List', 'mailinglist-plugin' ); ?></a>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e( 'Lists are rebuilt from their source automatically once a day, and again immediately before an email is sent.', 'mailinglist-plugin' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'mailinglist-plugin' ); ?></th>
						<th style="width:140px"><?php esc_html_e( 'Email type', 'mailinglist-plugin' ); ?></th>
						<th><?php esc_html_e( 'Source', 'mailinglist-plugin' ); ?></th>
						<th style="width:100px"><?php esc_html_e( 'Recipients', 'mailinglist-plugin' ); ?></th>
						<th style="width:190px"><?php esc_html_e( 'Last refreshed', 'mailinglist-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $lists ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No mailing lists yet.', 'mailinglist-plugin' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $lists as $list ) : ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( self::url( 'mlp-list-edit', array( 'list' => $list->id ) ) ); ?>"><?php echo esc_html( $list->name ); ?></a></strong>
							<div class="row-actions">
								<span><a href="<?php echo esc_url( self::url( 'mlp-list-edit', array( 'list' => $list->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'mailinglist-plugin' ); ?></a> | </span>
								<span><a href="<?php echo esc_url( self::url( 'mlp-lists', array( 'members' => $list->id ) ) ); ?>"><?php esc_html_e( 'View recipients', 'mailinglist-plugin' ); ?></a> | </span>
								<span><a href="<?php echo esc_url( self::action_url( 'refresh_list', array( 'list' => $list->id ) ) ); ?>"><?php esc_html_e( 'Refresh now', 'mailinglist-plugin' ); ?></a> | </span>
								<span><a href="<?php echo esc_url( self::url( 'mlp-compose', array( 'list' => $list->id ) ) ); ?>"><?php esc_html_e( 'Send email', 'mailinglist-plugin' ); ?></a> | </span>
								<span class="delete"><a href="<?php echo esc_url( self::action_url( 'delete_list', array( 'list' => $list->id ) ) ); ?>" class="mlp-confirm" data-confirm="<?php esc_attr_e( 'Delete this mailing list? Emails still being sent to it will be cancelled.', 'mailinglist-plugin' ); ?>"><?php esc_html_e( 'Delete', 'mailinglist-plugin' ); ?></a></span>
							</div>
						</td>
						<td><?php echo self::type_badge( $list->type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( MLP_Lists::describe_source( $list ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $list->member_count ) ); ?></td>
						<td>
							<?php echo esc_html( $list->last_refreshed ? mlp_format_date( $list->last_refreshed ) : __( 'Never', 'mailinglist-plugin' ) ); ?>
							<?php if ( $list->last_error ) : ?>
								<br><span class="mlp-error"><?php echo esc_html( $list->last_error ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function page_members( $list_id ) {
		$list = MLP_Lists::get( $list_id );
		if ( ! $list ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Mailing list not found.', 'mailinglist-plugin' ) . '</p></div>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$per_page = 50;
		$total    = MLP_Lists::count_members( $list->id, $search );
		$members  = MLP_Lists::get_members( $list->id, $per_page, $page, $search );
		?>
		<div class="wrap">
			<h1>
				<?php
				/* translators: %s: list name */
				echo esc_html( sprintf( __( 'Recipients: %s', 'mailinglist-plugin' ), $list->name ) );
				?>
			</h1>
			<p>
				<a href="<?php echo esc_url( self::url( 'mlp-lists' ) ); ?>">&larr; <?php esc_html_e( 'All lists', 'mailinglist-plugin' ); ?></a>
				&nbsp;|&nbsp; <?php echo self::type_badge( $list->type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo esc_html( MLP_Lists::describe_source( $list ) ); ?>
			</p>
			<form method="get">
				<input type="hidden" name="page" value="mlp-lists">
				<input type="hidden" name="members" value="<?php echo esc_attr( $list->id ); ?>">
				<p class="search-box">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email…', 'mailinglist-plugin' ); ?>">
					<?php submit_button( __( 'Search', 'mailinglist-plugin' ), '', '', false ); ?>
				</p>
			</form>
			<p>
				<?php
				/* translators: %s: number of recipients */
				echo esc_html( sprintf( _n( '%s recipient', '%s recipients', $total, 'mailinglist-plugin' ), number_format_i18n( $total ) ) );
				?>
			</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Email', 'mailinglist-plugin' ); ?></th>
						<th><?php esc_html_e( 'Name', 'mailinglist-plugin' ); ?></th>
						<th><?php esc_html_e( 'Source', 'mailinglist-plugin' ); ?></th>
						<th><?php esc_html_e( 'Added', 'mailinglist-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $members ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No recipients found.', 'mailinglist-plugin' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $members as $member ) : ?>
					<tr>
						<td><?php echo esc_html( $member->email ); ?></td>
						<td><?php echo esc_html( $member->name ); ?></td>
						<td><?php echo esc_html( $member->source_ref ); ?></td>
						<td><?php echo esc_html( mlp_format_date( $member->added_at ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $page,
							'total'   => max( 1, (int) ceil( $total / $per_page ) ),
						)
					)
				);
				?>
			</div></div>
		</div>
		<?php
	}

	public static function page_list_edit() {
		$list_id = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list    = $list_id ? MLP_Lists::get( $list_id ) : null;

		$has_wpforms = MLP_Source_WPForms::is_available();
		$has_wc      = MLP_Source_WooCommerce::is_available();
		$has_wcs     = MLP_Source_WooCommerce::subscriptions_available();

		$type = $list ? $list->type : ( ! $has_wpforms && $has_wc ? MLP_Lists::TYPE_PRODUCT : MLP_Lists::TYPE_MARKETING );
		$mk   = MLP_Lists::sanitize_config( MLP_Lists::TYPE_MARKETING, $list && MLP_Lists::TYPE_MARKETING === $list->type ? $list->config : array() );
		$pr   = MLP_Lists::sanitize_config( MLP_Lists::TYPE_PRODUCT, $list && MLP_Lists::TYPE_PRODUCT === $list->type ? $list->config : array( 'include_registered' => 1 ) );

		$forms  = $has_wpforms ? MLP_Source_WPForms::get_forms() : array();
		$fields = $mk['form_id'] ? MLP_Source_WPForms::get_form_fields( $mk['form_id'] ) : array();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $list ? __( 'Edit Mailing List', 'mailinglist-plugin' ) : __( 'Add Mailing List', 'mailinglist-plugin' ) ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mlp_save_list">
				<input type="hidden" name="list_id" value="<?php echo esc_attr( $list ? $list->id : 0 ); ?>">
				<?php wp_nonce_field( 'mlp_save_list' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mlp_name"><?php esc_html_e( 'List name', 'mailinglist-plugin' ); ?></label></th>
						<td><input type="text" id="mlp_name" name="name" class="regular-text" required value="<?php echo esc_attr( $list ? $list->name : '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email type', 'mailinglist-plugin' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="type" value="marketing" <?php checked( $type, 'marketing' ); ?>>
									<strong><?php esc_html_e( 'Marketing Email', 'mailinglist-plugin' ); ?></strong>
									— <?php esc_html_e( 'recipients come from WPForms entries. Every email includes an unsubscribe link.', 'mailinglist-plugin' ); ?>
								</label><br>
								<label>
									<input type="radio" name="type" value="product" <?php checked( $type, 'product' ); ?>>
									<strong><?php esc_html_e( 'Product Email', 'mailinglist-plugin' ); ?></strong>
									— <?php esc_html_e( 'recipients come from WooCommerce data, for legal / product notices such as recalls. Recipients cannot unsubscribe.', 'mailinglist-plugin' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>

					<?php // Marketing: WPForms. ?>
					<?php if ( ! $has_wpforms ) : ?>
						<tr class="mlp-source-marketing"><th></th><td><div class="notice notice-warning inline"><p><?php esc_html_e( 'WPForms is not active. Marketing lists need WPForms Pro, which stores form entries.', 'mailinglist-plugin' ); ?></p></div></td></tr>
					<?php elseif ( ! MLP_Source_WPForms::has_entry_storage() ) : ?>
						<tr class="mlp-source-marketing"><th></th><td><div class="notice notice-warning inline"><p><?php esc_html_e( 'WPForms entry storage was not found. Sourcing addresses from entries requires WPForms Pro.', 'mailinglist-plugin' ); ?></p></div></td></tr>
					<?php endif; ?>
					<tr class="mlp-source-marketing">
						<th scope="row"><label for="mlp_form_id"><?php esc_html_e( 'WPForms form', 'mailinglist-plugin' ); ?></label></th>
						<td>
							<select id="mlp_form_id" name="config[form_id]">
								<option value="0"><?php esc_html_e( '— Select a form —', 'mailinglist-plugin' ); ?></option>
								<?php foreach ( $forms as $form ) : ?>
									<option value="<?php echo esc_attr( $form->ID ); ?>" <?php selected( $mk['form_id'], $form->ID ); ?>><?php echo esc_html( $form->post_title . ' (#' . $form->ID . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The list contains the email address from every entry submitted to this form (spam, trashed and partial entries are ignored).', 'mailinglist-plugin' ); ?></p>
						</td>
					</tr>
					<tr class="mlp-source-marketing">
						<th scope="row"><label for="mlp_email_field"><?php esc_html_e( 'Email field', 'mailinglist-plugin' ); ?></label></th>
						<td>
							<select id="mlp_email_field" name="config[email_field]" data-current="<?php echo esc_attr( $mk['email_field'] ); ?>">
								<option value=""><?php esc_html_e( 'Auto-detect (first email field)', 'mailinglist-plugin' ); ?></option>
								<?php foreach ( $fields as $field ) : ?>
									<?php if ( 'name' !== $field['type'] ) : ?>
										<option value="<?php echo esc_attr( $field['id'] ); ?>" <?php selected( $mk['email_field'], $field['id'] ); ?>><?php echo esc_html( $field['label'] ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr class="mlp-source-marketing">
						<th scope="row"><label for="mlp_name_field"><?php esc_html_e( 'Name field', 'mailinglist-plugin' ); ?></label></th>
						<td>
							<select id="mlp_name_field" name="config[name_field]" data-current="<?php echo esc_attr( $mk['name_field'] ); ?>">
								<option value=""><?php esc_html_e( 'None', 'mailinglist-plugin' ); ?></option>
								<?php foreach ( $fields as $field ) : ?>
									<?php if ( 'email' !== $field['type'] ) : ?>
										<option value="<?php echo esc_attr( $field['id'] ); ?>" <?php selected( $mk['name_field'], $field['id'] ); ?>><?php echo esc_html( $field['label'] ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Optional. Used for the {name} merge tag.', 'mailinglist-plugin' ); ?></p>
						</td>
					</tr>

					<?php // Product: WooCommerce. ?>
					<?php if ( ! $has_wc ) : ?>
						<tr class="mlp-source-product"><th></th><td><div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce is not active. Product lists need WooCommerce.', 'mailinglist-plugin' ); ?></p></div></td></tr>
					<?php endif; ?>
					<tr class="mlp-source-product">
						<th scope="row"><label for="mlp_wc_mode"><?php esc_html_e( 'Recipients', 'mailinglist-plugin' ); ?></label></th>
						<td>
							<select id="mlp_wc_mode" name="config[wc_mode]">
								<?php foreach ( MLP_Source_WooCommerce::modes() as $mode => $label ) : ?>
									<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $pr['wc_mode'], $mode ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( $has_wc && ! $has_wcs ) : ?>
								<p class="description mlp-wc-row" data-modes="active_subscribers"><span class="mlp-error"><?php esc_html_e( 'WooCommerce Subscriptions is not active, so subscriber lists will be empty.', 'mailinglist-plugin' ); ?></span></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr class="mlp-source-product mlp-wc-row" data-modes="purchased_product active_subscribers">
						<th scope="row"><label for="mlp_product_id"><?php esc_html_e( 'Product or variation', 'mailinglist-plugin' ); ?></label></th>
						<td>
							<?php if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) : ?>
								<select id="mlp_product_id" name="config[product_id]" class="wc-product-search" style="width:400px" data-placeholder="<?php esc_attr_e( 'Search for a product or variation…', 'mailinglist-plugin' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
									<?php if ( $pr['product_id'] ) : ?>
										<option value="<?php echo esc_attr( $pr['product_id'] ); ?>" selected><?php echo esc_html( MLP_Source_WooCommerce::product_name( $pr['product_id'] ) ); ?></option>
									<?php endif; ?>
								</select>
							<?php else : ?>
								<input type="number" min="0" id="mlp_product_id" name="config[product_id]" value="<?php echo esc_attr( $pr['product_id'] ); ?>" class="small-text">
								<span class="description"><?php esc_html_e( 'Product or variation ID', 'mailinglist-plugin' ); ?></span>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Choose a product to include every variation of it, or choose a single variation to target only that variant.', 'mailinglist-plugin' ); ?></p>
							<p class="description mlp-wc-row" data-modes="active_subscribers"><?php esc_html_e( 'Leave empty to include subscribers to any subscription product.', 'mailinglist-plugin' ); ?></p>
						</td>
					</tr>
					<tr class="mlp-source-product mlp-wc-row" data-modes="all_customers purchased_product">
						<th scope="row"><?php esc_html_e( 'Order statuses', 'mailinglist-plugin' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( MLP_Source_WooCommerce::order_statuses() as $status => $label ) : ?>
									<label class="mlp-inline"><input type="checkbox" name="config[order_statuses][]" value="<?php echo esc_attr( $status ); ?>" <?php checked( in_array( $status, $pr['order_statuses'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Only orders with these statuses count.', 'mailinglist-plugin' ); ?></p>
						</td>
					</tr>
					<tr class="mlp-source-product mlp-wc-row" data-modes="all_customers">
						<th scope="row"><?php esc_html_e( 'Registered customers', 'mailinglist-plugin' ); ?></th>
						<td><label><input type="checkbox" name="config[include_registered]" value="1" <?php checked( $pr['include_registered'] ); ?>> <?php esc_html_e( 'Also include customer accounts that have not placed an order', 'mailinglist-plugin' ); ?></label></td>
					</tr>
					<tr class="mlp-source-product mlp-wc-row" data-modes="active_subscribers">
						<th scope="row"><?php esc_html_e( 'Subscription statuses', 'mailinglist-plugin' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( MLP_Source_WooCommerce::subscription_statuses() as $status => $label ) : ?>
									<label class="mlp-inline"><input type="checkbox" name="config[subscription_statuses][]" value="<?php echo esc_attr( $status ); ?>" <?php checked( in_array( $status, $pr['subscription_statuses'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button( $list ? __( 'Save and refresh list', 'mailinglist-plugin' ) : __( 'Create list', 'mailinglist-plugin' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save_list() {
		self::guard( 'mlp_save_list' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- sanitised in MLP_Lists::save().
		$list_id = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
		$name    = isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '';
		$type    = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		$config  = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : array();
		// phpcs:enable

		$result = MLP_Lists::save( $list_id, $name, $type, $config );
		if ( is_wp_error( $result ) ) {
			self::notice( $result->get_error_message(), 'error' );
			self::redirect( self::url( 'mlp-list-edit', $list_id ? array( 'list' => $list_id ) : array() ) );
		}

		$refresh = MLP_Refresher::refresh_list( MLP_Lists::get( $result ) );
		if ( is_wp_error( $refresh ) ) {
			/* translators: %s: error message */
			self::notice( sprintf( __( 'List saved, but it could not be refreshed: %s', 'mailinglist-plugin' ), $refresh->get_error_message() ), 'warning' );
		} else {
			/* translators: %s: number of recipients */
			self::notice( sprintf( __( 'List saved. It currently has %s recipient(s).', 'mailinglist-plugin' ), number_format_i18n( $refresh ) ) );
		}
		self::redirect( self::url( 'mlp-lists' ) );
	}

	public static function handle_refresh_list() {
		self::guard( 'mlp_refresh_list' );
		$list = MLP_Lists::get( isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0 );
		if ( $list ) {
			$refresh = MLP_Refresher::refresh_list( $list );
			if ( is_wp_error( $refresh ) ) {
				self::notice( $refresh->get_error_message(), 'error' );
			} else {
				/* translators: 1: list name, 2: number of recipients */
				self::notice( sprintf( __( '"%1$s" refreshed: %2$s recipient(s).', 'mailinglist-plugin' ), $list->name, number_format_i18n( $refresh ) ) );
			}
		}
		self::redirect( self::url( 'mlp-lists' ) );
	}

	public static function handle_delete_list() {
		self::guard( 'mlp_delete_list' );
		$list_id = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0;
		if ( $list_id ) {
			MLP_Lists::delete( $list_id );
			self::notice( __( 'Mailing list deleted.', 'mailinglist-plugin' ) );
		}
		self::redirect( self::url( 'mlp-lists' ) );
	}

	public static function ajax_form_fields() {
		check_ajax_referer( 'mlp_ajax' );
		if ( ! current_user_can( mlp_capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( MLP_Source_WPForms::get_form_fields( isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0 ) );
	}

	/* ---------------------------------------------------------------------
	 * Compose
	 * ------------------------------------------------------------------- */

	public static function page_compose() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$campaign_id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
		$preset_list = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0;
		// phpcs:enable

		$campaign = $campaign_id ? MLP_Campaigns::get( $campaign_id ) : null;
		if ( $campaign && 'draft' !== $campaign->status ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Send Email', 'mailinglist-plugin' ) . '</h1><p>' . esc_html__( 'This email has already been sent and can no longer be edited.', 'mailinglist-plugin' ) . ' <a href="' . esc_url( self::url( 'mlp-campaigns', array( 'view' => $campaign->id ) ) ) . '">' . esc_html__( 'View it', 'mailinglist-plugin' ) . '</a></p></div>';
			return;
		}

		$lists   = MLP_Lists::all();
		$list_id = $campaign ? (int) $campaign->list_id : $preset_list;
		$current = $list_id ? MLP_Lists::get( $list_id ) : null;
		$type    = $current ? $current->type : ( $campaign ? $campaign->type : MLP_Lists::TYPE_MARKETING );
		$format  = $campaign && 'html' === $campaign->body_format ? 'html' : 'visual';
		$body    = $campaign ? $campaign->body : '';
		$user    = wp_get_current_user();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $campaign ? __( 'Edit Email', 'mailinglist-plugin' ) : __( 'Send Email', 'mailinglist-plugin' ) ); ?></h1>

			<?php if ( ! $lists ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'You need a mailing list before you can send an email.', 'mailinglist-plugin' ); ?>
					<a href="<?php echo esc_url( self::url( 'mlp-list-edit' ) ); ?>"><?php esc_html_e( 'Add a list', 'mailinglist-plugin' ); ?></a>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mlp-compose-form">
				<input type="hidden" name="action" value="mlp_save_campaign">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign ? $campaign->id : 0 ); ?>">
				<?php wp_nonce_field( 'mlp_save_campaign' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mlp_email_type"><?php esc_html_e( 'Email type', 'mailinglist-plugin' ); ?></label></th>
						<td>
							<select id="mlp_email_type" name="email_type">
								<option value="marketing" <?php selected( $type, 'marketing' ); ?>><?php esc_html_e( 'Marketing Email (WPForms lists)', 'mailinglist-plugin' ); ?></option>
								<option value="product" <?php selected( $type, 'product' ); ?>><?php esc_html_e( 'Product Email (WooCommerce lists)', 'mailinglist-plugin' ); ?></option>
							</select>
							<p class="description" id="mlp-type-hint"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp_list_id"><?php esc_html_e( 'Mailing list', 'mailinglist-plugin' ); ?></label></th>
						<td>
							<select id="mlp_list_id" name="list_id" data-current="<?php echo esc_attr( $list_id ); ?>">
								<?php foreach ( $lists as $list ) : ?>
									<option value="<?php echo esc_attr( $list->id ); ?>" <?php selected( $list_id, $list->id ); ?>><?php echo esc_html( $list->name . ' — ' . MLP_Lists::type_label( $list->type ) . ' (' . number_format_i18n( $list->member_count ) . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The list is refreshed from its source right before sending.', 'mailinglist-plugin' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp_subject"><?php esc_html_e( 'Subject', 'mailinglist-plugin' ); ?></label></th>
						<td><input type="text" id="mlp_subject" name="subject" class="large-text" maxlength="255" value="<?php echo esc_attr( $campaign ? $campaign->subject : '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Content', 'mailinglist-plugin' ); ?></th>
						<td>
							<fieldset class="mlp-format">
								<label class="mlp-inline"><input type="radio" name="body_format" value="visual" <?php checked( $format, 'visual' ); ?>> <?php esc_html_e( 'Visual editor (WYSIWYG)', 'mailinglist-plugin' ); ?></label>
								<label class="mlp-inline"><input type="radio" name="body_format" value="html" <?php checked( $format, 'html' ); ?>> <?php esc_html_e( 'HTML code', 'mailinglist-plugin' ); ?></label>
							</fieldset>

							<div id="mlp-editor-visual">
								<?php
								wp_editor(
									'visual' === $format ? $body : '',
									'mlp_body_visual',
									array(
										'textarea_name' => 'mlp_body_visual',
										'textarea_rows' => 18,
										'media_buttons' => true,
									)
								);
								?>
							</div>
							<div id="mlp-editor-html" style="display:none">
								<textarea id="mlp_body_html" name="mlp_body_html" rows="22" class="large-text code" spellcheck="false"><?php echo esc_textarea( 'html' === $format ? $body : '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Sent exactly as written. Paste a complete HTML document (with its own <html> and <style>) or just a fragment, which is placed in a simple responsive wrapper.', 'mailinglist-plugin' ); ?></p>
							</div>

							<p class="description">
								<?php esc_html_e( 'Merge tags:', 'mailinglist-plugin' ); ?>
								<?php foreach ( MLP_Mailer::merge_tags() as $tag => $label ) : ?>
									<code title="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $tag ); ?></code>
								<?php endforeach; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp_test_to"><?php esc_html_e( 'Test email', 'mailinglist-plugin' ); ?></label></th>
						<td>
							<input type="email" id="mlp_test_to" class="regular-text" value="<?php echo esc_attr( $user->user_email ); ?>">
							<button type="button" class="button" id="mlp-send-test"><?php esc_html_e( 'Send test', 'mailinglist-plugin' ); ?></button>
							<button type="button" class="button" id="mlp-preview-btn"><?php esc_html_e( 'Preview', 'mailinglist-plugin' ); ?></button>
							<span id="mlp-ajax-status" aria-live="polite"></span>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="mlp_save" value="1" class="button button-secondary"><?php esc_html_e( 'Save draft', 'mailinglist-plugin' ); ?></button>
					<button type="submit" name="mlp_send" value="1" class="button button-primary" id="mlp-send-btn"><?php esc_html_e( 'Send to list', 'mailinglist-plugin' ); ?></button>
				</p>
			</form>

			<div id="mlp-preview" style="display:none">
				<h2><?php esc_html_e( 'Preview', 'mailinglist-plugin' ); ?></h2>
				<p><strong><?php esc_html_e( 'Subject:', 'mailinglist-plugin' ); ?></strong> <span id="mlp-preview-subject"></span></p>
				<iframe id="mlp-preview-frame" title="<?php esc_attr_e( 'Email preview', 'mailinglist-plugin' ); ?>" sandbox=""></iframe>
			</div>
		</div>
		<?php
	}

	public static function handle_save_campaign() {
		self::guard( 'mlp_save_campaign' );

		list( $body, $format ) = self::read_body();
		$campaign_id           = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		$result = MLP_Campaigns::save(
			$campaign_id,
			array(
				'list_id'     => isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0,
				'subject'     => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
				'body'        => $body,
				'body_format' => $format,
			)
		);
		if ( is_wp_error( $result ) ) {
			self::notice( $result->get_error_message(), 'error' );
			self::redirect( self::url( 'mlp-campaigns' ) );
		}

		$edit_url = self::url( 'mlp-compose', array( 'campaign' => $result ) );

		if ( empty( $_POST['mlp_send'] ) ) {
			self::notice( __( 'Draft saved.', 'mailinglist-plugin' ) );
			self::redirect( $edit_url );
		}

		$started = MLP_Campaigns::start( $result );
		if ( is_wp_error( $started ) ) {
			/* translators: %s: error message */
			self::notice( sprintf( __( 'Not sent — saved as a draft instead. %s', 'mailinglist-plugin' ), $started->get_error_message() ), 'error' );
			self::redirect( $edit_url );
		}

		/* translators: %s: number of recipients */
		$message = sprintf( __( 'Email queued for %s recipient(s). It is sent in the background in batches.', 'mailinglist-plugin' ), number_format_i18n( $started['total'] ) );
		if ( $started['warning'] ) {
			self::notice( $message . ' ' . $started['warning'], 'warning' );
		} else {
			self::notice( $message );
		}
		self::redirect( self::url( 'mlp-campaigns' ) );
	}

	/**
	 * A campaign-shaped object built from the composer's AJAX fields.
	 *
	 * @return array [ campaign, list ]
	 */
	private static function campaign_from_request() {
		list( $body, $format ) = self::read_body();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by caller.
		$list = MLP_Lists::get( isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0 );
		$type = $list ? $list->type : ( isset( $_POST['email_type'] ) && 'product' === $_POST['email_type'] ? MLP_Lists::TYPE_PRODUCT : MLP_Lists::TYPE_MARKETING );

		$campaign = (object) array(
			'id'          => 0,
			'type'        => $type,
			'subject'     => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'body'        => $body,
			'body_format' => $format,
		);
		// phpcs:enable

		return array( $campaign, $list );
	}

	public static function ajax_preview() {
		check_ajax_referer( 'mlp_ajax' );
		if ( ! current_user_can( mlp_capability() ) ) {
			wp_send_json_error( null, 403 );
		}

		list( $campaign ) = self::campaign_from_request();
		$user             = wp_get_current_user();

		list( $subject, $html ) = MLP_Mailer::render( $campaign, $user->user_email, $user->display_name, '#' );

		wp_send_json_success(
			array(
				'subject' => $subject,
				'html'    => $html,
			)
		);
	}

	public static function ajax_send_test() {
		check_ajax_referer( 'mlp_ajax' );
		if ( ! current_user_can( mlp_capability() ) ) {
			wp_send_json_error( null, 403 );
		}

		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			wp_send_json_error( __( 'Please enter a valid email address for the test.', 'mailinglist-plugin' ) );
		}

		list( $campaign, $list ) = self::campaign_from_request();
		if ( '' === trim( $campaign->subject ) ) {
			wp_send_json_error( __( 'Please enter a subject first.', 'mailinglist-plugin' ) );
		}

		$result = MLP_Mailer::send( $campaign, $list, $to, wp_get_current_user()->display_name, true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		/* translators: %s: email address */
		wp_send_json_success( sprintf( __( 'Test email sent to %s.', 'mailinglist-plugin' ), $to ) );
	}

	/* ---------------------------------------------------------------------
	 * Campaigns
	 * ------------------------------------------------------------------- */

	public static function page_campaigns() {
		if ( isset( $_GET['view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::page_campaign_view( absint( $_GET['view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Recover if a scheduled run was lost while a send is still in progress.
		MLP_Queue::maybe_schedule();

		$campaigns = MLP_Campaigns::all();
		$statuses  = MLP_Campaigns::statuses();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Emails', 'mailinglist-plugin' ); ?></h1>
			<a href="<?php echo esc_url( self::url( 'mlp-compose' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Send Email', 'mailinglist-plugin' ); ?></a>
			<hr class="wp-header-end">

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Subject', 'mailinglist-plugin' ); ?></th>
						<th><?php esc_html_e( 'Mailing list', 'mailinglist-plugin' ); ?></th>
						<th style="width:140px"><?php esc_html_e( 'Email type', 'mailinglist-plugin' ); ?></th>
						<th style="width:100px"><?php esc_html_e( 'Status', 'mailinglist-plugin' ); ?></th>
						<th style="width:170px"><?php esc_html_e( 'Progress', 'mailinglist-plugin' ); ?></th>
						<th style="width:190px"><?php esc_html_e( 'Date', 'mailinglist-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $campaigns ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No emails yet.', 'mailinglist-plugin' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $campaigns as $campaign ) : ?>
					<?php
					$is_draft = 'draft' === $campaign->status;
					$main_url = $is_draft ? self::url( 'mlp-compose', array( 'campaign' => $campaign->id ) ) : self::url( 'mlp-campaigns', array( 'view' => $campaign->id ) );
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( $main_url ); ?>"><?php echo esc_html( '' !== $campaign->subject ? $campaign->subject : __( '(no subject)', 'mailinglist-plugin' ) ); ?></a></strong>
							<div class="row-actions">
								<span><a href="<?php echo esc_url( $main_url ); ?>"><?php echo esc_html( $is_draft ? __( 'Edit', 'mailinglist-plugin' ) : __( 'View', 'mailinglist-plugin' ) ); ?></a> | </span>
								<?php if ( 'sending' === $campaign->status ) : ?>
									<span><a href="<?php echo esc_url( self::action_url( 'campaign_action', array( 'do' => 'cancel', 'campaign' => $campaign->id ) ) ); ?>" class="mlp-confirm" data-confirm="<?php esc_attr_e( 'Stop sending this email? Emails already sent cannot be recalled.', 'mailinglist-plugin' ); ?>"><?php esc_html_e( 'Cancel sending', 'mailinglist-plugin' ); ?></a> | </span>
								<?php endif; ?>
								<?php if ( $campaign->failed > 0 && in_array( $campaign->status, array( 'sent', 'sending' ), true ) ) : ?>
									<span><a href="<?php echo esc_url( self::action_url( 'campaign_action', array( 'do' => 'retry', 'campaign' => $campaign->id ) ) ); ?>"><?php esc_html_e( 'Retry failed', 'mailinglist-plugin' ); ?></a> | </span>
								<?php endif; ?>
								<span><a href="<?php echo esc_url( self::action_url( 'campaign_action', array( 'do' => 'duplicate', 'campaign' => $campaign->id ) ) ); ?>"><?php esc_html_e( 'Duplicate', 'mailinglist-plugin' ); ?></a></span>
								<?php if ( 'sending' !== $campaign->status ) : ?>
									<span class="delete"> | <a href="<?php echo esc_url( self::action_url( 'campaign_action', array( 'do' => 'delete', 'campaign' => $campaign->id ) ) ); ?>" class="mlp-confirm" data-confirm="<?php esc_attr_e( 'Delete this email and its delivery log?', 'mailinglist-plugin' ); ?>"><?php esc_html_e( 'Delete', 'mailinglist-plugin' ); ?></a></span>
								<?php endif; ?>
							</div>
						</td>
						<td><?php echo esc_html( $campaign->list_name ); ?></td>
						<td><?php echo self::type_badge( $campaign->type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( isset( $statuses[ $campaign->status ] ) ? $statuses[ $campaign->status ] : $campaign->status ); ?></td>
						<td>
							<?php if ( $is_draft ) : ?>
								—
							<?php else : ?>
								<?php
								/* translators: 1: sent count, 2: total recipients */
								echo esc_html( sprintf( __( '%1$s / %2$s sent', 'mailinglist-plugin' ), number_format_i18n( $campaign->sent ), number_format_i18n( $campaign->total ) ) );
								if ( $campaign->failed > 0 ) {
									/* translators: %s: failed count */
									echo '<br><span class="mlp-error">' . esc_html( sprintf( __( '%s failed', 'mailinglist-plugin' ), number_format_i18n( $campaign->failed ) ) ) . '</span>';
								}
								?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( mlp_format_date( $campaign->completed_at ? $campaign->completed_at : ( $campaign->started_at ? $campaign->started_at : $campaign->created_at ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function page_campaign_view( $id ) {
		$campaign = MLP_Campaigns::get( $id );
		if ( ! $campaign ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Email not found.', 'mailinglist-plugin' ) . '</p></div>';
			return;
		}

		$statuses = MLP_Campaigns::statuses();
		$counts   = MLP_Campaigns::queue_counts( $campaign->id );
		$failed   = MLP_Campaigns::get_failed( $campaign->id );
		$user     = wp_get_current_user();
		$labels   = array(
			'sent'         => __( 'Sent', 'mailinglist-plugin' ),
			'pending'      => __( 'Waiting', 'mailinglist-plugin' ),
			'sending'      => __( 'In progress', 'mailinglist-plugin' ),
			'failed'       => __( 'Failed', 'mailinglist-plugin' ),
			'unsubscribed' => __( 'Skipped (unsubscribed)', 'mailinglist-plugin' ),
			'cancelled'    => __( 'Cancelled', 'mailinglist-plugin' ),
		);

		list( $subject, $html ) = MLP_Mailer::render( $campaign, $user->user_email, $user->display_name, '#' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $campaign->subject ); ?></h1>
			<p><a href="<?php echo esc_url( self::url( 'mlp-campaigns' ) ); ?>">&larr; <?php esc_html_e( 'All emails', 'mailinglist-plugin' ); ?></a></p>

			<table class="widefat striped mlp-summary">
				<tr><th><?php esc_html_e( 'Status', 'mailinglist-plugin' ); ?></th><td><?php echo esc_html( isset( $statuses[ $campaign->status ] ) ? $statuses[ $campaign->status ] : $campaign->status ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Email type', 'mailinglist-plugin' ); ?></th><td><?php echo self::type_badge( $campaign->type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
				<tr><th><?php esc_html_e( 'Mailing list', 'mailinglist-plugin' ); ?></th><td><?php echo esc_html( $campaign->list_name ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Recipients', 'mailinglist-plugin' ); ?></th><td><?php echo esc_html( number_format_i18n( $campaign->total ) ); ?></td></tr>
				<?php foreach ( $labels as $status => $label ) : ?>
					<?php if ( ! empty( $counts[ $status ] ) ) : ?>
						<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( number_format_i18n( $counts[ $status ] ) ); ?></td></tr>
					<?php endif; ?>
				<?php endforeach; ?>
				<tr><th><?php esc_html_e( 'Started', 'mailinglist-plugin' ); ?></th><td><?php echo esc_html( mlp_format_date( $campaign->started_at ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Finished', 'mailinglist-plugin' ); ?></th><td><?php echo esc_html( mlp_format_date( $campaign->completed_at ) ); ?></td></tr>
			</table>

			<?php if ( $failed ) : ?>
				<h2><?php esc_html_e( 'Failed recipients', 'mailinglist-plugin' ); ?></h2>
				<p><a class="button" href="<?php echo esc_url( self::action_url( 'campaign_action', array( 'do' => 'retry', 'campaign' => $campaign->id ) ) ); ?>"><?php esc_html_e( 'Retry failed', 'mailinglist-plugin' ); ?></a></p>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th><?php esc_html_e( 'Email', 'mailinglist-plugin' ); ?></th><th><?php esc_html_e( 'Error', 'mailinglist-plugin' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $failed as $row ) : ?>
						<tr><td><?php echo esc_html( $row->email ); ?></td><td><?php echo esc_html( $row->error ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Content', 'mailinglist-plugin' ); ?></h2>
			<p><strong><?php esc_html_e( 'Subject:', 'mailinglist-plugin' ); ?></strong> <?php echo esc_html( $subject ); ?></p>
			<iframe id="mlp-preview-frame" title="<?php esc_attr_e( 'Email preview', 'mailinglist-plugin' ); ?>" sandbox="" srcdoc="<?php echo esc_attr( $html ); ?>"></iframe>
		</div>
		<?php
	}

	public static function handle_campaign_action() {
		self::guard( 'mlp_campaign_action' );

		$id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';

		switch ( $do ) {
			case 'cancel':
				if ( MLP_Campaigns::cancel( $id ) ) {
					self::notice( __( 'Sending cancelled. Emails that had already gone out are unaffected.', 'mailinglist-plugin' ) );
				}
				break;

			case 'retry':
				$count = MLP_Campaigns::retry_failed( $id );
				/* translators: %s: number of recipients */
				self::notice( sprintf( __( '%s failed recipient(s) queued again.', 'mailinglist-plugin' ), number_format_i18n( $count ) ) );
				break;

			case 'duplicate':
				$new = MLP_Campaigns::duplicate( $id );
				if ( ! is_wp_error( $new ) ) {
					self::notice( __( 'Copied to a new draft.', 'mailinglist-plugin' ) );
					self::redirect( self::url( 'mlp-compose', array( 'campaign' => $new ) ) );
				}
				break;

			case 'delete':
				$campaign = MLP_Campaigns::get( $id );
				if ( $campaign && 'sending' !== $campaign->status ) {
					MLP_Campaigns::delete( $id );
					self::notice( __( 'Email deleted.', 'mailinglist-plugin' ) );
				}
				break;
		}

		self::redirect( self::url( 'mlp-campaigns' ) );
	}

	/* ---------------------------------------------------------------------
	 * Unsubscribes
	 * ------------------------------------------------------------------- */

	public static function page_unsubscribes() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . MLP_DB::table( 'unsubscribes' ) . ' ORDER BY id DESC LIMIT 500' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Unsubscribes', 'mailinglist-plugin' ); ?></h1>
			<p class="description"><?php esc_html_e( 'People who used the unsubscribe link in a Marketing Email. Their WPForms entries were deleted, and they are kept off every list sourced from that form unless they submit the form again. Product Emails have no unsubscribe link.', 'mailinglist-plugin' ); ?></p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Email', 'mailinglist-plugin' ); ?></th>
						<th><?php esc_html_e( 'Form', 'mailinglist-plugin' ); ?></th>
						<th style="width:140px"><?php esc_html_e( 'Entries removed', 'mailinglist-plugin' ); ?></th>
						<th style="width:190px"><?php esc_html_e( 'Date', 'mailinglist-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Nobody has unsubscribed.', 'mailinglist-plugin' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $row->email ); ?>
							<div class="row-actions">
								<span class="delete"><a href="<?php echo esc_url( self::action_url( 'delete_unsubscribe', array( 'id' => $row->id ) ) ); ?>" class="mlp-confirm" data-confirm="<?php esc_attr_e( 'Remove this record? If an entry for this address still exists in the form, it will be added back to the list at the next refresh.', 'mailinglist-plugin' ); ?>"><?php esc_html_e( 'Remove record', 'mailinglist-plugin' ); ?></a></span>
							</div>
						</td>
						<td><?php echo esc_html( MLP_Source_WPForms::describe( array( 'form_id' => $row->form_id ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row->entries_removed ) ); ?></td>
						<td><?php echo esc_html( mlp_format_date( $row->created_at ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function handle_delete_unsubscribe() {
		global $wpdb;
		self::guard( 'mlp_delete_unsubscribe' );
		$wpdb->delete( MLP_DB::table( 'unsubscribes' ), array( 'id' => isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ) );
		self::notice( __( 'Unsubscribe record removed.', 'mailinglist-plugin' ) );
		self::redirect( self::url( 'mlp-unsubscribes' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public static function page_settings() {
		$s = MLP_Settings::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mailing List Settings', 'mailinglist-plugin' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mlp_save_settings">
				<?php wp_nonce_field( 'mlp_save_settings' ); ?>

				<h2><?php esc_html_e( 'Sending', 'mailinglist-plugin' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Emails are sent with wp_mail(), so they go through the mailer configured in WP Mail SMTP. Keep the rate below your SMTP server\'s limits.', 'mailinglist-plugin' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mlp_batch_size"><?php esc_html_e( 'Emails per batch', 'mailinglist-plugin' ); ?></label></th>
						<td><input type="number" id="mlp_batch_size" name="settings[batch_size]" min="1" max="500" class="small-text" value="<?php echo esc_attr( $s['batch_size'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp_batch_interval"><?php esc_html_e( 'Seconds between batches', 'mailinglist-plugin' ); ?></label></th>
						<td><input type="number" id="mlp_batch_interval" name="settings[batch_interval]" min="10" max="3600" class="small-text" value="<?php echo esc_attr( $s['batch_interval'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp_from_name"><?php esc_html_e( 'From name', 'mailinglist-plugin' ); ?></label></th>
						<td><input type="text" id="mlp_from_name" name="settings[from_name]" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp_from_email"><?php esc_html_e( 'From email', 'mailinglist-plugin' ); ?></label></th>
						<td>
							<input type="email" id="mlp_from_email" name="settings[from_email]" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to use the sender configured in WP Mail SMTP (recommended). WP Mail SMTP overrides this if "Force From Email" is enabled there.', 'mailinglist-plugin' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp_reply_to"><?php esc_html_e( 'Reply-To email', 'mailinglist-plugin' ); ?></label></th>
						<td><input type="email" id="mlp_reply_to" name="settings[reply_to]" class="regular-text" value="<?php echo esc_attr( $s['reply_to'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Marketing Email footer', 'mailinglist-plugin' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mlp_footer"><?php esc_html_e( 'Footer', 'mailinglist-plugin' ); ?></label></th>
						<td>
							<textarea id="mlp_footer" name="settings[footer]" rows="3" class="large-text"><?php echo esc_textarea( $s['footer'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Added to Marketing Emails that do not place {unsubscribe_link} or {unsubscribe_url} themselves. The unsubscribe link is always included, even if you remove it here. Never added to Product Emails.', 'mailinglist-plugin' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Data', 'mailinglist-plugin' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'mailinglist-plugin' ); ?></th>
						<td><label><input type="checkbox" name="settings[delete_data]" value="1" <?php checked( $s['delete_data'] ); ?>> <?php esc_html_e( 'Delete all lists, emails and unsubscribe records when the plugin is deleted', 'mailinglist-plugin' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save_settings() {
		self::guard( 'mlp_save_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in MLP_Settings::update().
		MLP_Settings::update( isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array() );
		self::notice( __( 'Settings saved.', 'mailinglist-plugin' ) );
		self::redirect( self::url( 'mlp-settings' ) );
	}
}
