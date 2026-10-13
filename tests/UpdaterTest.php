<?php
/**
 * The plugin registers a GitHub release update checker.
 */
class UpdaterTest extends MLP_TestCase {

	public function test_update_checker_is_bundled_and_starts() {
		$this->assertFileExists( MLP_DIR . 'lib/plugin-update-checker/plugin-update-checker.php' );
		$this->assertNotNull( MLP_Updater::init() );
	}
}
