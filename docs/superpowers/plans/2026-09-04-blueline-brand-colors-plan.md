# Blueline Brand Colors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the theme's 12 brand-palette color tokens editable from the
existing Blueline settings page (Appearance tab), with a live advisory
contrast readout, and have the WooCommerce/FUE email branding read the
same values, so a color change updates the site and transactional emails
together — all without a code deploy.

**Architecture:** Reuse the occasion-accent machinery end to end: flip
`tools/tokens.json`'s `tunable` flag for the 12 brand tokens, extend
`inc/team-colors.php`'s existing `BLUELINE_TOKEN_*` constants and contrast
helpers, add a new `color` field type to the settings page's existing
generic field-type dispatcher (`inc/settings/page.php` /
`inc/settings/sanitize.php` — the same mechanism `bool`/`date`/`page_id`
already use), extend the `blueline-tokens` inline-style handle
(`inc/occasions.php`'s existing pattern) with the overrides, and add one
more `block_editor_settings_all` filter callback for editor parity.

**Tech Stack:** WordPress theme (blueline), PHP 8+, PHPUnit (plain,
no WP test suite — see `tests/bootstrap.php`'s stub environment), plain
JS (no build step beyond the theme's existing asset pipeline), the
theme's existing JSON manifests (`tools/tokens.json`,
`tools/contrast-rules.json`).

**Spec:** `docs/superpowers/specs/2026-09-04-blueline-brand-colors-design.md`

## Global Constraints

- The 12 tokens, exactly: `ink`, `ink_deep`, `ink_mid`, `accent_text`,
  `steel`, `ice`, `pale`, `paper`, `white`, `success`, `warning`, `danger`
  (settings-key/token-key suffixes) mapping to `--bl-ink`, `--bl-ink-deep`,
  `--bl-ink-mid`, `--bl-accent-text`, `--bl-steel`, `--bl-ice`, `--bl-pale`,
  `--bl-paper`, `--bl-white`, `--bl-success`, `--bl-warning`, `--bl-danger`.
  No other `--bl-*` token becomes tunable.
- An unset override is the empty string `''`, never `null` or the literal
  default hex — this is what lets the CSS-output functions skip a token
  entirely rather than always emitting all 12.
- Contrast checking is **advisory only**: an invalid/low-contrast color
  always saves successfully. Nothing in this plan blocks a save.
- Skip any `contrast-rules.json` rule where either side is a computed
  `{"mix": [...]}` tint, references `--bl-occasion-accent`, or references
  any `--bl-content-*` token — none of those three shapes has a single
  static value this settings page can resolve. This is enforced inside
  `blueline_contrast_rules_for_token_from_json()` (Task 2), not left to
  callers to remember.
- Three of the twelve tokens (`--bl-success`, `--bl-warning`,
  `--bl-danger`) are redefined in `style.css`'s
  `@media (prefers-color-scheme: dark)` block and its
  `:root[data-theme="dark"]` explicit-toggle twin. Every CSS-emitting
  function in this plan (Tasks 5 and 6) must emit an override into all
  three selector shapes with the same value, or a dark-mode viewer will
  silently see the un-overridden default instead of the admin's choice.
- Every new PHP function is added to existing files, following each
  file's own established conventions (see each task) — no new PHP files.
  Two new JS files are added (Task 4).
- WooCommerce email colors (Task 7) must resolve through the exact same
  `blueline_resolved_brand_color()` (Task 1) that the front end and editor
  use — never a second, independently-hardcoded copy.

---

### Task 1: Token declarations, tunability manifest, and resolution

**Files:**
- Modify: `themes/blueline/inc/team-colors.php` (add constants + 2 new functions, after the existing `BLUELINE_TOKEN_INK`/`BLUELINE_TOKEN_PAPER` constants at lines 41-42)
- Modify: `themes/blueline/tools/tokens.json` (flip `tunable` for 12 entries)
- Modify: `themes/blueline/inc/settings/defaults.php` (add 12 settings keys, in the "Appearance tab" section near `hero_photo_rotate`)
- Test: `themes/blueline/tests/TeamColorsTest.php` (extend)

**Interfaces:**
- Produces: `BLUELINE_TOKEN_INK_DEEP`, `BLUELINE_TOKEN_INK_MID`,
  `BLUELINE_TOKEN_ACCENT_TEXT`, `BLUELINE_TOKEN_STEEL`,
  `BLUELINE_TOKEN_ICE`, `BLUELINE_TOKEN_PALE`, `BLUELINE_TOKEN_WHITE`,
  `BLUELINE_TOKEN_SUCCESS`, `BLUELINE_TOKEN_WARNING`,
  `BLUELINE_TOKEN_DANGER` (string constants, `inc/team-colors.php`).
- Produces: `blueline_brand_color_tokens(): array` — keyed by token key
  (`'ink'`, `'ink_deep'`, ...), each entry
  `array{css_var: string, default_hex: string, label: string}`.
- Produces: `blueline_resolved_brand_color( string $token_key ): string`
  — the admin override if set, else that token's `default_hex`; `''` for
  an unknown `$token_key`.
- Consumes (later tasks): `blueline_settings( string $key )` (already
  exists, `inc/settings/store.php`).

- [ ] **Step 1: Write the failing tests for the constants and token map**

Append to `themes/blueline/tests/TeamColorsTest.php`:

```php
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

	public function test_resolved_brand_color_falls_back_to_the_default_when_unset(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().

		$this->assertSame( BLUELINE_TOKEN_ICE, blueline_resolved_brand_color( 'ice' ) );
	}

	public function test_resolved_brand_color_returns_the_stored_override_when_set(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_ice' => '#123456' );

		$this->assertSame( '#123456', blueline_resolved_brand_color( 'ice' ) );
	}

	public function test_resolved_brand_color_returns_empty_string_for_an_unknown_token_key(): void {
		$this->assertSame( '', blueline_resolved_brand_color( 'not-a-real-token' ) );
	}

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
```

This uses the real, already-established convention for seeding the
settings option in a test — `tests/bootstrap.php`'s `get_option()` stub
reads directly from `$GLOBALS['bl_test_options']`, and
`tests/FooterAndHeroSettingsRenderTest.php:198` already seeds
`BLUELINE_SETTINGS_OPTION` this exact way. No new test helper is needed.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd themes/blueline && composer test -- --filter TeamColorsTest`
Expected: FAIL — `BLUELINE_TOKEN_INK_DEEP` undefined,
`blueline_brand_color_tokens()`/`blueline_resolved_brand_color()` undefined.

- [ ] **Step 3: Add the ten new constants**

In `themes/blueline/inc/team-colors.php`, replace:

```php
const BLUELINE_TOKEN_INK   = '#132343';
const BLUELINE_TOKEN_PAPER = '#F7FBFC';
```

with:

```php
const BLUELINE_TOKEN_INK         = '#132343';
const BLUELINE_TOKEN_INK_DEEP    = '#0D1729';
const BLUELINE_TOKEN_INK_MID     = '#2E4A74';
const BLUELINE_TOKEN_ACCENT_TEXT = '#3F6E9D';
const BLUELINE_TOKEN_STEEL       = '#5188B7';
const BLUELINE_TOKEN_ICE         = '#74C0E1';
const BLUELINE_TOKEN_PALE        = '#9ACDE7';
const BLUELINE_TOKEN_PAPER       = '#F7FBFC';
const BLUELINE_TOKEN_WHITE       = '#FFFFFF';
const BLUELINE_TOKEN_SUCCESS     = '#1F7A4D';
const BLUELINE_TOKEN_WARNING     = '#8A5A00';
const BLUELINE_TOKEN_DANGER      = '#A32C1B';
```

Update the constants' docblock (currently says "Mirrors of the two theme
tokens...") to say "twelve" and list the new ones, keeping the existing
"tools/check-contrast.mjs asserts these... still match style.css" note —
verify that assertion in `tools/check-contrast.mjs` actually only checks
`INK`/`PAPER` today (grep `check-contrast.mjs` for `BLUELINE_TOKEN`); if
it does, this docblock update is documentation-only for this task (no
`.mjs` change needed) — a future consistency-check expansion is not part
of this plan (YAGNI: nothing in this feature depends on that script also
checking the other ten).

- [ ] **Step 4: Add `blueline_brand_color_tokens()`**

Add to `themes/blueline/inc/team-colors.php`, after the constants:

```php
/**
 * Every brand-palette token this feature exposes as admin-tunable, keyed
 * by a short settings/token key (e.g. 'ice' for --bl-ice). This is the
 * one place the set of 12 is spelled out; everything else in this
 * feature iterates it rather than repeating the list.
 *
 * @return array<string, array{css_var: string, default_hex: string, label: string}>
 */
function blueline_brand_color_tokens(): array {
	return array(
		'ink'         => array(
			'css_var'     => '--bl-ink',
			'default_hex' => BLUELINE_TOKEN_INK,
			'label'       => 'Ink (body text & headings)',
		),
		'ink_deep'    => array(
			'css_var'     => '--bl-ink-deep',
			'default_hex' => BLUELINE_TOKEN_INK_DEEP,
			'label'       => 'Ink, deep (darkest shade)',
		),
		'ink_mid'     => array(
			'css_var'     => '--bl-ink-mid',
			'default_hex' => BLUELINE_TOKEN_INK_MID,
			'label'       => 'Ink, mid (secondary text)',
		),
		'accent_text' => array(
			'css_var'     => '--bl-accent-text',
			'default_hex' => BLUELINE_TOKEN_ACCENT_TEXT,
			'label'       => 'Accent (links & buttons)',
		),
		'steel'       => array(
			'css_var'     => '--bl-steel',
			'default_hex' => BLUELINE_TOKEN_STEEL,
			'label'       => 'Steel (borders, large text/strokes only)',
		),
		'ice'         => array(
			'css_var'     => '--bl-ice',
			'default_hex' => BLUELINE_TOKEN_ICE,
			'label'       => 'Ice (fill only — never text on light)',
		),
		'pale'        => array(
			'css_var'     => '--bl-pale',
			'default_hex' => BLUELINE_TOKEN_PALE,
			'label'       => 'Pale (text on dark surfaces only)',
		),
		'paper'       => array(
			'css_var'     => '--bl-paper',
			'default_hex' => BLUELINE_TOKEN_PAPER,
			'label'       => 'Paper (page background)',
		),
		'white'       => array(
			'css_var'     => '--bl-white',
			'default_hex' => BLUELINE_TOKEN_WHITE,
			'label'       => 'White (card surfaces)',
		),
		'success'     => array(
			'css_var'     => '--bl-success',
			'default_hex' => BLUELINE_TOKEN_SUCCESS,
			'label'       => 'Success',
		),
		'warning'     => array(
			'css_var'     => '--bl-warning',
			'default_hex' => BLUELINE_TOKEN_WARNING,
			'label'       => 'Warning',
		),
		'danger'      => array(
			'css_var'     => '--bl-danger',
			'default_hex' => BLUELINE_TOKEN_DANGER,
			'label'       => 'Danger',
		),
	);
}

/**
 * The real, currently-effective hex for one brand-palette token: the
 * admin's stored override (inc/settings/defaults.php's
 * `brand_color_{$token_key}` keys) when one is set, else that token's
 * style.css default. `''` for a `$token_key` blueline_brand_color_tokens()
 * does not declare.
 *
 * @param string $token_key e.g. 'ice'.
 * @return string
 */
function blueline_resolved_brand_color( string $token_key ): string {
	$tokens = blueline_brand_color_tokens();

	if ( ! isset( $tokens[ $token_key ] ) ) {
		return '';
	}

	$override = blueline_settings( "brand_color_{$token_key}" );

	if ( is_string( $override ) && '' !== $override ) {
		return $override;
	}

	return $tokens[ $token_key ]['default_hex'];
}
```

- [ ] **Step 5: Flip `tools/tokens.json`'s tunable flags**

In `themes/blueline/tools/tokens.json`, change `"tunable": false` to
`"tunable": true` for exactly these 12 entries (identified by their
`--bl-*` key): `--bl-ink`, `--bl-ink-deep`, `--bl-ink-mid`,
`--bl-accent-text`, `--bl-steel`, `--bl-ice`, `--bl-pale`, `--bl-paper`,
`--bl-white`, `--bl-success`, `--bl-warning`, `--bl-danger`. Leave every
other entry's `tunable` value exactly as it is today. Update the file's
top-level `"$comment"` to note that the brand tier is now tunable (it
currently says "only --bl-occasion-accent is tunable" — correct that
sentence to name both).

- [ ] **Step 6: Add the 12 settings keys to `defaults.php`**

In `themes/blueline/inc/settings/defaults.php`, inside
`blueline_settings_schema()`'s returned array, in the "Appearance tab"
section (near `hero_photo_rotate`/`standings_extra_stats_default`), add:

```php
		'brand_color_ink'         => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Ink (body text & headings)',
			'token_key' => 'ink',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_ink_deep'    => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Ink, deep (darkest shade)',
			'token_key' => 'ink_deep',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_ink_mid'     => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Ink, mid (secondary text)',
			'token_key' => 'ink_mid',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_accent_text' => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Accent (links & buttons)',
			'token_key' => 'accent_text',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_steel'       => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Steel (borders, large text/strokes only)',
			'token_key' => 'steel',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_ice'         => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Ice (fill only — never text on light)',
			'token_key' => 'ice',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_pale'        => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Pale (text on dark surfaces only)',
			'token_key' => 'pale',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_paper'       => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Paper (page background)',
			'token_key' => 'paper',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_white'       => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'White (card surfaces)',
			'token_key' => 'white',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_success'     => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Success',
			'token_key' => 'success',
			'help'      => 'Leave blank to use the theme default. Applies the same in light and dark mode.',
		),
		'brand_color_warning'     => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Warning',
			'token_key' => 'warning',
			'help'      => 'Leave blank to use the theme default. Applies the same in light and dark mode.',
		),
		'brand_color_danger'      => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Danger',
			'token_key' => 'danger',
			'help'      => 'Leave blank to use the theme default. Applies the same in light and dark mode.',
		),
```

Each key's own *default* (read via `blueline_settings( 'brand_color_ink' )`
before any override is saved) must be `''` — confirm
`blueline_settings_schema()`'s surrounding code derives a field's default
from something other than an explicit `'default'` key (check how
`hero_photo_rotate`, a `bool`, gets its default; if the schema requires an
explicit `'default' => ''`, add it to each of the 12 entries above).

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd themes/blueline && composer test -- --filter TeamColorsTest`
Expected: PASS (all 6 new tests).

- [ ] **Step 8: Run the full suite and commit**

Run: `cd themes/blueline && composer test`
Expected: PASS, no regressions.

```bash
git add themes/blueline/inc/team-colors.php themes/blueline/tools/tokens.json themes/blueline/inc/settings/defaults.php themes/blueline/tests/TeamColorsTest.php
git commit -m "feat(blueline): declare the 12 brand-palette tokens as tunable, add resolution"
```

---

### Task 2: Contrast rule lookup and per-token report

**Files:**
- Modify: `themes/blueline/inc/team-colors.php` (add 3 new functions)
- Test: `themes/blueline/tests/TeamColorsTest.php` (extend)

**Interfaces:**
- Consumes: `blueline_brand_color_tokens()`, `blueline_resolved_brand_color()`
  (Task 1); `blueline_contrast_ratio( string $a, string $b ): float`
  (already exists, `inc/team-colors.php`); `blueline_contrast_rules_read_failure()`
  (already exists).
- Produces: `blueline_contrast_rules_for_token_from_json( array $json, string $css_var ): array`
  — pure, testable against decoded JSON without touching disk. Returns a
  list of `array{id: string, description: string, fg: string, bg: string, min: ?float, max: ?float}`.
- Produces: `blueline_contrast_rules_for_token( string $css_var, ?string $path_override = null ): array`
  — file-reading wrapper around the above, same signature shape as
  `blueline_load_contrast_thresholds( ?string $path_override = null )`.
- Produces: `blueline_static_token_hex( string $css_var ): string` — the
  fixed hex for a small, closed set of non-brand tokens that appear as the
  *other* side of a brand-token contrast rule (`--bl-focus-color`,
  `--bl-focus-halo`, `--bl-border`, `--bl-border-strong`); `''` for
  anything else.
- Produces: `blueline_hex_for_css_var( string $css_var ): string` — the
  current effective hex for *any* `--bl-*` var this feature can resolve:
  one of the 12 brand tokens (via `blueline_resolved_brand_color()`) or
  one of `blueline_static_token_hex()`'s fixed set.
- Produces: `blueline_brand_color_contrast_report( string $token_key, string $candidate_hex ): array`
  — every applicable rule for `$token_key`, each row
  `array{id: string, description: string, ratio: float, passes: bool}`,
  computed against `$candidate_hex` and the *current* resolved value of
  whichever token each rule's other side names.

- [ ] **Step 1: Write the failing tests**

Append to `themes/blueline/tests/TeamColorsTest.php`:

```php
	public function test_contrast_rules_for_token_from_json_matches_a_token_referenced_as_fg_or_bg(): void {
		$json = array(
			'rules' => array(
				array( 'id' => 'a', 'description' => 'a', 'fg' => '--bl-ink', 'bg' => '--bl-ice', 'min' => 4.5 ),
				array( 'id' => 'b', 'description' => 'b', 'fg' => '--bl-ice', 'bg' => '--bl-ink', 'min' => 4.5 ),
				array( 'id' => 'c', 'description' => 'c', 'fg' => '--bl-ice', 'bg' => '--bl-paper', 'max' => 3.0 ),
				array( 'id' => 'd', 'description' => 'd', 'fg' => '--bl-danger', 'bg' => '--bl-white', 'min' => 4.5 ),
			),
		);

		$matches = blueline_contrast_rules_for_token_from_json( $json, '--bl-ice' );

		$this->assertSame( array( 'a', 'b', 'c' ), array_column( $matches, 'id' ) );
	}

	public function test_contrast_rules_for_token_from_json_excludes_mix_occasion_and_content_rules(): void {
		$json = array(
			'rules' => array(
				array( 'id' => 'mix', 'description' => 'x', 'fg' => '--bl-success', 'bg' => array( 'mix' => array( '--bl-success', 8, '--bl-white' ) ), 'min' => 4.5 ),
				array( 'id' => 'occasion', 'description' => 'x', 'fg' => '--bl-ink', 'bg' => '--bl-occasion-accent', 'min' => 4.5 ),
				array( 'id' => 'content', 'description' => 'x', 'fg' => '--bl-success', 'bg' => '--bl-content-bg', 'min' => 4.5 ),
				array( 'id' => 'keep', 'description' => 'x', 'fg' => '--bl-success', 'bg' => '--bl-white', 'min' => 4.5 ),
			),
		);

		$this->assertSame( array( 'keep' ), array_column( blueline_contrast_rules_for_token_from_json( $json, '--bl-success' ), 'id' ) );
	}

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

	public function test_static_token_hex_resolves_the_known_non_brand_tokens(): void {
		$this->assertSame( '#0D1729', blueline_static_token_hex( '--bl-focus-color' ) );
		$this->assertSame( '#FFFFFF', blueline_static_token_hex( '--bl-focus-halo' ) );
		$this->assertSame( '#DBE7F0', blueline_static_token_hex( '--bl-border' ) );
		$this->assertSame( '#7C93A8', blueline_static_token_hex( '--bl-border-strong' ) );
		$this->assertSame( '', blueline_static_token_hex( '--bl-not-a-real-token' ) );
	}

	public function test_hex_for_css_var_resolves_a_brand_token_through_its_override(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_paper' => '#EEEEEE' );

		$this->assertSame( '#EEEEEE', blueline_hex_for_css_var( '--bl-paper' ) );
		$this->assertSame( '#0D1729', blueline_hex_for_css_var( '--bl-focus-color' ) );
	}

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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd themes/blueline && composer test -- --filter TeamColorsTest`
Expected: FAIL — the new functions don't exist yet.

- [ ] **Step 3: Implement the rule lookup, static-token resolver, and report**

Add to `themes/blueline/inc/team-colors.php`:

```php
/**
 * Every tools/contrast-rules.json rule whose fg or bg is exactly
 * $css_var, from an already-decoded manifest. Excludes any rule where
 * either side is a computed `{"mix": [...]}` tint (no runtime
 * colour-mixing exists in this codebase — see this plan's Global
 * Constraints), references `--bl-occasion-accent` (its live value
 * depends on which occasion is active, unrelated to this settings page),
 * or references any `--bl-content-*` token (those are light/dark-toggle
 * aliases with no single static value).
 *
 * Split out from blueline_contrast_rules_for_token() so it is
 * unit-testable against arbitrary decoded JSON without touching the
 * filesystem — same reasoning as blueline_contrast_thresholds_from_json().
 *
 * @param mixed  $json    Decoded contrast-rules.json.
 * @param string $css_var e.g. '--bl-ice'.
 * @return array<int, array{id: string, description: string, fg: string, bg: string, min: ?float, max: ?float}>
 */
function blueline_contrast_rules_for_token_from_json( $json, string $css_var ): array {
	if ( ! is_array( $json ) || empty( $json['rules'] ) || ! is_array( $json['rules'] ) ) {
		return array();
	}

	$matches = array();

	foreach ( $json['rules'] as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}

		$fg = $rule['fg'] ?? null;
		$bg = $rule['bg'] ?? null;

		if ( ! is_string( $fg ) || ! is_string( $bg ) ) {
			continue; // A computed {"mix": [...]} side — not resolvable here.
		}

		$excluded = false;
		foreach ( array( $fg, $bg ) as $side ) {
			if ( '--bl-occasion-accent' === $side || 0 === strpos( $side, '--bl-content-' ) ) {
				$excluded = true;
				break;
			}
		}
		if ( $excluded ) {
			continue;
		}

		if ( $css_var !== $fg && $css_var !== $bg ) {
			continue;
		}

		$matches[] = array(
			'id'          => (string) ( $rule['id'] ?? '' ),
			'description' => (string) ( $rule['description'] ?? '' ),
			'fg'          => $fg,
			'bg'          => $bg,
			'min'         => isset( $rule['min'] ) && is_numeric( $rule['min'] ) ? (float) $rule['min'] : null,
			'max'         => isset( $rule['max'] ) && is_numeric( $rule['max'] ) ? (float) $rule['max'] : null,
		);
	}

	return $matches;
}

/**
 * Load every tools/contrast-rules.json rule referencing $css_var from disk.
 *
 * @param string      $css_var       e.g. '--bl-ice'.
 * @param string|null $path_override Explicit path, for tests.
 * @return array<int, array{id: string, description: string, fg: string, bg: string, min: ?float, max: ?float}>
 */
function blueline_contrast_rules_for_token( string $css_var, ?string $path_override = null ): array {
	if ( null !== $path_override ) {
		$path = $path_override;
	} else {
		$dir  = defined( 'BLUELINE_DIR' ) ? BLUELINE_DIR : dirname( __DIR__ );
		$path = $dir . '/tools/contrast-rules.json';
	}

	if ( ! is_readable( $path ) ) {
		blueline_contrast_rules_read_failure( $path, 'file is missing or unreadable' );
		return array();
	}

	$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file, not a remote URL.
	$json = json_decode( (string) $raw, true );

	if ( ! is_array( $json ) ) {
		blueline_contrast_rules_read_failure( $path, 'invalid JSON' );
		return array();
	}

	return blueline_contrast_rules_for_token_from_json( $json, $css_var );
}

/**
 * The fixed hex for a small, closed set of non-brand tokens that show up
 * as the OTHER side of a brand-token contrast rule (focus ring/halo,
 * dividers) — never admin-tunable, so this is a plain literal lookup, not
 * a settings read. Values copied verbatim from style.css's `:root` block.
 *
 * @param string $css_var e.g. '--bl-focus-color'.
 * @return string Hex, or '' if $css_var is not one of this fixed set.
 */
function blueline_static_token_hex( string $css_var ): string {
	$map = array(
		'--bl-focus-color'   => '#0D1729',
		'--bl-focus-halo'    => '#FFFFFF',
		'--bl-border'        => '#DBE7F0',
		'--bl-border-strong' => '#7C93A8',
	);

	return $map[ $css_var ] ?? '';
}

/**
 * The current effective hex for any --bl-* var this feature knows how to
 * resolve: one of the 12 brand tokens (through its live admin override,
 * blueline_resolved_brand_color()) or one of blueline_static_token_hex()'s
 * fixed non-brand set.
 *
 * @param string $css_var e.g. '--bl-ice'.
 * @return string Hex, or '' if neither resolver recognises $css_var.
 */
function blueline_hex_for_css_var( string $css_var ): string {
	foreach ( blueline_brand_color_tokens() as $token_key => $token ) {
		if ( $token['css_var'] === $css_var ) {
			return blueline_resolved_brand_color( $token_key );
		}
	}

	return blueline_static_token_hex( $css_var );
}

/**
 * Every applicable contrast rule for one brand token, evaluated against
 * $candidate_hex and the CURRENT resolved value of whichever token each
 * rule's other side names (so an admin's override on token A is reflected
 * live in token B's report, when a rule pairs A and B).
 *
 * @param string $token_key     e.g. 'ice'.
 * @param string $candidate_hex The value to check — not necessarily the
 *                               currently-saved one, so a not-yet-saved
 *                               edit can be checked before submit.
 * @return array<int, array{id: string, description: string, ratio: float, passes: bool}>
 */
function blueline_brand_color_contrast_report( string $token_key, string $candidate_hex ): array {
	$tokens = blueline_brand_color_tokens();

	if ( ! isset( $tokens[ $token_key ] ) ) {
		return array();
	}

	$css_var = $tokens[ $token_key ]['css_var'];
	$report  = array();

	foreach ( blueline_contrast_rules_for_token( $css_var ) as $rule ) {
		$other_var = $css_var === $rule['fg'] ? $rule['bg'] : $rule['fg'];
		$other_hex = blueline_hex_for_css_var( $other_var );

		$ratio = blueline_contrast_ratio( $candidate_hex, $other_hex );

		$passes = true;
		if ( null !== $rule['min'] ) {
			$passes = $ratio >= $rule['min'];
		} elseif ( null !== $rule['max'] ) {
			$passes = $ratio <= $rule['max'];
		}

		$report[] = array(
			'id'          => $rule['id'],
			'description' => $rule['description'],
			'ratio'       => $ratio,
			'passes'      => $passes,
		);
	}

	return $report;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd themes/blueline && composer test -- --filter TeamColorsTest`
Expected: PASS (all new tests from this task).

- [ ] **Step 5: Run the full suite and commit**

Run: `cd themes/blueline && composer test`
Expected: PASS.

```bash
git add themes/blueline/inc/team-colors.php themes/blueline/tests/TeamColorsTest.php
git commit -m "feat(blueline): contrast-rule lookup and live report for brand color tokens"
```

---

### Task 3: Settings-page `color` field type (schema already added in Task 1)

**Files:**
- Modify: `themes/blueline/inc/settings/sanitize.php` (add a `color` branch to `blueline_sanitize_field()`)
- Modify: `themes/blueline/inc/settings/page.php` (add a `color` branch to `blueline_settings_render_field()`, plus a new `blueline_settings_render_color_field()` helper)
- Test: New `themes/blueline/tests/BrandColorSettingsFieldTest.php`

**Interfaces:**
- Consumes: `blueline_brand_color_tokens()`, `blueline_resolved_brand_color()`,
  `blueline_brand_color_contrast_report()` (Tasks 1-2);
  `blueline_sanitize_hex_color()` (already exists, `inc/team-colors.php`).
- Produces: `blueline_settings_render_color_field( string $field_key, array $field, string $name, string $input_id, string $value ): void`
  — echoes markup, same calling convention as
  `blueline_settings_render_date_field()`/`blueline_settings_render_page_id_field()`.
- Modifies (in place): `blueline_sanitize_field()` gains a `'color' === $type`
  branch; `blueline_settings_render_field()` gains a `'color' === $type`
  branch in its existing `elseif` chain (placed right before the final
  bare-`term_id`/plain-text fallback branches).

- [ ] **Step 1: Write the failing tests**

Create `themes/blueline/tests/BrandColorSettingsFieldTest.php`:

```php
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

final class BrandColorSettingsFieldTest extends TestCase {

	protected function setUp(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
	}

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

	public function test_sanitize_field_accepts_empty_string_as_unset(): void {
		$field = array( 'type' => 'color', 'label' => 'Ice' );

		$this->assertSame( '', blueline_sanitize_field( '', $field ) );
		$this->assertSame( '', blueline_sanitize_field( '   ', $field ) );
	}

	public function test_sanitize_field_normalises_a_valid_hex_color(): void {
		$field = array( 'type' => 'color', 'label' => 'Ice' );

		$this->assertSame( '#3f6e9d', blueline_sanitize_field( '#3F6E9D', $field ) );
		$this->assertSame( '#3f6e9d', blueline_sanitize_field( '3F6E9D', $field ) );
	}

	public function test_sanitize_field_rejects_an_invalid_hex_color(): void {
		$field  = array( 'type' => 'color', 'label' => 'Ice' );
		$result = blueline_sanitize_field( 'not-a-color', $field );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_invalid_color', $result->get_error_code() );
	}

	public function test_render_color_field_outputs_the_default_placeholder_when_unset(): void {
		$field = array( 'token_key' => 'ice', 'label' => 'Ice' );

		ob_start();
		blueline_settings_render_color_field( 'brand_color_ice', $field, 'blueline_settings[brand_color_ice]', 'brand_color_ice', '' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'placeholder="' . BLUELINE_TOKEN_ICE . '"', $html );
		$this->assertStringContainsString( 'data-bl-brand-color-token="ice"', $html );
	}

	public function test_render_color_field_reflects_an_override_value_in_the_swatch(): void {
		$field = array( 'token_key' => 'paper', 'label' => 'Paper' );

		ob_start();
		blueline_settings_render_color_field( 'brand_color_paper', $field, 'blueline_settings[brand_color_paper]', 'brand_color_paper', '#123456' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="#123456"', $html );
	}

	public function test_render_color_field_includes_a_failing_contrast_row_for_ice_on_light(): void {
		// --bl-ice at its own default is documented ~1.94:1 on paper --
		// pushed to a value that WOULD read as body text (a high-contrast
		// hex against ink, e.g. white) to exercise the fail branch of
		// ice-not-text-on-light (max:3.0).
		$field = array( 'token_key' => 'ice', 'label' => 'Ice' );

		ob_start();
		blueline_settings_render_color_field( 'brand_color_ice', $field, 'blueline_settings[brand_color_ice]', 'brand_color_ice', '#000000' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'bl-contrast-fail', $html );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd themes/blueline && composer test -- --filter BrandColorSettingsFieldTest`
Expected: FAIL — schema entries, sanitize branch, and render function
don't exist yet.

- [ ] **Step 3: Add the `color` sanitize branch**

In `themes/blueline/inc/settings/sanitize.php`, inside
`blueline_sanitize_field()`, add (near the `'date'` branch):

```php
	if ( 'color' === $type ) {
		$raw = trim( (string) $value );

		if ( '' === $raw ) {
			return ''; // Unset is valid: falls back to the theme default.
		}

		$sanitized = blueline_sanitize_hex_color( $raw );

		if ( '' === $sanitized ) {
			return new WP_Error(
				'blueline_invalid_color',
				sprintf(
					/* translators: %s: the field's label. */
					__( '"%s" must be a valid hex color (e.g. #3F6E9D).', 'blueline' ),
					$label
				)
			);
		}

		return $sanitized;
	}
```

- [ ] **Step 4: Add the `color` render branch and helper**

In `themes/blueline/inc/settings/page.php`, inside
`blueline_settings_render_field()`'s `elseif` chain, add a branch right
before the final `else` (plain text) fallback:

```php
			<?php elseif ( 'color' === $type ) : ?>
				<?php blueline_settings_render_color_field( $field_key, $field, $name, $input_id, (string) $value ); ?>
```

Add the helper itself, near the other per-type render helpers
(`blueline_settings_render_date_field()` etc.):

```php
/**
 * Render one brand-color override field: a native colour-picker swatch
 * paired with a hex text input (kept in sync by
 * assets/src/js/settings-brand-colors.js), plus a live, advisory contrast
 * readout against every tools/contrast-rules.json rule that names this
 * token (blueline_brand_color_contrast_report()). Never blocks
 * submission — see this feature's design spec §1.
 *
 * @param string $field_key Schema key, e.g. 'brand_color_ice'.
 * @param array  $field     Schema entry; must carry 'token_key'.
 * @param string $name      Input name attribute.
 * @param string $input_id  Input id attribute.
 * @param string $value     Current stored value ('' if unset).
 * @return void
 */
function blueline_settings_render_color_field( string $field_key, array $field, string $name, string $input_id, string $value ): void {
	$token_key = (string) ( $field['token_key'] ?? '' );
	$tokens    = blueline_brand_color_tokens();
	$default   = $tokens[ $token_key ]['default_hex'] ?? '';
	$candidate = '' !== $value ? blueline_sanitize_hex_color( $value ) : '';
	$candidate = '' !== $candidate ? $candidate : $default;
	?>
	<input
		type="color"
		value="<?php echo esc_attr( $candidate ); ?>"
		data-bl-brand-color-picker
		tabindex="-1"
		aria-hidden="true"
	>
	<input
		type="text"
		id="<?php echo esc_attr( $input_id ); ?>"
		name="<?php echo esc_attr( $name ); ?>"
		value="<?php echo esc_attr( $value ); ?>"
		class="regular-text"
		placeholder="<?php echo esc_attr( $default ); ?>"
		data-bl-brand-color-hex
		data-bl-brand-color-token="<?php echo esc_attr( $token_key ); ?>"
	>
	<p class="description" data-bl-brand-color-contrast data-bl-brand-color-contrast-for="<?php echo esc_attr( $token_key ); ?>">
		<?php foreach ( blueline_brand_color_contrast_report( $token_key, $candidate ) as $row ) : ?>
			<span class="<?php echo esc_attr( $row['passes'] ? 'bl-contrast-pass' : 'bl-contrast-fail' ); ?>">
				<?php
				printf(
					/* translators: 1: rule description, 2: computed contrast ratio. */
					esc_html__( '%1$s: %2$s:1', 'blueline' ),
					esc_html( $row['description'] ),
					esc_html( number_format( $row['ratio'], 2 ) )
				);
				?>
			</span><br>
		<?php endforeach; ?>
	</p>
	<?php
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd themes/blueline && composer test -- --filter BrandColorSettingsFieldTest`
Expected: PASS.

- [ ] **Step 6: Run the full suite and commit**

Run: `cd themes/blueline && composer test`
Expected: PASS.

```bash
git add themes/blueline/inc/settings/sanitize.php themes/blueline/inc/settings/page.php themes/blueline/tests/BrandColorSettingsFieldTest.php
git commit -m "feat(blueline): add the settings-page 'color' field type for brand colors"
```

---

### Task 4: Live contrast JS

**Files:**
- Create: `themes/blueline/assets/src/js/settings-brand-colors.js`
- Create: `themes/blueline/assets/src/js/settings-brand-colors.test.mjs`
- Modify: `themes/blueline/inc/settings/page.php` (add `blueline_brand_color_js_rules()` + an enqueue function mirroring `blueline_settings_maybe_enqueue_photo_picker()`)
- Test: the `.test.mjs` file above, plus one PHP test appended to `tests/BrandColorSettingsFieldTest.php`

**Interfaces:**
- Consumes: `blueline_brand_color_tokens()`, `blueline_contrast_rules_for_token()`,
  `blueline_static_token_hex()` (Tasks 1-2).
- Produces: `blueline_brand_color_js_rules(): array` — PHP-side data for
  the JS, keyed by token key, each an array of
  `array{description: string, min: ?float, max: ?float, otherTokenKey: ?string, otherDefaultHex: string}`.
- Produces (JS globals, via `assets/src/js/settings-brand-colors.js`):
  `blBrandColorLuminance( hex )`, `blBrandColorContrastRatio( a, b )`,
  `blBrandColorIsHex( value )` — exported via the same
  `module.exports` guard `settings-occasions.js` uses, for the `.test.mjs`
  file to import.

- [ ] **Step 1: Write the failing JS test**

Create `themes/blueline/assets/src/js/settings-brand-colors.test.mjs`:

```js
import { describe, it, expect } from 'vitest';
import { blBrandColorLuminance, blBrandColorContrastRatio, blBrandColorIsHex } from './settings-brand-colors.js';

describe( 'blBrandColorContrastRatio', () => {
	it( 'matches style.css\'s documented ink-on-paper ratio', () => {
		expect( blBrandColorContrastRatio( '#132343', '#F7FBFC' ) ).toBeCloseTo( 14.94, 1 );
	} );

	it( 'matches style.css\'s documented ice-on-paper ratio (below AA)', () => {
		expect( blBrandColorContrastRatio( '#74C0E1', '#F7FBFC' ) ).toBeCloseTo( 1.94, 1 );
	} );

	it( 'matches style.css\'s documented accent-text-on-paper ratio', () => {
		expect( blBrandColorContrastRatio( '#3F6E9D', '#F7FBFC' ) ).toBeCloseTo( 5.13, 1 );
	} );

	it( 'is symmetric', () => {
		expect( blBrandColorContrastRatio( '#132343', '#F7FBFC' ) )
			.toBeCloseTo( blBrandColorContrastRatio( '#F7FBFC', '#132343' ), 5 );
	} );
} );

describe( 'blBrandColorIsHex', () => {
	it( 'accepts a well-formed 6-digit hex with a leading #', () => {
		expect( blBrandColorIsHex( '#3F6E9D' ) ).toBe( true );
	} );

	it( 'rejects anything else', () => {
		expect( blBrandColorIsHex( '3F6E9D' ) ).toBe( false );
		expect( blBrandColorIsHex( '#3F6' ) ).toBe( false );
		expect( blBrandColorIsHex( 'not-a-color' ) ).toBe( false );
		expect( blBrandColorIsHex( '' ) ).toBe( false );
	} );
} );
```

Check `themes/blueline/assets/src/js/settings-occasions.test.mjs`'s own
import style first (it may use a different test runner than `vitest` —
match whatever it actually uses instead of assuming).

Also append to `themes/blueline/tests/BrandColorSettingsFieldTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (JS): `cd themes/blueline && npm test -- settings-brand-colors`
Run (PHP): `cd themes/blueline && composer test -- --filter BrandColorSettingsFieldTest`
Expected: both FAIL — `settings-brand-colors.js` and
`blueline_brand_color_js_rules()` don't exist yet.

- [ ] **Step 3: Implement `blueline_brand_color_js_rules()` and the enqueue function**

Add to `themes/blueline/inc/settings/page.php` (or `inc/team-colors.php` —
match wherever `blueline_brand_color_contrast_report()` ended up in Task
2; keep both in the same file so the token-key-to-css-var lookup isn't
duplicated):

```php
/**
 * The per-token contrast-rule data assets/src/js/settings-brand-colors.js
 * needs to recompute a live readout in the browser, without the JS having
 * to read tools/contrast-rules.json itself.
 *
 * @return array<string, array<int, array{description: string, min: ?float, max: ?float, otherTokenKey: ?string, otherDefaultHex: string}>>
 */
function blueline_brand_color_js_rules(): array {
	$tokens = blueline_brand_color_tokens();
	$data   = array();

	foreach ( $tokens as $token_key => $token ) {
		$rows = array();

		foreach ( blueline_contrast_rules_for_token( $token['css_var'] ) as $rule ) {
			$other_var = $token['css_var'] === $rule['fg'] ? $rule['bg'] : $rule['fg'];
			$other_key = null;

			foreach ( $tokens as $candidate_key => $candidate_token ) {
				if ( $candidate_token['css_var'] === $other_var ) {
					$other_key = $candidate_key;
					break;
				}
			}

			$rows[] = array(
				'description'     => $rule['description'],
				'min'             => $rule['min'],
				'max'             => $rule['max'],
				'otherTokenKey'   => $other_key,
				'otherDefaultHex' => null !== $other_key
					? $tokens[ $other_key ]['default_hex']
					: blueline_static_token_hex( $other_var ),
			);
		}

		$data[ $token_key ] = $rows;
	}

	return $data;
}

/**
 * Enqueue the brand-colors live-contrast script, only on the Appearance
 * tab — same scoping reasoning as
 * blueline_settings_maybe_enqueue_photo_picker() (this file), which
 * enqueues wp_enqueue_media() only there for the same reason.
 *
 * @param string $hook_suffix The current admin screen's hook suffix.
 * @return void
 */
function blueline_settings_maybe_enqueue_brand_colors( string $hook_suffix ): void {
	if ( blueline_settings_page_hook() !== $hook_suffix ) {
		return;
	}

	if ( 'appearance' !== blueline_settings_current_tab() ) {
		return;
	}

	$relative = '/assets/src/js/settings-brand-colors.js';
	$path     = BLUELINE_DIR . $relative;

	wp_enqueue_script(
		'blueline-settings-brand-colors',
		BLUELINE_URI . $relative,
		array(),
		file_exists( $path ) ? (string) filemtime( $path ) : '1',
		true
	);

	wp_add_inline_script(
		'blueline-settings-brand-colors',
		'window.blSettingsBrandColorRules = ' . wp_json_encode( blueline_brand_color_js_rules() ) . ';',
		'before'
	);
}
add_action( 'admin_enqueue_scripts', 'blueline_settings_maybe_enqueue_brand_colors' );
```

Confirm `blueline_settings_maybe_enqueue_photo_picker()` is hooked to
`admin_enqueue_scripts` (grep it) before assuming the same hook name
above — match whatever it actually uses.

- [ ] **Step 4: Implement the JS**

Create `themes/blueline/assets/src/js/settings-brand-colors.js`:

```js
/**
 * Live contrast readouts and colour-input syncing for the Brand Colors
 * fields on the Appearance settings tab (inc/settings/page.php's `color`
 * field type).
 *
 * The ratio/luminance math below is a DELIBERATE, small, self-contained
 * DUPLICATE of blueline_relative_luminance()/blueline_contrast_ratio()
 * (inc/team-colors.php) — same trade-off settings-occasions.js's own
 * identical duplicate documents: this is a thin admin-UI convenience
 * recomputing what PHP already computed at render time, not a security
 * boundary, so importing build tooling into runtime admin JS for a
 * formula this small would be the wrong direction.
 */

function blBrandColorLuminance( hex ) {
	const clean = hex.replace( '#', '' );
	const rgb = [ 0, 2, 4 ].map( ( i ) => parseInt( clean.substr( i, 2 ), 16 ) / 255 );
	const linear = rgb.map( ( c ) => ( c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ) ) );
	return 0.2126 * linear[ 0 ] + 0.7152 * linear[ 1 ] + 0.0722 * linear[ 2 ];
}

function blBrandColorContrastRatio( a, b ) {
	const la = blBrandColorLuminance( a );
	const lb = blBrandColorLuminance( b );
	const light = Math.max( la, lb );
	const dark = Math.min( la, lb );
	return ( light + 0.05 ) / ( dark + 0.05 );
}

function blBrandColorIsHex( value ) {
	return /^#[0-9a-fA-F]{6}$/.test( value );
}

function hexForRow( row ) {
	const hexInput = row.querySelector( '[data-bl-brand-color-hex]' );
	if ( ! hexInput ) {
		return '';
	}
	const value = hexInput.value.trim();
	return blBrandColorIsHex( value ) ? value : ( hexInput.getAttribute( 'placeholder' ) || '' );
}

function currentHexForToken( tokenKey ) {
	const input = document.querySelector( `[data-bl-brand-color-hex][data-bl-brand-color-token="${ tokenKey }"]` );
	if ( ! input ) {
		return '';
	}
	return hexForRow( input.closest( 'tr' ) );
}

function updateReadout( row ) {
	const hexInput = row.querySelector( '[data-bl-brand-color-hex]' );
	const readout = row.querySelector( '[data-bl-brand-color-contrast]' );
	if ( ! hexInput || ! readout ) {
		return;
	}

	const tokenKey = hexInput.getAttribute( 'data-bl-brand-color-token' );
	const rules = window.blSettingsBrandColorRules || {};
	const tokenRules = rules[ tokenKey ] || [];
	const candidate = hexForRow( row );

	const parts = tokenRules.map( ( rule ) => {
		const otherHex = rule.otherTokenKey ? currentHexForToken( rule.otherTokenKey ) : rule.otherDefaultHex;
		const resolvedOther = otherHex || rule.otherDefaultHex;
		const ratio = blBrandColorContrastRatio( candidate, resolvedOther );
		const passes = null !== rule.min && undefined !== rule.min
			? ratio >= rule.min
			: ( null !== rule.max && undefined !== rule.max ? ratio <= rule.max : true );

		return { description: rule.description, ratio, passes };
	} );

	readout.innerHTML = parts
		.map( ( part ) => {
			const cls = part.passes ? 'bl-contrast-pass' : 'bl-contrast-fail';
			return `<span class="${ cls }">${ part.description }: ${ part.ratio.toFixed( 2 ) }:1</span>`;
		} )
		.join( '<br>' );
}

function updateAllRows() {
	document.querySelectorAll( '[data-bl-brand-color-hex]' ).forEach( ( hexInput ) => {
		const row = hexInput.closest( 'tr' );
		if ( row ) {
			updateReadout( row );
		}
	} );
}

function init() {
	document.querySelectorAll( '[data-bl-brand-color-hex]' ).forEach( ( hexInput ) => {
		const row = hexInput.closest( 'tr' );
		const picker = row ? row.querySelector( '[data-bl-brand-color-picker]' ) : null;

		hexInput.addEventListener( 'input', () => {
			if ( picker && blBrandColorIsHex( hexInput.value.trim() ) ) {
				picker.value = hexInput.value.trim();
			}
			updateAllRows();
		} );

		if ( picker ) {
			picker.addEventListener( 'input', () => {
				hexInput.value = picker.value;
				updateAllRows();
			} );
		}
	} );

	updateAllRows();
}

if ( 'undefined' !== typeof document ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}

if ( 'undefined' !== typeof module && module.exports ) {
	module.exports = {
		blBrandColorLuminance,
		blBrandColorContrastRatio,
		blBrandColorIsHex,
	};
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run (JS): `cd themes/blueline && npm test -- settings-brand-colors`
Run (PHP): `cd themes/blueline && composer test -- --filter BrandColorSettingsFieldTest`
Expected: both PASS.

- [ ] **Step 6: Run the full suites and commit**

Run: `cd themes/blueline && composer test && npm test`
Expected: PASS.

```bash
git add themes/blueline/assets/src/js/settings-brand-colors.js themes/blueline/assets/src/js/settings-brand-colors.test.mjs themes/blueline/inc/settings/page.php themes/blueline/tests/BrandColorSettingsFieldTest.php
git commit -m "feat(blueline): live contrast readout JS for the brand-colors settings fields"
```

---

### Task 5: Front-end CSS output

**Files:**
- Modify: `themes/blueline/inc/team-colors.php` (add 1 new function)
- Test: `themes/blueline/tests/TeamColorsTest.php` (extend)

**Interfaces:**
- Consumes: `blueline_brand_color_tokens()`, `blueline_settings()` (Task 1).
- Produces: `blueline_brand_color_front_end_styles(): void`, hooked to
  `wp_enqueue_scripts` at priority 20 (same hook/priority as
  `blueline_occasion_front_end_styles()`, `inc/occasions.php`), emitting
  into the same `blueline-tokens` handle.

- [ ] **Step 1: Write the failing tests**

Append to `themes/blueline/tests/TeamColorsTest.php`:

```php
	public function test_brand_color_front_end_styles_emits_nothing_when_nothing_is_overridden(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().

		blueline_brand_color_front_end_styles();

		$this->assertSame( array(), blueline_test_state()['inline_styles'] );
	}

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

	public function test_brand_color_front_end_styles_repeats_theme_aware_tokens_into_all_three_selectors(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_success' => '#00ff00' );

		blueline_brand_color_front_end_styles();

		$css = blueline_test_state()['inline_styles'][0][1];

		$this->assertStringContainsString( ':root{--bl-success:#00ff00;}', $css );
		$this->assertStringContainsString( '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--bl-success:#00ff00;}}', $css );
		$this->assertStringContainsString( ':root[data-theme="dark"]{--bl-success:#00ff00;}', $css );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd themes/blueline && composer test -- --filter TeamColorsTest`
Expected: FAIL — `blueline_brand_color_front_end_styles()` doesn't exist.

- [ ] **Step 3: Implement it**

Add to `themes/blueline/inc/team-colors.php`:

```php
/**
 * Emit every admin-overridden brand-palette colour as an inline override
 * on the blueline-tokens handle (inc/enqueue.php), same handle/hook/
 * priority as blueline_occasion_front_end_styles() (inc/occasions.php) —
 * both append to the same handle safely, since wp_add_inline_style()
 * appends rather than replaces.
 *
 * Three of the twelve brand tokens (--bl-success, --bl-warning,
 * --bl-danger) are redefined in style.css's own dark-mode block
 * (`@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) {...} }`)
 * and its `:root[data-theme="dark"]` explicit-toggle twin — both more
 * specific than a bare `:root` rule. An override emitted only into a bare
 * `:root` would therefore be silently beaten by style.css's own dark-mode
 * default under dark mode. Every override here is instead repeated,
 * unchanged, into all three selector shapes, so the admin's choice applies
 * the same regardless of theme — this deliberately drops style.css's own
 * per-theme tuning for any token an admin chooses to override.
 *
 * @return void
 */
function blueline_brand_color_front_end_styles(): void {
	$declarations = '';

	foreach ( blueline_brand_color_tokens() as $token_key => $token ) {
		$override = blueline_settings( "brand_color_{$token_key}" );

		if ( ! is_string( $override ) || '' === $override
			|| ! preg_match( '/^#[0-9a-fA-F]{6}$/', $override ) ) {
			continue; // Unset, or (defensively) malformed: the static default already covers this.
		}

		$declarations .= "{$token['css_var']}:{$override};";
	}

	if ( '' === $declarations ) {
		return; // Nothing overridden: style.css's own :root already covers every case.
	}

	$css  = ':root{' . $declarations . '}';
	$css .= '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $declarations . '}}';
	$css .= ':root[data-theme="dark"]{' . $declarations . '}';

	wp_add_inline_style( 'blueline-tokens', $css );
}
add_action( 'wp_enqueue_scripts', 'blueline_brand_color_front_end_styles', 20 );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd themes/blueline && composer test -- --filter TeamColorsTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite and commit**

Run: `cd themes/blueline && composer test`
Expected: PASS.

```bash
git add themes/blueline/inc/team-colors.php themes/blueline/tests/TeamColorsTest.php
git commit -m "feat(blueline): emit admin-overridden brand colors on the front end"
```

---

### Task 6: Block-editor parity

**Files:**
- Modify: `themes/blueline/inc/team-colors.php` (add 1 new function)
- Test: `themes/blueline/tests/TeamColorsTest.php` (extend)

**Interfaces:**
- Consumes: `blueline_brand_color_tokens()`, `blueline_settings()` (Task 1).
- Produces: `blueline_brand_color_editor_styles( array $settings ): array`,
  hooked to `block_editor_settings_all` (same filter
  `blueline_occasion_editor_styles()`, `inc/occasions.php`, already
  hooks) — a second, additive callback; does not modify
  `blueline_occasion_editor_styles()` itself.

- [ ] **Step 1: Write the failing tests**

Append to `themes/blueline/tests/TeamColorsTest.php`:

```php
	public function test_brand_color_editor_styles_passes_settings_through_unchanged_when_nothing_is_overridden(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().

		$settings = array( 'other' => 'untouched' );
		$this->assertSame( $settings, blueline_brand_color_editor_styles( $settings ) );
	}

	public function test_brand_color_editor_styles_appends_a_styles_entry_when_something_is_overridden(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_ice' => '#123456' );

		$result = blueline_brand_color_editor_styles( array() );

		$this->assertCount( 1, $result['styles'] );
		$this->assertStringContainsString( '--bl-ice:#123456;', $result['styles'][0]['css'] );
		$this->assertStringContainsString( '.editor-styles-wrapper', $result['styles'][0]['css'] );
	}

	public function test_brand_color_editor_styles_preserves_an_existing_styles_entry(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_ice' => '#123456' );

		$result = blueline_brand_color_editor_styles( array( 'styles' => array( array( 'css' => 'existing' ) ) ) );

		$this->assertCount( 2, $result['styles'] );
		$this->assertSame( 'existing', $result['styles'][0]['css'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd themes/blueline && composer test -- --filter TeamColorsTest`
Expected: FAIL — `blueline_brand_color_editor_styles()` doesn't exist.

- [ ] **Step 3: Implement it**

Add to `themes/blueline/inc/team-colors.php`:

```php
/**
 * Block-editor parity for admin-overridden brand colours, alongside
 * blueline_occasion_editor_styles()'s identical treatment of
 * --bl-occasion-accent (inc/occasions.php) — both hook
 * block_editor_settings_all and each appends its own `$settings['styles']`
 * entry, so neither has to know about the other.
 *
 * Unlike blueline_brand_color_front_end_styles(), this does not repeat
 * the declarations into a dark-mode media query: the block editor canvas
 * does not toggle between the site's light/dark states the same way the
 * front end does (occasions' own editor-parity filter has the same
 * single-mode scope).
 *
 * @param array<string, mixed> $settings Block editor settings.
 * @return array<string, mixed>
 */
function blueline_brand_color_editor_styles( array $settings ): array {
	$declarations = '';

	foreach ( blueline_brand_color_tokens() as $token_key => $token ) {
		$override = blueline_settings( "brand_color_{$token_key}" );

		if ( ! is_string( $override ) || '' === $override
			|| ! preg_match( '/^#[0-9a-fA-F]{6}$/', $override ) ) {
			continue;
		}

		$declarations .= "{$token['css_var']}:{$override};";
	}

	if ( '' === $declarations ) {
		return $settings;
	}

	$styles   = isset( $settings['styles'] ) && is_array( $settings['styles'] ) ? $settings['styles'] : array();
	$styles[] = array(
		'css'            => ':root, .editor-styles-wrapper { ' . $declarations . ' }',
		'__unstableType' => 'theme',
	);

	$settings['styles'] = $styles;

	return $settings;
}
add_filter( 'block_editor_settings_all', 'blueline_brand_color_editor_styles' );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd themes/blueline && composer test -- --filter TeamColorsTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite and commit**

Run: `cd themes/blueline && composer test`
Expected: PASS.

```bash
git add themes/blueline/inc/team-colors.php themes/blueline/tests/TeamColorsTest.php
git commit -m "feat(blueline): block-editor parity for admin-overridden brand colors"
```

---

### Task 7: WooCommerce/FUE email sync

**Files:**
- Modify: `themes/blueline/inc/woocommerce.php` (`blueline_wc_email_option_overrides()`)
- Test: `themes/blueline/tests/EmailBrandingTest.php` (extend)

**Interfaces:**
- Consumes: `blueline_resolved_brand_color()` (Task 1).
- Modifies (in place): `blueline_wc_email_option_overrides()`'s 5 color
  values now resolve through `blueline_resolved_brand_color()` instead of
  a hardcoded hex literal. Its 3 non-color keys
  (`woocommerce_email_header_alignment`, `_font_family`,
  `_header_image_width`) are untouched.

- [ ] **Step 1: Write the failing test**

Append to `themes/blueline/tests/EmailBrandingTest.php`:

```php
	public function test_email_option_overrides_reflect_a_live_brand_color_override(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_accent_text' => '#123456' );

		$overrides = blueline_wc_email_option_overrides();

		$this->assertSame( '#123456', $overrides['woocommerce_email_base_color'] );
	}

	public function test_email_option_overrides_fall_back_to_the_theme_default_when_unset(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().

		$overrides = blueline_wc_email_option_overrides();

		$this->assertSame( '#3F6E9D', $overrides['woocommerce_email_base_color'] );
		$this->assertSame( '#132343', $overrides['woocommerce_email_text_color'] );
		$this->assertSame( '#2E4A74', $overrides['woocommerce_email_footer_text_color'] );
		$this->assertSame( '#F7FBFC', $overrides['woocommerce_email_background_color'] );
		$this->assertSame( '#FFFFFF', $overrides['woocommerce_email_body_background_color'] );
	}
```

Add `require_once __DIR__ . '/../inc/team-colors.php';` near the top of
`tests/EmailBrandingTest.php` if `inc/woocommerce.php` does not already
pull it in transitively — check first (`inc/woocommerce.php` may already
`require_once` it, in which case this is redundant and can be skipped).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd themes/blueline && composer test -- --filter EmailBrandingTest`
Expected: FAIL — `blueline_wc_email_option_overrides()` still returns
hardcoded literals, so the override test fails (default-value test may
already pass by coincidence — confirm both fail or pass for the right
reason before moving on).

- [ ] **Step 3: Update `blueline_wc_email_option_overrides()`**

In `themes/blueline/inc/woocommerce.php`, change:

```php
	return array(
		'woocommerce_email_background_color'      => '#F7FBFC', // --bl-paper (outer canvas).
		'woocommerce_email_body_background_color' => '#FFFFFF', // --bl-white (card surface).
		'woocommerce_email_base_color'             => '#3F6E9D', // --bl-accent-text (links + buttons).
		'woocommerce_email_text_color'             => '#132343', // --bl-ink (body copy, headings).
		'woocommerce_email_footer_text_color'      => '#2E4A74', // --bl-ink-mid (footer credit line).
		'woocommerce_email_header_alignment'       => 'left',
		'woocommerce_email_font_family'            => 'Helvetica',
		'woocommerce_email_header_image_width'     => '96',
	);
```

to:

```php
	return array(
		'woocommerce_email_background_color'      => blueline_resolved_brand_color( 'paper' ),
		'woocommerce_email_body_background_color' => blueline_resolved_brand_color( 'white' ),
		'woocommerce_email_base_color'             => blueline_resolved_brand_color( 'accent_text' ),
		'woocommerce_email_text_color'             => blueline_resolved_brand_color( 'ink' ),
		'woocommerce_email_footer_text_color'      => blueline_resolved_brand_color( 'ink_mid' ),
		'woocommerce_email_header_alignment'       => 'left',
		'woocommerce_email_font_family'            => 'Helvetica',
		'woocommerce_email_header_image_width'     => '96',
	);
```

Update the function's docblock to note these now resolve through the
admin-tunable brand-color settings (Task 1) rather than being fixed
literals, keeping the existing accessibility reasoning (why `base_color`
must be the `accent_text` token, never `ice`) intact — that reasoning is
about *which token* feeds this option, unchanged by this task.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd themes/blueline && composer test -- --filter EmailBrandingTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite, lint, and commit**

Run: `cd themes/blueline && composer test && composer lint`
Expected: PASS.

```bash
git add themes/blueline/inc/woocommerce.php themes/blueline/tests/EmailBrandingTest.php
git commit -m "feat(blueline): WooCommerce/FUE email branding reads the live brand-color settings"
```

---

## Final verification (after all 7 tasks)

- [ ] Run `cd themes/blueline && composer lint && composer test && npm test` — all green.
- [ ] Manually verify on staging (after deploy): open **Appearance →
  Blueline Theme Settings → Appearance tab**, confirm 12 new color rows
  render with swatch, hex input, and a live contrast readout that updates
  as you type; save an override for `--bl-ice`, confirm it appears in a
  page's rendered `<style>` output (view source, search `blueline-tokens`)
  and in a test WooCommerce email's rendered HTML for
  `woocommerce_email_base_color` if you overrode `accent_text`.
- [ ] Toggle the site's dark-mode footer control (if available) or use
  browser dev tools to force `prefers-color-scheme: dark`, and confirm an
  overridden `--bl-success`/`--bl-warning`/`--bl-danger` still shows the
  admin's chosen color rather than reverting to style.css's dark-mode
  default.
