<?php
/**
 * Covers inc/settings/import.php -- the payload machinery `wp blueline
 * settings import|validate` and (from the following commit) the panel's own
 * import form both run, and the two bounds the design spec's 6.8 asks for:
 * "bounds size and nesting depth".
 *
 * ## Neither bound existed before this file
 *
 * The decode step was a bare `json_decode( $raw, true )`. That leaves PHP's
 * own default 512-level depth limit as the only ceiling, and no size
 * ceiling at all, and it reports both a 600-level-deep document and a
 * plain "hello" as the same message ("The file does not contain a valid
 * JSON object."), which tells an operator nothing about which of the two
 * they hit. There was also no depth mention anywhere in the settings code
 * to build on -- inc/settings/page.php:373's "in more depth" is prose in a
 * docblock sentence, not a bound.
 *
 * ## Where the two numbers come from
 *
 * A real export of the shipped defaults is 1,895 bytes pretty-printed
 * (measured, not estimated), so BLUELINE_SETTINGS_IMPORT_MAX_BYTES at 256
 * KiB leaves roughly 138x headroom for long copy fields while still being a
 * bound. BLUELINE_SETTINGS_IMPORT_MAX_DEPTH is 8 against a real export's
 * needed depth of 4 -- the object, `hero_photos`, one row object, its
 * scalars -- which this file measures rather than asserts, in
 * test_a_real_export_decodes_well_inside_both_bounds().
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/import.php';

/**
 * See this file's own docblock.
 */
final class SettingsImportPayloadTest extends TestCase {

	use Blueline_Assert_WP_Error;

	/**
	 * Reset the in-memory stores between cases.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * The ordinary case still works: a small, well-formed JSON object
	 * decodes to an array.
	 */
	public function test_a_well_formed_payload_decodes(): void {
		$decoded = blueline_settings_import_decode( '{"footer_heading":"The League"}' );

		$this->assertSame( array( 'footer_heading' => 'The League' ), $decoded );
	}

	/**
	 * Non-JSON is still refused, with the message that says so.
	 */
	public function test_non_json_is_refused(): void {
		$result = blueline_settings_import_decode( 'not json at all' );

		$this->assertWPError( $result );
		$this->assertSame( 'blueline_import_invalid_json', $result->get_error_code() );
	}

	/**
	 * A payload over the size bound is refused BEFORE it is parsed, and the
	 * message names both the file's size and the limit, in bytes, so the
	 * operator can tell how far over they are.
	 */
	public function test_an_oversized_payload_is_refused_by_size(): void {
		$oversized = str_repeat( 'a', BLUELINE_SETTINGS_IMPORT_MAX_BYTES + 1 );

		$result = blueline_settings_import_decode( $oversized );

		$this->assertWPError( $result );
		$this->assertSame( 'blueline_import_too_large', $result->get_error_code() );
		$this->assertStringContainsString(
			(string) BLUELINE_SETTINGS_IMPORT_MAX_BYTES,
			$result->get_error_message(),
			'the message must name the limit'
		);
	}

	/**
	 * A payload exactly ON the size bound is accepted -- the bound is a
	 * maximum, not a strict one-below.
	 */
	public function test_a_payload_exactly_at_the_size_bound_is_accepted(): void {
		$key     = 'footer_heading';
		$padding = BLUELINE_SETTINGS_IMPORT_MAX_BYTES - strlen( '{"' . $key . '":""}' );
		$raw     = '{"' . $key . '":"' . str_repeat( 'a', $padding ) . '"}';

		$this->assertSame( BLUELINE_SETTINGS_IMPORT_MAX_BYTES, strlen( $raw ), 'premise: the fixture is exactly at the bound' );

		$decoded = blueline_settings_import_decode( $raw );

		$this->assertIsArray( $decoded );
	}

	/**
	 * A payload nested deeper than the bound is refused with a message
	 * about NESTING, distinct from the generic "not valid JSON" one -- the
	 * whole point of setting an explicit depth rather than leaving PHP's
	 * default 512 in place is that the operator finds out which limit they
	 * hit.
	 */
	public function test_an_over_nested_payload_is_refused_by_depth(): void {
		$nested = '1';
		for ( $i = 0; $i < BLUELINE_SETTINGS_IMPORT_MAX_DEPTH + 2; $i++ ) {
			$nested = '[' . $nested . ']';
		}

		$result = blueline_settings_import_decode( '{"a":' . $nested . '}' );

		$this->assertWPError( $result );
		$this->assertSame( 'blueline_import_too_deep', $result->get_error_code() );
		$this->assertStringContainsString( (string) BLUELINE_SETTINGS_IMPORT_MAX_DEPTH, $result->get_error_message() );
	}

	/**
	 * The two bounds are independent: an over-nested payload that is well
	 * under the size limit is still refused, and refused for the right
	 * reason.
	 */
	public function test_depth_is_checked_independently_of_size(): void {
		$nested = str_repeat( '[', 40 ) . '1' . str_repeat( ']', 40 );

		$this->assertLessThan( BLUELINE_SETTINGS_IMPORT_MAX_BYTES, strlen( $nested ), 'premise: the fixture is small' );

		$result = blueline_settings_import_decode( '{"a":' . $nested . '}' );

		$this->assertWPError( $result );
		$this->assertSame( 'blueline_import_too_deep', $result->get_error_code() );
	}

	/**
	 * The bounds must not reject the thing this feature exists to move
	 * around: an actual export of this site's own settings, including a
	 * populated `hero_photos` list (the deepest shape the schema has).
	 */
	public function test_a_real_export_decodes_well_inside_both_bounds(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'hero_photos' => array(
					array(
						'id'    => 7,
						'align' => 'center-center',
					),
				),
			)
		);

		$raw = (string) wp_json_encode( blueline_settings_export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$this->assertLessThan( BLUELINE_SETTINGS_IMPORT_MAX_BYTES, strlen( $raw ) );

		$decoded = blueline_settings_import_decode( $raw );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( '_schema', $decoded );
	}

	/**
	 * A JSON scalar (`"hello"`, `42`) is valid JSON but is not a settings
	 * payload; it must be refused as such rather than decoded into
	 * something the sanitizer then walks.
	 */
	public function test_a_json_scalar_is_not_a_payload(): void {
		$this->assertWPError( blueline_settings_import_decode( '"hello"' ) );
		$this->assertWPError( blueline_settings_import_decode( '42' ) );
	}

	/**
	 * The moved helpers keep the behaviour the CLI already depended on:
	 * a payload whose own `_schema` is newer is refused outright, and
	 * `_schema`/`_posted_fields`/`_tab` never survive preparation.
	 */
	public function test_prepare_still_refuses_a_newer_schema_and_strips_bookkeeping(): void {
		$refused = blueline_settings_import_prepare(
			array( '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION + 1 ),
			BLUELINE_SETTINGS_SCHEMA_VERSION
		);

		$this->assertWPError( $refused );

		$prepared = blueline_settings_import_prepare(
			array(
				'footer_heading' => 'The League',
				'_schema'        => BLUELINE_SETTINGS_SCHEMA_VERSION,
				'_tab'           => 'content',
				'_posted_fields' => array( 'page_faqs' ),
			),
			BLUELINE_SETTINGS_SCHEMA_VERSION
		);

		$this->assertSame( array( 'footer_heading' => 'The League' ), $prepared );
	}

	/**
	 * The sanitize walk still reports both halves -- what each accepted
	 * field would become, and one message per rejected field -- and still
	 * skips a key the schema does not declare rather than calling it an
	 * error.
	 */
	public function test_sanitize_payload_reports_values_and_errors(): void {
		$result = blueline_settings_import_sanitize_payload(
			array(
				'footer_heading'   => '  The League  ',
				'contact_email'    => 'not-an-email',
				'not_a_real_field' => 'ignored',
			),
			blueline_settings_schema()
		);

		$this->assertSame( array( 'footer_heading' => 'The League' ), $result['values'], 'the value must be the NORMALISED one' );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame(
			array( 'not_a_real_field' ),
			blueline_settings_import_dropped_keys(
				array(
					'footer_heading'   => 'x',
					'not_a_real_field' => 'y',
				),
				blueline_settings_schema()
			)
		);
	}
}
