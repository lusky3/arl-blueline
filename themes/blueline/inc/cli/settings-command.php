<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1). This file is named for what it does (the `settings` WP-CLI command group), not for Blueline_Settings_Command, matching this theme's established file-naming convention (inc/settings/page.php defines no class at all; every other inc/ file is named for its subject, never for a single class it happens to declare).
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- the three pure helpers below (blueline_settings_cli_decode_payload(), blueline_settings_cli_prepare_import(), blueline_settings_cli_validate_payload()) exist ONLY to be called from Blueline_Settings_Command's own methods, and are unit tested directly alongside it in tests/SettingsCliCommandTest.php; splitting them into a second file would scatter one command's logic across two files for no reader's benefit, the same trade-off tests/SettingsCacheTest.php's own docblock makes for its Redis fakes.
/**
 * `wp blueline settings export|import|validate|repair|reset` -- WP-CLI
 * access to the same one option (BLUELINE_SETTINGS_OPTION) the Appearance
 * -> Blueline panel reads and writes.
 *
 * Loaded ONLY under `defined( 'WP_CLI' ) && WP_CLI` (see functions.php) so
 * this file -- and the `WP_CLI_Command`/`WP_CLI` symbols it depends on --
 * never parses on an ordinary web request, where neither exists.
 * tests/IncRequireCoverageTest.php's own docblock names this exact file as
 * the case its "does functions.php mention this path anywhere, guarded or
 * not" check was designed to accept.
 *
 * ## Import is a trust boundary
 *
 * `import` is the one subcommand that writes untrusted, unreviewed data
 * (a JSON file that could have come from anywhere -- a backup, another
 * environment, a hand-edited fixture) into the site's live settings. Three
 * things make that safe, in the order this file applies them:
 *
 * 1. `current_user_can( 'manage_options' )` -- the SAME capability the
 *    panel itself requires (inc/settings/page.php's blueline_settings_add_page()
 *    and blueline_settings_render_page()). WP-CLI does not authenticate a
 *    "current user" unless the operator explicitly passes `--user=<who>`,
 *    so by default this check fails closed: an unattended `wp` invocation
 *    with no `--user` cannot import settings at all. That is deliberate,
 *    not a bug to work around with a broader check -- this command can
 *    silently corrupt every setting the panel manages, using the exact
 *    same `sanitize_option_{$option}` filter a stray "%" or a malformed
 *    page ID would trip, and CLI access to a production box is not by
 *    itself proof the invoker meant to change site configuration.
 * 2. blueline_settings_cli_prepare_import() rejects a payload whose own
 *    `_schema` exceeds BLUELINE_SETTINGS_SCHEMA_VERSION outright, before a
 *    single field is written -- importing a newer environment's export
 *    into an older, not-yet-updated theme must fail loudly, not silently
 *    downgrade a shape this code does not fully understand yet. This
 *    mirrors inc/settings/store.php's blueline_settings_migrate() forward-
 *    only guard for exactly the same reason.
 * 3. The actual write goes through `update_option( BLUELINE_SETTINGS_OPTION, ... )`
 *    -- the SAME sanitize_option_{$option} callback
 *    (blueline_settings_sanitize_callback(), inc/settings/page.php) the
 *    panel's own save request runs through, not a second, parallel
 *    validator this file would have to keep in sync by hand. That callback
 *    is registered UNCONDITIONALLY at file scope specifically so every
 *    write path -- wp-admin, WP-CLI, this import command -- is validated
 *    identically; see inc/settings/page.php's own docblock ("Every write
 *    path is validated") for the reasoning, and
 *    SettingsCliCommandTest::test_sanitize_option_filter_is_registered_at_file_scope_before_any_command_runs()
 *    for the assertion that proves it rather than assumes it. Any field
 *    that callback rejects keeps its currently-stored value (never the bad
 *    one); any top-level key that is neither a real schema field nor the
 *    reserved `_schema` bookkeeping key is dropped outright -- the
 *    allow-list model Task 7 restored, never reintroduced as
 *    "forward anything unrecognised".
 *
 *    That callback drops an unrecognised key with a bare `continue` and
 *    never calls add_settings_error() -- it has no CLI to report to, and
 *    is not this command's only caller. Silently reporting `Success.` for
 *    a file that partly didn't apply is exactly the "a silent skip is how
 *    bad settings arrive unnoticed" failure mode this command exists to
 *    avoid, so `import` (and `validate`, its dry-run twin) computes the
 *    same drop decision independently, on the CLI side --
 *    blueline_settings_cli_dropped_keys() -- and names each dropped key via
 *    `WP_CLI::warning()` before reporting overall success. A dropped key
 *    does NOT make the command exit non-zero: it is not evidence of a
 *    broken import (most often a typo, or a field an older/newer theme
 *    version simply doesn't declare), and every other recognised key in
 *    the same file still needs to be applied -- the schema-version guard
 *    above is what actually catches the more serious "this file doesn't
 *    belong on this install at all" case.
 *
 * `_posted_fields` and `_tab` are request-scoped bookkeeping the panel's own
 * render loop emits for ONE specific purpose (telling
 * blueline_settings_merge() which absent keys are a deliberate delete vs.
 * "belongs to a tab this request didn't touch" -- see inc/settings/store.php).
 * A file has no such request to describe, so both are stripped
 * unconditionally by blueline_settings_cli_prepare_import() before the
 * sanitizer ever sees the payload -- never honoured from a file, regardless
 * of whether the file happens to carry them (e.g. a raw copy of what a
 * browser once posted).
 *
 * ## Export carries no secret
 *
 * blueline_settings_schema() declares no `password`/`token`/`key`-typed
 * field, and nothing this schema stores is credential-shaped: eight page
 * IDs, one term ID, one email address (an admin-facing CONTACT address,
 * meant to be public-facing on the site itself -- not a login secret), and
 * plain text/textarea copy. Export is therefore a straight dump of
 * blueline_settings() plus the stored `_schema` version, with nothing
 * withheld. If a future schema field ever stores something credential-like,
 * it must be excluded here explicitly rather than assumed safe by omission.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decode a settings export/import JSON blob. Pure: never touches the
 * database, WP_CLI, or the filesystem -- just parses a string.
 *
 * @param string $raw Raw file contents.
 * @return array<string, mixed>|WP_Error The decoded associative array, or a
 *                                        WP_Error if $raw is not a valid
 *                                        JSON object.
 */
function blueline_settings_cli_decode_payload( string $raw ) {
	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return new WP_Error(
			'blueline_cli_invalid_json',
			__( 'The file does not contain a valid JSON object.', 'blueline' )
		);
	}

	return $data;
}

/**
 * Decide whether a decoded payload is safe to import at all, and strip the
 * request-scoped bookkeeping keys a file must never carry weight for.
 * Pure: no WordPress calls, no database access -- everything this needs is
 * already in $payload and $current_schema_version.
 *
 * Runs BEFORE blueline_sanitize_field()/update_option() ever see the
 * payload, and is the one place `_schema` is actually enforced for an
 * import -- the payload's OWN `_schema` (what version the export was taken
 * from), not the value already stored on this site.
 *
 * @param array<string, mixed> $payload                Decoded JSON payload.
 * @param int                  $current_schema_version This code's own
 *                                                       BLUELINE_SETTINGS_SCHEMA_VERSION.
 * @return array<string, mixed>|WP_Error $payload with `_posted_fields`,
 *                                        `_tab` and `_schema` all removed
 *                                        (ready for the allow-list
 *                                        sanitizer to run on), or a WP_Error
 *                                        if the payload's own `_schema` is
 *                                        newer than this code understands.
 */
function blueline_settings_cli_prepare_import( array $payload, int $current_schema_version ) {
	// Never honoured from a file -- see this file's own docblock.
	unset( $payload['_posted_fields'], $payload['_tab'] );

	$payload_schema = isset( $payload['_schema'] ) ? (int) $payload['_schema'] : 0;
	// `_schema` bookkeeping belongs to inc/settings/store.php's migration,
	// never to an import: stripped here regardless of the outcome below, so
	// a payload that DOES pass the schema check still can't smuggle its own
	// `_schema` value past blueline_settings_sanitize_callback()'s reserved-
	// key path and overwrite what blueline_settings_migrate() already wrote.
	unset( $payload['_schema'] );

	if ( $payload_schema > $current_schema_version ) {
		return new WP_Error(
			'blueline_cli_schema_too_new',
			sprintf(
				/* translators: 1: the file's schema version, 2: the schema version this code understands. */
				__( 'Refusing to import: the file\'s schema (%1$d) is newer than this install understands (%2$d). Update the theme before importing this file.', 'blueline' ),
				$payload_schema,
				$current_schema_version
			)
		);
	}

	return $payload;
}

/**
 * Dry-run every schema-recognised key in $payload through
 * blueline_sanitize_field() -- the SAME validator import's real
 * update_option() call ultimately triggers via
 * sanitize_option_{$option} -- WITHOUT writing anything. A key the schema
 * does not recognise is silently skipped here too (not reported as an
 * error): update_option() would drop it the same way, so it is not a
 * validation failure, just a key with no field to validate against.
 *
 * @param array<string, mixed>                $payload Payload already run
 *                                                       through
 *                                                       blueline_settings_cli_prepare_import().
 * @param array<string, array<string, mixed>> $schema blueline_settings_schema().
 * @return string[] One human-readable message per rejected field; empty
 *                   when every recognised field validates cleanly.
 */
function blueline_settings_cli_validate_payload( array $payload, array $schema ): array {
	$errors = array();

	foreach ( $payload as $key => $value ) {
		if ( ! isset( $schema[ $key ] ) ) {
			continue; // Not a real field -- update_option() would drop it, not reject it.
		}

		$result = blueline_sanitize_field( $value, $schema[ $key ] );

		if ( is_wp_error( $result ) ) {
			$errors[] = $result->get_error_message();
		}
	}

	return $errors;
}

/**
 * Which top-level keys in $payload the schema does not declare -- exactly
 * the keys blueline_settings_sanitize_callback() (inc/settings/page.php)
 * drops with a bare `continue`, never surfacing them via
 * add_settings_error(), because that callback also runs on ordinary
 * wp-admin saves and has no business emitting CLI-shaped output. `import`
 * calls this SEPARATELY, on the CLI side, so an operator importing a file
 * with a typo'd or stale key is actually told which key was silently
 * ignored, rather than seeing an unqualified "Success." that implies the
 * whole file applied -- fixing exactly the "silent skip is how bad
 * settings arrive unnoticed" failure mode this command is a trust boundary
 * against.
 *
 * Deliberately a pure, static comparison against the schema -- NOT a
 * before/after diff of the actually-stored option -- because that is
 * exactly the same test blueline_settings_sanitize_callback() itself
 * applies (`! isset( $schema[ $key ] )`) to decide what to drop; re-deriving
 * it here needs no database round-trip, and it cannot be confused with a
 * DIFFERENT, already-separately-reported outcome: a key the schema DOES
 * recognise but whose value failed validation (surfaced instead via
 * get_settings_errors(), a distinct code path both `import` and `validate`
 * already read from).
 *
 * @param array<string, mixed>                $payload Payload already run
 *                                                       through
 *                                                       blueline_settings_cli_prepare_import().
 * @param array<string, array<string, mixed>> $schema  blueline_settings_schema().
 * @return string[] Keys present in $payload that $schema does not declare,
 *                   in the order they appear in $payload. Empty when every
 *                   key is a real schema field.
 */
function blueline_settings_cli_dropped_keys( array $payload, array $schema ): array {
	return array_keys( array_diff_key( $payload, $schema ) );
}

/**
 * `wp blueline settings export|import|validate|repair|reset`.
 */
class Blueline_Settings_Command extends WP_CLI_Command {

	/**
	 * Print the current Blueline settings as JSON.
	 *
	 * The stored `_schema` version is included explicitly (blueline_settings()
	 * itself deliberately excludes it -- see that function's own docblock --
	 * since callers asking for field values do not want migration
	 * bookkeeping mixed in; export is the one caller that DOES want it, so
	 * `wp blueline settings import` can enforce the forward-only schema
	 * check against it).
	 *
	 * Carries no secret: see this file's own docblock, "Export carries no
	 * secret".
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write the export to this file instead of standard output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueline settings export
	 *     wp blueline settings export --file=blueline-settings.json
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function export( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP_CLI_Command's dispatch contract; this subcommand takes no positional argument.
		$settings = blueline_settings();

		$stored              = get_option( BLUELINE_SETTINGS_OPTION, array() );
		$stored              = is_array( $stored ) ? $stored : array();
		$settings['_schema'] = isset( $stored['_schema'] ) ? (int) $stored['_schema'] : BLUELINE_SETTINGS_SCHEMA_VERSION;

		$json = (string) wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$file = $assoc_args['file'] ?? '';

		if ( '' === $file ) {
			WP_CLI::log( $json );
			return;
		}

		if ( false === file_put_contents( $file, $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI writing a local export file the operator named; no HTTP/remote concern, and WP_Filesystem is not guaranteed available in a WP-CLI context.
			WP_CLI::error( sprintf( 'Could not write to %s.', $file ) );
			return;
		}

		WP_CLI::success( sprintf( 'Exported settings to %s.', $file ) );
	}

	/**
	 * Import Blueline settings from a JSON file, through the exact same
	 * `sanitize_option_{$option}` callback the control panel's own save
	 * request runs through.
	 *
	 * See this file's own docblock, "Import is a trust boundary", for the
	 * full reasoning behind each guard below.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a JSON file, e.g. one produced by `wp blueline settings export`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueline settings import blueline-settings.json
	 *
	 * @param array<int, string>    $args       Positional arguments: [ $file ].
	 * @param array<string, string> $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function import( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP_CLI_Command's dispatch contract; this subcommand takes no associative argument.
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( __( 'The current user is not allowed to manage_options. Re-run with --user=<an administrator>.', 'blueline' ) );
			return;
		}

		list( $file ) = $args;

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
			return;
		}

		$raw     = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo/operator-supplied file, not a remote URL; wp_remote_get() is for HTTP requests.
		$payload = blueline_settings_cli_decode_payload( $raw );

		if ( is_wp_error( $payload ) ) {
			WP_CLI::error( $payload->get_error_message() );
			return;
		}

		$prepared = blueline_settings_cli_prepare_import( $payload, BLUELINE_SETTINGS_SCHEMA_VERSION );

		if ( is_wp_error( $prepared ) ) {
			WP_CLI::error( $prepared->get_error_message() );
			return;
		}

		// Which keys the schema will drop, computed BEFORE the write -- see
		// blueline_settings_cli_dropped_keys()'s own docblock for why this
		// lives here rather than inside the sanitize callback itself: that
		// callback runs on ordinary wp-admin saves too and has no business
		// emitting CLI-shaped output, so this command surfaces the drop
		// itself instead of trusting the (silent) callback to have done so.
		// A dropped key is reported as a warning, not an error -- it is not
		// evidence the import went wrong, only that one key in the file
		// wasn't recognised (most often a typo, or a field this version of
		// the theme no longer declares); every OTHER recognised key still
		// needs to be applied, and the schema-version guard above already
		// catches the more serious "this file is from a newer theme"
		// case. This intentionally does NOT make the command exit non-zero.
		foreach ( blueline_settings_cli_dropped_keys( $prepared, blueline_settings_schema() ) as $dropped_key ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %s: the unrecognised key name. */
					__( '"%s" is not a recognised Blueline setting and was not imported.', 'blueline' ),
					$dropped_key
				)
			);
		}

		// The SAME sanitize_option_{$option} callback the panel's own save
		// request runs -- registered unconditionally at file scope by
		// inc/settings/page.php, never conditioned on admin_init -- so this
		// path is validated identically, not by a second validator this
		// file would have to keep in sync by hand. A rejected field keeps
		// its currently-stored value; an unrecognised key is dropped (and
		// already warned about, above).
		update_option( BLUELINE_SETTINGS_OPTION, $prepared );

		foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
			WP_CLI::warning( $error['message'] );
		}

		WP_CLI::success( sprintf( 'Imported settings from %s.', $file ) );
	}

	/**
	 * Validate a settings JSON file WITHOUT writing anything -- the same
	 * checks `import` would apply, run as a dry run.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a JSON file to check.
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueline settings validate blueline-settings.json
	 *
	 * @param array<int, string>    $args       Positional arguments: [ $file ].
	 * @param array<string, string> $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function validate( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP_CLI_Command's dispatch contract; this subcommand takes no associative argument.
		list( $file ) = $args;

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
			return;
		}

		$raw     = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo/operator-supplied file, not a remote URL; wp_remote_get() is for HTTP requests.
		$payload = blueline_settings_cli_decode_payload( $raw );

		if ( is_wp_error( $payload ) ) {
			WP_CLI::error( $payload->get_error_message() );
			return;
		}

		$prepared = blueline_settings_cli_prepare_import( $payload, BLUELINE_SETTINGS_SCHEMA_VERSION );

		if ( is_wp_error( $prepared ) ) {
			WP_CLI::error( $prepared->get_error_message() );
			return;
		}

		// Same dropped-key warning `import` surfaces (see
		// blueline_settings_cli_dropped_keys()'s docblock) -- `validate` is
		// a preview of what `import` would do, so it must not stay silent
		// about something `import` itself now warns about.
		foreach ( blueline_settings_cli_dropped_keys( $prepared, blueline_settings_schema() ) as $dropped_key ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %s: the unrecognised key name. */
					__( '"%s" is not a recognised Blueline setting and would not be imported.', 'blueline' ),
					$dropped_key
				)
			);
		}

		$errors = blueline_settings_cli_validate_payload( $prepared, blueline_settings_schema() );

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $message ) {
				WP_CLI::warning( $message );
			}
			WP_CLI::error( sprintf( '%d field(s) failed validation.', count( $errors ) ) );
			return;
		}

		WP_CLI::success( sprintf( '%s is valid.', $file ) );
	}

	/**
	 * Replace every STORED setting the panel's own validator would refuse
	 * with that field's default, and name each one.
	 *
	 * For values that never went through update_option() at all -- `wp db
	 * import`, a restored SQL dump, a hand-edited row -- which is the one
	 * way an invalid value can be sitting in this option in the first
	 * place. See blueline_settings_repair() (inc/settings/store.php) for
	 * why this is an explicit command rather than something
	 * blueline_settings() quietly does on every read.
	 *
	 * EXITS NON-ZERO WHENEVER IT CHANGED ANYTHING, which is what makes it
	 * usable as a deploy check: a zero exit means "the stored settings were
	 * already valid", not merely "the command ran". A run that had to
	 * repair something has already written the repair by the time it exits
	 * non-zero -- the non-zero status reports that the database WAS broken,
	 * it does not mean the repair was refused.
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueline settings repair --user=admin
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function repair( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP_CLI_Command's dispatch contract; this subcommand takes neither a positional nor an associative argument.
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( __( 'The current user is not allowed to manage_options. Re-run with --user=<an administrator>.', 'blueline' ) );
			return;
		}

		$stored = get_option( BLUELINE_SETTINGS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$result = blueline_settings_repair( $stored );

		if ( empty( $result['repaired'] ) ) {
			WP_CLI::success( __( 'Every stored Blueline setting is valid. Nothing to repair.', 'blueline' ) );
			return;
		}

		foreach ( $result['repaired'] as $key ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %s: the settings key that held an invalid value. */
					__( '"%s" held a value the panel would refuse. It has been reset to its default.', 'blueline' ),
					$key
				)
			);
		}

		update_option( BLUELINE_SETTINGS_OPTION, $result['settings'] );

		// Non-zero, deliberately, AFTER the write -- see this method's own
		// docblock: the exit status reports that something was broken, not
		// that the repair failed.
		WP_CLI::error(
			sprintf(
				/* translators: %d: how many settings were repaired. */
				_n(
					'Repaired %d setting that was stored invalid.',
					'Repaired %d settings that were stored invalid.',
					count( $result['repaired'] ),
					'blueline'
				),
				count( $result['repaired'] )
			)
		);
	}

	/**
	 * Reset every Blueline setting to its default value.
	 *
	 * Gated behind the same `manage_options`/`--yes` pair as `import`, for
	 * the same reason: this overwrites every field the panel manages in one
	 * call, from a command an unattended script could run without a human
	 * reading a confirmation prompt.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueline settings reset --yes
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function reset( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP_CLI_Command's dispatch contract; this subcommand takes no positional argument.
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( __( 'The current user is not allowed to manage_options. Re-run with --user=<an administrator>.', 'blueline' ) );
			return;
		}

		WP_CLI::confirm( 'This will overwrite every Blueline setting with its default value. Continue?', $assoc_args );

		update_option( BLUELINE_SETTINGS_OPTION, blueline_settings_defaults() );

		WP_CLI::success( 'Settings reset to defaults.' );
	}
}

WP_CLI::add_command( 'blueline settings', 'Blueline_Settings_Command' );
