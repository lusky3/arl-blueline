<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';

/**
 * Covers blueline_extract_placeholders() and blueline_sanitize_field() --
 * the validator that stands between a volunteer's copy-field edit and an
 * uncaught fatal at one of the theme's ~40 sprintf()/printf() call sites.
 *
 * Several tests below call PHP's real sprintf() directly, deliberately
 * outside blueline_sanitize_field(), with the exact string the sanitizer
 * just rejected. That is not incidental coverage: it is the proof that
 * rejecting the string matters -- each one demonstrates the actual
 * PHP 8.3 exception a bare pre-Task-3 settings save would have shipped to
 * production, on this exact runtime, rather than trusting a description of
 * PHP's behaviour written elsewhere.
 */
final class SettingsSanitizeTest extends TestCase {
	use Blueline_Assert_WP_Error;

	/**
	 * The brief's own extraction fixtures: a plain %s, a mix of positional
	 * specs, and a string with no specs at all.
	 */
	public function test_extract_placeholders_finds_positional_and_plain_specs(): void {
		$this->assertSame( array( '%s' ), blueline_extract_placeholders( 'Back on the ice %s.' ) );
		$this->assertSame( array( '%1$s', '%2$d' ), blueline_extract_placeholders( '%1$s · Week %2$d' ) );
		$this->assertSame( array(), blueline_extract_placeholders( 'no specs here' ) );
	}

	/**
	 * "%%" is PHP's own escape for a literal percent character -- verified
	 * `sprintf( 'save 50%% today' )` returns 'save 50% today' with no
	 * argument consumed -- so it must never be reported as a placeholder.
	 */
	public function test_a_literal_percent_is_not_a_placeholder(): void {
		$this->assertSame( array(), blueline_extract_placeholders( 'save 50%% today' ) );
	}

	/**
	 * Width, precision and flag characters are all valid PHP conversion
	 * syntax (verified: %05.2f, %-10s and %+d all sanitize without a PHP
	 * warning). The extractor must return each whole specification intact,
	 * not a truncated or mis-split fragment of it.
	 */
	public function test_extract_placeholders_handles_flags_width_and_precision(): void {
		$this->assertSame( array( '%05.2f' ), blueline_extract_placeholders( 'Price: %05.2f' ) );
		$this->assertSame( array( '%-10s' ), blueline_extract_placeholders( 'Name: %-10s|' ) );
		$this->assertSame( array( '%+d' ), blueline_extract_placeholders( 'Delta: %+d' ) );
	}

	/**
	 * "h" and "H" (PHP 8.0's locale-independent shortest float
	 * representation) are valid conversion types -- verified
	 * `sprintf( '%h', 3.14159265358979 )` does not throw -- but are absent
	 * from many quick references and easy to omit from a type character
	 * class written from memory rather than verified. A field declaring
	 * `%h`/`%H` as a required placeholder must not have its own contract
	 * treated as an invalid, would-fatal specifier.
	 */
	public function test_extract_placeholders_recognises_the_h_and_capital_h_types(): void {
		$this->assertSame( array( '%h' ), blueline_extract_placeholders( 'Distance: %h' ) );
		$this->assertSame( array( '%H' ), blueline_extract_placeholders( 'Distance: %H' ) );
	}

	/**
	 * A format string may freely mix a positional spec with a plain one --
	 * PHP accepts this syntactically (verified: it does not throw, though it
	 * silently reuses argument 1 for both, a separate footgun this
	 * validator does not need to solve). The extractor must still find and
	 * return both tokens, in order, distinctly.
	 */
	public function test_extract_placeholders_handles_mixed_positional_and_plain_specs(): void {
		$this->assertSame( array( '%1$s', '%s' ), blueline_extract_placeholders( '%1$s and %s' ) );
	}

	/**
	 * There is no longer a third, unchecked state: a field that omits
	 * `placeholders` entirely is treated exactly like `placeholders =>
	 * array()`, not skipped. This is the fix-round-1 hole close -- the
	 * earlier "no key means skip" behaviour meant a field that SHOULD have
	 * declared a contract but didn't (a schema-authoring mistake, or a
	 * future field that starts feeding sprintf() without the schema being
	 * updated) was invisibly unprotected, reproducing the exact fatal this
	 * task exists to prevent, one step removed. A stray "%" is rejected
	 * regardless of whether the key is present.
	 */
	public function test_fields_without_a_placeholders_key_still_get_the_safety_check(): void {
		$field  = array( 'type' => 'text' );
		$result = blueline_sanitize_field( 'Save 50% today', $field );
		$this->assertWPError( $result, 'omitting placeholders must not be a way to skip the check' );
	}

	/**
	 * The companion accept path for the same field shape: a value with no
	 * "%" at all satisfies the implicit `array()` contract and sanitizes
	 * normally.
	 */
	public function test_fields_without_a_placeholders_key_accept_a_value_with_no_percent(): void {
		$field  = array( 'type' => 'text' );
		$result = blueline_sanitize_field( 'Save big today', $field );
		$this->assertSame( 'Save big today', $result );
	}

	/**
	 * The brief's load-bearing rejection case: a field contractually
	 * requiring %s must refuse a replacement that drops it -- the real call
	 * site would ArgumentCountError for lack of a value to fill the slot
	 * that no longer exists.
	 */
	public function test_rejects_a_replacement_that_drops_a_required_placeholder(): void {
		$field  = array(
			'type'         => 'text',
			'placeholders' => array( '%s' ),
		);
		$result = blueline_sanitize_field( 'Back on the ice.', $field );
		$this->assertWPError( $result );
	}

	/**
	 * A bare "%" followed by a character that cannot complete a valid
	 * specification is an unknown format specifier and fatals -- verified
	 * `sprintf( 'save 50% today', 'x' )` throws ValueError: Unknown format
	 * specifier "t" (the space after "%" is itself a valid flag, so parsing
	 * proceeds to the very next character before failing on "t").
	 */
	public function test_rejects_a_bare_percent_that_would_fatal(): void {
		$field  = array(
			'type'         => 'text',
			'placeholders' => array(),
		);
		$result = blueline_sanitize_field( 'save 50% today', $field );
		$this->assertWPError( $result, 'a bare % is an unknown format specifier and fatals' );
	}

	/**
	 * Proof, not assertion by description: the exact string the previous
	 * test rejected really does throw on this runtime when actually handed
	 * to sprintf() with the argument count the field's real call site would
	 * supply. This is why the rejection above matters.
	 */
	public function test_the_rejected_bare_percent_string_actually_fatals_in_sprintf(): void {
		$this->expectException( ValueError::class );
		$this->expectExceptionMessage( 'Unknown format specifier "t"' );

		sprintf( 'save 50% today', 'x' );
	}

	/**
	 * A bare "%" at the very end of a string has no character left to
	 * complete a specification with at all -- verified
	 * `sprintf( 'ends in %' )` throws ArgumentCountError, not a graceful
	 * fallback to literal text.
	 */
	public function test_rejects_a_bare_percent_at_the_end_of_the_string(): void {
		$field  = array(
			'type'         => 'text',
			'placeholders' => array(),
		);
		$result = blueline_sanitize_field( 'ends in %', $field );
		$this->assertWPError( $result );
	}

	/**
	 * Same proof-not-description standard as above, for the end-of-string
	 * case specifically -- a different PHP exception class than the
	 * unknown-specifier case, which is exactly why both are covered rather
	 * than just one being taken to represent "malformed %" in general.
	 */
	public function test_bare_percent_at_end_of_string_actually_fatals_in_sprintf(): void {
		$this->expectException( ArgumentCountError::class );

		sprintf( 'ends in %' );
	}

	/**
	 * The non-obvious case this validator exists to catch beyond the
	 * brief's own examples: ordinary prose can hide a syntactically valid
	 * conversion specification with no "%s"-shaped placeholder in sight,
	 * because " " (space) is a valid flag and "o" (among others) is a valid
	 * type -- "% off" parses as a complete `% o` specification. A field
	 * declaring exactly one required placeholder must reject a value that
	 * -- entirely by accident -- also contains this second, undeclared one,
	 * because the real call site only supplies one argument.
	 */
	public function test_rejects_a_replacement_that_hides_an_extra_placeholder_in_ordinary_text(): void {
		$field  = array(
			'type'         => 'text',
			'placeholders' => array( '%s' ),
		);
		$result = blueline_sanitize_field( 'Save 50% off, %s!', $field );
		$this->assertWPError( $result, 'the % in "% off" is itself a valid, undeclared conversion spec' );
	}

	/**
	 * Proof this accidental extra placeholder is not a theoretical concern:
	 * verified `sprintf( 'Save 50% off, %s!', 'now' )` throws
	 * ArgumentCountError on this runtime -- the exact shape of call a real
	 * settings-panel call site makes, with exactly the one argument the
	 * schema's `placeholders` contract promised would be enough.
	 */
	public function test_the_hidden_extra_placeholder_actually_fatals_in_sprintf(): void {
		$this->expectException( ArgumentCountError::class );

		sprintf( 'Save 50% off, %s!', 'now' );
	}

	/**
	 * The accept path: a replacement that preserves the exact declared
	 * placeholder is sanitized and returned unchanged (sanitize_text_field()
	 * does not alter this string -- no tags, no leading/trailing
	 * whitespace).
	 */
	public function test_accepts_a_replacement_preserving_the_contract(): void {
		$field = array(
			'type'         => 'text',
			'placeholders' => array( '%s' ),
		);
		$this->assertSame( 'Back on the ice %s!', blueline_sanitize_field( 'Back on the ice %s!', $field ) );
	}

	/**
	 * `bool` fields are outside the sprintf() contract entirely: they
	 * sanitize to a real PHP boolean regardless of what is submitted.
	 */
	public function test_bool_fields_cast_to_boolean(): void {
		$this->assertTrue( blueline_sanitize_field( '1', array( 'type' => 'bool' ) ) );
		$this->assertFalse( blueline_sanitize_field( '', array( 'type' => 'bool' ) ) );
	}

	/**
	 * `page_id`/`term_id` fields sanitize to a non-negative integer via
	 * absint() -- matching the schema's own documented "0 means use the
	 * fallback" contract (inc/settings/defaults.php) -- and never run the
	 * sprintf() checks, since their sanitized value is never a string.
	 */
	public function test_page_id_and_term_id_fields_sanitize_to_absolute_integers(): void {
		$this->assertSame( 42, blueline_sanitize_field( '42', array( 'type' => 'page_id' ) ) );
		$this->assertSame( 5, blueline_sanitize_field( '-5', array( 'type' => 'term_id' ) ) );
		$this->assertSame( 0, blueline_sanitize_field( 'not-a-number', array( 'type' => 'term_id' ) ) );
	}

	/**
	 * The escape helper must leave a recognised "%%" escape and a complete
	 * conversion spec untouched, and double ONLY the "%" that could not
	 * complete either -- so the suggestion it produces is safe to paste
	 * straight back into the field without disturbing an already-valid
	 * part of the string.
	 */
	public function test_escape_stray_percents_only_touches_the_unsafe_percent(): void {
		$this->assertSame( 'save 50%% today', blueline_escape_stray_percents( 'save 50% today' ) );
		$this->assertSame( 'ends in %%', blueline_escape_stray_percents( 'ends in %' ) );
		// Already-safe input is returned unchanged: an existing "%%" escape
		// and an existing valid spec must not be re-escaped or duplicated.
		$this->assertSame( 'save 50%% today', blueline_escape_stray_percents( 'save 50%% today' ) );
		$this->assertSame( 'Back on the ice %s!', blueline_escape_stray_percents( 'Back on the ice %s!' ) );
	}

	/**
	 * The rejection message itself must be actionable, not just true: a
	 * volunteer hitting this must not have to guess what "%%" means or
	 * where to put it. Asserts the exact wording, including the corrected
	 * string handed back ready to paste in.
	 */
	public function test_the_unsafe_percent_error_names_the_fix_and_shows_the_correction(): void {
		$field  = array(
			'type'         => 'text',
			'label'        => 'Footer location line',
			'placeholders' => array(),
		);
		$result = blueline_sanitize_field( 'Save 50% today', $field );

		$this->assertWPError( $result );
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'Footer location line', $message );
		$this->assertStringContainsString( '%%', $message, 'must tell the volunteer a literal percent is written as %%' );
		$this->assertStringContainsString(
			'Save 50%% today',
			$message,
			'must show the corrected, ready-to-paste string, not just describe the fix'
		);
	}

	/**
	 * Integration check against the real schema, not a hand-built fixture:
	 * every one of Task 1's 13 existing field defaults must still validate
	 * through blueline_sanitize_field() unchanged now that `text` fields
	 * carry an explicit `placeholders => array()` contract. A default that
	 * failed here would mean the panel ships an un-saveable field the
	 * moment an admin opens its own tab and re-submits the form untouched.
	 */
	public function test_every_schema_default_validates_through_the_sanitizer(): void {
		$schema   = blueline_settings_schema();
		$defaults = blueline_settings_defaults();

		$this->assertNotEmpty( $schema );
		$this->assertCount( 13, $schema, 'this test pins the count so a future schema change is a deliberate edit here too' );

		foreach ( $schema as $key => $field ) {
			$result = blueline_sanitize_field( $defaults[ $key ], $field );
			$this->assertFalse(
				is_wp_error( $result ),
				"$key's own default value was rejected by its own field contract: " . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
			);
		}
	}
}
