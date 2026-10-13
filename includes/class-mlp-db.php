<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database schema.
 */
class MLP_DB {

	const VERSION        = '1';
	const VERSION_OPTION = 'mlp_db_version';

	/**
	 * Full table name for one of the plugin's tables.
	 *
	 * @param string $name lists|members|campaigns|queue|unsubscribes.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'mlp_' . $name;
	}

	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// Emails are capped at 180 chars so the composite unique keys fit in 767-byte index limits.
		$lists        = self::table( 'lists' );
		$members      = self::table( 'members' );
		$campaigns    = self::table( 'campaigns' );
		$queue        = self::table( 'queue' );
		$unsubscribes = self::table( 'unsubscribes' );

		dbDelta(
			"CREATE TABLE {$lists} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT 'marketing',
			config longtext NOT NULL,
			member_count int(10) unsigned NOT NULL DEFAULT 0,
			last_refreshed datetime DEFAULT NULL,
			last_error text DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$members} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			list_id bigint(20) unsigned NOT NULL,
			email varchar(180) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			source_ref varchar(190) NOT NULL DEFAULT '',
			seen_run varchar(32) NOT NULL DEFAULT '',
			added_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY list_email (list_id,email),
			KEY list_run (list_id,seen_run)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$campaigns} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			list_id bigint(20) unsigned NOT NULL DEFAULT 0,
			list_name varchar(190) NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT 'marketing',
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			body_format varchar(10) NOT NULL DEFAULT 'visual',
			status varchar(20) NOT NULL DEFAULT 'draft',
			total int(10) unsigned NOT NULL DEFAULT 0,
			sent int(10) unsigned NOT NULL DEFAULT 0,
			failed int(10) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			email varchar(180) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			error text DEFAULT NULL,
			claimed_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY campaign_status (campaign_id,status),
			KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$unsubscribes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(180) NOT NULL,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			list_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entries_removed int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY form_email (form_id,email)
			) {$charset};"
		);

		if ( ! get_option( 'mlp_secret' ) ) {
			add_option( 'mlp_secret', wp_generate_password( 64, false ), '', 'no' );
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	public static function uninstall() {
		global $wpdb;
		foreach ( array( 'lists', 'members', 'campaigns', 'queue', 'unsubscribes' ) as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( self::VERSION_OPTION );
		delete_option( 'mlp_secret' );
		delete_option( 'mlp_settings' );
		delete_option( 'mlp_queue_lock' );
	}
}
