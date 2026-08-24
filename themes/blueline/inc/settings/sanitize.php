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
 * `email`-typed fields are the one exception to "sanitize_text_field() plus
 * the placeholder check": before Task 8's fix round, `type => 'email'` was
 * purely decorative -- an email field fell through to the exact same path
 * as a plain `text` field, guaranteeing nothing about the value's actual
 * shape. That mattered concretely for `contact_email`, echoed into a
 * `mailto:` href: rather than trust esc_url() alone to neutralise whatever a
 * volunteer typed, blueline_sanitize_field() now runs an `email` value
 * through core's own is_email() and rejects anything it would reject --
 * closing the gap at the value's source rather than only at its render site.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The grammar of one PHP sprintf()/printf() conversion specification,
 * EXCLUDING the "%%" literal-percent escape (handled separately in the
 * patterns built from this constant, and written before this branch -- see
 * blueline_extract_placeholders()'s docblock, which measures both orderings
 * and finds them equivalent; the order is readability, not correctness).
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
 * "%%" is tried FIRST in the pattern below, matching the left-to-right,
 * first-match-wins order PHP's sprintf() parser itself uses: for the input
 * "%%s", sprintf() consumes "%%" as a literal percent and leaves "s" as plain
 * text. Verified: `sprintf( '%%s' )` returns the literal string '%s' (one
 * percent, one "s"), not a placeholder call requiring an argument.
 *
 * That ordering is NOT load-bearing, despite reading as though it must be, and
 * an earlier version of this docblock claimed it was -- that putting the
 * conversion-spec branch first would "match '%s' starting at the second '%'".
 * It would not. At the first "%" of "%%", the spec branch cannot match at all:
 * "%" is neither a valid flag nor a valid type, so the alternation has only
 * "%%" available there whichever branch is written first, and the engine never
 * reaches the second "%" with the first one unconsumed. Measured across "%%s",
 * "%%%s", "%%1$s", "%'%s" and five others: both orderings return identical
 * results for every input. The order is kept because it reads in the same
 * order sprintf() thinks, not because reversing it would break anything.
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
 * back into the field: a "%%" escape is always left exactly as written, and
 * every OTHER "%" -- a bare one that cannot complete any specification at
 * all, or a complete conversion spec that is not in $wanted -- is escaped by
 * prefixing it with an extra "%", turning it into a literal percent sign
 * followed by whatever text came after it (e.g. "% o" becomes "%% o", which
 * sprintf() renders back as the literal text "% o", consuming no argument).
 *
 * Scans with the same three-way pattern as the functions above, tried in the
 * same order (recognised "%%" first, then a complete spec, then a bare "%"
 * as the fallback that only matches what neither of those could), so a fix
 * touches only the "%" that actually needs it.
 *
 * $wanted defaults to "every complete spec already present in $text" --
 * i.e. touch nothing but a genuinely bare, unparseable "%" -- which is the
 * right default when the caller already knows $text failed
 * blueline_percent_is_safe() and just wants that failure corrected without
 * relitigating which specs are declared. Passing the field's OWN declared
 * `placeholders` array instead (see blueline_sanitize_field()) additionally
 * escapes a complete-but-UNDECLARED spec -- the "hides in ordinary prose"
 * case from BLUELINE_SPRINTF_SPEC's docblock, where blueline_percent_is_safe()
 * alone would report the text as "safe" because the spec parses fine; it is
 * just not one this field ever asked for.
 *
 * @param string        $text   Text to correct.
 * @param string[]|null $wanted Conversion specs to leave untouched even
 *                              though they are not "%%". Defaults to every
 *                              spec already found in $text.
 * @return string $text with every unwanted "%" escaped to a literal "%%".
 */
function blueline_escape_stray_percents( string $text, ?array $wanted = null ): string {
	$wanted ??= blueline_extract_placeholders( $text );

	return (string) preg_replace_callback(
		'/%%|' . BLUELINE_SPRINTF_SPEC . '|%/',
		static function ( array $m ) use ( $wanted ): string {
			if ( '%%' === $m[0] ) {
				return $m[0];
			}

			if ( '%' === $m[0] ) {
				return '%%';
			}

			return in_array( $m[0], $wanted, true ) ? $m[0] : '%' . $m[0];
		},
		$text
	);
}

/**
 * Render a count as a short English phrase for a rejection message --
 * "once", "twice", or "N times" -- rather than the grammatically awkward
 * "1 times" a plain sprintf( '%d times', $n ) would produce for the single
 * most common case.
 *
 * @param int $n Count to phrase.
 * @return string
 */
function blueline_times_phrase( int $n ): string {
	if ( 1 === $n ) {
		return __( 'once', 'blueline' );
	}

	if ( 2 === $n ) {
		return __( 'twice', 'blueline' );
	}

	return sprintf(
		/* translators: %d: a count of 0, or 3 or more. */
		__( '%d times', 'blueline' ),
		$n
	);
}

/**
 * Build the human-readable list of placeholder-contract problems between
 * $required and $found -- one clause per mismatched spec, ready to
 * implode( '; ', ... ) into a single message. Empty when $required and
 * $found are the same multiset (nothing to report).
 *
 * COUNT-AWARE, unlike a plain array_diff( $required, $found ): array_diff()
 * is set-based, so a spec repeated a different number of times than
 * declared (e.g. `placeholders => array( '%s', '%s' )` but the value has
 * only one `%s`) diffs to nothing in EITHER direction -- `$missing` and
 * `$extra` both come back empty even though the reject decision (a proper
 * sorted-array multiset compare, done by the caller) correctly refused the
 * value. A blank message ('"Season banner" .') is worse than no message:
 * this function exists so that case has something real to say ("must
 * contain %s twice, but the value only has it once").
 *
 * @param string[] $required Declared placeholder contract (may contain
 *                            duplicates on purpose, e.g. a spec used twice).
 * @param string[] $found    Placeholders actually present in the value.
 * @return string[] One already-punctuated clause per spec whose required
 *                   and actual counts differ, in a stable order (every
 *                   `$required` spec first, then any spec found but never
 *                   declared at all).
 */
function blueline_placeholder_mismatch_reasons( array $required, array $found ): array {
	$required_counts = array_count_values( $required );
	$found_counts    = array_count_values( $found );

	$specs = array_unique( array_merge( array_keys( $required_counts ), array_keys( $found_counts ) ) );

	$reasons = array();
	foreach ( $specs as $spec ) {
		$needed = $required_counts[ $spec ] ?? 0;
		$have   = $found_counts[ $spec ] ?? 0;

		if ( $needed === $have ) {
			continue; // This spec's count matches; nothing wrong with it.
		}

		if ( 0 === $have ) {
			// Declared, but does not appear in the value at all -- the
			// simple "dropped a placeholder" case, wording unchanged from
			// before this function existed.
			$reasons[] = sprintf(
				/* translators: %s: the missing placeholder token. */
				__( 'must contain %s -- it\'s missing', 'blueline' ),
				$spec
			);
		} elseif ( 0 === $needed ) {
			// Present, but never declared at all -- the simple "extra
			// placeholder" case, wording unchanged from before this
			// function existed.
			$reasons[] = sprintf(
				/* translators: %s: the unexpected placeholder token. */
				__( 'contains %s, which is not expected here -- remove it', 'blueline' ),
				$spec
			);
		} elseif ( $have < $needed ) {
			// Declared AND present, just not the right number of times.
			$reasons[] = sprintf(
				/* translators: 1: placeholder token, 2: how many times required, 3: how many times actually present. */
				__( 'must contain %1$s %2$s, but the value only has it %3$s', 'blueline' ),
				$spec,
				blueline_times_phrase( $needed ),
				blueline_times_phrase( $have )
			);
		} else {
			$reasons[] = sprintf(
				/* translators: 1: placeholder token, 2: how many times required, 3: how many times actually present. */
				__( 'must contain %1$s %2$s, but the value has it %3$s -- remove the extra', 'blueline' ),
				$spec,
				blueline_times_phrase( $needed ),
				blueline_times_phrase( $have )
			);
		}
	}

	return $reasons;
}

/**
 * Whether two placeholder lists are the same MULTISET -- same specs, same
 * count of each, order irrelevant.
 *
 * A plain `$required === $found` would fail for the identical contract
 * satisfied in a different order (e.g. `%2$s ... %1$s` vs `%1$s ... %2$s`),
 * and a set-based compare (array_diff() in either direction) would miss a
 * spec repeated the wrong number of times -- see
 * blueline_placeholder_mismatch_reasons()'s own docblock for that failure
 * mode. Sorting a copy of each array first, then comparing, is the cheapest
 * correct multiset compare for the small lists this deals with.
 *
 * @param string[] $required Declared placeholder contract (may contain
 *                            duplicates on purpose, e.g. a spec used twice).
 * @param string[] $found    Placeholders actually present in a value.
 * @return bool
 */
function blueline_placeholders_match( array $required, array $found ): bool {
	$required_sorted = $required;
	sort( $required_sorted );
	$found_sorted = $found;
	sort( $found_sorted );

	return $required_sorted === $found_sorted;
}

/**
 * Sanitize one settings-panel field value according to its schema type,
 * enforcing the `placeholders` sprintf() contract for every string-valued
 * field.
 *
 * `page_id`/`term_id` fields sanitize to a non-negative integer (`0` means
 * "use the fallback", per the schema's own docblock); `bool` and `section`
 * fields both sanitize to a real boolean -- neither passes through a
 * sprintf() format string ever, so none of these four run the checks
 * below. Every other type -- `text`, `email`, `textarea` today -- sanitizes
 * through sanitize_text_field(): see this file's docblock for why
 * wp_kses_post() is deliberately not used.
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
 * against the alternative false negative: a stray "%" that, depending on
 * arity, either silently corrupts the rendered output or fatals the public
 * site (see the worked example a few paragraphs down). Given that choice,
 * false positive wins.
 *
 * The full check, run unconditionally for every string-valued field, ends in
 * one of two DISTINCT rejection messages -- not one generic one -- because
 * they call for different fixes and a volunteer should not have to work out
 * which applies:
 *
 * (a) STRAY PERCENT -- `blueline_unsafe_format_specifier`. Either
 *     blueline_percent_is_safe() found a "%" that cannot complete any valid
 *     specification at all, or the field declares NO placeholders at all
 *     (`placeholders => array()`) yet the value contains one anyway -- which
 *     can only mean an ordinary "%" a volunteer meant literally was
 *     accidentally read as a formatting instruction (see
 *     BLUELINE_SPRINTF_SPEC's docblock for how easily "% off" becomes a real
 *     `% o` spec). Both read the same to the person who has to fix them: a
 *     stray "%" that must become "%%". The message names the offending
 *     sequence when there is one to name, explains what PHP does with it,
 *     and hands back a corrected string via blueline_escape_stray_percents()
 *     -- ALWAYS built against $required, never the escape helper's own
 *     "every spec already present" default, and ALWAYS verified (by
 *     re-running it through blueline_extract_placeholders() and comparing
 *     to $required) before it is offered, so the suggestion handed back is
 *     never one that would itself be rejected on resubmission. A value can
 *     have a genuinely-broken "%" AND separately fail the placeholder
 *     contract (most often: a required spec is simply absent, unrelated to
 *     the broken "%"); when escaping alone cannot also satisfy the
 *     contract, the message says so explicitly and names the remaining
 *     problem via blueline_placeholder_mismatch_reasons() rather than
 *     silently handing back a "fix" that fails a second time.
 * (b) CONTRACT MISMATCH -- `blueline_placeholder_mismatch`. The field DOES
 *     declare specific required placeholders, and the value's actual set
 *     differs from them -- a required one was dropped, an unexpected one was
 *     added, a spec was repeated the wrong number of times, or some
 *     combination. Nothing here is "accidental" in the same sense as (a):
 *     these are real, valid conversion specs, just the wrong ones (or the
 *     wrong count) for this field's call site. The message names which
 *     placeholder is missing and/or which one is unexpected, COUNT-AWARE
 *     (blueline_placeholder_mismatch_reasons() uses array_count_values(),
 *     not array_diff(), specifically so a spec declared twice but supplied
 *     once has something real to say rather than rendering blank), rather
 *     than printing the whole required list and leaving the reader to diff
 *     it against what they typed.
 *
 * Either way: a value missing a required spec would ArgumentCountError at
 * the real call site for lack of an argument to fill it; a value containing
 * an extra spec the schema did not declare would ArgumentCountError or
 * silently misuse an argument for exactly the same reason, just from the
 * other direction -- verified on this runtime (see BLUELINE_SPRINTF_SPEC's
 * docblock and the task report):
 *
 *   sprintf( 'Save 50% off', 'X' )  -> 'Save 500ff'      (silent corruption)
 *   sprintf( 'Save 50% off' )       -> ArgumentCountError (public-site fatal)
 *
 * A field failing either check returns a WP_Error rather than the sanitized
 * value, so register_setting()'s sanitize_callback (a later task's wiring)
 * can refuse to store it and report the failure back to the admin screen
 * that submitted it, instead of writing a value that fatals the next time
 * its call site runs. Both messages interpolate field-controlled data (the
 * schema's own `label`) and value-derived data (the corrected string, the
 * offending specs) that a caller echoing this message MUST still esc_html()
 * at the point of output -- this function returns plain text, not
 * pre-escaped markup.
 *
 * @param mixed $value The raw, as-submitted field value.
 * @param array $field The field's schema entry (inc/settings/defaults.php's
 *                      blueline_settings_schema()), at minimum carrying
 *                      `type` and optionally `placeholders`.
 * @return mixed The sanitized value, or a WP_Error describing why the
 *               submitted value was rejected.
 */
function blueline_sanitize_field( $value, array $field ) {
	$type  = $field['type'] ?? 'text';
	$label = $field['label'] ?? '';

	if ( 'bool' === $type || 'section' === $type ) {
		// `section` (inc/settings/sections.php's presence toggles) sanitizes
		// identically to `bool` -- a distinct type name only so the schema
		// and blueline_section_enabled() stay conceptually separate from the
		// panel's other boolean toggles, not because the value needs
		// different handling.
		return (bool) $value;
	}

	if ( 'page_id' === $type || 'term_id' === $type ) {
		return absint( $value );
	}

	if ( 'band_photos' === $type ) {
		return blueline_sanitize_band_photos( $value, $field );
	}

	if ( 'email' === $type ) {
		// Fix round 1 (Task 8): before this, an `email`-typed field fell
		// through to the exact same sanitize_text_field() + placeholder-only
		// path as a plain `text` field -- meaning the schema's `email` type
		// was purely decorative, guaranteeing NOTHING about the value's
		// shape. That matters here specifically because contact_email is
		// echoed into a `mailto:` href: a value is_email() would reject
		// (stray quotes, angle brackets, spaces -- none of which are legal
		// in either the local-part or domain WordPress' own is_email()
		// accepts) can never be assembled into that attribute in the first
		// place, rather than trusting esc_url() alone to neutralise
		// whatever a volunteer typed. is_email() is core's own validator,
		// already used elsewhere in this theme (inc/account/avatars.php).
		$sanitized = sanitize_text_field( (string) $value );

		if ( '' === $sanitized || ! is_email( $sanitized ) ) {
			return new WP_Error(
				'blueline_invalid_email',
				sprintf(
					/* translators: %s: the field's label. */
					__( '"%s" must be a valid email address.', 'blueline' ),
					$label
				)
			);
		}

		return $sanitized;
	}

	if ( 'date' === $type ) {
		/*
		 * Task 6: a `date` field stores a strict Y-m-d string, or '' for
		 * "not set" -- both of the announcement window's bounds are
		 * optional, so '' is a legitimate value here, unlike in the
		 * `email` branch above where an empty address is simply wrong.
		 *
		 * A bad value returns a WP_Error rather than coercing to '',
		 * following that same `email` precedent: coercing would silently
		 * turn "shown until the 30th" into "shown forever" and report a
		 * successful save while doing it. The admin is told instead.
		 *
		 * The round-trip comparison is what makes this STRICT rather than
		 * merely well-formed. DateTimeImmutable::createFromFormat() accepts
		 * an out-of-range day and rolls it over -- '2026-02-30' becomes 2
		 * March -- so a value is only accepted when reformatting the parsed
		 * date reproduces exactly what was submitted. That also rejects the
		 * loose forms ('2026-9-1', '30/09/2026') that <input type="date">
		 * never produces but a paste or an import can.
		 */
		$submitted = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $submitted ) {
			return '';
		}

		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d', $submitted, wp_timezone() );

		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $submitted ) {
			return new WP_Error(
				'blueline_invalid_date',
				sprintf(
					/* translators: %s: the field's label. */
					__( '"%s" must be a date in YYYY-MM-DD form, or empty.', 'blueline' ),
					$label
				)
			);
		}

		return $submitted;
	}

	return blueline_sanitize_placeholder_text( $value, $field );
}

/**
 * Sanitize the default text-field branch: `sanitize_text_field()` plus a
 * `choices` allow-list check (when the schema declares one) plus the
 * `placeholders` sprintf() contract every string-valued field must satisfy
 * exactly, with no third "unchecked" state.
 *
 * Every type blueline_sanitize_field() dispatches to a WP_Error or a coerced
 * scalar of its own BEFORE reaching here (`bool`/`section`, `page_id`/`term_id`,
 * `band_photos`, `email`, `date`) falls through to this branch instead --
 * `text`, `textarea`, and any `choices`-bearing field (`season_state_override`,
 * `announcement_severity`) among them.
 *
 * @param mixed $value Raw submitted value.
 * @param array $field The field's schema entry.
 * @return string|WP_Error The sanitized value, or an error describing the
 *                          rejection.
 */
function blueline_sanitize_placeholder_text( $value, array $field ) {
	$label = $field['label'] ?? '';

	$sanitized = sanitize_text_field( (string) $value );
	$required  = (array) ( $field['placeholders'] ?? array() );

	if ( isset( $field['choices'] ) && is_array( $field['choices'] ) ) {
		/*
		 * Task 7 fix round: a field declaring `choices` accepts only the
		 * values on its own list, and says so when it does not.
		 *
		 * This is a SAVE-time guard, and the two fields that use it
		 * (`season_state_override` and `announcement_severity`) also clamp
		 * on read. That is not redundant, but the reason is narrower than
		 * it might look: this branch runs on EVERY write through
		 * update_option(), including WP-CLI and import scripts, because it
		 * is reached via `sanitize_option_{$option}` registered at file
		 * scope (see inc/settings/page.php's "Every write path is
		 * validated"). The read clamps exist for what this branch cannot
		 * see: a value written straight to the database (`wp db import`, a
		 * `$wpdb` write, a hand-edited row), which never reaches any PHP
		 * write guard, and -- since the clamps sit on the read side -- a
		 * plugin filtering `option_blueline_settings` on the way out, which
		 * no write-time check could catch even in principle.
		 *
		 * What a read clamp CANNOT do is tell anybody, which is what this
		 * branch adds. Before it existed, an admin who typed `playofs` into
		 * the break-glass got "Settings saved.", no change to the site, and
		 * no admin notice either -- the notice only renders while the
		 * override reads back as one of the five. A silent no-op is the
		 * wrong failure mode for an emergency control.
		 *
		 * The renderer (inc/settings/page.php) makes the invalid state
		 * mostly unreachable by rendering these as a `<select>`; this is
		 * the guard behind it, for a hand-built POST or a WP-CLI write.
		 *
		 * Compared as strings against array_keys(), not via
		 * array_key_exists(), because PHP silently casts a numeric-string
		 * array key to an integer -- no current choice is numeric, but a
		 * future one would fail this check for a reason nobody would guess.
		 *
		 * A passing value then falls THROUGH to the ordinary text checks
		 * below rather than returning here, so a `choices` field's
		 * `placeholders` contract (which SettingsDefaultsTest requires it to
		 * declare, like any other text field) is still actually enforced
		 * instead of being a declaration nothing reads. Today every listed
		 * value is a bare literal, so those checks pass trivially -- which
		 * is the point: the guarantee holds without anyone having to
		 * remember it.
		 */
		$allowed = array_map( 'strval', array_keys( $field['choices'] ) );

		if ( ! in_array( $sanitized, $allowed, true ) ) {
			return new WP_Error(
				'blueline_invalid_choice',
				sprintf(
					/* translators: 1: the field's label, 2: comma-separated list of the values the field accepts. */
					__( '"%1$s" only accepts one of: %2$s', 'blueline' ),
					$label,
					implode( ', ', array_map( static fn( $choice ) => '' === $choice ? '(empty)' : $choice, $allowed ) )
				)
			);
		}
	}

	if ( ! blueline_percent_is_safe( $sanitized ) ) {
		// Escaping MUST be checked against $required, not left at
		// blueline_escape_stray_percents()'s "every spec already present"
		// default: a value can have a genuinely-broken "%" AND separately
		// fail the placeholder contract (e.g. this field requires nothing,
		// but the value also happens to contain a real, undeclared "%s").
		// Escaping with the default would leave that undeclared spec in
		// place, so the "corrected" string offered back would itself be
		// rejected on resubmission -- exactly the trust-destroying failure
		// mode this message exists to prevent.
		$corrected = blueline_escape_stray_percents( $sanitized, $required );

		$corrected_found = blueline_extract_placeholders( $corrected );

		if ( blueline_placeholders_match( $required, $corrected_found ) ) {
			return new WP_Error(
				'blueline_unsafe_format_specifier',
				sprintf(
					/* translators: 1: the field's label, 2: the value corrected to a saveable form. */
					__( '"%1$s" contains a "%%" that PHP can\'t parse as a formatting instruction -- depending on the value, this can silently corrupt the saved text or crash the page. To show a percent sign, double it: %2$s', 'blueline' ),
					$label,
					$corrected
				)
			);
		}

		// Escaping the stray "%" is not enough by itself: the placeholder
		// contract is ALSO violated (typically a required spec is simply
		// absent, which no amount of escaping can fabricate -- there is no
		// way to know where a volunteer meant to place it). Say so
		// explicitly rather than handing back $corrected as if it were a
		// complete fix; it is only a partial one.
		return new WP_Error(
			'blueline_unsafe_format_specifier',
			sprintf(
				/* translators: 1: the field's label, 2: the value with the stray percent escaped (not a complete fix by itself), 3: the separate placeholder-contract problem(s), already assembled into one clause. */
				__( '"%1$s" contains a "%%" that PHP can\'t parse as a formatting instruction. Escaping it -- e.g. "%2$s" -- is not enough by itself: it also %3$s.', 'blueline' ),
				$label,
				$corrected,
				implode( '; ', blueline_placeholder_mismatch_reasons( $required, $corrected_found ) )
			)
		);
	}

	$found = blueline_extract_placeholders( $sanitized );

	if ( blueline_placeholders_match( $required, $found ) ) {
		return $sanitized;
	}

	if ( empty( $required ) && ! empty( $found ) ) {
		// This field declares NO placeholders at all, yet the value
		// contains one -- there is no legitimate reading of that: it can
		// only be an ordinary "%" a volunteer meant literally, accidentally
		// completing a real conversion spec (see BLUELINE_SPRINTF_SPEC's
		// docblock). Treated as a stray percent, not a "contract mismatch",
		// because that is what it actually is. Escaping with $required
		// (empty here) always fully resolves this case -- every found spec
		// gets escaped, leaving nothing for the placeholder check to trip
		// on -- so, unlike the branch above, there is no compound case to
		// worry about here.
		return new WP_Error(
			'blueline_unsafe_format_specifier',
			sprintf(
				/* translators: 1: the field's label, 2: the accidental conversion spec(s) found, 3: the value corrected to a saveable form. */
				__( '"%1$s" contains "%2$s", which PHP reads as a formatting instruction rather than a literal percent sign -- depending on the value, this can silently corrupt the saved text or crash the page. To show a percent sign, double it: %3$s', 'blueline' ),
				$label,
				implode( ', ', $found ),
				blueline_escape_stray_percents( $sanitized, $required )
			)
		);
	}

	return new WP_Error(
		'blueline_placeholder_mismatch',
		sprintf(
			/* translators: 1: the field's label, 2: what is missing and/or unexpected, already assembled into one clause. */
			__( '"%1$s" %2$s.', 'blueline' ),
			$label,
			implode( '; ', blueline_placeholder_mismatch_reasons( $required, $found ) )
		)
	);
}

/**
 * The nine alignments a band photograph may be given, mapped to the
 * `background-position` each one becomes.
 *
 * A FIXED WHITELIST, not a free-text field. The value ends up inside a
 * stylesheet declaration, so anything an admin could type would be typed
 * straight into CSS -- a closed set means the render path never has to trust
 * it, because an unknown key simply is not in the map.
 *
 * @return array<string,string> Alignment key => background-position value.
 */
function blueline_band_photo_alignments(): array {
	return array(
		'left-top'      => 'left 20%',
		'center-top'    => 'center 20%',
		'right-top'     => 'right 20%',
		'left-center'   => 'left 40%',
		'center-center' => 'center 40%',
		'right-center'  => 'right 40%',
		'left-bottom'   => 'left 75%',
		'center-bottom' => 'center 75%',
		'right-bottom'  => 'right 75%',
	);
}

/**
 * Sanitize the hero photograph list: rows of { id, align }.
 *
 * Rejects rather than repairs anything that is not a real image attachment. A
 * silently-dropped bad id would leave the admin looking at a list one shorter
 * than the one they submitted, with no explanation; a rejection names the
 * field and keeps their input on screen.
 *
 * @param mixed $value Raw submitted value: expected to be an array of rows.
 * @param array $field The field's schema entry.
 * @return array|WP_Error Sanitized rows, or an error describing the rejection.
 */
function blueline_sanitize_band_photos( $value, array $field ) {
	$label = $field['label'] ?? '';
	$max   = (int) ( $field['max'] ?? 12 );

	// An untouched field posts nothing at all, which is a legitimately empty
	// list -- and empty means "use the theme's own photographs", so it is
	// never an error.
	if ( '' === $value || null === $value || array() === $value ) {
		return array();
	}

	if ( ! is_array( $value ) ) {
		return new WP_Error(
			'blueline_band_photos_shape',
			sprintf(
				/* translators: %s: the field's label. */
				__( '"%s" could not be read. Please re-select the photographs and save again.', 'blueline' ),
				$label
			)
		);
	}

	if ( count( $value ) > $max ) {
		return new WP_Error(
			'blueline_band_photos_max',
			sprintf(
				/* translators: 1: the field's label, 2: the maximum number of photographs. */
				__( '"%1$s" is limited to %2$d photographs. Every one of them can be downloaded by a visitor, so the list is capped deliberately.', 'blueline' ),
				$label,
				$max
			)
		);
	}

	$alignments = blueline_band_photo_alignments();
	$rows       = array();
	$seen       = array();

	foreach ( $value as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$id = absint( $row['id'] ?? 0 );

		if ( ! $id ) {
			continue;
		}

		/*
		 * wp_attachment_is_image(), not merely "does a post with this id
		 * exist": the id arrives from a form and could name any post on the
		 * site. Rendering a PDF or an ordinary page id as a background-image
		 * would emit a broken url() and, for a private attachment, confirm
		 * that it exists.
		 */
		if ( ! wp_attachment_is_image( $id ) ) {
			return new WP_Error(
				'blueline_band_photos_not_image',
				sprintf(
					/* translators: 1: the field's label, 2: the offending attachment ID. */
					__( '"%1$s" includes something that is not an image (ID %2$d). Please remove it and save again.', 'blueline' ),
					$label,
					$id
				)
			);
		}

		// The same photograph twice makes rotation look broken rather than
		// random, and doubles its odds for no reason.
		if ( isset( $seen[ $id ] ) ) {
			continue;
		}

		$seen[ $id ] = true;

		$align = (string) ( $row['align'] ?? '' );

		if ( ! isset( $alignments[ $align ] ) ) {
			$align = 'center-center';
		}

		$rows[] = array(
			'id'    => $id,
			'align' => $align,
		);
	}

	return $rows;
}
