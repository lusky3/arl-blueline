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
 * schema (inc/settings/defaults.php) opts a field into this protection by
 * declaring a `placeholders` array -- the exact conversion specs (e.g. '%s')
 * its value is required to contain, because the call site sprintf()s it with
 * exactly that many arguments. A field with no `placeholders` key is never
 * used as a format string and is not subject to either check below.
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
 * - type: one of `bcdeEfFgGosuxX`, verified individually as accepted by
 *   sprintf(); every other letter (a, n, t, z, ...) verified to throw
 *   ValueError "Unknown format specifier".
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
const BLUELINE_SPRINTF_SPEC = '%(?:[1-9][0-9]*\$)?(?:[-+ 0]|\'.)*[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX]';

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
 * Sanitize one settings-panel field value according to its schema type,
 * enforcing the `placeholders` sprintf() contract when the field declares
 * one.
 *
 * `page_id`/`term_id` fields sanitize to a non-negative integer (`0` means
 * "use the fallback", per the schema's own docblock); `bool` fields sanitize
 * to a real boolean. Every other type -- `text`, `email`, `textarea` today --
 * sanitizes through sanitize_text_field(): see this file's docblock for why
 * wp_kses_post() is deliberately not used.
 *
 * A field that declares a `placeholders` key (checked with
 * array_key_exists(), so an explicitly empty array() still opts in -- that
 * is how a field asserts "my value is used as a sprintf() format string with
 * zero required arguments") is additionally required to:
 *
 * 1. Contain no "%" that would fatal in sprintf()/printf() -- see
 *    blueline_percent_is_safe().
 * 2. Contain EXACTLY the declared set of conversion specifications, as a
 *    multiset (same specs, same count each, order-independent) -- not
 *    merely "contains at least these". A value missing a required spec
 *    would ArgumentCountError at the real call site for lack of an argument
 *    to fill it; a value containing an EXTRA spec the schema did not declare
 *    -- including an accidental one hiding in ordinary prose, see
 *    BLUELINE_SPRINTF_SPEC's docblock -- would ArgumentCountError or
 *    silently misuse an argument for exactly the same reason, just from the
 *    other direction.
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

	if ( ! array_key_exists( 'placeholders', $field ) ) {
		return $sanitized;
	}

	if ( ! blueline_percent_is_safe( $sanitized ) ) {
		return new WP_Error(
			'blueline_unsafe_format_specifier',
			sprintf(
				/* translators: %s: the field's label. */
				__( '"%s" contains a "%%" that is not a valid format placeholder and would crash the site if saved.', 'blueline' ),
				$field['label'] ?? ''
			)
		);
	}

	$required = (array) $field['placeholders'];
	sort( $required );

	$found = blueline_extract_placeholders( $sanitized );
	sort( $found );

	if ( $required !== $found ) {
		return new WP_Error(
			'blueline_placeholder_mismatch',
			sprintf(
				/* translators: 1: the field's label, 2: comma-separated list of required placeholders. */
				__( '"%1$s" must contain exactly these placeholders: %2$s', 'blueline' ),
				$field['label'] ?? '',
				implode( ', ', (array) $field['placeholders'] )
			)
		);
	}

	return $sanitized;
}
