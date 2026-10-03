<?php
/**
 * The sanitize_option_{$option} callback for the Blueline settings option and
 * its reserved-key sanitizers. Loaded by inc/settings/page.php, so the filter
 * is wired at file scope on every write path. "This file's docblock" below
 * means inc/settings/page.php's, where these functions used to live.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'sanitize_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_settings_sanitize_callback' );
/**
 * The sanitize_option_{$option} callback for BLUELINE_SETTINGS_OPTION,
 * registered UNCONDITIONALLY above, at file scope, exactly the way
 * inc/settings/store.php registers blueline_settings_merge() on
 * `pre_update_option_{$option}`, so this runs on every write reachable
 * through update_option(), not only ones that pass through wp-admin's
 * `admin_init`. This is the single choke point every save passes through,
 * immediately before blueline_settings_merge() runs.
 *
 * Four responsibilities, each described in this file's own docblock in
 * more depth:
 *
 * 1. Validate every posted field with blueline_sanitize_field(). A field
 *    that fails keeps its EXISTING stored value rather than the rejected
 *    one, so one bad field cannot corrupt the option, and every other field
 *    in the same submission still saves normally.
 * 2. Escape every WP_Error message with esc_html() before it is handed to
 *    add_settings_error(); see this file's docblock's Escaping section.
 * 3. Forward `_posted_fields` (filtered to known schema keys AND to keys
 *    whose OWN schema `tab` matches the submission's `_tab`) AND `_tab`
 *    itself so blueline_settings_merge() can tell "this tab cleared a
 *    field it owns" from "this field belongs to an untouched tab" from "no
 *    tab at all", meaning a programmatic write, where `_posted_fields` carries no
 *    ownership. A submission naming a foreign tab's field is not honoured
 *    for that field; see this file's docblock's `_posted_fields`
 *    section.
 * 4. Every OTHER key is checked against an explicit reserved-key
 *    allow-list (BLUELINE_SETTINGS_RESERVED_KEYS, today `_schema`,
 *    `aa_acknowledgements`, `occasions`, and `_validated_against`), never
 *    forwarded merely for being unrecognised; see this file's docblock's
 *    `_schema` section for why
 *    "forward anything unrecognised" was rejected. A reserved key is still
 *    sanitized, though not identically: `_schema` is sanitized like every
 *    other integer-valued field (absint()) and additionally clamped to
 *    BLUELINE_SETTINGS_SCHEMA_VERSION, while `aa_acknowledgements` is not
 *    an integer at all and instead goes through its own validator,
 *    blueline_sanitize_acknowledgements() (inc/settings/acknowledgements.php).
 *    Either way, the key is dropped outright when the submission carries a
 *    `_tab` (came from this file's own form, which never legitimately
 *    submits either one), EXCEPT `occasions`, which is the one reserved
 *    key that DOES survive a tab-scoped submission, and only when that
 *    submission's own `_tab` is literally `'occasions'` (design spec
 *    §5.1's second ruling): that tab's own rendered form posts
 *    `blueline_settings[occasions]` as one opaque map value, never through
 *    `_posted_fields` per-field carry-forward. `_schema`,
 *    `aa_acknowledgements`, and `_validated_against` keep the absolute
 *    drop-on-any-tab rule unchanged.
 *
 * @param mixed $input Raw value from $_POST[BLUELINE_SETTINGS_OPTION], as
 *                      WordPress' sanitize_option_{$option} filter hands it
 *                      to us: only the keys THIS submission posted.
 * @return array<string, mixed> The value to store, before
 *                               blueline_settings_merge() carries forward
 *                               whatever belongs to other tabs.
 */
function blueline_settings_sanitize_callback( $input ): array {
	$input   = is_array( $input ) ? $input : array();
	$schema  = blueline_settings_schema();
	$current = blueline_settings();

	$submitted_tab = isset( $input['_tab'] ) ? (string) $input['_tab'] : '';

	$posted_fields = array();
	if ( isset( $input['_posted_fields'] ) && is_array( $input['_posted_fields'] ) ) {
		foreach ( $input['_posted_fields'] as $posted_key ) {
			$posted_key = (string) $posted_key;
			if ( ! isset( $schema[ $posted_key ] ) ) {
				continue; // Not a real field at all.
			}
			if ( ( $schema[ $posted_key ]['tab'] ?? '' ) !== $submitted_tab ) {
				// Named by a submission that does not own it. A request
				// merely SHAPED like another tab's form (or a tampered
				// one) naming a foreign field here must never be able to
				// delete it. Silently dropped, not honoured.
				continue;
			}
			$posted_fields[] = $posted_key;
		}
	}

	$output = array();

	foreach ( $input as $key => $value ) {
		if ( '_posted_fields' === $key || '_tab' === $key ) {
			// Reserved bookkeeping, both already consumed above:
			// `_posted_fields` is rebuilt, filtered, below; `_tab` is
			// forwarded, unchanged, below too. Neither is persisted on an
			// ordinary, steady-state save (the first-ever-write re-sanitize
			// quirk set out in inc/settings/snapshots.php's file docblock is
			// the one exception), because blueline_settings_merge() (the
			// very next filter this same write triggers) is what actually
			// needs `_tab`, to tell a tab-scoped submission (where
			// `_posted_fields` decides deletion) apart from a programmatic
			// one (where it carries no ownership at all; see that
			// function's own docblock), and strips both before anything
			// reaches storage.
			continue;
		}

		if ( ! isset( $schema[ $key ] ) ) {
			if ( ! in_array( $key, BLUELINE_SETTINGS_RESERVED_KEYS, true ) ) {
				// Not a real field and not on the reserved allow-list:
				// dropped, exactly as if it had never been declared at
				// all. See this file's docblock's `_schema` section for
				// why this is an explicit allow-list rather than "forward
				// anything unrecognised".
				continue;
			}

			if ( '' !== $submitted_tab && ! ( 'occasions' === $key && 'occasions' === $submitted_tab ) ) {
				// Reserved, but this submission carries `_tab`, meaning it
				// came from this file's own rendered form, which never
				// legitimately submits a reserved key... EXCEPT
				// `occasions` submitted BY its own Occasions tab (design
				// spec §5.1's second ruling): that tab's own form posts
				// `blueline_settings[occasions]` as one opaque map value,
				// never through `_posted_fields` per-field carry-forward,
				// since `occasions` is not a scalar schema field at all.
				// `_schema`, `aa_acknowledgements`, and `_validated_against`
				// keep the absolute rule unchanged. This exception names
				// `occasions` AND `'occasions' === $submitted_tab` together,
				// rather than loosening the rule for every reserved key.
				continue;
			}

			if ( 'aa_acknowledgements' === $key ) {
				$output[ $key ] = blueline_settings_sanitize_reserved_aa_acknowledgements( $value );
				continue;
			}

			if ( '_validated_against' === $key ) {
				$output[ $key ] = blueline_settings_sanitize_reserved_validated_against( $value );
				continue;
			}

			if ( 'occasions' === $key ) {
				$output = array_merge(
					$output,
					blueline_settings_sanitize_reserved_occasions( $value, $submitted_tab, $current )
				);
				continue;
			}

			// The only reserved key with no explicit branch above.
			$output[ $key ] = blueline_settings_sanitize_reserved_schema( $value );
			continue;
		}

		$result = blueline_sanitize_field( $value, $schema[ $key ] );

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				BLUELINE_SETTINGS_OPTION,
				$key,
				esc_html( $result->get_error_message() ),
				'error'
			);
			// Keep what is already stored; never write the rejected value.
			$output[ $key ] = $current[ $key ] ?? null;
			continue;
		}

		$output[ $key ] = $result;
	}

	$output['_posted_fields'] = $posted_fields;
	$output['_tab']           = $submitted_tab;

	return $output;
}

/**
 * Sanitize a reserved `aa_acknowledgements` submission.
 *
 * Not an integer like every other reserved key today: a map of
 * acknowledgement entries (inc/settings/acknowledgements.php). Its own
 * validator drops anything malformed rather than corrupting the option or
 * crashing a later reader.
 *
 * @param mixed $value Raw posted (or programmatically written) value.
 * @return array<string, array<string, mixed>>
 */
function blueline_settings_sanitize_reserved_aa_acknowledgements( $value ): array {
	return blueline_sanitize_acknowledgements( $value );
}

/**
 * Sanitize a reserved `_validated_against` submission.
 *
 * A hash string (blueline_settings_inputs_hash()'s own return shape), not an
 * integer like every other reserved key. It is validated as a plain string,
 * defaulting to '' for anything else. No complex validation is needed here
 * (design spec §6.5's storage-shape ruling): this key is never read back
 * through blueline_settings() (see blueline_settings_defaults()'s own
 * docblock for why), only through inc/settings/validation.php's
 * blueline_validated_against(), which applies the identical
 * is_string()-or-default fallback on read, so a malformed stored value can
 * never reach a caller as anything other than ''.
 *
 * @param mixed $value Raw posted (or programmatically written) value.
 * @return string
 */
function blueline_settings_sanitize_reserved_validated_against( $value ): string {
	return is_string( $value ) ? $value : '';
}

/**
 * Sanitize a reserved `occasions` submission: the only reserved key with
 * two structurally different sub-branches, depending on WHERE the write
 * came from.
 *
 * @param mixed                $value          Raw posted (or programmatically written) value.
 * @param string               $submitted_tab  The submission's own `_tab`, '' for a
 *                                              programmatic write.
 * @param array<string, mixed> $current blueline_settings()'s current value,
 *                                       for reading the stored occasions map
 *                                       an Occasions-tab submission derives
 *                                       ids against.
 * @return array<string, mixed> The `occasions` key, and, only for an
 *                               Occasions-tab submission, the
 *                               `aa_acknowledgements` key it also decides.
 */
function blueline_settings_sanitize_reserved_occasions( $value, string $submitted_tab, array $current ): array {
	if ( 'occasions' !== $submitted_tab ) {
		// A programmatic write (WP-CLI, a direct update_option() call, an
		// import) does no id derivation: the caller is expected to already
		// supply final, correctly-keyed ids, exactly as this branch behaved
		// before 2.1b.
		return array( 'occasions' => blueline_sanitize_occasions( $value ) );
	}

	// The Occasions tab's own save (design spec §5.1's first ruling): derive
	// and de-duplicate every row's id server-side BEFORE the unchanged
	// blueline_sanitize_occasions() ever sees it, because the admin never
	// types an id directly.
	$stored_occasions = is_array( $current['occasions'] ?? null ) ? $current['occasions'] : array();
	$with_ids         = blueline_occasions_assign_unique_ids( $value, $stored_occasions );

	// Per-row override checkboxes ride along inside $with_ids
	// (blueline_occasions_assign_unique_ids() copies every OTHER key of a
	// row through untouched); read them here, keyed by each row's own
	// FINAL id, before blueline_sanitize_occasions() strips the extra
	// `override_aa` key off (it only ever keeps the eight documented
	// Occasion keys).
	$raw_overrides = array();
	foreach ( $with_ids as $row_id => $row ) {
		$raw_overrides[ $row_id ] = is_array( $row ) && ! empty( $row['override_aa'] );
	}

	$sanitized_occasions = blueline_sanitize_occasions( $with_ids );

	// design spec §5.1's fifth ruling: the Occasions tab's own save is also
	// what decides this save's new `aa_acknowledgements` value,
	// per-occasion, symmetric record/remove, plus orphan cleanup. A forged
	// `aa_acknowledgements` field in the raw POST can't overwrite this
	// computed value even though the rendered form never posts one: the
	// reserved-key guard in blueline_settings_sanitize_callback() (the
	// `'occasions' === $key && 'occasions' === $submitted_tab` check) only
	// lets `aa_acknowledgements` through that loop when $submitted_tab is ''
	// (a programmatic write), never alongside an `occasions`-tab submission,
	// so this assignment is always the last word.
	return array(
		'occasions'           => $sanitized_occasions,
		'aa_acknowledgements' => blueline_occasions_apply_aa_overrides(
			$sanitized_occasions,
			$raw_overrides,
			array(
				'acknowledgements' => blueline_stored_acknowledgements(),
				'inputs_hash'      => blueline_settings_inputs_hash(),
				'user_id'          => get_current_user_id(),
			)
		),
	);
}

/**
 * Sanitize the one reserved key with no explicit branch of its own:
 * `_schema`, today the only member of BLUELINE_SETTINGS_RESERVED_KEYS that
 * blueline_settings_sanitize_callback() has not already dispatched by name.
 *
 * A programmatic write (blueline_settings_migrate()'s own update_option()
 * call, WP-CLI, an import script) is exactly the path this reserved key
 * needs to keep surviving. Still sanitized like everything else, never
 * trusted as opaque data, then clamped to BLUELINE_SETTINGS_SCHEMA_VERSION,
 * matching the limit blueline_settings_import_prepare()
 * (inc/settings/import.php) enforces on an import's own `_schema` for the
 * CLI and the panel alike, since fe37280 unified them (there, by refusing
 * the whole import outright; here, by clamping, since this path returns a
 * value to store rather than an all-or-nothing operation to abort). Without
 * this, a `_schema` at or above the running code's version, written via
 * any direct update_option() call this reserved-key branch lets through,
 * would make blueline_settings_migrate()'s forward-only guard
 * (inc/settings/store.php) treat the install as already current,
 * permanently and silently skipping every future migration, recoverable
 * only via WP-CLI or the database directly.
 *
 * @param mixed $value Raw posted (or programmatically written) value.
 * @return int
 */
function blueline_settings_sanitize_reserved_schema( $value ): int {
	return min( absint( $value ), BLUELINE_SETTINGS_SCHEMA_VERSION );
}
