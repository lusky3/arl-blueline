<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/updater-rules.php';
require_once __DIR__ . '/../inc/updater-client.php';
require_once __DIR__ . '/../inc/updater.php';

/**
 * Covers inc/updater.php: when it hooks in and what the hooks return. Tests
 * that need a token run in their own process (constants cannot be undefined).
 */
final class UpdaterWiringTest extends TestCase {

	private const API = 'https://api.github.com/repos/lusky3/arl-blueline/';

	/**
	 * The hook tags the updater registers once booted with a token.
	 *
	 * @var string[]
	 */
	private const TAGS = array( 'update_themes_github.com', 'upgrader_pre_download', 'upgrader_process_complete', 'load-update-core.php' );

	/**
	 * Reset the in-memory stores before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * A cached state with a candidate, as blueline_updater_state() would store it.
	 *
	 * @return array<string, mixed>
	 */
	private function seed_candidate(): array {
		$candidate = array(
			'version'     => '1.2.0',
			'release_url' => 'https://github.com/lusky3/arl-blueline/releases/tag/v1.2.0',
			'package'     => self::API . 'releases/assets/21',
			'manifest'    => array(
				'version'      => '1.2.0',
				'requires_wp'  => '6.9',
				'requires_php' => '8.3',
				'tested_wp'    => '7.1',
				'zip'          => 'blueline-1.2.0.zip',
				'sha256'       => hash( 'sha256', 'ZIP' ),
				'changelog'    => '',
			),
		);

		set_site_transient(
			BLUELINE_UPDATER_CACHE_KEY,
			array(
				'status'    => 'ok',
				'channel'   => 'stable',
				'installed' => BLUELINE_VERSION,
				'checked'   => time(),
				'candidate' => $candidate,
			),
			HOUR_IN_SECONDS
		);

		return $candidate;
	}

	/**
	 * Without a token the module registers nothing.
	 */
	public function test_boot_registers_nothing_without_token(): void {
		blueline_updater_boot();

		foreach ( self::TAGS as $tag ) {
			$this->assertArrayNotHasKey( $tag, $GLOBALS['bl_test_hooks'], $tag );
		}
	}

	/**
	 * Boot is wired to after_setup_theme at file scope.
	 */
	public function test_boot_is_hooked_on_after_setup_theme(): void {
		$source = (string) file_get_contents( __DIR__ . '/../inc/updater.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test; wp_remote_get() is for HTTP.

		$this->assertMatchesRegularExpression( "/^add_action\\( 'after_setup_theme', 'blueline_updater_boot' \\);/m", $source );
	}

	/**
	 * With a token the four hooks are registered.
	 */
	#[RunInSeparateProcess]
	public function test_boot_registers_hooks_with_token(): void {
		define( 'BLUELINE_GITHUB_TOKEN', 'x' );

		blueline_updater_boot();

		foreach ( self::TAGS as $tag ) {
			$this->assertArrayHasKey( $tag, $GLOBALS['bl_test_hooks'], $tag );
		}
	}

	/**
	 * Other themes' checks pass through untouched and cost no request.
	 */
	#[RunInSeparateProcess]
	public function test_filter_update_ignores_other_stylesheets(): void {
		define( 'BLUELINE_GITHUB_TOKEN', 'x' );

		$this->assertSame( 'untouched', blueline_updater_filter_update( 'untouched', array(), 'twentytwentyfive', array() ) );
		$this->assertSame( array(), $GLOBALS['bl_test_http_log'] );
	}

	/**
	 * The exact array WordPress gets for Blueline.
	 */
	#[RunInSeparateProcess]
	public function test_filter_update_returns_wp_update_shape_for_blueline(): void {
		define( 'BLUELINE_GITHUB_TOKEN', 'x' );
		$this->seed_candidate();

		$this->assertSame(
			array(
				'theme'        => 'blueline',
				'version'      => '1.2.0',
				'url'          => 'https://github.com/lusky3/arl-blueline/releases/tag/v1.2.0',
				'package'      => self::API . 'releases/assets/21',
				'requires'     => '6.9',
				'requires_php' => '8.3',
			),
			blueline_updater_filter_update( false, array(), 'blueline', array() )
		);
	}

	/**
	 * Empty requirements are omitted rather than sent as ''.
	 */
	public function test_build_update_omits_empty_requirements(): void {
		$candidate = array(
			'version'     => '1.2.0',
			'release_url' => '',
			'package'     => self::API . 'releases/assets/21',
			'manifest'    => array(
				'requires_wp'  => '',
				'requires_php' => '',
			),
		);
		$update    = blueline_updater_build_update( $candidate );

		$this->assertSame( array( 'theme', 'version', 'package' ), array_keys( $update ) );
	}

	/**
	 * No candidate, or a failed check: whatever was passed in comes back.
	 */
	#[RunInSeparateProcess]
	public function test_filter_update_returns_input_when_no_candidate_or_error_state(): void {
		define( 'BLUELINE_GITHUB_TOKEN', 'x' );

		set_site_transient(
			BLUELINE_UPDATER_CACHE_KEY,
			array(
				'status'    => 'unauthorized',
				'channel'   => 'stable',
				'installed' => BLUELINE_VERSION,
				'checked'   => time(),
				'candidate' => null,
			),
			HOUR_IN_SECONDS
		);

		$this->assertFalse( blueline_updater_filter_update( false, array(), 'blueline', array() ) );
		$this->assertSame( 'other-offer', blueline_updater_filter_update( 'other-offer', array(), 'blueline', array() ) );
	}

	/**
	 * Packages that are not ours go to WordPress's own downloader.
	 */
	#[RunInSeparateProcess]
	public function test_pre_download_ignores_foreign_packages(): void {
		define( 'BLUELINE_GITHUB_TOKEN', 'x' );

		$this->assertFalse( blueline_updater_filter_pre_download( false, 'https://downloads.wordpress.org/theme/x.zip', null, array() ) );
		$this->assertSame( array(), $GLOBALS['bl_test_http_log'] );
	}

	/**
	 * Our package is fetched, verified and handed back as a file path.
	 */
	#[RunInSeparateProcess]
	public function test_pre_download_delegates_for_repo_api_url(): void {
		define( 'BLUELINE_GITHUB_TOKEN', 'x' );
		$candidate = $this->seed_candidate();
		$signed    = 'https://release-assets.githubusercontent.com/signed/zip?sig=1';

		blueline_test_http_expect(
			$candidate['package'],
			array(
				'response' => array( 'code' => 302 ),
				'headers'  => array( 'location' => $signed ),
			)
		);
		blueline_test_http_expect(
			$signed,
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'ZIP',
			)
		);

		$result = blueline_updater_filter_pre_download( false, $candidate['package'], null, array() );

		$this->assertIsString( $result );
		$this->assertSame( 'ZIP', file_get_contents( $result ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local temp file in a unit test.
		unlink( $result ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup of the temp file.
	}

	/**
	 * Only theme upgrades flush the cache.
	 */
	public function test_on_upgrade_flushes_only_for_themes(): void {
		$this->seed_candidate();

		blueline_updater_on_upgrade( null, array( 'type' => 'plugin' ) );
		$this->assertIsArray( get_site_transient( BLUELINE_UPDATER_CACHE_KEY ), 'plugin upgrade keeps the cache' );

		blueline_updater_on_upgrade( null, array( 'type' => 'theme' ) );
		$this->assertFalse( get_site_transient( BLUELINE_UPDATER_CACHE_KEY ) );
	}

	/**
	 * "Check again" clears the cache for users who may update themes.
	 */
	public function test_force_check_flushes_only_with_flag_and_capability(): void {
		$this->seed_candidate();

		blueline_updater_on_force_check();
		$this->assertIsArray( get_site_transient( BLUELINE_UPDATER_CACHE_KEY ), 'no flag, no flush' );

		$_GET['force-check'] = '1';
		blueline_updater_on_force_check();
		$this->assertIsArray( get_site_transient( BLUELINE_UPDATER_CACHE_KEY ), 'no capability, no flush' );

		$state                          = &blueline_test_state();
		$state['caps']['update_themes'] = true;
		blueline_updater_on_force_check();
		$this->assertFalse( get_site_transient( BLUELINE_UPDATER_CACHE_KEY ) );

		unset( $_GET['force-check'] );
	}
}
