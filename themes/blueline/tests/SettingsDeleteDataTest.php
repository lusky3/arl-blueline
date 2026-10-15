<?php
/**
 * Covers blueline_settings_delete_all_data() (inc/settings/delete-data.php) --
 * the spec's section 6.7 teardown, and the most destructive thing this theme
 * can do to its own data.
 *
 * Every claim the admin-facing copy and the WP-CLI confirmation prompt make
 * about this operation is pinned here, because "delete everything" is precisely
 * the copy nobody can afford to have be aspirational: an admin reads it once,
 * at the moment they are deciding whether to run it, and cannot check it
 * afterwards.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/snapshots.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/import.php';
require_once __DIR__ . '/../inc/settings/cache.php';
require_once __DIR__ . '/../inc/settings/delete-data.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/page.php';
require_once __DIR__ . '/../inc/sportspress/core.php'; // BLUELINE_SP_CACHE_GEN_OPTION and blueline_sp_cache_key().
require_once __DIR__ . '/../inc/occasions.php'; // The boundary-purge cron hook and its unscheduler.
require_once __DIR__ . '/../inc/updater-rules.php';
require_once __DIR__ . '/../inc/updater-client.php'; // BLUELINE_UPDATER_CACHE_KEY.

/**
 * Pins the scope and the reporting of the section 6.7 teardown.
 *
 * @package blueline
 */
final class SettingsDeleteDataTest extends TestCase {

	/**
	 * Reset the in-memory option and transient stores between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		// The handler cases below write these, and a value leaking from one
		// case into the next makes the leak look like the behaviour under
		// test: without this, the unticked-confirmation case inherited a
		// ticked box from the case before it and reported a teardown as
		// proof that an unticked box deletes everything.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture cleanup of superglobals between cases, not a real request.
		unset( $_POST['blueline_delete_confirm'], $_GET['blueline_delete_confirm'], $_REQUEST['_wpnonce'] );
		unset( $GLOBALS['wpdb'], $GLOBALS['bl_test_ext_object_cache'] );
	}

	/**
	 * Remove the fake database so it cannot leak into another test class.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['bl_test_ext_object_cache'] );
	}

	/**
	 * Install a fake `$wpdb` whose DELETE understands the two LIKE patterns the
	 * teardown sends, applied to the in-memory transient store, so the test
	 * proves the patterns match what the theme writes and nothing wider.
	 *
	 * @return void
	 */
	private function install_fake_wpdb(): void {
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Table name the real $wpdb would expose.
			 *
			 * @var string
			 */
			public $options = 'wp_options';

			/**
			 * Patterns captured by prepare(), consumed by query().
			 *
			 * @var string[]
			 */
			private $patterns = array();

			/**
			 * Real esc_like(): escapes the LIKE wildcards.
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( $text ) {
				return addcslashes( $text, '_%\\' );
			}

			/**
			 * Remember the patterns; the SQL itself is not executed.
			 *
			 * @param string $query Query.
			 * @param string ...$args Arguments.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				$this->patterns = $args;
				return $query;
			}

			/**
			 * Apply the DELETE to the transient store (value rows only; the
			 * timeout rows share the prefix and are covered by the second
			 * pattern, which this store has nothing to match against).
			 *
			 * @param string $sql SQL.
			 * @return int Rows deleted.
			 */
			public function query( $sql ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the patterns, not the SQL text, are what is exercised.
				$deleted = 0;
				$regexes = array();

				foreach ( $this->patterns as $pattern ) {
					$regex     = preg_quote( stripcslashes( rtrim( $pattern, '%' ) ), '/' );
					$regexes[] = '/^' . $regex . '/';
				}

				foreach ( array_keys( $GLOBALS['bl_test_transients'] ) as $name ) {
					foreach ( $regexes as $regex ) {
						if ( preg_match( $regex, '_transient_' . $name ) ) {
							++$deleted;
							unset( $GLOBALS['bl_test_transients'][ $name ] );
							break;
						}
					}
				}

				return $deleted;
			}
		};
	}

	/**
	 * Seed all three options plus the transient, so a test asserting they are
	 * gone is asserting a change rather than an absence that was always true.
	 *
	 * This is the premise-assertion habit the read-clamp tests established: a
	 * teardown test that seeds nothing passes just as well when the teardown
	 * does nothing at all.
	 */
	private function seed_everything(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The ARL' ) );
		update_option(
			BLUELINE_SETTINGS_SNAPSHOTS_OPTION,
			array(
				array(
					'id'       => 1,
					'settings' => array(),
				),
			)
		);
		update_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, true );
		update_option( BLUELINE_SP_CACHE_GEN_OPTION, 7 );
		set_transient( BLUELINE_SEASON_STATE_TRANSIENT, array( 'state' => 'in_season' ), 900 );
		set_site_transient( BLUELINE_UPDATER_CACHE_KEY, array( 'status' => 'ok' ), 3600 );
		set_transient( blueline_sp_cache_key( 'roster_stats', array( 12, 3 ) ), array( 1 => array() ), 900 );
		set_transient( blueline_sp_cache_key( 'table', array( 44, '', '' ) ), array( array() ), 900 );
		wp_schedule_single_event( 1796083200, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
	}

	/**
	 * Every option this theme's settings layer created is removed.
	 *
	 * @return void
	 */
	public function test_every_option_the_settings_layer_owns_is_deleted(): void {
		$this->seed_everything();

		// Premise: all three really are stored before we delete anything.
		$this->assertNotFalse( get_option( BLUELINE_SETTINGS_OPTION, false ), 'premise: settings were seeded' );
		$this->assertNotFalse( get_option( BLUELINE_SETTINGS_SNAPSHOTS_OPTION, false ), 'premise: snapshots were seeded' );
		$this->assertNotFalse( get_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, false ), 'premise: purge flag was seeded' );

		blueline_settings_delete_all_data();

		$this->assertFalse( get_option( BLUELINE_SETTINGS_OPTION, false ) );
		$this->assertFalse( get_option( BLUELINE_SETTINGS_SNAPSHOTS_OPTION, false ) );
		$this->assertFalse( get_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, false ) );
	}

	/**
	 * The snapshot history is the half an admin is most likely to assume
	 * survives, since it is the undo mechanism for everything else. The CLI
	 * prompt promises it does not.
	 */
	public function test_the_snapshot_history_goes_too(): void {
		$this->seed_everything();

		blueline_settings_delete_all_data();

		$this->assertSame( array(), blueline_settings_snapshot_list() );
	}

	/**
	 * A stale season-state cache must not outlive the settings it derived from.
	 *
	 * @return void
	 */
	public function test_the_season_state_transient_is_cleared(): void {
		$this->seed_everything();
		$this->assertNotFalse( get_transient( BLUELINE_SEASON_STATE_TRANSIENT ), 'premise: transient was seeded' );

		blueline_settings_delete_all_data();

		$this->assertFalse( get_transient( BLUELINE_SEASON_STATE_TRANSIENT ) );
	}

	/**
	 * The trap this function was written around: the ordinary purge path calls
	 * blueline_mark_cache_purge_needed(), which update_option()s
	 * BLUELINE_CACHE_PURGE_NEEDED_OPTION back into existence. A teardown that
	 * purged through it would delete three rows and leave a fourth behind.
	 */
	public function test_the_purge_does_not_recreate_the_option_it_just_deleted(): void {
		$this->seed_everything();

		blueline_settings_delete_all_data();

		$this->assertFalse(
			get_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, false ),
			'the cache-purge marker must not be written back by the purge attempt'
		);
	}

	/**
	 * The report names each row that was actually removed.
	 *
	 * @return void
	 */
	public function test_it_reports_only_what_was_actually_deleted(): void {
		$this->seed_everything();

		$result = blueline_settings_delete_all_data();

		$this->assertContains( BLUELINE_SETTINGS_OPTION, $result['deleted'] );
		$this->assertContains( BLUELINE_SETTINGS_SNAPSHOTS_OPTION, $result['deleted'] );
		$this->assertContains( BLUELINE_CACHE_PURGE_NEEDED_OPTION, $result['deleted'] );
		$this->assertContains( BLUELINE_SEASON_STATE_TRANSIENT, $result['deleted'] );
	}

	/**
	 * Running it twice must say so. `delete_option()` returns false for a row
	 * that was not there, and a report that claimed four deletions on an empty
	 * database would tell a worried admin the opposite of the truth.
	 */
	public function test_a_second_run_reports_nothing_deleted(): void {
		$this->seed_everything();
		blueline_settings_delete_all_data();

		$second = blueline_settings_delete_all_data();

		$this->assertSame( array(), $second['deleted'] );
	}

	/**
	 * A teardown on a site that stored nothing reports nothing.
	 *
	 * @return void
	 */
	public function test_nothing_stored_reports_nothing_deleted(): void {
		$result = blueline_settings_delete_all_data();

		$this->assertSame( array(), $result['deleted'] );
	}

	/**
	 * After a delete, reads fall back to defaults rather than erroring -- the
	 * difference from `reset` is what is IN the database, not how the site
	 * renders. If this failed, a teardown would take the front end down.
	 */
	public function test_settings_still_read_as_defaults_afterwards(): void {
		$this->seed_everything();

		blueline_settings_delete_all_data();

		$this->assertSame(
			blueline_settings_defaults()['footer_heading'],
			blueline_settings( 'footer_heading' )
		);
	}

	/* ------------------------------------------------- the request handler */

	/*
	 * Everything above tests blueline_settings_delete_all_data(), the pure
	 * teardown. Everything below tests the HANDLER that decides whether to call
	 * it -- which shipped with no coverage at all, while the function it guards
	 * had nine cases.
	 *
	 * That split was not a judgement about risk. The pure function was easy to
	 * test and the handler ends `wp_safe_redirect( ... ); exit;`, which could
	 * not be reached from PHPUnit until tests/bootstrap.php grew a redirect
	 * stub that throws. The untestable shape decided what got tested, and the
	 * most destructive control on the branch is what fell out.
	 */

	/**
	 * Grant the fake current user `manage_options`.
	 *
	 * @return void
	 */
	private function grant_manage_options(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;
	}

	/**
	 * Put a valid nonce and a ticked confirmation box in place.
	 *
	 * @return void
	 */
	private function seed_valid_delete_request(): void {
		$_REQUEST['_wpnonce']             = wp_create_nonce( 'blueline_settings_delete_all_data' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- seeding the token the code under test verifies.
		$_POST['blueline_delete_confirm'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture standing in for a real submission.
	}

	/**
	 * Run the handler, swallowing the redirect it ends with.
	 *
	 * @return void
	 */
	private function run_handler(): void {
		try {
			blueline_settings_handle_delete_all_data();
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			unset( $e );
		}
	}

	/**
	 * Without `manage_options`, nothing is deleted.
	 *
	 * @return void
	 */
	public function test_the_handler_refuses_without_manage_options(): void {
		$this->seed_everything();
		$this->seed_valid_delete_request();

		try {
			blueline_settings_handle_delete_all_data();
			$this->fail( 'the handler should have refused' );
		} catch ( Blueline_Test_WP_Die_Exception $e ) {
			unset( $e );
		}

		$this->assertNotFalse( get_option( BLUELINE_SETTINGS_OPTION, false ), 'nothing may be deleted without the capability' );
	}

	/**
	 * Without a valid nonce, nothing is deleted -- with the capability granted,
	 * so this cannot pass for the capability check's reason.
	 *
	 * @return void
	 */
	public function test_the_handler_refuses_without_a_valid_nonce(): void {
		$this->seed_everything();
		$this->grant_manage_options();
		$_REQUEST['_wpnonce']             = 'not-the-right-token'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- deliberately wrong, that is the point of this case.
		$_POST['blueline_delete_confirm'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture.

		try {
			blueline_settings_handle_delete_all_data();
			$this->fail( 'the handler should have refused' );
		} catch ( Blueline_Test_WP_Die_Exception $e ) {
			unset( $e );
		}

		$this->assertNotFalse( get_option( BLUELINE_SETTINGS_OPTION, false ), 'nothing may be deleted without a valid nonce' );
	}

	/**
	 * An unticked confirmation box deletes nothing and says so.
	 *
	 * @return void
	 */
	public function test_an_unticked_confirmation_deletes_nothing(): void {
		$this->seed_everything();
		$this->grant_manage_options();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'blueline_settings_delete_all_data' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- seeding the token the code under test verifies.

		$this->run_handler();

		$this->assertNotFalse( get_option( BLUELINE_SETTINGS_OPTION, false ) );
	}

	/**
	 * A confirmation value that is merely PRESENT is not enough -- it must be
	 * exactly '1'. Guards against a checkbox rendered with a different value,
	 * and against the array-shaped POST that was a real bug elsewhere on this
	 * branch.
	 *
	 * @return void
	 */
	public function test_a_confirmation_that_is_present_but_not_one_deletes_nothing(): void {
		$this->seed_everything();
		$this->grant_manage_options();
		$_REQUEST['_wpnonce']             = wp_create_nonce( 'blueline_settings_delete_all_data' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- seeding the token the code under test verifies.
		$_POST['blueline_delete_confirm'] = 'on'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture.

		$this->run_handler();

		$this->assertNotFalse( get_option( BLUELINE_SETTINGS_OPTION, false ), 'a present-but-wrong value must not count as confirmation' );
	}

	/**
	 * An array-shaped confirmation value deletes nothing.
	 *
	 * @return void
	 */
	public function test_an_array_shaped_confirmation_deletes_nothing(): void {
		$this->seed_everything();
		$this->grant_manage_options();
		$_REQUEST['_wpnonce']             = wp_create_nonce( 'blueline_settings_delete_all_data' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- seeding the token the code under test verifies.
		$_POST['blueline_delete_confirm'] = array( '1' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture.

		$this->run_handler();

		$this->assertNotFalse( get_option( BLUELINE_SETTINGS_OPTION, false ) );
	}

	/**
	 * A GET carrying a valid nonce deletes nothing.
	 *
	 * This is the case that documents WHY the confirmation is read from $_POST
	 * rather than $_REQUEST: `admin_post_{action}` fires for GET as well as
	 * POST, so a bookmarked, prefetched or link-followed
	 * admin-post.php?action=...&_wpnonce=... reaches this handler with both
	 * other guards satisfied. Reading the confirmation from $_POST is the only
	 * thing standing between that request and a teardown.
	 *
	 * @return void
	 */
	public function test_a_get_with_a_valid_nonce_deletes_nothing(): void {
		$this->seed_everything();
		$this->grant_manage_options();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'blueline_settings_delete_all_data' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- seeding the token the code under test verifies.
		// Deliberately NOT set in $_POST: this models a GET request that
		// carries the confirmation as a query argument.
		$_GET['blueline_delete_confirm'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture modelling a GET.

		$this->run_handler();

		$this->assertNotFalse(
			get_option( BLUELINE_SETTINGS_OPTION, false ),
			'a GET carrying the confirmation must not be able to trigger a teardown'
		);

		unset( $_GET['blueline_delete_confirm'] );
	}

	/**
	 * With all three guards satisfied, the teardown actually runs.
	 *
	 * Without this, every case above would pass just as well if the handler
	 * deleted nothing under any circumstances.
	 *
	 * @return void
	 */
	public function test_a_fully_confirmed_post_does_delete(): void {
		$this->seed_everything();
		$this->grant_manage_options();
		$this->seed_valid_delete_request();

		$this->run_handler();

		$this->assertFalse( get_option( BLUELINE_SETTINGS_OPTION, false ) );
		$this->assertFalse( get_option( BLUELINE_SETTINGS_SNAPSHOTS_OPTION, false ) );
	}

	/**
	 * The SportsPress data-cache generation option is autoloaded and lives in
	 * inc/sportspress/, outside the folder the constant scan used to cover.
	 *
	 * @return void
	 */
	public function test_the_sportspress_cache_generation_option_is_deleted(): void {
		$this->seed_everything();
		$this->assertSame( 7, get_option( BLUELINE_SP_CACHE_GEN_OPTION, false ), 'premise: the generation was seeded' );

		blueline_settings_delete_all_data();

		$this->assertFalse( get_option( BLUELINE_SP_CACHE_GEN_OPTION, false ) );
	}

	/**
	 * Every `bl_*` cache transient goes, and a transient that merely looks
	 * similar (another plugin's) is left alone by that sweep.
	 *
	 * @return void
	 */
	public function test_the_bl_cache_transients_are_deleted_and_lookalikes_are_not(): void {
		$this->install_fake_wpdb();
		$this->seed_everything();
		set_transient( 'blogroll_cache', 'keep', 900 );
		set_transient( 'blX_cache', 'keep', 900 );

		$roster = blueline_sp_cache_key( 'roster_stats', array( 12, 3 ) );
		$table  = blueline_sp_cache_key( 'table', array( 44, '', '' ) );
		$this->assertNotFalse( get_transient( $roster ), 'premise: the roster cache was seeded' );

		$result = blueline_settings_delete_all_data();

		$this->assertFalse( get_transient( $roster ) );
		$this->assertFalse( get_transient( $table ) );
		$this->assertSame( 'keep', get_transient( 'blogroll_cache' ), 'another plugin\'s transient must survive' );
		$this->assertSame( 'keep', get_transient( 'blX_cache' ), 'a transient that only matches if `_` were a wildcard must survive' );
		$this->assertContains( 'bl_* cache transients (2 rows)', $result['deleted'] );
	}

	/**
	 * With a persistent object cache the transients are not in the options
	 * table, so the sweep must not run SQL it cannot make sense of.
	 *
	 * @return void
	 */
	public function test_the_cache_sweep_is_skipped_with_an_external_object_cache(): void {
		$this->install_fake_wpdb();
		$GLOBALS['bl_test_ext_object_cache'] = true;
		$this->seed_everything();

		$this->assertSame( 0, blueline_settings_delete_cache_transients() );
		$this->assertNotFalse( get_transient( blueline_sp_cache_key( 'roster_stats', array( 12, 3 ) ) ) );
	}

	/**
	 * The updater's cached check result is theme-created too.
	 *
	 * @return void
	 */
	public function test_the_updater_site_transient_is_deleted(): void {
		$this->seed_everything();
		$this->assertNotFalse( get_site_transient( BLUELINE_UPDATER_CACHE_KEY ), 'premise: the updater state was seeded' );

		$result = blueline_settings_delete_all_data();

		$this->assertFalse( get_site_transient( BLUELINE_UPDATER_CACHE_KEY ) );
		$this->assertContains( BLUELINE_UPDATER_CACHE_KEY, $result['deleted'] );
	}

	/**
	 * A leftover boundary-purge event would fire into a theme that may no
	 * longer be active, and must not be re-created by the teardown itself.
	 *
	 * @return void
	 */
	public function test_the_scheduled_boundary_purge_is_unscheduled(): void {
		$this->seed_everything();
		$this->assertNotFalse( wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ), 'premise: the event was scheduled' );

		$result = blueline_settings_delete_all_data();

		$this->assertFalse( wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ) );
		$this->assertContains( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK, $result['deleted'] );
	}

	/**
	 * Enumerates every storage write the theme makes -- options, transients,
	 * site transients, cron events and user meta -- and fails on any whose
	 * target is not accounted for here.
	 *
	 * The constant scan below only sees `const BLUELINE_*OPTION` names; a
	 * transient, cron hook or option written from a variable is invisible to
	 * it. This reads the write calls themselves. A new write therefore has to be
	 * either deleted by "Delete all Blueline data" (and listed in $deleted) or
	 * exempted below with a reason, instead of being forgotten.
	 *
	 * @return void
	 */
	public function test_every_storage_write_the_theme_makes_is_accounted_for(): void {
		$deleted = array(
			'BLUELINE_SETTINGS_OPTION'              => 'deleted option',
			'BLUELINE_SETTINGS_SNAPSHOTS_OPTION'    => 'deleted option',
			'BLUELINE_CACHE_PURGE_NEEDED_OPTION'    => 'deleted option',
			'BLUELINE_SP_CACHE_GEN_OPTION'          => 'deleted option',
			'BLUELINE_SEASON_STATE_TRANSIENT'       => 'deleted transient',
			'BLUELINE_UPDATER_CACHE_KEY'            => 'deleted site transient',
			'BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK' => 'unscheduled cron hook',
			'$key'                                  => 'blueline_sp_cache_key() transient, swept by the bl_ prefix',
			'$bl_table_cache_key'                   => 'blueline_sp_cache_key() transient, swept by the bl_ prefix',
		);

		$exempt = array(
			"'settings_errors'"                  => 'WordPress core\'s own 30-second admin-notice transient, not ours',
			'BLUELINE_THEME_PREFERENCE_META_KEY' => 'a member\'s own light/dark choice, kept on purpose and stated in the panel copy',
		);

		$root  = realpath( __DIR__ . '/..' );
		$files = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( (string) $root, FilesystemIterator::SKIP_DOTS ),
				static function ( SplFileInfo $file ): bool {
					return ! in_array( $file->getFilename(), array( 'vendor', 'node_modules', 'tests', '.wp-core-oracle', 'assets', '.git' ), true );
				}
			)
		);

		$found = array();

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file in a test; there is no WP filesystem API in this bare PHPUnit bootstrap.
			// Strip block comments and whole-line // comments so a docblock that names a function is not a call.
			$source = (string) preg_replace( '~/\*.*?\*/~s', '', $source );
			$source = (string) preg_replace( '~^\s*//[^\n]*$~m', '', $source );

			// Option and transient writes name their target first.
			if ( preg_match_all( '/\b(?:update_option|add_option|set_transient|set_site_transient)\(\s*([^,()]+?)\s*,/', $source, $m ) ) {
				foreach ( $m[1] as $target ) {
					$found[ $target ] = basename( $file->getPathname() );
				}
			}

			// User meta names the user first and the key second.
			if ( preg_match_all( '/\b(?:update_user_meta|add_user_meta)\(\s*[^,]+,\s*([^,()]+?)\s*,/', $source, $m ) ) {
				foreach ( $m[1] as $target ) {
					$found[ $target ] = basename( $file->getPathname() );
				}
			}

			// A cron schedule names the hook second.
			if ( preg_match_all( '/\bwp_schedule_(?:single_)?event\(\s*[^,]+,\s*([^,()]+?)\s*[,)]/', $source, $m ) ) {
				foreach ( $m[1] as $target ) {
					$found[ $target ] = basename( $file->getPathname() );
				}
			}
		}

		// Premise: the scan saw the writes at all.
		$this->assertArrayHasKey( 'BLUELINE_SETTINGS_OPTION', $found, 'premise: the scan found the settings write' );
		$this->assertArrayHasKey( 'BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK', $found, 'premise: the scan found the cron schedule' );
		$this->assertArrayHasKey( 'BLUELINE_UPDATER_CACHE_KEY', $found, 'premise: the scan found the site transient write' );

		foreach ( $found as $target => $file ) {
			$this->assertTrue(
				isset( $deleted[ $target ] ) || isset( $exempt[ $target ] ),
				sprintf(
					'%s writes %s, which "Delete all Blueline data" does not account for. Delete it in blueline_settings_delete_all_data(), then list it in $deleted here, or exempt it in $exempt with a reason.',
					$file,
					$target
				)
			);
		}
	}

	/**
	 * Pins the list itself, so a change to it is a deliberate edit here too.
	 *
	 * @return void
	 */
	public function test_the_deletable_option_list_is_exactly_the_settings_layer_options(): void {
		$this->assertSame(
			array(
				BLUELINE_SETTINGS_OPTION,
				BLUELINE_SETTINGS_SNAPSHOTS_OPTION,
				BLUELINE_CACHE_PURGE_NEEDED_OPTION,
				BLUELINE_SP_CACHE_GEN_OPTION,
			),
			blueline_settings_deletable_options()
		);
	}

	/**
	 * Discovers option-name constants anywhere in inc/ and fails if any is
	 * missing from the teardown list.
	 *
	 * The list-pinning test above CANNOT catch the failure that actually
	 * matters. Declaring a fourth option in the settings layer and
	 * forgetting to add it here leaves that test perfectly green, because it
	 * only compares the list to itself. It does not
	 * "fail if that line is forgotten".
	 *
	 * So this scan is the thing that fails when someone forgets. An option that genuinely should
	 * survive a teardown becomes an explicit exemption with a reason, instead
	 * of an oversight nobody sees.
	 *
	 * @return void
	 */
	public function test_every_settings_layer_option_constant_is_covered(): void {
		$declared = array();

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../inc', FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file in a test to scan it for constant declarations; wp_remote_get() is for remote URLs and there is no WP filesystem API in this bare PHPUnit bootstrap.

			if ( preg_match_all( '/^const\s+(BLUELINE_\w*OPTION)\s*=/m', $source, $matches ) ) {
				foreach ( $matches[1] as $name ) {
					$declared[ $name ] = basename( $file->getPathname() );
				}
			}
		}

		// Premise: the scan found the constants at all. Without this, a regex
		// that quietly stopped matching would make the whole test pass over an
		// empty list -- the exact shape of vacuity it exists to prevent.
		$this->assertGreaterThanOrEqual(
			4,
			count( $declared ),
			'premise: the scan should find at least the four known option constants'
		);

		$covered = blueline_settings_deletable_options();

		foreach ( $declared as $name => $file ) {
			$this->assertContains(
				constant( $name ),
				$covered,
				sprintf(
					'%s (declared in %s) is not deleted by "Delete all Blueline data". Add it to blueline_settings_deletable_options(), or exempt it here with a reason.',
					$name,
					$file
				)
			);
		}
	}
}
