<?php
/**
 * Field sanitizers for the Appearance -> Blueline control panel, including
 * the placeholder contract that keeps a stored value safe to reach any of
 * the theme's ~40 sprintf()/printf() call sites.
 *
 * On PHP 7 a malformed format string passed to sprintf()/printf() emitted a
 * warning and limped on. On PHP 8.3 -- this theme's runtime -- it THROWS:
 *
 *   sprintf( '%1$s . Week %2$d', 'Fall' )   -> ArgumentCountError
 *   sprintf( 'save 50% today', 'x' )        -> ValueError: Unknown format
 *                                              specifier "t"
 *
 * (Both verified with `php -r` against this exact runtime; see
 * SettingsSanitizeTest for a test that reproduces the second one directly,
 * proving the rejection this file performs is not academic.)
 *
 * A settings field whose value becomes the FORMAT STRING argument to one of
 * those call sites therefore cannot accept arbitrary admin input: a volunteer
 * typing an ordinary sentence containing a stray "%" can take the public site
 * down for every anonymous visitor, from a screen that reported success. The
 * schema (inc/settings/defaults.php) declares a `placeholders` array on every
 * `text`/`textarea` field -- the exact conversion specs (e.g. '%s') its value
 * is required to contain, because the call site sprintf()s it with exactly
 * that many arguments; `array()` for a field whose call site takes none.
 *
 * There is deliberately no third state where a field opts OUT of this check
 * by omitting `placeholders` entirely: SettingsDefaultsTest enforces that
 * every `text`/`textarea` field declares the key (even as `array()`), so
 * omission is a broken build, not a silent gap -- and blueline_sanitize_field()
 * below treats a missing key exactly like `placeholders => array()` (see its
 * own docblock) rather than skipping the check, so even a field the schema
 * test somehow missed still gets the safe default: reject any conversion
 * spec at all, rather than trust an absent key to mean "never a format
 * string". A field that legitimately never feeds sprintf() therefore still
 * declares `placeholders => array()`, and a literal "%" in its value must be
 * written "%%" -- the error message below says so and shows the fix.
 *
 * The sanitizer is sanitize_text_field(), never wp_kses_post(): every one of
 * these fields is echoed through esc_html()/esc_attr() at its call site (29
 * of the 40 are attribute contexts), so any markup wp_kses_post() permitted
 * through would render as literal, visible tag text on the front end rather
 * than as markup -- the opposite of what an admin typing "<strong>" would
 * expect, and no safer for it.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The grammar of one PHP sprintf()/printf() conversion specification,
 * EXCLUDING the "%%" literal-percent escape (handled separately in the
 * patterns built from this constant, and always tried before this branch --
 * see blueline_extract_placeholders()'s docblock for why the order matters).
 *
 * Matches PHP 8.3's own parser (ext/standard/formatted_print.c). Every clause
 * below was verified empirically with `php -r` against this runtime rather
 * than assumed; the task report carries the full transcript. Summary:
 *
 *   %  [ position$ ]  [ flags ]*  [ width ]  [ .precision ]  type
 *
 * - position: `[1-9][0-9]*\$` -- one or more digits NOT starting with 0,
 *   followed by "$". Verified `sprintf( '%0$s', 'x' )` throws ValueError
 *   ("Argument number specifier must be greater than zero..."), so a leading
 *   zero is deliberately excluded from the position branch.
 * - flags: `(?:[-+ 0]|'.)*`, zero or more of "-", "+", " ", "0", or a custom
 *   pad character introduced by an apostrophe followed by any one character
 *   (e.g. "%'*10d" pads with "*"). Verified `sprintf( "%'*10d", 5 )` produces
 *   a width-10 string padded with "*".
 * - width / precision: plain digit runs, precision introduced by ".".
 *   Verified `%05.2f`, `%-10s`, `%+d` all sanitize correctly with this
 *   grammar rather than being mis-split into "width" vs "flag" wrong.
 * - type: one of `bcdeEfFgGhHosuxX`, verified individually as accepted by
 *   sprintf() -- including "h"/"H" (PHP 8.0's locale-independent shortest
 *   float representation), easy to miss because they are rarely used and
 *   absent from many quick references. Every other letter (a, n, t, z,
 *   ...) verified to throw ValueError "Unknown format specifier" --
 *   notably "n" is NOT a valid PHP sprintf() type despite being one in
 *   some other languages' printf().
 *
 * The type set deliberately does NOT include a bare "%" (that is the
 * separate %% literal-escape branch) or "$" (that only appears as part of
 * the position clause above, never as a type on its own).
 *
 * IMPORTANT, non-obvious finding from verifying this empirically: because
 * " " (space) is a valid FLAG and "o", "s", "d", "f", "b", "x", "g", "u",
 * "c", "e" are all valid TYPEs, an entirely ordinary sentence can contain an
 * accidental, syntactically valid conversion spec with no "%s"-shaped
 * placeholder in sight -- e.g. "Save 50% off, %s!" hides a real `% o` spec
 * (flag " ", type "o") in "% off", verified to consume a whole extra
 * argument and mangle the string:
 *
 *   sprintf( 'Save 50% off, %s!', 'now' )
 *     -> ArgumentCountError: 3 arguments are required, 2 given
 *   sprintf( 'Save 50% off, %s!', 'now', 'extra' )
 *     -> 'Save 500ff, extra!'   (the "% o" ate 'now' as an octal conversion)
 *
 * This is exactly why blueline_sanitize_field() below requires the set of
 * placeholders actually found to match the declared contract EXACTLY (not
 * merely "contains the required ones") -- an accidental extra spec like this
 * is invisible to a "does it contain %s" check but still fatal in
 * production.
 */
const BLUELINE_SPRINTF_SPEC = '%(?:[1-9][0-9]*\$)?(?:[-+ 0]|\'.)*[0-9]*(?:\.[0-9]+)?[bcdeEfFgGhHosuxX]';

/**
 * Extract every sprintf()/printf() conversion specification actually present
 * in $text, in the order they appear, skipping "%%" (a literal percent, not
 * a placeholder -- verified `sprintf( 'save 50%% today' )` returns the plain
 * string 'save 50% today' with no argument consumed).
 *
 * "%%" is tried FIRST in the pattern below, and PHP's own preg_match_all()
 * scans left to right without backtracking past a completed match -- the
 * same left-to-right, first-match-wins order PHP's sprintf() parser itself
 * uses. That ordering is load-bearing: for the input "%%s", PHP's sprintf()
 * consumes "%%" as a literal percent first and leaves "s" as plain text (no
 * placeholder). Verified: `sprintf( '%%s' )` returns the literal string
 * '%s' (one percent, one "s"), not a placeholder call requiring an argument.
 * Trying the conversion-spec branch first would instead match "%s" starting
 * at the second "%" and misreport a placeholder that sprintf() never sees.
 *
 * @param string $text Text to scan for conversion specifications.
 * @return string[] Placeholder tokens in order of appearance, e.g.
 *                   array( '%s' ) or array( '%1$s', '%2$d' ). Empty when
 *                   $text contains no conversion specification.
 */
function blueline_extract_placeholders( string $text ): array {
	preg_match_all( '/%%|(' . BLUELINE_SPRINTF_SPEC . ')/', $text, $matches );

	return array_values( array_filter( $matches[1], static fn( $spec ) => '' !== $spec ) );
}

/**
 * Whether every "%" in $text is accounted for by either the literal "%%"
 * escape or a complete, validly-typed conversion specification -- i.e.
 * whether $text is safe to use as a sprintf()/printf() format string without
 * throwing.
 *
 * Works by stripping every recognised "%%" and conversion-spec occurrence
 * (the same two branches blueline_extract_placeholders() matches) and
 * checking whether any "%" survives. A "%" survives only when it could not
 * complete either branch -- e.g. a bare "%" at the end of the string, "%"
 * followed by a character that can start neither a flag/width/precision/type
 * sequence, or "%" followed by a letter outside the valid type set. Each of
 * those was verified to throw when passed through sprintf():
 *
 *   sprintf( 'save 50% today', 'x' )  -> ValueError: Unknown format
 *                                        specifier "t" ( "% t": " " is a
 *                                        valid flag, "t" is not a valid type,
 *                                        so parsing fails on the type char )
 *   sprintf( 'ends in %' )            -> ArgumentCountError (bare "%" at
 *                                        end of string; no type char to
 *                                        complete a specification at all)
 *
 * @param string $text Text to check.
 * @return bool True if $text contains no "%" that would fatal in
 *              sprintf()/printf(); false otherwise.
 */
function blueline_percent_is_safe( string $text ): bool {
	$stripped = preg_replace( '/%%|' . BLUELINE_SPRINTF_SPEC . '/', '', $text );

	return ! str_contains( (string) $stripped, '%' );
}

/**
 * Produce a corrected version of $text that a volunteer can paste straight
 * back into the field: every "%" that could not fatal (a recognised "%%"
 * escape, or a complete conversion specification) is left exactly as
 * written, and every "%" that WOULD fatal -- the ones blueline_percent_is_safe()
 * flags -- is doubled into a literal "%%" escape.
 *
 * Scans with the same three-way pattern as the two functions above, tried in
 * the same order (recognised "%%" first, then a complete spec, then a bare
 * "%" as the fallback that only matches what neither of those could), so a
 * bare "%" is corrected without disturbing a "%%" or a spec already present
 * elsewhere in the same string.
 *
 * @param string $text Text to correct.
 * @return string $text with every unsafe "%" escaped to "%%".
 */
function blueline_escape_stray_percents( string $text ): string {
	return (string) preg_replace_callback(
		'/%%|' . BLUELINE_SPRINTF_SPEC . '|%/',
		static fn( array $m ): string => '%' === $m[0] ? '%%' : $m[0],
		$text
	);
}

/**
 * Sanitize one settings-panel field value according to its schema type,
 * enforcing the `placeholders` sprintf() contract for every string-valued
 * field.
 *
 * `page_id`/`term_id` fields sanitize to a non-negative integer (`0` means
 * "use the fallback", per the schema's own docblock); `bool` fields sanitize
 * to a real boolean -- neither passes through a sprintf() format string
 * ever, so neither runs the checks below. Every other type -- `text`,
 * `email`, `textarea` today -- sanitizes through sanitize_text_field(): see
 * this file's docblock for why wp_kses_post() is deliberately not used.
 *
 * Every such string-valued field is REQUIRED to contain exactly its declared
 * `placeholders` contract, with no third "unchecked" state: `$field['placeholders']`
 * missing entirely is treated exactly like `placeholders => array()` --
 * zero conversion specs permitted -- rather than skipping the check. This
 * is deliberate, not an oversight: earlier this validator only ran when a
 * field opted in by declaring the key, which meant a field that SHOULD have
 * declared a contract but didn't (a schema-authoring mistake, or a future
 * field that starts feeding a sprintf() call site nobody updated the schema
 * for) was invisibly unprotected -- reproducing the exact class of fatal
 * this task exists to prevent, just one step removed. Requiring the key on
 * every `text`/`textarea` field (enforced by SettingsDefaultsTest, a broken
 * build rather than a silent gap) and defaulting an absent key to `array()`
 * closes that hole from both directions at once.
 *
 * The trade this accepts: a field that never touches sprintf() and simply
 * contains an ordinary "%" (e.g. "Save 50% today" in a heading) is now
 * rejected too, because `array()` permits zero specs and this string
 * contains one it doesn't recognise. That is an instantly-recoverable false
 * positive -- typing "%%" instead of "%" fixes it, and the error message
 * below says so and hands back the corrected string -- traded deliberately
 * against the alternative false negative: a stray "%" that silently
 * corrupts the rendered output or fatals the public site, verified on this
 * runtime (see BLUELINE_SPRINTF_SPEC's docblock and the task report):
 *
 *   sprintf( 'Save 50% off', 'X' )  -> 'Save 500ff'      (silent corruption)
 *   sprintf( 'Save 50% off' )       -> ArgumentCountError (public-site fatal)
 *
 * Given that choice, false positive wins.
 *
 * The full check, run unconditionally for every string-valued field:
 *
 * 1. Contain no "%" that would fatal in sprintf()/printf() -- see
 *    blueline_percent_is_safe(). Rejection names the fix: write a literal
 *    percent as "%%", and shows the corrected string
 *    (blueline_escape_stray_percents()) rather than leaving a volunteer to
 *    guess.
 * 2. Contain EXACTLY the declared set of conversion specifications (`array()`
 *    when `placeholders` is absent), as a multiset (same specs, same count
 *    each, order-independent) -- not merely "contains at least these". A
 *    value missing a required spec would ArgumentCountError at the real call
 *    site for lack of an argument to fill it; a value containing an EXTRA
 *    spec the schema did not declare -- including an accidental one hiding
 *    in ordinary prose, see BLUELINE_SPRINTF_SPEC's docblock -- would
 *    ArgumentCountError or silently misuse an argument for exactly the same
 *    reason, just from the other direction.
 *
 * A field failing either check returns a WP_Error rather than the sanitized
 * value, so register_setting()'s sanitize_callback (a later task's wiring)
 * can refuse to store it and report the failure back to the admin screen
 * that submitted it, instead of writing a value that fatals the next time
 * its call site runs.
 *
 * @param mixed $value The raw, as-submitted field value.
 * @param array $field The field's schema entry (inc/settings/defaults.php's
 *                      blueline_settings_schema()), at minimum carrying
 *                      `type` and optionally `placeholders`.
 * @return mixed The sanitized value, or a WP_Error describing why the
 *               submitted value was rejected.
 */
function blueline_sanitize_field( $value, array $field ) {
	$type = $field['type'] ?? 'text';

	if ( 'bool' === $type ) {
		return (bool) $value;
	}

	if ( 'page_id' === $type || 'term_id' === $type ) {
		return absint( $value );
	}

	$sanitized = sanitize_text_field( (string) $value );

	if ( ! blueline_percent_is_safe( $sanitized ) ) {
		return new WP_Error(
			'blueline_unsafe_format_specifier',
			sprintf(
				/* translators: 1: the field's label, 2: the value corrected to a saveable form. */
				__( '"%1$s" can\'t be saved as written -- it contains a "%%" that PHP would treat as a broken placeholder and crash the site on. To write a literal percent sign, use "%%%%" instead. Try: %2$s', 'blueline' ),
				$field['label'] ?? '',
				blueline_escape_stray_percents( $sanitized )
			)
		);
	}

	$required = (array) ( $field['placeholders'] ?? array() );
	sort( $required );

	$found = blueline_extract_placeholders( $sanitized );
	sort( $found );

	if ( $required !== $found ) {
		return new WP_Error(
			'blueline_placeholder_mismatch',
			sprintf(
				/* translators: 1: the field's label, 2: comma-separated list of required placeholders, or "none" when the field permits no conversion specs at all. */
				__( '"%1$s" must contain exactly these placeholders: %2$s', 'blueline' ),
				$field['label'] ?? '',
				$required ? implode( ', ', $required ) : __( 'none', 'blueline' )
			)
		);
	}

	return $sanitized;
}
