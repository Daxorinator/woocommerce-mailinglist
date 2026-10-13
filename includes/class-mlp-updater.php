<?php
defined( 'ABSPATH' ) || exit;

/**
 * Self-updates from GitHub releases using the bundled Plugin Update Checker.
 *
 * The release workflow attaches mailinglist-plugin-<version>.zip to each GitHub
 * release; the checker installs that asset rather than the source archive.
 */
class MLP_Updater {

	const REPOSITORY = 'https://github.com/Daxorinator/woocommerce-mailinglist/';

	/**
	 * @return object|null The update checker, or null when it could not be started.
	 */
	public static function init() {
		$loader = MLP_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return null;
		}
		require_once $loader;

		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return null;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			apply_filters( 'mlp_update_repository', self::REPOSITORY ),
			MLP_FILE,
			'mailinglist-plugin'
		);

		$api = $checker->getVcsApi();
		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/mailinglist-plugin-[\d.]+\.zip$/i' );
		}

		return $checker;
	}
}
