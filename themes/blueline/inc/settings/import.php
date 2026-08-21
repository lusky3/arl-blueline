<?php
/**
 * The settings export/import payload machinery: decode-and-bound, the
 * forward-only `_schema` refusal, the allow-list sanitizer walk, the
 * unknown-key report, and the one-line value renderer a preview is built
 * from.
 *
 * ## Why this is not in inc/cli/settings-command.php any more
 *
 * These functions started life there, as `blueline_settings_cli_*`, because
 * WP-CLI was the only caller. It is not any more: the panel's own
 * export/import controls (inc/settings/page.php) need the SAME decode, the
 * SAME `_schema` refusal, the SAME sanitizer walk and the SAME diff
 * preview, and inc/cli/settings-command.php is loaded only under
 * `defined( 'WP_CLI' ) && WP_CLI`, so a web request cannot reach anything
 * declared in it. Leaving them there and reimplementing for the panel would
 * have produced exactly the second, weaker validator this file's original
 * docblock argued against for the sanitize callback.
 *
 * What stayed behind in the CLI file is the CLI-SHAPED part:
 * blueline_settings_cli_diff_lines() (fixed-width text lines) and
 * blueline_settings_cli_validate_payload() (`validate`'s errors-only view).
 *
 * ## Import is a trust boundary
 *
 * An import writes untrusted, unreviewed data -- a JSON file that could
 * have come from anywhere -- into the site's live settings. Five things
 * stand in front of that, and this file is the first four:
 *
 * 1. A SIZE bound and a NESTING-DEPTH bound, both applied by
 *    blueline_settings_import_decode() before anything is parsed or walked
 *    (design spec 6.8: "bounds size and nesting depth"). See each
 *    constant's own docblock for where its number comes from.
 * 2. blueline_settings_import_prepare() refuses a payload whose own
 *    `_schema` exceeds BLUELINE_SETTINGS_SCHEMA_VERSION outright, mirroring
 *    inc/settings/store.php's forward-only migration guard: importing a
 *    newer environment's export into an older theme must fail loudly, not
 *    silently downgrade a shape this code does not understand yet. It also
 *    strips `_schema`, `_posted_fields` and `_tab`, which are never
 *    honoured from a file.
 * 3. blueline_settings_import_sanitize_payload() runs every schema-
 *    recognised key through blueline_sanitize_field() -- the same validator
 *    the real write triggers -- returning both what each field WOULD become
 *    and what was rejected.
 * 4. blueline_settings_import_dropped_keys() names every key the schema
 *    does not declare, so a caller can report them rather than leave the
 *    write to drop them in silence.
 * 5. (Not here.) The write itself goes through
 *    `update_option( BLUELINE_SETTINGS_OPTION, ... )`, hence through
 *    inc/settings/page.php's `sanitize_option_{$option}` callback, on every
 *    path.
 *
 * ## Export carries no secret
 *
 * blueline_settings_schema() declares no `password`/`token`/`key`-typed
 * field, and nothing this schema stores is credential-shaped: eight page
 * IDs, one term ID, one email address (an admin-facing CONTACT address,
 * meant to be public on the site itself -- not a login secret), plain copy,
 * and a handful of booleans. blueline_settings_export_payload() is
 * therefore a straight dump with nothing withheld. If a future schema field
 * ever stores something credential-like, it must be excluded there
 * explicitly rather than assumed safe by omission.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Largest import payload accepted, in bytes, checked against the RAW file
 * contents before any parsing happens.
 *
 * 256 KiB. A real export of the shipped defaults measures 1,895 bytes
 * pretty-printed (measured against blueline_settings_export_payload(), not
 * estimated), so this leaves roughly 138x headroom for long copy fields and
 * a full photograph list while still being an actual ceiling rather than
 * "whatever PHP will hold".
 */
const BLUELINE_SETTINGS_IMPORT_MAX_BYTES = 262144;

/**
 * Largest JSON nesting depth accepted, passed straight to json_decode()'s
 * own `$depth` argument.
 *
 * 8, against a real export's needed depth of 4 -- counted the way
 * json_decode() counts: the top-level object is 1, `hero_photos`' array is
 * 2, one photograph row object is 3, its scalars 4. (Verified empirically:
 * `json_decode( '{"hero_photos":[{"id":1}]}', true, 3 )` returns null with
 * JSON_ERROR_DEPTH; at 4 it decodes.) Eight leaves room for a future nested
 * key without leaving PHP's own 512 default as the only ceiling.
 */
const BLUELINE_SETTINGS_IMPORT_MAX_DEPTH = 8;

/**
 * The "that file is too big" refusal, as one string with one translation.
 *
 * Shared by blueline_settings_import_decode() (which measures the bytes it
 * was handed) and inc/settings/page.php's
 * blueline_settings_import_read_upload() (which refuses on the size the
 * upload REPORTS, before reading a byte of it -- the whole point of a size
 * bound on a browser upload being that the file is never loaded at all).
 *
 * @param int $size The offending size, in bytes.
 * @return string
 */
function blueline_settings_import_too_large_message( int $size ): string {
	return sprintf(
		/* translators: 1: the file's size in bytes, 2: the largest size accepted, in bytes. */
		__( 'That file is %1$d bytes; the largest settings file this accepts is %2$d bytes. A settings export is normally a couple of kilobytes, so a file this size is almost certainly not one.', 'blueline' ),
		$size,
		BLUELINE_SETTINGS_IMPORT_MAX_BYTES
	);
}

/**
 * Decode a settings export/import JSON blob, refusing anything past either
 * bound. Pure: never touches the database, WP_CLI, or the filesystem --
 * just parses a string it is handed.
 *
 * The three refusals carry three DISTINCT error codes and messages, rather
 * than one "that isn't valid JSON": an operator who hit the size ceiling,
 * the nesting ceiling, or genuinely malformed JSON has three different
 * things to do about it, and the previous single message told them which of
 * the three it was only by accident.
 *
 * @param string $raw Raw file contents.
 * @return array<string, mixed>|WP_Error The decoded associative array, or a
 *                                        WP_Error naming which bound (or
 *                                        which shape problem) refused it.
 */
function blueline_settings_import_decode( string $raw ) {
	$size = strlen( $raw );

	if ( $size > BLUELINE_SETTINGS_IMPORT_MAX_BYTES ) {
		return new WP_Error( 'blueline_import_too_large', blueline_settings_import_too_large_message( $size ) );
	}

	$data = json_decode( $raw, true, BLUELINE_SETTINGS_IMPORT_MAX_DEPTH );

	if ( JSON_ERROR_DEPTH === json_last_error() ) {
		return new WP_Error(
			'blueline_import_too_deep',
			sprintf(
				/* translators: %d: the deepest nesting accepted. */
				__( 'That file nests deeper than %d levels. A settings export never nests more than four deep, so a file this deeply nested is not one.', 'blueline' ),
				BLUELINE_SETTINGS_IMPORT_MAX_DEPTH
			)
		);
	}

	if ( ! is_array( $data ) ) {
		return new WP_Error(
			'blueline_import_invalid_json',
			__( 'The file does not contain a valid JSON object.', 'blueline' )
		);
	}

	return $data;
}

/**
 * Decide whether a decoded payload is safe to import at all, and strip the
 * bookkeeping keys a file must never carry weight for. Pure: no WordPress
 * calls, no database access -- everything this needs is already in $payload
 * and $current_schema_version.
 *
 * Runs BEFORE blueline_sanitize_field()/update_option() ever see the
 * payload, and is the one place `_schema` is actually enforced for an
 * import -- the payload's OWN `_schema` (what version the export was taken
 * from), not the value already stored on this site.
 *
 * `_posted_fields` and `_tab` are request-scoped bookkeeping the panel's own
 * render loop emits for ONE purpose (telling blueline_settings_merge() which
 * absent keys are a deliberate delete rather than "belongs to a tab this
 * request didn't touch" -- see inc/settings/store.php). A file has no such
 * request to describe, so both are stripped unconditionally, regardless of
 * whether the file happens to carry them (e.g. a raw copy of what a browser
 * once posted).
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
function blueline_settings_import_prepare( array $payload, int $current_schema_version ) {
	// Never honoured from a file -- see this function's own docblock.
	unset( $payload['_posted_fields'], $payload['_tab'] );

	// Discarded UNCONDITIONALLY, exactly as the spec requires: "discards
	// `aa_acknowledgements` and `advanced_enabled` unconditionally (otherwise a
	// crafted file arrives pre-excused)". Both are consent, not configuration
	// -- a record that a human looked at a dangerous control and chose to
	// proceed. Importing that consent would let a file assert it on an admin's
	// behalf, which is the one thing consent cannot be.
	//
	// This has to be explicit, and it has to be HERE rather than left to the
	// unknown-key drop below: that drop only removes keys the schema does not
	// recognise, and `advanced_enabled` became a real schema key in this same
	// task. The moment it was added, the drop stopped covering it.
	// `aa_acknowledgements` now has real storage too (Phase 2.0's own
	// inc/settings/acknowledgements.php) -- but the reasoning above already
	// covers it regardless: consent is not something a file can assert on an
	// admin's behalf, whether or not the key had storage behind it yet. It
	// was named here from the start, before that storage existed, precisely
	// so the discard would already be in place the moment it did -- the gap
	// between a key becoming real and a discard naming it explicitly is
	// exactly when a crafted file would work.
	unset( $payload['advanced_enabled'], $payload['aa_acknowledgements'] );

	$payload_schema = isset( $payload['_schema'] ) ? (int) $payload['_schema'] : 0;
	// `_schema` bookkeeping belongs to inc/settings/store.php's migration,
	// never to an import: stripped here regardless of the outcome below, so
	// a payload that DOES pass the schema check still can't smuggle its own
	// `_schema` value past blueline_settings_sanitize_callback()'s reserved-
	// key path and overwrite what blueline_settings_migrate() already wrote.
	unset( $payload['_schema'] );

	if ( $payload_schema > $current_schema_version ) {
		return new WP_Error(
			'blueline_import_schema_too_new',
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
 * blueline_sanitize_field() -- the SAME validator a real import's
 * update_option() call ultimately triggers via sanitize_option_{$option} --
 * WITHOUT writing anything, returning BOTH what each field would become and
 * what was rejected.
 *
 * The `values` half is what makes a preview honest. blueline_sanitize_field()
 * does not only accept or reject: it NORMALISES, most visibly through
 * sanitize_text_field()'s trim. A preview built from the raw payload would
 * report `"The ARL" -> "  The ARL  "` for a file whose import changes
 * nothing at all, which is the preview being wrong about the one thing it
 * exists to be right about. Only `values` describes what would actually be
 * stored.
 *
 * A key the schema does not recognise is skipped by both halves (not
 * reported as an error and not given a value): update_option() would drop
 * it rather than reject it, so it is not a validation failure, just a key
 * with no field to validate against. Callers report those separately via
 * blueline_settings_import_dropped_keys().
 *
 * A key that IS rejected appears in `errors` and is absent from `values` --
 * a rejected field keeps its currently-stored value, so there is no
 * "would be stored" value to offer for it.
 *
 * @param array<string, mixed>                $payload Payload already run
 *                                                       through
 *                                                       blueline_settings_import_prepare().
 * @param array<string, array<string, mixed>> $schema  blueline_settings_schema().
 * @return array{values: array<string, mixed>, errors: string[]} The value
 *               each recognised, accepted field would be stored as, and one
 *               human-readable message per rejected field.
 */
function blueline_settings_import_sanitize_payload( array $payload, array $schema ): array {
	$values = array();
	$errors = array();

	foreach ( $payload as $key => $value ) {
		if ( ! isset( $schema[ $key ] ) ) {
			continue; // Not a real field -- update_option() would drop it, not reject it.
		}

		$result = blueline_sanitize_field( $value, $schema[ $key ] );

		if ( is_wp_error( $result ) ) {
			$errors[] = $result->get_error_message();
			continue;
		}

		$values[ $key ] = $result;
	}

	return array(
		'values' => $values,
		'errors' => $errors,
	);
}

/**
 * Which top-level keys in $payload the schema does not declare -- exactly
 * the keys blueline_settings_sanitize_callback() (inc/settings/page.php)
 * drops with a bare `continue`, never surfacing them via
 * add_settings_error(), because that callback also runs on ordinary
 * wp-admin saves and has no business emitting caller-shaped output. Each
 * import path calls this SEPARATELY so an operator importing a file with a
 * typo'd or stale key is actually told which key was ignored, rather than
 * seeing an unqualified success that implies the whole file applied.
 *
 * Deliberately a pure, static comparison against the schema -- NOT a
 * before/after diff of the actually-stored option -- because that is
 * exactly the same test blueline_settings_sanitize_callback() itself
 * applies (`! isset( $schema[ $key ] )`) to decide what to drop; re-deriving
 * it here needs no database round-trip, and it cannot be confused with a
 * DIFFERENT, already-separately-reported outcome: a key the schema DOES
 * recognise but whose value failed validation.
 *
 * @param array<string, mixed>                $payload Payload already run
 *                                                       through
 *                                                       blueline_settings_import_prepare().
 * @param array<string, array<string, mixed>> $schema  blueline_settings_schema().
 * @return string[] Keys present in $payload that $schema does not declare,
 *                   in the order they appear in $payload. Empty when every
 *                   key is a real schema field.
 */
function blueline_settings_import_dropped_keys( array $payload, array $schema ): array {
	return array_keys( array_diff_key( $payload, $schema ) );
}

/**
 * Render one settings value as an unambiguous one-line string for a
 * preview. Pure: no database, no output.
 *
 * JSON, not a bare cast, because this schema's values are not all strings
 * and the differences that matter are exactly the ones a cast erases: a
 * `bool` false and an empty string both print as nothing, `0` and `'0'` and
 * `''` all print as something a reader would have to guess at, and
 * `hero_photos` is an array. JSON prints `false`, `""`, `0` and `[]` as
 * four visibly different things, which is the whole job here.
 *
 * @param mixed $value A settings value.
 * @return string
 */
function blueline_settings_import_render_value( $value ): string {
	return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * The exportable settings payload: every field blueline_settings() returns,
 * plus the stored `_schema` version.
 *
 * `_schema` is included explicitly because blueline_settings() deliberately
 * excludes it (callers asking for field values do not want migration
 * bookkeeping mixed in) and an import needs it: it is what
 * blueline_settings_import_prepare() enforces the forward-only check
 * against. When nothing is stored yet -- a site that has never saved --
 * this code's own current version is reported, since that is the shape the
 * exported values are in.
 *
 * @return array<string, mixed>
 */
function blueline_settings_export_payload(): array {
	$settings = blueline_settings();

	$stored = get_option( BLUELINE_SETTINGS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();

	$settings['_schema'] = isset( $stored['_schema'] ) ? (int) $stored['_schema'] : BLUELINE_SETTINGS_SCHEMA_VERSION;

	return $settings;
}
