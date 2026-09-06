# Blueline — admin-editable brand colors

## 1. Scope

**In scope**: make the theme's 12 brand-palette color tokens editable from
the existing Blueline settings page, without a code deploy, reusing the
existing occasion-accent machinery (`tools/tokens.json`,
`tools/contrast-rules.json`, the `blueline-tokens` inline-style handle,
the `assets/src/js/settings-occasions.js` pattern) rather than building a
parallel system. The 12 tokens: `--bl-ink`, `--bl-ink-deep`,
`--bl-ink-mid`, `--bl-accent-text`, `--bl-steel`, `--bl-ice`, `--bl-pale`,
`--bl-paper`, `--bl-white`, `--bl-success`, `--bl-warning`, `--bl-danger`.

**Confirmed out of scope**:

- Every other `--bl-*` token (surface/derived aliases, typography, spacing,
  radius, shadow, layout, z-index, focus, signature/skew tokens). These
  stay `tunable: false`, exactly as today.
- The WordPress Customizer. This theme already has a real, working
  settings-page framework (`inc/settings/`) purpose-built for this kind of
  admin-configurable value; adding a second, parallel system (the
  Customizer) for the same job would fragment where "theme configuration"
  lives for no benefit.
- Live-evaluating any `contrast-rules.json` rule whose background is a
  computed `{"mix": [...]}` tint rather than a plain token reference (e.g.
  `success-on-its-tint`, `ink-on-steel-tint`). Nothing in this codebase
  currently implements color-mixing math at runtime; building it now for
  a handful of advisory warnings would be premature generality for a need
  that doesn't exist yet, mirroring this exact codebase's own reasoning in
  `tools/tokens.json`'s §4.1 scope-down. Only rules with two plain
  `--bl-*` token references (or a literal) on both sides are live-checked.
- A hard save-time block, and the occasion mechanism's full
  acknowledge-to-activate/`aa_acknowledgements` audit trail. A brand-color
  override always saves; the admin sees a live, advisory contrast ratio
  while editing and decides for themself. (Contrast with occasions, which
  gate *activation* behind acknowledgement — there is no equivalent
  "activation" concept for a color that simply always applies.)

## 2. Relationship to the P2 occasions decision

`docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md` (§1,
§4.1) explicitly scoped brand-palette editing OUT of P2: *"No brand-palette
token becomes tunable in P2 — see §1,"* restating the original P2 decision
record's *"Brand palette stays."* `tools/tokens.json`'s own `$comment`
calls this *"a structural limit, not a promise the panel merely chooses to
keep."*

This document is a deliberate reversal of that specific decision, made
after presenting it back to the site owner: the palette should become
tunable after all, using the exact framework that decision itself built
(the tunability manifest, the contrast-rules reader, the settings-page
pattern) rather than any new mechanism. Nothing else that document decided
is revisited here — occasions, the acknowledgement system, deploy-drift
revalidation, and Site Health all continue to work exactly as they do
today, unmodified.

## 3. Token & defaults model

`inc/team-colors.php` currently declares two PHP constants,
`BLUELINE_TOKEN_INK` and `BLUELINE_TOKEN_PAPER` — the only two brand hexes
any PHP code needs today. Extend this to all 12:

```php
const BLUELINE_TOKEN_INK          = '#132343';
const BLUELINE_TOKEN_INK_DEEP     = '#0D1729';
const BLUELINE_TOKEN_INK_MID      = '#2E4A74';
const BLUELINE_TOKEN_ACCENT_TEXT  = '#3F6E9D';
const BLUELINE_TOKEN_STEEL        = '#5188B7';
const BLUELINE_TOKEN_ICE          = '#74C0E1';
const BLUELINE_TOKEN_PALE         = '#9ACDE7';
const BLUELINE_TOKEN_PAPER        = '#F7FBFC';
const BLUELINE_TOKEN_WHITE        = '#FFFFFF';
const BLUELINE_TOKEN_SUCCESS      = '#1F7A4D';
const BLUELINE_TOKEN_WARNING      = '#8A5A00';
const BLUELINE_TOKEN_DANGER       = '#A32C1B';
```

Values copied verbatim from `style.css`'s `:root` block — this becomes the
single place PHP code looks up "what is the real default," replacing any
hardcoded hex literal duplicated elsewhere (notably
`blueline_wc_email_option_overrides()`, see §7).

`tools/tokens.json`: flip `tunable` from `false` to `true` for exactly
these 12 entries (all currently `"tier": "brand"`, plus this changes
nothing about their `type`/`group`/`bounds`). No other entry in the
manifest changes.

A new pure function in `inc/team-colors.php`, `blueline_brand_color_tokens()`,
returns the declarative map every other piece of this feature reads from —
one row per token: its CSS custom-property name, its settings key, its
`BLUELINE_TOKEN_*` default, and a human label for the settings-page field
(e.g. "Ink (body text)"). This is the *only* place the list of 12 tokens is
spelled out; everything else (defaults, sanitization, rendering, CSS
output, the JS) iterates it rather than repeating the list.

## 4. Settings storage

Twelve new flat keys in `inc/settings/defaults.php`'s settings-defaults
array, following the existing convention (`'occasions' => array()`, etc.):

```php
'brand_color_ink'         => '',
'brand_color_ink_deep'    => '',
'brand_color_ink_mid'     => '',
'brand_color_accent_text' => '',
'brand_color_steel'       => '',
'brand_color_ice'         => '',
'brand_color_pale'        => '',
'brand_color_paper'       => '',
'brand_color_white'       => '',
'brand_color_success'     => '',
'brand_color_warning'     => '',
'brand_color_danger'      => '',
```

`''` (empty string) means "unset — use the theme default," exactly
mirroring how an occasion's own `accent` field works today
(`'' !== $accent ? blueline_sanitize_hex_color( $accent ) : blueline_occasion_accent_default()`).
A resolver, `blueline_resolved_brand_color( string $token_key ): string`,
implements that same fallback for each of the 12 keys, reading
`blueline_settings( "brand_color_{$token_key}" )` and falling back to
`blueline_brand_color_tokens()`'s declared default.

Sanitization: each of the 12 keys runs through the existing
`blueline_sanitize_hex_color()` (already used by the occasion accent field)
and is allowed to be empty (unset). No new sanitizer needed.

## 5. Settings-page UI

The settings page already has an "Appearance" tab (`inc/settings/page.php`,
tab slug `appearance`) holding the `band_photos` field and three `bool`
toggles — the natural home for this, rather than a new top-level tab.

New file `inc/settings/brand-colors.php` (mirroring
`inc/settings/occasions-tab.php`'s shape), providing
`blueline_settings_render_brand_colors_section()`, called from the
Appearance tab's template alongside its existing fields. For each of the
12 tokens: a labelled row with a native `<input type="color">` swatch
paired with a text `<input>` for the hex value (kept in sync via JS,
identical UX to the occasion-accent field), plus a live contrast readout
area (§6) below it.

Required, per your approved answers earlier in this conversation:
- **Location**: this settings page, not the Customizer.
- **Guardrails**: advisory only — a computed ratio is shown live as the
  admin edits, but nothing blocks save.

New `assets/src/js/settings-brand-colors.js`, structured like
`settings-occasions.js`: a small, deliberate, self-contained duplicate of
`blueline_relative_luminance()`/`blueline_contrast_ratio()`
(`inc/team-colors.php`) in JS — that file's own docblock documents why this
duplication (over importing build tooling) is intentional, and this
follows the same reasoning. On every color-input change, it recomputes and
redisplays the ratio for every rule `blueline_contrast_rules_for_token()`
(§6) returned for that token, using the *current* (possibly also
just-edited) value of whichever other token each rule references.

## 6. Contrast checking

A new pure function in `inc/team-colors.php`:

```php
/**
 * Every tools/contrast-rules.json rule whose fg or bg is exactly
 * $css_var (e.g. '--bl-ice'), excluding any rule where either side is a
 * computed `{"mix": [...]}` tint (see §1's scope cut).
 *
 * @param string $css_var e.g. '--bl-ice'.
 * @return array<int, array{id:string, description:string, fg:string, bg:string, min:?float, max:?float}>
 */
function blueline_contrast_rules_for_token( string $css_var ): array {}
```

Reads `tools/contrast-rules.json` the same way
`blueline_load_contrast_thresholds()` already does (same read-failure
logging convention). For example, `--bl-ice` today matches 4 rules:
`ink-on-ice`, `paper-on-ink`/`ice-on-ink` (bg=ink, unaffected — only rules
*naming* `--bl-ice` match: `ink-on-ice`, `ice-on-ink`, `ice-not-text-on-light`,
`focus-on-ice`).

A companion, `blueline_brand_color_contrast_report( string $token_key, string $candidate_hex ): array`,
resolves each matched rule's *other* side to its current value (via
`blueline_resolved_brand_color()` when that side is itself one of the 12
tokens, else the fixed `BLUELINE_TOKEN_*`/literal default for anything
outside this feature's scope — e.g. `--bl-focus-color`, which stays
static), computes the ratio with the existing `blueline_contrast_ratio()`,
and returns pass/fail against the rule's `min`/`max`. The settings-page
render function uses this server-side (for the page's initial, pre-JS
render); the JS duplicate (§5) recomputes the same thing live as the admin
types.

## 7. CSS output

Extend `inc/occasions.php`'s existing pattern rather than duplicating the
hook: a new function (placed in `inc/team-colors.php`, since it's about
brand tokens, not occasions). This reads `blueline_settings()` directly
rather than through `blueline_resolved_brand_color()` (§4) deliberately:
it needs to know whether a token was left unset at all (to skip emitting
that line), where the resolver's job is instead to always return
something usable, override or default.

```php
function blueline_brand_color_front_end_styles(): void {
	$css = '';

	foreach ( blueline_brand_color_tokens() as $token ) {
		$override = blueline_settings( "brand_color_{$token['key']}" );

		if ( ! is_string( $override ) || '' === $override
			|| ! preg_match( '/^#[0-9a-fA-F]{6}$/', $override ) ) {
			continue; // Unset, or (defensively) malformed: the static default already covers this.
		}

		$css .= "{$token['css_var']}:{$override};";
	}

	if ( '' === $css ) {
		return; // Nothing overridden: style.css's own :root already covers every case.
	}

	wp_add_inline_style( 'blueline-tokens', ':root{' . $css . '}' );
}
add_action( 'wp_enqueue_scripts', 'blueline_brand_color_front_end_styles', 20 );
```

Same handle (`blueline-tokens`), same hook/priority as
`blueline_occasion_front_end_styles()` — `wp_add_inline_style()` appends
rather than replaces, so both coexist safely; whichever registers last
(existing file-load order in `functions.php`) simply appends its `<style>`
block after the other's, and CSS's own cascade (later same-specificity
rule wins) resolves any token neither one else touches identically either
way, since the two functions never write the same custom property.

Block-editor parity: `inc/occasions.php` (~line 1360) already emits
`--bl-occasion-accent` into `editor-styles-wrapper` for Gutenberg preview
consistency. Extend that same existing filter to also include any brand
overrides, for the same reason: so the block editor doesn't show colors
that don't match the live site.

## 8. Email / WooCommerce sync

Per your approved answer, this is the actual point of the original gap:
one color change should update the site AND transactional emails
together, not two independently-maintained hardcoded copies.

`inc/woocommerce.php`'s `blueline_wc_email_option_overrides()` currently
returns a fixed array of hardcoded hex literals. Each one becomes:

```php
'woocommerce_email_base_color' => blueline_resolved_brand_color( 'accent_text' ),
'woocommerce_email_text_color' => blueline_resolved_brand_color( 'ink' ),
'woocommerce_email_footer_text_color' => blueline_resolved_brand_color( 'ink_mid' ),
'woocommerce_email_background_color' => blueline_resolved_brand_color( 'paper' ),
'woocommerce_email_body_background_color' => blueline_resolved_brand_color( 'white' ),
```

(`woocommerce_email_header_alignment`, `_font_family`, and
`_header_image_width` are not colors and are untouched.) The accessibility
reasoning already documented there (why `base_color` must be
`--bl-accent-text`, never `--bl-ice`) is unchanged and still enforced —
it's a property of *which token* feeds that option, not of whether the
token's value is static or admin-overridden.

## 9. Testing

New `tests/BrandColorsTest.php` (PHPUnit, following
`tests/EmailBrandingTest.php`/`tests/OccasionsTest.php`'s existing
conventions and stub environment):

- `blueline_brand_color_tokens()` returns exactly the 12 expected keys,
  each with a default matching its `BLUELINE_TOKEN_*` constant.
- `blueline_resolved_brand_color()` falls back to the default when the
  setting is `''`, and returns the sanitized override when set.
- `blueline_contrast_rules_for_token()` returns the right rule set for a
  token with multiple rules (e.g. `--bl-ice`'s 4), excludes `{"mix":...}`
  rules, and returns an empty array for a token with no rules.
- `blueline_brand_color_contrast_report()` correctly resolves the *other*
  side of a rule to a currently-overridden value (not just its default)
  when that side is itself one of the 12 tokens.
- `blueline_brand_color_front_end_styles()` emits nothing when nothing is
  overridden, and emits only the overridden custom properties otherwise
  (not all 12) — asserted via the existing `wp_add_inline_style()` test
  stub / `blueline_test_state()['inline_styles']` (already present in
  `tests/bootstrap.php` for this exact purpose).
- `blueline_wc_email_option_overrides()` reflects an overridden
  `brand_color_accent_text` setting in `woocommerce_email_base_color`
  (extending the existing `EmailBrandingTest.php`).

New `assets/src/js/settings-brand-colors.test.mjs`, mirroring
`settings-occasions.test.mjs`: the JS luminance/contrast duplicate agrees
with known WCAG ratio values, and the live-readout update fires on input
change.

## 10. Risks

- **Two independent contrast-math implementations (PHP + JS) drifting
  apart.** Pre-existing risk, not introduced here — `settings-occasions.js`
  already carries and documents this exact trade-off. Mitigated the same
  way: both are small, both are tested against known WCAG values.
- **An admin sets a low-contrast override and ships it**, since save is
  never blocked. Accepted deliberately (your approved answer, §1) — the
  live warning exists specifically so this is an informed choice, not a
  silent one.
- **Deploy-drift**: if a future `style.css` edit changes a brand token's
  *default* value, an existing admin override silently continues to win
  (it's an explicit override, not a "matches-default" acknowledgement like
  occasions' `aa_acknowledgements`) — there is nothing to invalidate, since
  brand-color overrides don't use that acknowledgement mechanism at all.
  Worth a Site Health note (not built here) if this ever surprises anyone
  in practice; not a new class of risk, since it's the same as any admin-
  set value anywhere else in this settings page.

## 11. Non-goals (restated)

Everything in original P2's own non-goals list stays a non-goal here too,
except the one item this document reverses (brand-palette tunability).
Customizer support, mix-based live contrast checking, and per-team
revalidation remain explicitly out of scope.
