<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Covers `wp blueline settings flush-cache` (inc/cli/settings-command.php)
 * -- the subcommand the design spec's 6.9 list named and the panel work
 * never added.
 *
 * ## Why the shipped path reports failure, and why that is correct
 *
 * inc/settings/cache.php's guarded Redis/nginx srcache purge is gated
 * behind `BLUELINE_SRCACHE_PURGE`, which defaults to FALSE for the reasons
 * that file's own docblock sets out at length (nobody has confirmed the
 * object cache and nginx's srcache share a Redis database, and staging has
 * no page-cache layer to confirm it on). This subcommand does not override
 * that gate -- it runs the same blueline_apply_cache_purge_policy() every
 * settings save runs, and then reports what that policy actually did.
 *
 * On a default install that means: nothing was purged, the manual-purge
 * flag stays set, the exact manual command is printed, and the command
 * exits non-zero. A `flush-cache` that printed "Success." while purging
 * nothing would be the same class of untrue admin-facing claim this
 * project keeps finding.
 *
 * ## The subcommand's name is pinned, and here is why that needs a test
 *
 * WP-CLI derives a subcommand's name from the `@subcommand` docblock tag
 * if present and otherwise from the method name VERBATIM -- it does not
 * convert `flush_cache` to `flush-cache`. Verified directly against the
 * WP-CLI actually installed on this machine (read out of the phar):
 * `WP_CLI\Dispatcher\CommandFactory::create_subcommand()` takes
 * `$docparser->get_tag( 'subcommand' )` first and falls back to
 * `$reflection->name`, and `WP_CLI\DocParser::get_tag()` matches
 * `^@subcommand\s+([a-z-_0-9]+)` against the docblock after its leading
 * ` * ` decorations are stripped. Every other subcommand in this file is a
 * single word, so this never came up before;
 * test_the_subcommand_is_named_flush_cache_not_flush_underscore_cache()
 * pins it so a future edit that drops the tag cannot silently rename the
 * command to `flush_cache`.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/cli-stubs.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/snapshots.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/page.php';
require_once __DIR__ . '/../inc/settings/cache.php';

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

require_once __DIR__ . '/../inc/cli/settings-command.php';

/**
 * See this file's own docblock.
 */
final class SettingsCliFlushCacheTest extends TestCase {

	/**
	 * Reset every in-memory store, the fake user's capabilities, the CLI
	 * log, and the object-cache global this file's own fakes populate.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_cli_log'] = array();

		global $wp_object_cache;
		$wp_object_cache = null; // phpcs:ignore WordPress.Variables.GlobalVariables.OverrideProhibited -- this IS the global the Redis Object Cache drop-in populates in production; a test must control it directly to exercise the purge's guards.
	}

	/**
	 * Grant the fake current user `manage_options`.
	 */
	private function grant_manage_options(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;
	}

	/**
	 * Every WP_CLI call recorded, in order.
	 *
	 * @return array<int, array{type: string, message: string}>
	 */
	private function cli_log(): array {
		return $GLOBALS['bl_test_cli_log'];
	}

	/**
	 * Messages of one recorded type, in order.
	 *
	 * @param string $type 'log', 'success', 'warning' or 'error'.
	 * @return string[]
	 */
	private function messages_of_type( string $type ): array {
		$found = array();
		foreach ( $this->cli_log() as $entry ) {
			if ( $entry['type'] === $type ) {
				$found[] = $entry['message'];
			}
		}
		return $found;
	}

	/**
	 * Same gate as `import`, `repair` and `reset`: an unattended `wp`
	 * invocation with no `--user` cannot run this.
	 */
	public function test_refuses_without_manage_options(): void {
		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		( new Blueline_Settings_Command() )->flush_cache( array(), array() );
	}

	/**
	 * The shipped default (BLUELINE_SRCACHE_PURGE off): nothing is purged,
	 * so the command says so, prints the exact command to run instead, and
	 * exits non-zero rather than reporting a success it did not achieve.
	 */
	public function test_reports_failure_and_prints_the_manual_command_when_the_purge_is_off(): void {
		$this->grant_manage_options();

		$threw = false;
		try {
			( new Blueline_Settings_Command() )->flush_cache( array(), array() );
		} catch ( Blueline_Test_Cli_Exit_Exception $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'the command must exit non-zero when nothing was purged' );

		$this->assertNotEmpty( $this->messages_of_type( 'warning' ) );
		$this->assertSame( array(), $this->messages_of_type( 'success' ), 'nothing was purged, so nothing may report success' );

		$expected_command = blueline_cache_purge_command( blueline_cache_purge_host() );
		$this->assertNotSame( '', $expected_command, 'the test host must resolve, or this case proves nothing' );
		$this->assertContains( $expected_command, $this->messages_of_type( 'log' ) );
	}

	/**
	 * ...and it leaves the manual-purge flag set, so the wp-admin notice
	 * inc/settings/cache.php renders keeps telling an admin the front end
	 * may be stale.
	 */
	public function test_leaves_the_manual_purge_flag_set_when_nothing_was_purged(): void {
		$this->grant_manage_options();

		try {
			( new Blueline_Settings_Command() )->flush_cache( array(), array() );
		} catch ( Blueline_Test_Cli_Exit_Exception $e ) {
			unset( $e );
		}

		$this->assertTrue( blueline_cache_purge_needed() );
	}

	/**
	 * The seam the subcommand reports from, exercised with the purge
	 * switched ON and a Redis-shaped object cache in place: the real
	 * purge runs, the manual-purge flag is cleared, and the helper reports
	 * true -- which is the condition the subcommand's own success branch
	 * is written against.
	 *
	 * Called directly rather than through the subcommand because
	 * BLUELINE_SRCACHE_PURGE is a constant defined at require time and
	 * cannot be redefined mid-process; this is the same seam
	 * tests/SettingsCacheTest.php uses to reach the enabled branch.
	 */
	public function test_the_enabled_branch_purges_and_clears_the_flag(): void {
		blueline_mark_cache_purge_needed();
		$this->assertTrue( blueline_cache_purge_needed(), 'premise: a purge is pending before this runs' );

		$redis = new class() {
			/**
			 * Key batches handed to unlink().
			 *
			 * @var array<int, string[]>
			 */
			public array $unlinked = array();

			/**
			 * One SCAN cycle: hands back a single batch, then converges.
			 *
			 * @param int    $cursor  Cursor, by reference in phpredis.
			 * @param string $pattern Match pattern.
			 * @param int    $count   Batch hint.
			 * @return string[]
			 */
			public function scan( &$cursor, $pattern, $count ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with phpredis' own SCAN; this fake ignores the hint.
				$cursor = 0;
				return array( 'nginx-cache:' . $pattern . ':1' );
			}

			/**
			 * Record an UNLINK.
			 *
			 * @param string[] $keys Keys to unlink.
			 * @return int
			 */
			public function unlink( $keys ) {
				$this->unlinked[] = $keys;
				return count( $keys );
			}
		};

		global $wp_object_cache;
		$wp_object_cache = new class( $redis ) { // phpcs:ignore WordPress.Variables.GlobalVariables.OverrideProhibited -- see setUp().
			/**
			 * The fake client redis_instance() hands back.
			 *
			 * @var object
			 */
			private object $redis;

			/**
			 * Constructor.
			 *
			 * @param object $redis Fake client.
			 */
			public function __construct( object $redis ) {
				$this->redis = $redis;
			}

			/**
			 * The one method blueline_srcache_purge_attempt() calls.
			 *
			 * @return object
			 */
			public function redis_instance() {
				return $this->redis;
			}
		};

		$this->assertTrue( blueline_settings_cli_flush_page_cache( true ) );
		$this->assertFalse( blueline_cache_purge_needed() );
		$this->assertNotEmpty( $redis->unlinked, 'the real purge must actually have run' );
	}

	/**
	 * WP-CLI names a subcommand from `@subcommand` or, failing that, from
	 * the method name verbatim -- see this file's own docblock for where
	 * that was verified. Without the tag this would ship as
	 * `wp blueline settings flush_cache`, which is not what the spec lists
	 * and not what anyone would type.
	 */
	public function test_the_subcommand_is_named_flush_cache_not_flush_underscore_cache(): void {
		$doc = ( new ReflectionMethod( Blueline_Settings_Command::class, 'flush_cache' ) )->getDocComment();

		$this->assertIsString( $doc );
		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*@subcommand\s+flush-cache\s*$/m',
			$doc,
			'the method must carry an explicit @subcommand tag; WP-CLI does not convert underscores to dashes'
		);
	}
}
