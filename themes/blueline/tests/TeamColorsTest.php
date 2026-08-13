<?php
/**
 * Team colour derivation.
 *
 * These pin the behaviour that makes the feature safe: SportsPress's stored
 * palettes are entered by hand and frequently unusable, so every colour the
 * theme paints is derived here rather than trusted. The fixtures below are
 * real production values, not invented ones.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/team-colors.php';

/**
 * Tests for team colour sanitisation, contrast maths, and derivation.
 */
final class TeamColorsTest extends TestCase {

	/**
	 * Resets the in-memory post-meta/option test double before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/* -------------------------------------------------- hex sanitisation */

	/**
	 * Asserts hex colours are accepted with or without a leading hash and
	 * surrounding whitespace, normalised to lowercase.
	 */
	public function test_accepts_six_digit_hex_with_and_without_hash(): void {
		$this->assertSame( '#f8c63f', blueline_sanitize_hex_color( '#f8c63f' ) );
		$this->assertSame( '#f8c63f', blueline_sanitize_hex_color( 'f8c63f' ) );
		$this->assertSame( '#f8c63f', blueline_sanitize_hex_color( '  #F8C63F  ' ) );
	}

	/**
	 * Asserts 3-digit hex shorthand expands to the equivalent 6-digit form.
	 */
	public function test_expands_three_digit_hex(): void {
		$this->assertSame( '#ffffff', blueline_sanitize_hex_color( '#fff' ) );
		$this->assertSame( '#aabbcc', blueline_sanitize_hex_color( 'abc' ) );
	}

	/**
	 * Asserts non-hex-colour inputs, including non-string types, sanitise
	 * to an empty string.
	 */
	public function test_rejects_anything_that_is_not_a_hex_colour(): void {
		foreach ( array( '', 'red', 'rgb(1,2,3)', '#12345', '#gggggg', null, array(), 42 ) as $bad ) {
			$this->assertSame( '', blueline_sanitize_hex_color( $bad ) );
		}
	}

	/* ------------------------------------------------------- contrast maths */

	/**
	 * Asserts the ink/paper token pairing's contrast ratio matches the
	 * value the CSS contrast guard also asserts.
	 */
	public function test_contrast_ratio_matches_the_known_token_pairing(): void {
		// The same 14.94 the CSS contrast guard asserts for ink on paper.
		$this->assertEqualsWithDelta(
			14.94,
			blueline_contrast_ratio( BLUELINE_TOKEN_INK, BLUELINE_TOKEN_PAPER ),
			0.01
		);
	}

	/**
	 * Asserts contrast ratio is symmetric regardless of which colour is
	 * passed first.
	 */
	public function test_contrast_ratio_is_symmetric(): void {
		$this->assertSame(
			blueline_contrast_ratio( '#002d62', '#ffffff' ),
			blueline_contrast_ratio( '#ffffff', '#002d62' )
		);
	}

	/* ------------------------------------------------ foreground selection */

	/**
	 * Asserts a dark team primary colour is paired with the paper (light)
	 * foreground token.
	 */
	public function test_dark_team_colour_takes_the_paper_foreground(): void {
		// Oilers #002d62 -- dark navy, so paper text sits on it.
		$this->assertSame( BLUELINE_TOKEN_PAPER, blueline_readable_foreground( '#002d62' ) );
	}

	/**
	 * Asserts light team primary colours are paired with the ink (dark)
	 * foreground token.
	 */
	public function test_light_team_colour_takes_the_ink_foreground(): void {
		// Canadiens #ffffff and Penguins #f8c63f are both light.
		$this->assertSame( BLUELINE_TOKEN_INK, blueline_readable_foreground( '#ffffff' ) );
		$this->assertSame( BLUELINE_TOKEN_INK, blueline_readable_foreground( '#f8c63f' ) );
	}

	/**
	 * Asserts the chosen foreground for every real team primary in the
	 * fixture set clears the minimum body contrast ratio.
	 */
	public function test_chosen_foreground_actually_clears_body_contrast(): void {
		// Every real primary in the fixture set must end up legible.
		foreach ( array( '#002d62', '#55bfd2', '#f8c63f', '#ffffff', '#adadad', '#e2b85e', '#032a95' ) as $primary ) {
			$fg = blueline_readable_foreground( $primary );
			$this->assertGreaterThanOrEqual(
				BLUELINE_CONTRAST_BODY,
				blueline_contrast_ratio( $fg, $primary ),
				"foreground for $primary is not legible"
			);
		}
	}

	/* --------------------------------------------------------- darkening */

	/**
	 * Asserts a pale team colour that fails body contrast is darkened
	 * until it passes.
	 */
	public function test_darkening_makes_a_pale_team_colour_readable_as_text(): void {
		// Sharks #55bfd2 is only ~2:1 on paper and must not be used as text raw.
		$this->assertLessThan(
			BLUELINE_CONTRAST_BODY,
			blueline_contrast_ratio( '#55bfd2', BLUELINE_TOKEN_PAPER )
		);

		$darkened = blueline_darken_to_contrast( '#55bfd2', BLUELINE_TOKEN_PAPER, BLUELINE_CONTRAST_BODY );

		$this->assertGreaterThanOrEqual(
			BLUELINE_CONTRAST_BODY,
			blueline_contrast_ratio( $darkened, BLUELINE_TOKEN_PAPER )
		);
	}

	/**
	 * Asserts darkening a colour to meet contrast preserves its hue by
	 * keeping channel ordering intact.
	 */
	public function test_darkening_preserves_hue_order(): void {
		// A cyan must stay cyan: blue channel highest, red lowest.
		$darkened = blueline_darken_to_contrast( '#55bfd2', BLUELINE_TOKEN_PAPER, BLUELINE_CONTRAST_BODY );

		$r = hexdec( substr( $darkened, 1, 2 ) );
		$g = hexdec( substr( $darkened, 3, 2 ) );
		$b = hexdec( substr( $darkened, 5, 2 ) );

		$this->assertGreaterThan( $r, $b, 'blue should still dominate red' );
		$this->assertGreaterThan( $r, $g, 'green should still dominate red' );
	}

	/**
	 * Asserts a colour that already meets the contrast target is returned
	 * unchanged.
	 */
	public function test_already_dark_colour_is_returned_untouched(): void {
		$this->assertSame(
			'#002d62',
			blueline_darken_to_contrast( '#002d62', BLUELINE_TOKEN_PAPER, BLUELINE_CONTRAST_BODY )
		);
	}

	/* ------------------------------------------------------- the full set */

	/**
	 * Asserts a team with no stored colour meta yields an empty colour
	 * set.
	 */
	public function test_team_with_no_meta_yields_no_colours(): void {
		$this->assertSame( array(), blueline_team_color_set( 123 ) );
	}

	/**
	 * Asserts a stored primary colour that fails hex sanitisation yields
	 * an empty colour set.
	 */
	public function test_team_with_unusable_primary_yields_no_colours(): void {
		blueline_test_state()['post_meta'][123]['sp_colors'] = array( 'primary' => 'not-a-colour' );
		$this->assertSame( array(), blueline_team_color_set( 123 ) );
	}

	/**
	 * Asserts a corrupt serialized sp_colors string (an unserialize()
	 * failure) yields an empty colour set rather than a fatal error.
	 */
	public function test_failed_unserialize_yields_no_colours(): void {
		// WordPress hands back the raw string when unserialize() fails.
		blueline_test_state()['post_meta'][123]['sp_colors'] = 'a:1:{s:7:"primary";s:7:"#f8c63f"';
		$this->assertSame( array(), blueline_team_color_set( 123 ) );
	}

	/**
	 * Asserts derivation still works for a real team row that stores only
	 * `primary` and `link`, without a full palette.
	 */
	public function test_boomers_two_key_palette_still_works(): void {
		// Real production row: only `primary` and `link` exist.
		blueline_test_state()['post_meta'][123]['sp_colors'] = array(
			'primary' => '#e2b85e',
			'link'    => '#355b0f',
		);

		$set = blueline_team_color_set( 123 );

		$this->assertSame( '#e2b85e', $set['primary'] );
		$this->assertSame( BLUELINE_TOKEN_INK, $set['on_primary'] );
		$this->assertNotSame( '', $set['accent'] );
	}

	/**
	 * Asserts an achromatic (white) team primary withholds the derived
	 * text accent rather than producing a meaningless grey.
	 */
	public function test_achromatic_team_gets_no_text_accent(): void {
		// Canadiens' primary is pure white; a darkened white is grey, which
		// is not their identity, so the accent is deliberately withheld and
		// the CSS falls back to the theme token.
		blueline_test_state()['post_meta'][123]['sp_colors'] = array( 'primary' => '#ffffff' );

		$set = blueline_team_color_set( 123 );

		$this->assertSame( '#ffffff', $set['primary'] );
		$this->assertSame( BLUELINE_TOKEN_INK, $set['on_primary'] );
		$this->assertSame( '', $set['accent'], 'white must not produce a grey "team" accent' );
	}

	/**
	 * Asserts every derived accent colour, when present, is legible
	 * against the paper token.
	 */
	public function test_derived_accent_is_always_legible_on_paper(): void {
		foreach ( array( '#55bfd2', '#f8c63f', '#e2b85e', '#002d62', '#032a95' ) as $primary ) {
			blueline_test_state()['post_meta'][123]['sp_colors'] = array( 'primary' => $primary );

			$set = blueline_team_color_set( 123 );

			if ( '' === $set['accent'] ) {
				continue;
			}

			$this->assertGreaterThanOrEqual(
				BLUELINE_CONTRAST_BODY,
				blueline_contrast_ratio( $set['accent'], BLUELINE_TOKEN_PAPER ),
				"accent derived from $primary is not legible on paper"
			);
		}
	}

	/* --------------------------------------------------- the style attribute */

	/**
	 * Asserts the style attribute helper returns an empty string when a
	 * team has no colours.
	 */
	public function test_style_attribute_is_absent_without_colours(): void {
		$this->assertSame( '', blueline_team_color_style_attr( 123 ) );
	}

	/**
	 * Asserts the style attribute carries the validated primary and
	 * on-primary custom properties with the expected formatting.
	 */
	public function test_style_attribute_carries_only_validated_values(): void {
		blueline_test_state()['post_meta'][123]['sp_colors'] = array( 'primary' => '#002d62' );

		$attr = blueline_team_color_style_attr( 123 );

		$this->assertStringContainsString( '--bl-team-primary: #002d62', $attr );
		$this->assertStringContainsString( '--bl-team-on-primary: ' . BLUELINE_TOKEN_PAPER, $attr );
		$this->assertStringStartsWith( ' style="', $attr );
	}

	/**
	 * Asserts the style attribute omits the --bl-team-accent property when
	 * the accent was withheld.
	 */
	public function test_style_attribute_omits_the_accent_when_withheld(): void {
		blueline_test_state()['post_meta'][123]['sp_colors'] = array( 'primary' => '#ffffff' );

		$this->assertStringNotContainsString( '--bl-team-accent', blueline_team_color_style_attr( 123 ) );
	}
}
