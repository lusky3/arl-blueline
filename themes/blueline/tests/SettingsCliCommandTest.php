<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/cli-stubs.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/snapshots.php'; // blueline_settings_diff(), which `import --dry-run` builds its preview from.
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/page.php';

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

require_once __DIR__ . '/../inc/cli/settings-command.php';

/**
 * Covers `wp blueline settings export|import|validate|repair|reset`
 * (inc/cli/settings-command.php) -- P1a's Task 10, Part A, plus the
 * `repair` subcommand P1b's Task 9 added.
 *
 * Three things this file exists to prove, each directly requested by the
 * task brief rather than assumed:
 *
 * - test_sanitize_option_filter_is_registered_at_file_scope_before_any_command_runs()
 *   proves (does not assume) that `import`/`reset`'s update_option() calls
 *   run through the SAME sanitize_option_{$option} callback the panel's own
 *   save request uses, before any command-specific test relies on that
 *   being true.
 * - test_import_rejects_a_payload_whose_schema_is_newer_than_the_codes()
 *   proves the forward-only guard: a newer `_schema` must fail loudly, not
 *   silently downgrade.
 * - test_export_then_import_round_trips_settings_exactly() proves the two
 *   subcommands actually agree on a shape, not just independently look
 *   correct.
 */
final class SettingsCliCommandTest extends TestCase {

	/**
	 * Temp files created by a test, cleaned up in tearDown() regardless of
	 * pass/fail.
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	/**
	 * Reset every in-memory store, the fake current user's capabilities,
	 * and this test's own CLI log before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_cli_log'] = array();
		// Deliberately NOT resetting $GLOBALS['bl_test_cli_commands'] here:
		// WP_CLI::add_command() is called once, at file-scope, when this
		// file's own require_once (top of file) first loads
		// inc/cli/settings-command.php -- long before any test's setUp()
		// runs. Wiping it per-test would erase that one real registration
		// and make test_settings_command_is_registered_under_blueline_settings()
		// fail for every test after the first, exactly the trap
		// tests/bootstrap.php's own blueline_test_reset_hooks() docblock
		// describes for the identical reason.
		$this->temp_files = array();
	}

	/**
	 * Grant the in-memory current user the `manage_options` capability --
	 * the one `import`/`reset` require.
	 */
	private function grant_manage_options(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;
	}

	/**
	 * Delete every temp file this test created, regardless of pass/fail.
	 */
	protected function tearDown(): void {
		foreach ( $this->temp_files as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Write $data as JSON to a fresh temp file, tracked for cleanup.
	 *
	 * @param array<string, mixed> $data Data to encode.
	 * @return string Path to a temp file containing $data as JSON.
	 */
	private function write_temp_json( array $data ): string {
		$path               = sys_get_temp_dir() . '/blueline-cli-test-' . uniqid( '', true ) . '.json';
		$this->temp_files[] = $path;
		file_put_contents( $path, (string) wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture in the system temp dir, not theme runtime code.
		return $path;
	}

	/**
	 * Every message WP_CLI::error()/success()/warning()/log() recorded, in
	 * call order.
	 *
	 * @return array<int, array{type: string, message: string}>
	 */
	private function cli_log(): array {
		return $GLOBALS['bl_test_cli_log'];
	}

	/**
	 * The command's own file-scope WP_CLI::add_command() call actually ran
	 * when this file was required, registering the real subcommand group --
	 * not merely that the class exists.
	 */
	public function test_settings_command_is_registered_under_blueline_settings(): void {
		$this->assertSame(
			'Blueline_Settings_Command',
			$GLOBALS['bl_test_cli_commands']['blueline settings'] ?? null
		);
	}

	/**
	 * The load-bearing claim inc/cli/settings-command.php's own docblock
	 * makes rather than assumes: "sanitize_option_blueline_settings is
	 * registered at file scope, so any update_option() traverses it
	 * automatically". Proven directly against the real filter store, before
	 * any command-specific test below relies on it being true.
	 */
	public function test_sanitize_option_filter_is_registered_at_file_scope_before_any_command_runs(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'not-an-email' ) );

		$stored = get_option( BLUELINE_SETTINGS_OPTION );

		// blueline_settings_defaults()' own contact_email default, proving
		// the invalid value was rejected by the sanitizer rather than
		// stored verbatim -- which could only happen if
		// sanitize_option_blueline_settings actually ran.
		$this->assertSame( 'play@rookiehockey.ca', $stored['contact_email'] );
	}

	/**
	 * `export` prints the current settings, plus the stored `_schema`,
	 * as valid JSON that decodes back to an equivalent array.
	 */
	public function test_export_prints_current_settings_including_schema_version(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'league@example.test' ) );
		blueline_settings_migrate();

		( new Blueline_Settings_Command() )->export( array(), array() );

		$log = $this->cli_log();
		$this->assertCount( 1, $log );
		$this->assertSame( 'log', $log[0]['type'] );

		$decoded = json_decode( $log[0]['message'], true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'league@example.test', $decoded['contact_email'] );
		$this->assertSame( BLUELINE_SETTINGS_SCHEMA_VERSION, $decoded['_schema'] );
	}

	/**
	 * `export --file=<path>` writes the same JSON to disk instead of stdout.
	 */
	public function test_export_with_file_flag_writes_to_disk_instead_of_stdout(): void {
		$path               = sys_get_temp_dir() . '/blueline-cli-test-' . uniqid( '', true ) . '.json';
		$this->temp_files[] = $path;

		( new Blueline_Settings_Command() )->export( array(), array( 'file' => $path ) );

		$this->assertFileExists( $path );
		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back a test fixture this same test just wrote.
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'contact_email', $decoded );

		$log = $this->cli_log();
		$this->assertSame( 'success', $log[0]['type'] );
	}

	/**
	 * A full export -> import round trip preserves every setting exactly --
	 * the two subcommands must agree on a shape, not just independently
	 * look correct in isolation.
	 */
	public function test_export_then_import_round_trips_settings_exactly(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'contact_email'  => 'roundtrip@example.test',
				'footer_heading' => 'Custom Footer Heading',
				'page_schedule'  => 42,
			)
		);
		$before = blueline_settings();

		$path               = sys_get_temp_dir() . '/blueline-cli-test-' . uniqid( '', true ) . '.json';
		$this->temp_files[] = $path;
		( new Blueline_Settings_Command() )->export( array(), array( 'file' => $path ) );

		// Wipe the option, proving import (not a leftover value) restores it.
		blueline_test_reset_options();

		$this->grant_manage_options();
		( new Blueline_Settings_Command() )->import( array( $path ), array() );

		$this->assertSame( $before, blueline_settings() );

		$errors = array_filter( $this->cli_log(), static fn( $e ) => 'error' === $e['type'] );
		$this->assertSame( array(), array_values( $errors ) );
	}

	/**
	 * `import` requires `manage_options` -- the same capability the panel
	 * itself requires. Without it, nothing is written at all.
	 */
	public function test_import_refuses_without_manage_options_capability(): void {
		$path = $this->write_temp_json( array( 'contact_email' => 'someone@example.test' ) );

		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		try {
			( new Blueline_Settings_Command() )->import( array( $path ), array() );
		} finally {
			// Whatever happens, the option must never have been written.
			$this->assertSame( false, get_option( BLUELINE_SETTINGS_OPTION, false ) );
		}
	}

	/**
	 * The forward-only guard: a file whose own `_schema` exceeds
	 * BLUELINE_SETTINGS_SCHEMA_VERSION must be refused outright, and nothing
	 * written -- mirrors inc/settings/store.php's blueline_settings_migrate()
	 * refusing to downgrade.
	 */
	public function test_import_rejects_a_payload_whose_schema_is_newer_than_the_codes(): void {
		$this->grant_manage_options();

		$path = $this->write_temp_json(
			array(
				'contact_email' => 'from-the-future@example.test',
				'_schema'       => BLUELINE_SETTINGS_SCHEMA_VERSION + 1,
			)
		);

		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		try {
			( new Blueline_Settings_Command() )->import( array( $path ), array() );
		} finally {
			$this->assertSame( false, get_option( BLUELINE_SETTINGS_OPTION, false ) );
		}
	}

	/**
	 * A key the schema does not declare is dropped, never stored -- the
	 * allow-list model Task 7 restored, not reintroduced as "forward
	 * anything unrecognised" by this new write path.
	 */
	public function test_import_drops_unknown_keys_rather_than_storing_them(): void {
		$this->grant_manage_options();

		$path = $this->write_temp_json(
			array(
				'contact_email'     => 'known@example.test',
				'some_future_field' => 'should never be stored',
			)
		);

		( new Blueline_Settings_Command() )->import( array( $path ), array() );

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertArrayNotHasKey( 'some_future_field', $stored );
		$this->assertSame( 'known@example.test', $stored['contact_email'] );
	}

	/**
	 * The load-bearing fix for the review's Finding 1: a dropped, unknown
	 * key must not be a SILENT drop -- the operator is named the exact key
	 * that was ignored, via WP_CLI::warning(), even though the command
	 * still reports overall success (a dropped key is not itself evidence
	 * of a broken import -- see inc/cli/settings-command.php's own
	 * docblock for the full reasoning).
	 */
	public function test_import_warns_by_name_about_a_dropped_key_but_still_reports_success(): void {
		$this->grant_manage_options();

		$path = $this->write_temp_json(
			array(
				'contact_email'     => 'known@example.test',
				'some_future_field' => 'should never be stored',
			)
		);

		( new Blueline_Settings_Command() )->import( array( $path ), array() );

		$warnings = array_column(
			array_filter( $this->cli_log(), static fn( $e ) => 'warning' === $e['type'] ),
			'message'
		);
		$this->assertNotEmpty(
			array_filter( $warnings, static fn( $m ) => str_contains( $m, 'some_future_field' ) ),
			'expected a warning naming the dropped key "some_future_field"; got: ' . implode( ' | ', $warnings )
		);

		$successes = array_filter( $this->cli_log(), static fn( $e ) => 'success' === $e['type'] );
		$this->assertNotEmpty( $successes, 'a dropped-but-unrecognised key should not itself make import fail' );

		$errors = array_filter( $this->cli_log(), static fn( $e ) => 'error' === $e['type'] );
		$this->assertSame( array(), array_values( $errors ), 'a dropped key must not make import exit non-zero' );
	}

	/**
	 * `validate` (the dry-run preview of `import`) must warn about the same
	 * dropped key `import` itself would -- it would be worse than useless
	 * for a preview to stay silent about something the real import now
	 * reports.
	 */
	public function test_validate_also_warns_by_name_about_a_dropped_key(): void {
		$path = $this->write_temp_json(
			array(
				'contact_email'     => 'known@example.test',
				'some_future_field' => 'should never be imported',
			)
		);

		( new Blueline_Settings_Command() )->validate( array( $path ), array() );

		$warnings = array_column(
			array_filter( $this->cli_log(), static fn( $e ) => 'warning' === $e['type'] ),
			'message'
		);
		$this->assertNotEmpty(
			array_filter( $warnings, static fn( $m ) => str_contains( $m, 'some_future_field' ) ),
			'expected a warning naming the dropped key "some_future_field"; got: ' . implode( ' | ', $warnings )
		);
		$this->assertSame( false, get_option( BLUELINE_SETTINGS_OPTION, false ), 'validate must never write' );
	}

	/**
	 * A value that violates the placeholder contract (Task 3/8) is rejected
	 * by the SAME sanitizer the panel uses -- the existing value survives,
	 * a warning is surfaced, and the command still reports success for the
	 * rest of the file (partial success, exactly like a panel save).
	 */
	public function test_import_rejects_a_value_violating_the_placeholder_contract(): void {
		$this->grant_manage_options();

		$path = $this->write_temp_json(
			array(
				// hero_offseason_headline requires exactly one %s -- a bare
				// "%" here is the stray-percent case Task 3 exists to catch.
				'hero_offseason_headline' => 'Back on the ice 50% sooner.',
			)
		);

		( new Blueline_Settings_Command() )->import( array( $path ), array() );

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		// The rejected value never overwrote the (default) stored value.
		$this->assertSame( blueline_settings_defaults()['hero_offseason_headline'], $stored['hero_offseason_headline'] ?? null );

		$warnings = array_filter( $this->cli_log(), static fn( $e ) => 'warning' === $e['type'] );
		$this->assertNotEmpty( $warnings );

		$successes = array_filter( $this->cli_log(), static fn( $e ) => 'success' === $e['type'] );
		$this->assertNotEmpty( $successes, 'import should still report overall success even when one field was rejected' );
	}

	/**
	 * `_posted_fields`/`_tab` are request-scoped bookkeeping a file must
	 * never carry weight for -- present in a file, they are stripped before
	 * the sanitizer ever runs and never persisted.
	 *
	 * Seeds the option with an unrelated prior write first so the import's
	 * own update_option() call is not the option's first-ever write: core's
	 * real update_option() delegates a first-ever write to add_option(),
	 * which independently re-applies sanitize_option_{$option} to a value
	 * that has already been through the merge once (and so no longer has
	 * `_posted_fields` in it) -- re-adding an EMPTY `_posted_fields` array
	 * that nothing then strips a second time, since add_option()'s own
	 * write path has no merge stage at all
	 * (BootstrapFidelityTest::test_update_option_first_write_sanitizes_twice_but_merges_once()
	 * pins this exact core quirk in isolation). That is a real, if
	 * cosmetic and self-healing, fact about every WordPress option -- not
	 * something this test's OWN CLI-specific stripping logic could ever
	 * prevent -- so this test seeds a normal, steady-state write first to
	 * isolate the behaviour it actually exists to cover.
	 */
	public function test_import_never_honours_posted_fields_or_tab_from_a_file(): void {
		$this->grant_manage_options();
		update_option( BLUELINE_SETTINGS_OPTION, array() ); // Not the first-ever write -- see this test's own docblock.

		$path = $this->write_temp_json(
			array(
				'contact_email'  => 'safe@example.test',
				'_posted_fields' => array( 'contact_email', 'footer_heading' ),
				'_tab'           => 'content',
			)
		);

		( new Blueline_Settings_Command() )->import( array( $path ), array() );

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertArrayNotHasKey( '_posted_fields', $stored );
		$this->assertArrayNotHasKey( '_tab', $stored );
	}

	/**
	 * `validate` reports the exact same rejection a real import would,
	 * WITHOUT writing anything -- a pure dry run.
	 */
	public function test_validate_reports_errors_without_writing_anything(): void {
		$path = $this->write_temp_json(
			array( 'contact_email' => 'not-an-email-at-all' )
		);

		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		try {
			( new Blueline_Settings_Command() )->validate( array( $path ), array() );
		} finally {
			$this->assertSame( false, get_option( BLUELINE_SETTINGS_OPTION, false ), 'validate must never write' );
			$warnings = array_filter( $this->cli_log(), static fn( $e ) => 'warning' === $e['type'] );
			$this->assertNotEmpty( $warnings );
		}
	}

	/**
	 * `validate` reports success for a file with nothing wrong, still
	 * without writing.
	 */
	public function test_validate_succeeds_for_a_clean_file(): void {
		$path = $this->write_temp_json( array( 'contact_email' => 'clean@example.test' ) );

		( new Blueline_Settings_Command() )->validate( array( $path ), array() );

		$this->assertSame( false, get_option( BLUELINE_SETTINGS_OPTION, false ) );
		$successes = array_filter( $this->cli_log(), static fn( $e ) => 'success' === $e['type'] );
		$this->assertNotEmpty( $successes );
	}

	/**
	 * `reset` requires manage_options AND --yes; without either, nothing is
	 * written.
	 */
	public function test_reset_refuses_without_manage_options(): void {
		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		try {
			( new Blueline_Settings_Command() )->reset( array(), array( 'yes' => true ) );
		} finally {
			$this->assertSame( false, get_option( BLUELINE_SETTINGS_OPTION, false ) );
		}
	}

	/**
	 * `reset` without --yes refuses even with the right capability.
	 */
	public function test_reset_refuses_without_yes_confirmation(): void {
		$this->grant_manage_options();

		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		try {
			( new Blueline_Settings_Command() )->reset( array(), array() );
		} finally {
			$this->assertSame( false, get_option( BLUELINE_SETTINGS_OPTION, false ) );
		}
	}

	/**
	 * `reset --yes` (with the capability) overwrites every field with its
	 * default.
	 */
	public function test_reset_restores_defaults_when_confirmed(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'custom@example.test' ) );
		$this->grant_manage_options();

		( new Blueline_Settings_Command() )->reset( array(), array( 'yes' => true ) );

		$this->assertSame( blueline_settings_defaults(), blueline_settings() );
	}

	/**
	 * Every message the CLI recorded, joined -- for asserting that a
	 * particular fact reached the operator, without pinning which call
	 * carried it.
	 *
	 * @return string
	 */
	private function cli_output(): string {
		return implode( "\n", array_column( $this->cli_log(), 'message' ) );
	}

	/**
	 * `import --dry-run` writes NOTHING and shows what would change.
	 */
	public function test_import_dry_run_previews_a_change_without_writing(): void {
		$this->grant_manage_options();
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );

		$path = $this->write_temp_json( array( 'footer_heading' => 'The ARL' ) );

		( new Blueline_Settings_Command() )->import( array( $path ), array( 'dry-run' => true ) );

		$this->assertSame( 'The League', blueline_settings( 'footer_heading' ), 'a dry run must not write' );

		$output = $this->cli_output();
		$this->assertStringContainsString( 'footer_heading', $output );
		$this->assertStringContainsString( 'The ARL', $output );
	}

	/**
	 * THE ONE THAT MATTERS. An import payload that OMITS a key does not
	 * reset that key -- blueline_settings_merge() carries the stored value
	 * forward. A preview listing only differing keys would read as "these
	 * are the only differences" while every omitted key sat invisible in
	 * it, so the preview must name the omitted key AND the value that will
	 * survive.
	 */
	public function test_import_dry_run_surfaces_a_key_the_file_omits_with_its_carried_forward_value(): void {
		$this->grant_manage_options();
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_location' => 'Hamilton, Ontario' ) );

		$this->assertNotSame(
			blueline_settings_defaults()['footer_location'],
			blueline_settings( 'footer_location' ),
			'the fixture only proves anything if the stored value is not the default'
		);

		$path = $this->write_temp_json( array( 'footer_heading' => 'The ARL' ) );

		( new Blueline_Settings_Command() )->import( array( $path ), array( 'dry-run' => true ) );

		$lines = array_filter(
			explode( "\n", $this->cli_output() ),
			static fn( $line ) => str_contains( $line, 'footer_location' )
		);

		$this->assertNotEmpty( $lines, 'a key the file omits must still appear in the preview' );

		$line = implode( "\n", $lines );
		$this->assertStringContainsString( 'Hamilton, Ontario', $line, 'with its carried-forward value' );
		$this->assertStringContainsString( 'kept', $line, 'and said to be kept, not reset' );
	}

	/**
	 * The preview must describe what would be STORED, not what is in the
	 * file. Those differ whenever the sanitizer normalises a value:
	 * sanitize_text_field() trims, so a payload of "  The ARL  " against a
	 * stored "The ARL" imports as a change to nothing at all.
	 *
	 * Previewing the raw payload instead reports a `changed` line and a
	 * `to` value that will never exist in the database -- the preview being
	 * wrong about the one thing it exists to be right about.
	 */
	public function test_import_dry_run_previews_the_value_that_would_be_stored_not_the_raw_payload(): void {
		$this->grant_manage_options();
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The ARL' ) );

		$path = $this->write_temp_json( array( 'footer_heading' => '  The ARL  ' ) );

		( new Blueline_Settings_Command() )->import( array( $path ), array( 'dry-run' => true ) );

		$line = implode(
			"\n",
			array_filter(
				explode( "\n", $this->cli_output() ),
				static fn( $candidate ) => str_contains( $candidate, 'footer_heading' )
			)
		);

		$this->assertStringContainsString( 'unchanged', $line );
		$this->assertStringNotContainsString( '  The ARL  ', $line, 'the untrimmed value is never what gets stored' );

		// And the claim the preview makes is the one the real import keeps.
		( new Blueline_Settings_Command() )->import( array( $path ), array() );
		$this->assertSame( 'The ARL', blueline_settings( 'footer_heading' ) );
	}

	/**
	 * A dry run's unrecognised-key warning must speak in the conditional:
	 * saying a key "was not imported" when nothing was imported at all is
	 * exactly the kind of claim-more-than-the-code-does copy this settings
	 * layer keeps being bitten by.
	 */
	public function test_import_dry_run_says_a_dropped_key_would_not_be_imported(): void {
		$this->grant_manage_options();

		$path = $this->write_temp_json( array( 'not_a_real_setting' => 'x' ) );

		( new Blueline_Settings_Command() )->import( array( $path ), array( 'dry-run' => true ) );

		$output = $this->cli_output();
		$this->assertStringContainsString( 'would not be imported', $output );
		$this->assertStringNotContainsString( 'was not imported', $output );
	}

	/**
	 * A dry run reports a field that would be REJECTED, rather than
	 * previewing a value that is never going to be written.
	 */
	public function test_import_dry_run_reports_an_invalid_field(): void {
		$this->grant_manage_options();

		$path = $this->write_temp_json( array( 'contact_email' => 'not-an-email-at-all' ) );

		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		try {
			( new Blueline_Settings_Command() )->import( array( $path ), array( 'dry-run' => true ) );
		} finally {
			$this->assertSame( false, get_option( BLUELINE_SETTINGS_OPTION, false ) );
		}
	}

	/**
	 * Put a value into the option store WITHOUT going through
	 * update_option() -- so neither blueline_settings_sanitize_callback()
	 * nor blueline_settings_merge() ever sees it.
	 *
	 * That bypass is the whole premise of `repair`: it is what `wp db
	 * import`, a restored SQL dump and a direct table edit all look like
	 * from this option's point of view. Seeding through update_option()
	 * would be impossible here anyway -- the sanitize callback would refuse
	 * the bad value and keep the old one, which is the very protection
	 * `repair` exists to backfill for writes that never had it.
	 *
	 * @param array<string, mixed> $stored Raw option value to plant.
	 * @return void
	 */
	private function plant_unsanitized_option( array $stored ): void {
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = $stored;
	}

	/**
	 * `repair` writes, so it is gated on the same capability as
	 * `import`/`reset` -- and refuses before touching the option.
	 */
	public function test_repair_refuses_without_manage_options(): void {
		$this->plant_unsanitized_option( array( 'contact_email' => 'not an address' ) );

		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		try {
			( new Blueline_Settings_Command() )->repair( array(), array() );
		} finally {
			$this->assertSame(
				array( 'contact_email' => 'not an address' ),
				get_option( BLUELINE_SETTINGS_OPTION ),
				'repair must not write before the capability check passes'
			);
		}
	}

	/**
	 * A store with nothing wrong: `repair` reports success, exits zero (no
	 * WP_CLI::error(), so no exception from the stub) and leaves the stored
	 * value alone.
	 */
	public function test_repair_reports_success_and_changes_nothing_for_a_clean_store(): void {
		$this->grant_manage_options();
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'clean@example.test' ) );
		$before = get_option( BLUELINE_SETTINGS_OPTION );

		( new Blueline_Settings_Command() )->repair( array(), array() );

		$this->assertSame( $before, get_option( BLUELINE_SETTINGS_OPTION ) );
		$successes = array_filter( $this->cli_log(), static fn( $e ) => 'success' === $e['type'] );
		$this->assertNotEmpty( $successes );
	}

	/**
	 * A broken store: `repair` names the field it replaced, actually writes
	 * the repaired value, and exits NON-ZERO (WP_CLI::error(), which this
	 * stub raises as an exception) so a deploy script can treat "I had to
	 * change something" as a failing check.
	 */
	public function test_repair_replaces_a_broken_value_and_exits_non_zero(): void {
		$this->grant_manage_options();
		$this->plant_unsanitized_option(
			array(
				'hero_registration_headline' => 'save 50% today',
				'footer_heading'             => 'The League',
			)
		);

		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		try {
			( new Blueline_Settings_Command() )->repair( array(), array() );
		} finally {
			$this->assertSame(
				blueline_settings_defaults()['hero_registration_headline'],
				blueline_settings( 'hero_registration_headline' ),
				'the rejected value must be gone from storage'
			);
			$this->assertSame(
				'The League',
				blueline_settings( 'footer_heading' ),
				'a field that was fine must not be touched'
			);

			$named = array_filter(
				$this->cli_log(),
				static fn( $e ) => str_contains( $e['message'], 'hero_registration_headline' )
			);
			$this->assertNotEmpty( $named, 'repair must name the key it replaced' );
		}
	}
}
