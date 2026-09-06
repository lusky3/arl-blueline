<?php
/**
 * Unit tests for the settings page's `color` field type — sanitisation
 * and rendering for the 12 brand-palette override fields
 * (inc/settings/defaults.php's `brand_color_*` keys). See
 * tests/TeamColorsTest.php for the pure token/resolution/contrast-report
 * functions this field type calls into.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/page.php';

/**
 * Covers the `color` field type's schema declaration, sanitisation, and
 * server-side rendering for the 12 `brand_color_*` settings fields.
 */
final class BrandColorSettingsFieldTest extends TestCase {

	/**
	 * Reset every stateful stub this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
	}

	/**
	 * Every brand-palette token gets its own `color`-type field on the
	 * `appearance` tab, keyed to the matching token.
	 */
	public function test_defaults_schema_declares_all_twelve_brand_color_fields_on_the_appearance_tab(): void {
		$schema = blueline_settings_schema();

		foreach ( blueline_brand_color_tokens() as $token_key => $token ) {
			$field_key = "brand_color_{$token_key}";
			$this->assertArrayHasKey( $field_key, $schema, "missing schema entry for {$field_key}" );
			$this->assertSame( 'color', $schema[ $field_key ]['type'] );
			$this->assertSame( 'appearance', $schema[ $field_key ]['tab'] );
			$this->assertSame( $token_key, $schema[ $field_key ]['token_key'] );
		}
	}

	/**
	 * An empty (or whitespace-only) submitted value sanitises to `''`,
	 * meaning "unset -- fall back to the palette default".
	 */
	public function test_sanitize_field_accepts_empty_string_as_unset(): void {
		$field = array(
			'type'  => 'color',
			'label' => 'Ice',
		);

		$this->assertSame( '', blueline_sanitize_field( '', $field ) );
		$this->assertSame( '', blueline_sanitize_field( '   ', $field ) );
	}

	/**
	 * A valid hex value sanitises to its lower-cased, `#`-prefixed form
	 * regardless of the case or leading `#` it was submitted with.
	 */
	public function test_sanitize_field_normalises_a_valid_hex_color(): void {
		$field = array(
			'type'  => 'color',
			'label' => 'Ice',
		);

		$this->assertSame( '#3f6e9d', blueline_sanitize_field( '#3F6E9D', $field ) );
		$this->assertSame( '#3f6e9d', blueline_sanitize_field( '3F6E9D', $field ) );
	}

	/**
	 * A value that isn't a hex color at all sanitises to a `WP_Error`
	 * carrying the `blueline_invalid_color` error code.
	 */
	public function test_sanitize_field_rejects_an_invalid_hex_color(): void {
		$field  = array(
			'type'  => 'color',
			'label' => 'Ice',
		);
		$result = blueline_sanitize_field( 'not-a-color', $field );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_invalid_color', $result->get_error_code() );
	}

	/**
	 * With no stored override, the field renders the token's own default
	 * hex as its `placeholder`.
	 */
	public function test_render_color_field_outputs_the_default_placeholder_when_unset(): void {
		$field = array(
			'token_key' => 'ice',
			'label'     => 'Ice',
		);

		ob_start();
		blueline_settings_render_color_field( 'brand_color_ice', $field, 'blueline_settings[brand_color_ice]', 'brand_color_ice', '', false, '' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'placeholder="' . BLUELINE_TOKEN_ICE . '"', $html );
		$this->assertStringContainsString( 'data-bl-brand-color-token="ice"', $html );
	}

	/**
	 * A stored override value renders as the text input's (and, by
	 * extension, the color swatch's) `value` attribute.
	 */
	public function test_render_color_field_reflects_an_override_value_in_the_swatch(): void {
		$field = array(
			'token_key' => 'paper',
			'label'     => 'Paper',
		);

		ob_start();
		blueline_settings_render_color_field( 'brand_color_paper', $field, 'blueline_settings[brand_color_paper]', 'brand_color_paper', '#123456', false, '' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="#123456"', $html );
	}

	/**
	 * A candidate hex that fails one of `--bl-ice`'s contrast rules renders
	 * that rule's readout with the `bl-contrast-fail` class.
	 */
	public function test_render_color_field_includes_a_failing_contrast_row_for_ice_on_light(): void {
		// --bl-ice at its own default is documented ~1.94:1 on paper --
		// pushed to a value that WOULD read as body text (a high-contrast
		// hex against ink, e.g. white) to exercise the fail branch of
		// ice-not-text-on-light (max:3.0).
		$field = array(
			'token_key' => 'ice',
			'label'     => 'Ice',
		);

		ob_start();
		blueline_settings_render_color_field( 'brand_color_ice', $field, 'blueline_settings[brand_color_ice]', 'brand_color_ice', '#000000', false, '' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'bl-contrast-fail', $html );
	}

	/**
	 * `blueline_brand_color_js_rules()` localises `--bl-ice`'s documented
	 * contrast rules -- both the token-pair rule (against `--bl-paper`) and
	 * the fixed-hex rule (against a literal focus-ring color) -- for
	 * assets/src/js/settings-brand-colors.js to recompute live.
	 */
	public function test_brand_color_js_rules_localises_the_documented_ice_rules(): void {
		require_once __DIR__ . '/../inc/team-colors.php';

		$rules = blueline_brand_color_js_rules();

		$this->assertArrayHasKey( 'ice', $rules );
		$ids = array_column( $rules['ice'], 'description' );
		$this->assertNotEmpty( $ids );

		$paper_pair = current(
			array_filter( $rules['ice'], static fn( $r ) => 'paper' === $r['otherTokenKey'] )
		);
		$this->assertNotFalse( $paper_pair );
		$this->assertSame( BLUELINE_TOKEN_PAPER, $paper_pair['otherDefaultHex'] );

		$focus_pair = current(
			array_filter( $rules['ice'], static fn( $r ) => null === $r['otherTokenKey'] )
		);
		$this->assertNotFalse( $focus_pair );
		$this->assertSame( '#0D1729', $focus_pair['otherDefaultHex'] );
	}
}
