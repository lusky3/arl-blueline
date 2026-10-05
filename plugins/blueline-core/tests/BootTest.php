<?php
/**
 * Unit tests for the plugin skeleton: boot, module loading, legacy coexistence, rewrite flush, uninstall.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers blueline-core.php, includes/boot.php, includes/modules.php and uninstall.php.
 */
final class BootTest extends TestCase {

	/**
	 * Fixture modules, as paths relative to includes/.
	 */
	private const FIXTURE_MODULES = array(
		'alpha'   => '../tests/fixtures/modules/alpha.php',
		'missing' => '../tests/fixtures/modules/does-not-exist.php',
		'beta'    => '../tests/fixtures/modules/beta.php',
	);

	/**
	 * Temp file standing in for the PHP error log, so a skipped module's log line is captured.
	 *
	 * @var string
	 */
	private string $error_log_file = '';

	/**
	 * The error_log ini value to restore.
	 *
	 * @var string|false
	 */
	private $previous_error_log = false;

	/**
	 * Reset the stub stores and the module registries; capture the error log.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$loaded = &blueline_core_loaded_modules();
		$loaded = array();
		$failed = &blueline_core_failed_modules();
		$failed = array();

		$this->error_log_file     = '';
		$this->previous_error_log = false;
	}

	/**
	 * Send error_log() output to a temp file. Called from inside a test, not setUp(): PHPUnit installs
	 * its own error_log capture after setUp() and would override a redirect made there.
	 */
	private function capture_error_log(): void {
		$this->error_log_file     = (string) tempnam( sys_get_temp_dir(), 'blueline-core-log' );
		$this->previous_error_log = ini_set( 'error_log', $this->error_log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- redirecting the log into a temp file for this test only.
	}

	/**
	 * Restore the error log and remove the temp file.
	 */
	protected function tearDown(): void {
		if ( '' === $this->error_log_file ) {
			return;
		}

		ini_set( 'error_log', (string) $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring what capture_error_log() redirected.
		if ( is_file( $this->error_log_file ) ) {
			unlink( $this->error_log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this test's own temp file.
		}
	}

	/**
	 * Everything written to the (redirected) error log so far.
	 *
	 * @return string
	 */
	private function logged(): string {
		return (string) file_get_contents( $this->error_log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this test's own temp log file.
	}

	/**
	 * Swap the real module list for the fixtures and pin legacy detection.
	 *
	 * @param bool $legacy Whether a legacy theme is reported active.
	 */
	private function use_fixtures( bool $legacy ): void {
		$this->capture_error_log();
		add_filter(
			'blueline_core_modules',
			static function () {
				return self::FIXTURE_MODULES;
			}
		);
		add_filter( 'blueline_core_legacy_theme_active', $legacy ? '__return_true' : '__return_false' );
	}

	/**
	 * Count how often an action fires.
	 *
	 * @param string $hook Action name.
	 * @return \ArrayObject<int, int> Index 0 holds the count.
	 */
	private function count_action( string $hook ): \ArrayObject {
		$count = new \ArrayObject( array( 0 ) );
		add_action(
			$hook,
			static function () use ( $count ) {
				++$count[0];
			}
		);

		return $count;
	}

	/**
	 * Allow or forbid the flush via the safety filter.
	 *
	 * @param bool $safe Whether flushing is allowed.
	 */
	private function flush_safe( bool $safe ): void {
		add_filter( 'blueline_core_rewrite_flush_safe', $safe ? '__return_true' : '__return_false' );
	}

	/**
	 * The main file hooks boot at after_setup_theme priority 1 and registers activation/deactivation.
	 */
	public function test_main_file_wires_boot_and_lifecycle_hooks(): void {
		$this->assertSame( 1, has_action( 'after_setup_theme', 'blueline_core_boot' ) );
		$this->assertSame( 'blueline_core_activate', $GLOBALS['blueline_core_test_activation_hooks'][ BLUELINE_CORE_FILE ] );
		$this->assertSame( 'blueline_core_deactivate', $GLOBALS['blueline_core_test_deactivation_hooks'][ BLUELINE_CORE_FILE ] );
	}

	/**
	 * BLUELINE_CORE_VERSION agrees with the plugin header.
	 */
	public function test_version_constant_matches_plugin_header(): void {
		$header = (string) file_get_contents( BLUELINE_CORE_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin source in a unit test.

		$this->assertMatchesRegularExpression( '/^ \* Version:\s+' . preg_quote( BLUELINE_CORE_VERSION, '/' ) . '$/m', $header );
	}

	/**
	 * The shipped list names every planned module, in order, as <slug>/<slug>.php.
	 */
	public function test_real_module_list_is_ordered_and_follows_the_directory_convention(): void {
		$expected = array( 'player-link', 'player-photo', 'avatars', 'account-endpoints', 'seo-meta', 'mail', 'checkout', 'admin-bar', 'search', 'privacy', 'plugin-info' );
		$modules  = blueline_core_modules();

		$this->assertSame( $expected, array_keys( $modules ) );
		foreach ( $modules as $slug => $file ) {
			$this->assertSame( "{$slug}/{$slug}.php", $file );
		}
	}

	/**
	 * Boot requires listed files in order, skips a missing one and fires blueline_core_loaded.
	 */
	public function test_boot_loads_listed_modules_in_order_and_skips_missing_files(): void {
		$this->use_fixtures( false );
		$loaded_action = $this->count_action( 'blueline_core_loaded' );

		blueline_core_boot();

		$this->assertSame( array( 'alpha', 'beta' ), array_keys( blueline_core_loaded_modules() ) );
		$this->assertTrue( blueline_core_module_loaded( 'alpha' ) );
		$this->assertFalse( blueline_core_module_loaded( 'missing' ) );
		$this->assertSame( 'beta', blueline_core_test_fixture_beta() );
		$this->assertSame( 1, $loaded_action[0] );
		$this->assertSame( 99, has_action( 'init', 'blueline_core_maybe_flush_rewrite_rules' ) );
		$this->assertFalse( has_action( 'admin_notices', 'blueline_core_legacy_theme_notice' ) );
		$this->assertSame( 10, has_action( 'admin_notices', 'blueline_core_failed_modules_notice' ), 'The missing file is surfaced, not silent.' );
	}

	/**
	 * A listed but missing module is recorded, logged and named in the admin notice (admins only).
	 */
	public function test_a_missing_module_is_recorded_logged_and_shown_to_admins(): void {
		$this->use_fixtures( false );

		blueline_core_boot();

		$failed = blueline_core_failed_modules();
		$this->assertSame( array( 'missing' ), array_keys( $failed ) );
		$this->assertStringEndsWith( 'tests/fixtures/modules/does-not-exist.php', $failed['missing'] );
		$this->assertSame( 1, substr_count( $this->logged(), 'blueline-core: module "missing" was not loaded' ) );

		ob_start();
		blueline_core_failed_modules_notice();
		$this->assertSame( '', ob_get_clean(), 'Not for non-admins.' );

		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;

		ob_start();
		blueline_core_failed_modules_notice();
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<div class="notice notice-error"><p>', $html );
		$this->assertStringContainsString( 'could not load these modules: missing', $html );
	}

	/**
	 * An unreadable (not just absent) module file is treated the same way.
	 */
	public function test_an_unreadable_module_file_is_recorded(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'root can read a mode 0000 file.' );
		}

		$this->capture_error_log();
		$file = __DIR__ . '/fixtures/modules/locked-tmp.php';
		file_put_contents( $file, '<?php' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- creating this test's own temporary fixture.
		chmod( $file, 0000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- making this test's own fixture unreadable.

		try {
			blueline_core_load_modules( array( 'locked' => '../tests/fixtures/modules/locked-tmp.php' ) );
		} finally {
			chmod( $file, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- so it can be removed.
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this test's own fixture.
		}

		$this->assertArrayHasKey( 'locked', blueline_core_failed_modules() );
		$this->assertFalse( blueline_core_module_loaded( 'locked' ) );
	}

	/**
	 * With every module present nothing is recorded and no notice is queued.
	 */
	public function test_a_clean_load_records_no_failures(): void {
		$this->capture_error_log();
		blueline_core_load_modules( array( 'alpha' => self::FIXTURE_MODULES['alpha'] ) );

		$this->assertSame( array(), blueline_core_failed_modules() );
		$this->assertFalse( has_action( 'admin_notices', 'blueline_core_failed_modules_notice' ) );
		$this->assertSame( '', $this->logged() );
	}

	/**
	 * Site Health: critical and naming the slug on a failure, recommended when idle, good otherwise.
	 */
	public function test_site_health_reports_failed_modules(): void {
		$critical = blueline_core_site_health_result( array( 'privacy' => '/x/privacy.php' ), array( 'mail' => '/x/mail.php' ) );

		$this->assertSame( 'critical', $critical['status'] );
		$this->assertSame( 'red', $critical['badge']['color'] );
		$this->assertSame( 'blueline_core_modules', $critical['test'] );
		$this->assertStringContainsString( 'privacy', $critical['description'] );
		$this->assertStringStartsWith( '<p>', $critical['description'] );

		$idle = blueline_core_site_health_result( array(), array() );
		$this->assertSame( 'recommended', $idle['status'] );

		$good = blueline_core_site_health_result(
			array(),
			array(
				'mail'   => '/x/mail.php',
				'search' => '/x/search.php',
			)
		);
		$this->assertSame( 'good', $good['status'] );
		$this->assertStringContainsString( 'mail, search', $good['description'] );
	}

	/**
	 * The Site Health test is registered as a direct test and reads this request's registries.
	 */
	public function test_site_health_test_is_registered_and_uses_the_live_registries(): void {
		$tests = blueline_core_register_site_health_test( array( 'direct' => array( 'existing' => array() ) ) );

		$this->assertArrayHasKey( 'existing', $tests['direct'] );
		$this->assertSame( 'blueline_core_run_site_health_test', $tests['direct']['blueline_core_modules']['test'] );
		$this->assertSame( 10, has_filter( 'site_status_tests', 'blueline_core_register_site_health_test' ) );

		$this->assertSame( 'recommended', blueline_core_run_site_health_test()['status'] );

		$failed            = &blueline_core_failed_modules();
		$failed['privacy'] = '/x/privacy.php';
		$this->assertSame( 'critical', blueline_core_run_site_health_test()['status'] );
	}

	/**
	 * The mail module reads seo-meta's logo helper, so seo-meta is listed (and so loads) first.
	 */
	public function test_the_real_list_loads_seo_meta_before_mail(): void {
		$slugs = array_keys( blueline_core_modules() );

		$this->assertLessThan( array_search( 'mail', $slugs, true ), array_search( 'seo-meta', $slugs, true ) );
	}

	/**
	 * Modules load in list order, and a module switched off is simply absent (no other module is pulled in).
	 */
	public function test_boot_loads_modules_in_list_order_and_skips_switched_off_ones(): void {
		add_filter(
			'blueline_core_modules',
			static function () {
				return array(
					'seo-meta' => '../tests/fixtures/modules/beta.php',
					'mail'     => '../tests/fixtures/modules/alpha.php',
				);
			}
		);
		add_filter( 'blueline_core_legacy_theme_active', '__return_false' );

		blueline_core_boot();

		$this->assertSame( array( 'seo-meta', 'mail' ), array_keys( blueline_core_loaded_modules() ) );
	}

	/**
	 * Beside a legacy theme boot loads nothing and only registers the admin notice.
	 */
	public function test_boot_beside_a_legacy_theme_loads_nothing_and_registers_the_notice(): void {
		$this->use_fixtures( true );
		$loaded_action = $this->count_action( 'blueline_core_loaded' );

		blueline_core_boot();

		$this->assertSame( array(), blueline_core_loaded_modules() );
		$this->assertSame( 0, $loaded_action[0] );
		$this->assertSame( 10, has_action( 'admin_notices', 'blueline_core_legacy_theme_notice' ) );
		$this->assertFalse( has_action( 'init', 'blueline_core_maybe_flush_rewrite_rules' ) );
	}

	/**
	 * A sentinel counts as legacy only when defined outside the plugin's includes/.
	 */
	public function test_legacy_detection_only_counts_a_sentinel_defined_outside_the_plugin(): void {
		require_once __DIR__ . '/fixtures/legacy-sentinel.php';

		$this->assertTrue( blueline_core_legacy_theme_active( 'blueline_core_test_legacy_sentinel' ) );
		$this->assertFalse( blueline_core_legacy_theme_active( 'blueline_core_boot' ), 'Defined in the plugin\'s own includes/.' );
		$this->assertFalse( blueline_core_legacy_theme_active( 'blueline_core_test_no_such_function' ) );
		$this->assertSame( 'blueline_get_linked_player_id', BLUELINE_CORE_LEGACY_SENTINEL );
	}

	/**
	 * The legacy notice renders for manage_options only.
	 */
	public function test_legacy_notice_is_for_admins_only(): void {
		ob_start();
		blueline_core_legacy_theme_notice();
		$this->assertSame( '', ob_get_clean() );

		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;

		ob_start();
		blueline_core_legacy_theme_notice();
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<div class="notice notice-warning"><p>', $html );
		$this->assertStringContainsString( 'Blueline theme to 1.1.0 or newer', $html );
	}

	/**
	 * Activation forgets the flushed version; the next init flushes once and records it again.
	 */
	public function test_activation_makes_the_next_init_flush_once(): void {
		$this->flush_safe( true );
		$flushed = $this->count_action( 'blueline_core_rewrite_rules_flushed' );
		update_option( BLUELINE_CORE_VERSION_OPTION, BLUELINE_CORE_VERSION );

		$this->assertFalse( blueline_core_maybe_flush_rewrite_rules(), 'Nothing pending before activation.' );

		blueline_core_activate();
		$this->assertFalse( get_option( BLUELINE_CORE_VERSION_OPTION ), 'No separate flag option is written.' );
		$this->assertFalse( get_option( BLUELINE_CORE_FLUSH_OPTION ) );

		$this->assertTrue( blueline_core_maybe_flush_rewrite_rules() );
		$this->assertSame( BLUELINE_CORE_VERSION, get_option( BLUELINE_CORE_VERSION_OPTION ) );
		$this->assertFalse( blueline_core_maybe_flush_rewrite_rules(), 'Recorded: no second flush.' );
		$this->assertSame( 1, $flushed[0] );
	}

	/**
	 * The version option is stored autoloaded, so the per-request check is served from the options
	 * preload; the steady-state path reads only that one option.
	 */
	public function test_the_version_option_is_autoloaded_and_the_steady_state_reads_only_it(): void {
		$this->flush_safe( true );

		blueline_core_maybe_flush_rewrite_rules();
		$this->assertTrue( blueline_test_option_autoload_args( BLUELINE_CORE_VERSION_OPTION )[0], 'update_option() is called with autoload = true.' );

		// A stale legacy flag row must not matter in the steady state (it is never consulted).
		update_option( BLUELINE_CORE_FLUSH_OPTION, 1 );
		$flushed = $this->count_action( 'blueline_core_rewrite_rules_flushed' );

		$this->assertFalse( blueline_core_maybe_flush_rewrite_rules() );
		$this->assertSame( 0, $flushed[0] );
	}

	/**
	 * A stored version that differs from the code triggers exactly one flush.
	 */
	public function test_a_version_change_triggers_one_flush(): void {
		$this->flush_safe( true );
		update_option( BLUELINE_CORE_VERSION_OPTION, '0.0.1' );

		$this->assertTrue( blueline_core_maybe_flush_rewrite_rules() );
		$this->assertSame( BLUELINE_CORE_VERSION, get_option( BLUELINE_CORE_VERSION_OPTION ) );
		$this->assertFalse( blueline_core_maybe_flush_rewrite_rules() );
	}

	/**
	 * Without the register-fix mu-plugin the flush waits and an admin notice is queued.
	 */
	public function test_flush_is_held_back_without_the_register_fix_mu_plugin(): void {
		$flushed = $this->count_action( 'blueline_core_rewrite_rules_flushed' );
		blueline_core_activate();

		$this->assertFalse( blueline_core_rewrite_flush_safe(), 'The stub mu-plugin directory has no register-fix file.' );
		$this->assertFalse( blueline_core_maybe_flush_rewrite_rules() );
		$this->assertFalse( get_option( BLUELINE_CORE_VERSION_OPTION ), 'Still unrecorded, so a later, safe init flushes.' );
		$this->assertSame( 10, has_action( 'admin_notices', 'blueline_core_flush_blocked_notice' ) );
		$this->assertSame( 0, $flushed[0] );
	}

	/**
	 * Deactivation clears the flag and drops stored rules only when flushing is safe.
	 */
	public function test_deactivation_clears_the_flag_and_lets_rules_rebuild_when_safe(): void {
		update_option( BLUELINE_CORE_FLUSH_OPTION, 1 );
		update_option( 'rewrite_rules', array( 'x' => 'y' ) );

		blueline_core_deactivate();
		$this->assertFalse( get_option( BLUELINE_CORE_FLUSH_OPTION ) );
		$this->assertSame( array( 'x' => 'y' ), get_option( 'rewrite_rules' ), 'Unsafe: rules untouched.' );

		$this->flush_safe( true );
		blueline_core_deactivate();
		$this->assertFalse( get_option( 'rewrite_rules' ) );
	}

	/**
	 * Uninstall deletes the plugin's options and leaves the theme's.
	 */
	public function test_uninstall_removes_only_the_plugins_own_options(): void {
		update_option( BLUELINE_CORE_FLUSH_OPTION, 1 );
		update_option( BLUELINE_CORE_VERSION_OPTION, BLUELINE_CORE_VERSION );
		update_option( 'blueline_settings', array( 'keep' => true ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'blueline-core/blueline-core.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( BLUELINE_CORE_FLUSH_OPTION ) );
		$this->assertFalse( get_option( BLUELINE_CORE_VERSION_OPTION ) );
		$this->assertSame( array( 'keep' => true ), get_option( 'blueline_settings' ) );
	}

	/**
	 * Uninstall never deletes user or post meta (sp_user, blueline_avatar_id, ...).
	 */
	public function test_uninstall_never_touches_user_or_post_meta(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin source in a unit test.

		$this->assertDoesNotMatchRegularExpression( '/delete_(user_meta|post_meta|metadata)\s*\(|\$wpdb/', $source );
	}
}
