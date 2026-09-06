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

require_once __DIR__ . '/../inc/settings/defaults.php';
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

	/**
	 * Every BLUELINE_TOKEN_* constant for the 12 brand tokens is defined
	 * and matches style.css's own documented literal hex.
	 */
	public function test_all_twelve_brand_token_constants_are_defined_and_match_style_css(): void {
		$this->assertSame( '#132343', BLUELINE_TOKEN_INK );
		$this->assertSame( '#0D1729', BLUELINE_TOKEN_INK_DEEP );
		$this->assertSame( '#2E4A74', BLUELINE_TOKEN_INK_MID );
		$this->assertSame( '#3F6E9D', BLUELINE_TOKEN_ACCENT_TEXT );
		$this->assertSame( '#5188B7', BLUELINE_TOKEN_STEEL );
		$this->assertSame( '#74C0E1', BLUELINE_TOKEN_ICE );
		$this->assertSame( '#9ACDE7', BLUELINE_TOKEN_PALE );
		$this->assertSame( '#F7FBFC', BLUELINE_TOKEN_PAPER );
		$this->assertSame( '#FFFFFF', BLUELINE_TOKEN_WHITE );
		$this->assertSame( '#1F7A4D', BLUELINE_TOKEN_SUCCESS );
		$this->assertSame( '#8A5A00', BLUELINE_TOKEN_WARNING );
		$this->assertSame( '#A32C1B', BLUELINE_TOKEN_DANGER );
	}

	/**
	 * Confirms blueline_brand_color_tokens() returns exactly the 12
	 * expected token keys, in order, each carrying its `css_var`,
	 * `default_hex`, and a non-empty `label`.
	 */
	public function test_brand_color_tokens_returns_exactly_the_twelve_expected_keys(): void {
		$tokens = blueline_brand_color_tokens();

		$this->assertSame(
			array( 'ink', 'ink_deep', 'ink_mid', 'accent_text', 'steel', 'ice', 'pale', 'paper', 'white', 'success', 'warning', 'danger' ),
			array_keys( $tokens )
		);

		$this->assertSame( '--bl-ice', $tokens['ice']['css_var'] );
		$this->assertSame( BLUELINE_TOKEN_ICE, $tokens['ice']['default_hex'] );
		$this->assertNotSame( '', $tokens['ice']['label'] );
	}

	/**
	 * With no stored override, blueline_resolved_brand_color() falls back
	 * to the token's own default hex.
	 */
	public function test_resolved_brand_color_falls_back_to_the_default_when_unset(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().

		$this->assertSame( BLUELINE_TOKEN_ICE, blueline_resolved_brand_color( 'ice' ) );
	}

	/**
	 * With a stored override, blueline_resolved_brand_color() returns that
	 * override instead of the token's default hex.
	 */
	public function test_resolved_brand_color_returns_the_stored_override_when_set(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_ice' => '#123456' );

		$this->assertSame( '#123456', blueline_resolved_brand_color( 'ice' ) );
	}

	/**
	 * An unknown token key resolves to `''` rather than erroring or
	 * falling back to some other token's default.
	 */
	public function test_resolved_brand_color_returns_empty_string_for_an_unknown_token_key(): void {
		$this->assertSame( '', blueline_resolved_brand_color( 'not-a-real-token' ) );
	}

	/**
	 * A malformed stored brand-color value -- as could be left behind by a
	 * hand-edited DB row, a restored SQL dump, or a migration script, none
	 * of which go through update_option()'s own sanitize_option_* pipeline
	 * -- falls back to the token's default hex, exactly like an unset
	 * value, instead of flowing through verbatim.
	 */
	public function test_resolved_brand_color_falls_back_to_the_default_for_a_malformed_stored_value(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_ice' => 'not-a-real-color' );

		$this->assertSame( BLUELINE_TOKEN_ICE, blueline_resolved_brand_color( 'ice' ) );
	}

	/**
	 * The same malformed stored value must not leak into the emitted
	 * front-end CSS either: since it resolves back to the token's own
	 * default, it is treated as "not overridden" and produces no
	 * declaration at all for that token, matching the unset behaviour.
	 */
	public function test_brand_color_front_end_styles_emits_nothing_for_a_malformed_stored_value(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_ice' => 'not-a-real-color' );

		blueline_brand_color_front_end_styles();

		$this->assertSame( array(), blueline_test_state()['inline_styles'] );
	}

	/**
	 * Confirms tools/tokens.json marks every one of the 12 brand tokens'
	 * CSS variables as `tunable`, so tools/check-contrast.mjs treats them
	 * as admin-adjustable rather than fixed.
	 */
	public function test_tools_tokens_json_marks_all_twelve_brand_tokens_tunable(): void {
		$path = BLUELINE_DIR . '/tools/tokens.json';
		$json = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads a local repo file.

		foreach ( blueline_brand_color_tokens() as $token ) {
			$this->assertTrue(
				$json['tokens'][ $token['css_var'] ]['tunable'] ?? false,
				"{$token['css_var']} should be tunable"
			);
		}
	}

	/**
	 * Confirms blueline_contrast_rules_for_token_from_json() matches a
	 * token referenced as either `fg` or `bg`, and only rules referencing
	 * it.
	 */
	public function test_contrast_rules_for_token_from_json_matches_a_token_referenced_as_fg_or_bg(): void {
		$json = array(
			'rules' => array(
				array(
					'id'          => 'a',
					'description' => 'a',
					'fg'          => '--bl-ink',
					'bg'          => '--bl-ice',
					'min'         => 4.5,
				),
				array(
					'id'          => 'b',
					'description' => 'b',
					'fg'          => '--bl-ice',
					'bg'          => '--bl-ink',
					'min'         => 4.5,
				),
				array(
					'id'          => 'c',
					'description' => 'c',
					'fg'          => '--bl-ice',
					'bg'          => '--bl-paper',
					'max'         => 3.0,
				),
				array(
					'id'          => 'd',
					'description' => 'd',
					'fg'          => '--bl-danger',
					'bg'          => '--bl-white',
					'min'         => 4.5,
				),
			),
		);

		$matches = blueline_contrast_rules_for_token_from_json( $json, '--bl-ice' );

		$this->assertSame( array( 'a', 'b', 'c' ), array_column( $matches, 'id' ) );
	}

	/**
	 * Confirms blueline_contrast_rules_for_token_from_json() excludes rules
	 * whose other side is a `mix(...)` expression, `--bl-occasion-accent`,
	 * or `--bl-content-bg` -- none of which are brand-palette tokens.
	 */
	public function test_contrast_rules_for_token_from_json_excludes_mix_occasion_and_content_rules(): void {
		$json = array(
			'rules' => array(
				array(
					'id'          => 'mix',
					'description' => 'x',
					'fg'          => '--bl-success',
					'bg'          => array( 'mix' => array( '--bl-success', 8, '--bl-white' ) ),
					'min'         => 4.5,
				),
				array(
					'id'          => 'occasion',
					'description' => 'x',
					'fg'          => '--bl-ink',
					'bg'          => '--bl-occasion-accent',
					'min'         => 4.5,
				),
				array(
					'id'          => 'content',
					'description' => 'x',
					'fg'          => '--bl-success',
					'bg'          => '--bl-content-bg',
					'min'         => 4.5,
				),
				array(
					'id'          => 'keep',
					'description' => 'x',
					'fg'          => '--bl-success',
					'bg'          => '--bl-white',
					'min'         => 4.5,
				),
			),
		);

		$this->assertSame( array( 'keep' ), array_column( blueline_contrast_rules_for_token_from_json( $json, '--bl-success' ), 'id' ) );
	}

	/**
	 * Confirms blueline_contrast_rules_for_token() reads the real
	 * tools/contrast-rules.json and finds exactly the 4 rules style.css
	 * documents `--bl-ice` as appearing in.
	 */
	public function test_contrast_rules_for_token_reads_the_real_contrast_rules_json(): void {
		// --bl-ice is documented (style.css comments) to appear in exactly
		// these 4 rules today: ink-on-ice, ice-on-ink, ice-not-text-on-light,
		// focus-on-ice.
		$ids = array_column( blueline_contrast_rules_for_token( '--bl-ice' ), 'id' );
		sort( $ids );

		$this->assertSame(
			array( 'focus-on-ice', 'ice-not-text-on-light', 'ice-on-ink', 'ink-on-ice' ),
			$ids
		);
	}

	/**
	 * Confirms blueline_static_token_hex() resolves the known non-brand CSS
	 * variables (focus color, focus halo, borders) and returns `''` for
	 * anything it doesn't recognise.
	 */
	public function test_static_token_hex_resolves_the_known_non_brand_tokens(): void {
		$this->assertSame( '#0D1729', blueline_static_token_hex( '--bl-focus-color' ) );
		$this->assertSame( '#FFFFFF', blueline_static_token_hex( '--bl-focus-halo' ) );
		$this->assertSame( '#DBE7F0', blueline_static_token_hex( '--bl-border' ) );
		$this->assertSame( '#7C93A8', blueline_static_token_hex( '--bl-border-strong' ) );
		$this->assertSame( '', blueline_static_token_hex( '--bl-not-a-real-token' ) );
	}

	/**
	 * Confirms blueline_hex_for_css_var() resolves a brand-token CSS
	 * variable through its live override, and a non-brand variable through
	 * blueline_static_token_hex().
	 */
	public function test_hex_for_css_var_resolves_a_brand_token_through_its_override(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_paper' => '#EEEEEE' );

		// blueline_resolved_brand_color() now runs the stored override
		// through blueline_sanitize_hex_color() (Fix 2 of the final-review
		// fix wave), which normalises to lowercase -- so the resolved
		// value is '#eeeeee', not the mixed-case '#EEEEEE' as stored.
		$this->assertSame( '#eeeeee', blueline_hex_for_css_var( '--bl-paper' ) );
		$this->assertSame( '#0D1729', blueline_hex_for_css_var( '--bl-focus-color' ) );
	}

	/**
	 * Confirms blueline_brand_color_contrast_report() computes style.css's
	 * documented 1.94:1 ice-on-paper ratio and correctly reports it as a
	 * PASS of the "not usable as text" max:3.0 rule.
	 */
	public function test_brand_color_contrast_report_computes_the_documented_ice_on_paper_failure(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().

		$report = blueline_brand_color_contrast_report( 'ice', BLUELINE_TOKEN_ICE );
		$row    = current( array_filter( $report, static fn( $r ) => 'ice-not-text-on-light' === $r['id'] ) );

		// style.css documents --bl-ice at 1.94:1 on paper, well under the
		// rule's max:3.0 pass bound for "correctly unusable as text" -- so
		// this rule reads as a PASS for the correct reason: 1.94 <= 3.0.
		$this->assertNotFalse( $row );
		$this->assertEqualsWithDelta( 1.94, $row['ratio'], 0.05 );
		$this->assertTrue( $row['passes'] );
	}

	/**
	 * Confirms blueline_brand_color_contrast_report() uses a live override
	 * on the OTHER side of the pairing (paper), not just the token being
	 * reported on (ice).
	 */
	public function test_brand_color_contrast_report_reflects_a_live_override_on_the_other_side(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		// Override paper to pure white, then check ice's contrast against
		// paper — it should use the OVERRIDDEN paper value, not the default.
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_paper' => '#FFFFFF' );

		$report = blueline_brand_color_contrast_report( 'ice', BLUELINE_TOKEN_ICE );
		$row    = current( array_filter( $report, static fn( $r ) => 'ice-not-text-on-light' === $r['id'] ) );

		$expected = blueline_contrast_ratio( BLUELINE_TOKEN_ICE, '#FFFFFF' );
		$this->assertEqualsWithDelta( $expected, $row['ratio'], 0.01 );
	}

	/**
	 * Confirms blueline_brand_color_front_end_styles() enqueues no inline
	 * style at all when no brand-color token is overridden.
	 */
	public function test_brand_color_front_end_styles_emits_nothing_when_nothing_is_overridden(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().

		blueline_brand_color_front_end_styles();

		$this->assertSame( array(), blueline_test_state()['inline_styles'] );
	}

	/**
	 * Confirms blueline_brand_color_front_end_styles() emits declarations
	 * only for the tokens actually overridden, leaving un-overridden
	 * tokens (ink) out of the emitted CSS entirely.
	 */
	public function test_brand_color_front_end_styles_emits_only_the_overridden_tokens(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array(
			'brand_color_ice'   => '#123456',
			'brand_color_paper' => '#abcdef',
		);

		blueline_brand_color_front_end_styles();

		$calls = blueline_test_state()['inline_styles'];
		$this->assertCount( 1, $calls );
		$this->assertSame( 'blueline-tokens', $calls[0][0] );
		$this->assertStringContainsString( '--bl-ice:#123456;', $calls[0][1] );
		$this->assertStringContainsString( '--bl-paper:#abcdef;', $calls[0][1] );
		$this->assertStringNotContainsString( '--bl-ink:', $calls[0][1] );
	}

	/**
	 * An overridden theme-aware token (success) repeats into all three
	 * selectors -- plain `:root`, the dark-mode media query, and the
	 * explicit `[data-theme="dark"]` override -- not just the first.
	 */
	public function test_brand_color_front_end_styles_repeats_theme_aware_tokens_into_all_three_selectors(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_success' => '#00ff00' );

		blueline_brand_color_front_end_styles();

		$css = blueline_test_state()['inline_styles'][0][1];

		$this->assertStringContainsString( ':root{--bl-success:#00ff00;}', $css );
		$this->assertStringContainsString( '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--bl-success:#00ff00;}}', $css );
		$this->assertStringContainsString( ':root[data-theme="dark"]{--bl-success:#00ff00;}', $css );
	}

	/**
	 * Confirms blueline_brand_color_editor_styles() is a pass-through of
	 * the block-editor `$settings` array when nothing is overridden.
	 */
	public function test_brand_color_editor_styles_passes_settings_through_unchanged_when_nothing_is_overridden(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().

		$settings = array( 'other' => 'untouched' );
		$this->assertSame( $settings, blueline_brand_color_editor_styles( $settings ) );
	}

	/**
	 * An overridden token appends a new `styles` entry carrying its CSS
	 * declaration to the block-editor settings.
	 */
	public function test_brand_color_editor_styles_appends_a_styles_entry_when_something_is_overridden(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_ice' => '#123456' );

		$result = blueline_brand_color_editor_styles( array() );

		$this->assertCount( 1, $result['styles'] );
		$this->assertStringContainsString( '--bl-ice:#123456;', $result['styles'][0]['css'] );
		$this->assertStringContainsString( '.editor-styles-wrapper', $result['styles'][0]['css'] );
	}

	/**
	 * Confirms blueline_brand_color_editor_styles() appends to an existing
	 * `styles` array rather than replacing it.
	 */
	public function test_brand_color_editor_styles_preserves_an_existing_styles_entry(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_ice' => '#123456' );

		$result = blueline_brand_color_editor_styles( array( 'styles' => array( array( 'css' => 'existing' ) ) ) );

		$this->assertCount( 2, $result['styles'] );
		$this->assertSame( 'existing', $result['styles'][0]['css'] );
	}
}
