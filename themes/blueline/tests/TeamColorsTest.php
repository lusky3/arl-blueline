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
				blueline_contrast_threshold( 'body' ),
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
			blueline_contrast_threshold( 'body' ),
			blueline_contrast_ratio( '#55bfd2', BLUELINE_TOKEN_PAPER )
		);

		$darkened = blueline_darken_to_contrast( '#55bfd2', BLUELINE_TOKEN_PAPER, blueline_contrast_threshold( 'body' ) );

		$this->assertGreaterThanOrEqual(
			blueline_contrast_threshold( 'body' ),
			blueline_contrast_ratio( $darkened, BLUELINE_TOKEN_PAPER )
		);
	}

	/**
	 * Asserts darkening a colour to meet contrast preserves its hue by
	 * keeping channel ordering intact.
	 */
	public function test_darkening_preserves_hue_order(): void {
		// A cyan must stay cyan: blue channel highest, red lowest.
		$darkened = blueline_darken_to_contrast( '#55bfd2', BLUELINE_TOKEN_PAPER, blueline_contrast_threshold( 'body' ) );

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
			blueline_darken_to_contrast( '#002d62', BLUELINE_TOKEN_PAPER, blueline_contrast_threshold( 'body' ) )
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
				blueline_contrast_threshold( 'body' ),
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

	/* ---------------------------------------------------- shared thresholds */

	/**
	 * Asserts blueline_contrast_threshold() resolves 'body' and 'large' from
	 * the shared tools/contrast-rules.json table's explicit, declared
	 * top-level "thresholds" key -- not inferred from rules[].min, which a
	 * single unrelated rule addition could silently move.
	 */
	public function test_thresholds_come_from_the_shared_rules_table(): void {
		$json = json_decode(
			file_get_contents( __DIR__ . '/../tools/contrast-rules.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo fixture, not a remote URL.
			true
		);

		$this->assertArrayHasKey(
			'thresholds',
			$json,
			'the shared table must declare its AA thresholds explicitly, not leave them to be inferred from rules[].min'
		);
		$this->assertSame( 4.5, $json['thresholds']['body'] );
		$this->assertSame( 3.0, $json['thresholds']['large'] );
		$this->assertSame( 4.5, blueline_contrast_threshold( 'body' ) );
		$this->assertSame( 3.0, blueline_contrast_threshold( 'large' ) );
	}

	/**
	 * Asserts a rule carrying an unusual `min` (e.g. an AAA-strength 7.0)
	 * cannot move the declared thresholds. This is the exact failure this
	 * task's Fix 1 closes: the previous derivation took
	 * body = max(rules[].min), so adding any rule with `"min": 7.0` would
	 * have silently raised the AA floor used by blueline_readable_foreground()
	 * and blueline_team_color_set() for every team page on the site.
	 */
	public function test_an_unusual_rule_min_does_not_move_the_declared_thresholds(): void {
		$fallback = array(
			'body'  => 4.5,
			'large' => 3.0,
		);

		$json = array(
			'thresholds' => array(
				'body'  => 4.5,
				'large' => 3.0,
			),
			'rules'      => array(
				array(
					'id'  => 'hypothetical-aaa-rule',
					'fg'  => '--bl-ink',
					'bg'  => '--bl-paper',
					'min' => 7.0,
				),
			),
		);

		$thresholds = blueline_contrast_thresholds_from_json( $json, $fallback, 'test-fixture.json' );

		$this->assertSame( 4.5, $thresholds['body'], 'a rule min of 7.0 must not raise the declared body threshold' );
		$this->assertSame( 3.0, $thresholds['large'], 'a rule min of 7.0 must not move the declared large threshold either' );
	}

	/**
	 * Asserts a missing or malformed "thresholds" key falls back to sane
	 * hard-coded defaults rather than fataling or returning nonsense.
	 */
	public function test_missing_thresholds_key_falls_back_to_defaults(): void {
		$fallback = array(
			'body'  => 4.5,
			'large' => 3.0,
		);

		$this->assertSame( $fallback, blueline_contrast_thresholds_from_json( array( 'rules' => array() ), $fallback ) );
		$this->assertSame( $fallback, blueline_contrast_thresholds_from_json( array( 'thresholds' => 'not-an-object' ), $fallback ) );
		$this->assertSame( $fallback, blueline_contrast_thresholds_from_json( null, $fallback ) );
		$this->assertSame( $fallback, blueline_contrast_thresholds_from_json( 'not even an array', $fallback ) );
	}

	/**
	 * Asserts a "thresholds" object with a non-numeric value falls back to
	 * that one value's default while still logging -- never a fatal, never
	 * a silently wrong number.
	 */
	public function test_non_numeric_threshold_value_falls_back_for_that_value_only(): void {
		$fallback = array(
			'body'  => 4.5,
			'large' => 3.0,
		);

		$thresholds = blueline_contrast_thresholds_from_json(
			array(
				'thresholds' => array(
					'body'  => 'not-a-number',
					'large' => 3.0,
				),
			),
			$fallback
		);

		$this->assertSame( 4.5, $thresholds['body'] );
		$this->assertSame( 3.0, $thresholds['large'] );
	}

	/**
	 * Asserts blueline_load_contrast_thresholds() never fatals against a
	 * nonexistent file and returns the sane fallback -- the "unreadable"
	 * half of Fix 2 (the file is unreadable or malformed must still return
	 * sane values, never fatal).
	 */
	public function test_load_thresholds_falls_back_when_file_is_unreadable(): void {
		$thresholds = blueline_load_contrast_thresholds( '/nonexistent/path/contrast-rules.json' );

		$this->assertSame( 4.5, $thresholds['body'] );
		$this->assertSame( 3.0, $thresholds['large'] );
	}

	/**
	 * Asserts blueline_load_contrast_thresholds() never fatals against a
	 * file that exists but is not valid JSON -- the "malformed" half of
	 * Fix 2.
	 */
	public function test_load_thresholds_falls_back_when_file_is_malformed_json(): void {
		$path = sys_get_temp_dir() . '/blueline-malformed-' . uniqid( '', true ) . '.json';
		file_put_contents( $path, '{ not valid json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture, not a remote URL.

		try {
			$thresholds = blueline_load_contrast_thresholds( $path );

			$this->assertSame( 4.5, $thresholds['body'] );
			$this->assertSame( 3.0, $thresholds['large'] );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup, not a remote resource.
		}
	}

	/**
	 * Asserts the read-failure trace hook never fatals and is safe to call
	 * repeatedly (it is called on every request where the file is broken).
	 */
	public function test_read_failure_hook_never_fatals(): void {
		blueline_contrast_rules_read_failure( '/nonexistent/path.json', 'missing or unreadable' );
		blueline_contrast_rules_read_failure( '/nonexistent/path.json', 'missing or unreadable' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Asserts blueline_readable_foreground() reports failure via its
	 * by-reference $passes parameter when neither ink nor paper reaches AA
	 * on the given background, rather than failing silently.
	 */
	public function test_readable_foreground_reports_when_neither_option_passes(): void {
		// #808080 has no AA-passing foreground from {ink, paper}: best is ~3.9.
		$passes = null;
		$fg     = blueline_readable_foreground( '#808080', $passes );

		$this->assertNotNull( $fg, 'it must still return a colour to render' );
		$this->assertFalse( $passes, 'but it must report that the colour fails' );
	}

	/**
	 * Asserts blueline_readable_foreground() reports success via $passes
	 * for a background where a fully AA-passing foreground exists.
	 */
	public function test_readable_foreground_reports_success_for_a_usable_colour(): void {
		$passes = null;
		blueline_readable_foreground( '#FFFFFF', $passes );

		$this->assertTrue( $passes );
	}
}
