# Blueline Control Panel — Design Spec

**Status:** DRAFT — **REVIEWED AND REJECTED. Do not implement.**
Five independent reviews found critical defects, platform errors and internal
contradictions. See `2026-08-12-blueline-control-panel-review.md`. This file is
retained only as the record of what was reviewed; it will be replaced once the
scope decision in that document's §7 is taken.
**Date:** 2026-08-12
**Theme:** `themes/blueline` (standalone, text-domain `blueline`)
**Depends on:** `docs/DESIGN.md`, `docs/PRODUCT.md`, `docs/superpowers/specs/2026-08-11-arl-blueline-theme-design.md`

---

## 1. Problem

The theme has **no settings surface at all**. Verified: zero matches for
`customize_register`, `get_theme_mod`, `WP_Customize`, `register_setting`, or
`add_theme_page` across the theme. Every colour, dimension, label, and section
ordering is hardcoded in `style.css` or PHP.

Consequences today:

- Changing a brand colour requires editing `style.css` and redeploying.
- Renaming league vocabulary ("Player", "Goalie", "Registration") requires a
  code change across 137 translatable strings.
- Turning a homepage module off requires editing
  `blueline_homepage_module_order()`.
- The league's volunteers cannot change anything without a developer.

## 2. Goal

A first-class control panel that lets a league admin change colours, layout,
labels, and which sections appear — **without weakening the accessibility and
design guarantees the theme was built to hold.**

## 3. Non-goals / explicit scope boundary

The panel controls **tunable values**: colours, geometry, copy, ordering,
presence.

It deliberately does **not** expose *structural* rules:

- table scroll wrappers and the "body never scrolls horizontally" rule
- explicit empty states (leaf mark + one line)
- focus rings, skip links, `tabindex="-1"` on main
- output escaping

These are correctness, not preference. A toggle for them is a bug generator.

Also out of scope: multisite network settings, a front-end editing UI, and
role-based partial access beyond the two tiers in §8.

## 4. Decisions taken (user, 2026-08-12)

| Question | Decision |
|---|---|
| Panel home | Custom admin page under **Appearance → Blueline** |
| Audience | Volunteers by default, **Advanced tier behind a toggle** |
| Label scope | **Every theme string**, via a gettext override map |
| WCAG AA failures | **Block by default**, allow override with explicit acknowledgement |

### Approach chosen

**Semantic override layer.** The panel writes overrides for a named, grouped
subset of tokens; derived tokens recompute from them. Volunteers see
"Primary / Accent / Ice"; Advanced sees all raw tokens.

Rejected: a flat raw-variable free-for-all (no semantics — a volunteer has no
way to know `--bl-ink-mid` is the standings STRK column that already failed
contrast once); presets-only (safe but does not meet the ask). Curated presets
survive *inside* the chosen approach as one-click starting points.

## 5. Storage

Two versioned options:

- **`blueline_settings`** — autoloaded array: `tokens`, `layout`, `sections`,
  `advanced_enabled`, `aa_acknowledgements`, `_schema`, and a **precomputed
  CSS string** (see §7).
- **`blueline_label_overrides`** — autoloaded flat map,
  `original string => replacement`. Only explicit overrides are stored.

Both export/import as JSON. This is not a nice-to-have: staging and production
are separate databases and a cutover checklist already exists, so settings must
be able to travel between them.

`_schema` is an integer. A migration runs on `admin_init` when the stored
schema is older than the code's.

## 6. Defaults, and the drift problem

The panel must know default token values to compute diffs. Hardcoding them in
PHP creates a second source of truth that **will** drift from `style.css`.

**Decision:** PHP parses the `:root` block out of `style.css` directly, porting
the brace-matching `extractRootTokens()` that already exists in
`tools/check-contrast.mjs`. Result cached in a transient keyed on the existing
`blueline_stylesheet_version()` (a `filemtime`), so editing `style.css`
invalidates it automatically.

Zero drift, no new build step. Fallback to a minimal hardcoded set if parsing
fails, so a malformed stylesheet degrades rather than fatals.

## 7. CSS delivery

Diffs are resolved **at save time**, not per request. The resolved
`:root{...}` string — containing only tokens that differ from default — is
stored in `blueline_settings`.

Front end: one option read, one `echo` into `wp_head` after the
`blueline-tokens` handle. No parsing, no filesystem write, and nothing for
Redis srcache to hold stale independently of the page HTML it is embedded in.

A default install must emit **zero** inline CSS (asserted by test, §11).

### 7.1 The editor and the PHP mirror — REQUIRED

`tools/check-contrast.mjs` enforces three *mirror-parity* checks that runtime
overrides would silently break:

1. **`editor.css` duplicates 26 tokens byte-for-byte.** The stored CSS string
   must therefore also be injected into the block editor canvas via
   `enqueue_block_editor_assets`, or the editor preview diverges from the front
   end.
2. **`inc/team-colors.php` mirrors 2 tokens as PHP constants** —
   `BLUELINE_TOKEN_INK` and `BLUELINE_TOKEN_PAPER`. These feed
   `blueline_darken_to_contrast()`, which derives contrast-safe per-team
   colours. **If an admin edits ink or paper, team-colour derivation would keep
   using the old values and could emit team colours that fail AA against the
   real background.** Those constants must become resolver function calls
   reading the effective (overridden) values.
3. `sportspress.css` neutralises the SportsPress STRK inline colour to
   `--bl-ink-mid`. Any override of `--bl-ink-mid` must stay ≥ 4.5:1 on the
   effective paper colour, which §8's rule table covers only if the rule table
   is evaluated against *effective* values rather than defaults.

## 8. The contrast gate — one rule table, three consumers

Today the rules live only in `tools/check-contrast.mjs` (11 contrast pairs +
3 parity checks = 14 checks, currently all passing).

**Decision:** extract the contrast rules to **`tools/contrast-rules.json`**,
read by three consumers:

1. `check-contrast.mjs` — CI, behaviour unchanged
2. a new PHP validator — runs on save against *effective* values
3. the panel's JS — live ratio readout beside each swatch

The build-time guard and the runtime guard then become **incapable of
disagreeing**, because there is one table.

### Enforcement behaviour

- **Volunteer tier:** save is blocked, with a specific message —
  "Accent text on paper is 3.1:1, needs 4.5:1."
- **Advanced tier:** the admin may tick an explicit acknowledgement. The
  acknowledgement is recorded in `blueline_settings.aa_acknowledgements` with
  the rule id, the measured ratio, the user id, and a timestamp, so a later
  reviewer can see the site is running a knowingly-degraded palette.

Inverse rules (e.g. "`--bl-ice` must stay *unusable* as light-background text,
< 3.0") are expressed in the same table with a `max` bound rather than `min`.

## 9. Panel shell and the two tiers

`Appearance → Blueline`, capability `edit_theme_options`. Tabs:

**Brand · Layout · Sections · Labels · Settings**

Built with the theme's existing `@wordpress/scripts` 34 toolchain as a third
webpack entry (`admin`), alongside `index` and `editor`.

The Advanced toggle lives in **Settings** and is itself gated on
`manage_options`.

Advanced-only controls:

- raw editing of all tokens (vs. the semantic subset)
- signature geometry: `--bl-skew`, `--bl-band-ice`, `--bl-band-ink`
  (a volunteer setting skew to −45° destroys the brand)
- custom CSS
- per-season-state homepage module ordering
- the AA override acknowledgement

Every write is nonce-protected and capability-checked. All settings are run
through an explicit sanitizer keyed by field type — colours through a hex
validator, dimensions through a unit whitelist, labels through
`wp_kses_post`, booleans cast.

## 10. Labels

A single filter pair on `gettext` and `gettext_with_context`, short-circuited
on `'blueline' !== $domain` and on an empty override map, then an O(1)
`isset()` lookup. This covers **all** call forms — the theme uses `__()`,
`_e()`, `esc_html_e()`, `esc_attr_e()`, `_x()` and `_n()` (63 non-`__()`
occurrences counted), and every one of them routes through these filters.

The searchable string browser needs a string registry, which needs a `.pot` —
and **`themes/blueline/languages/` does not exist**, so the existing
`load_theme_textdomain()` call in `inc/setup.php` currently points at nothing.

New `npm run i18n:pot` step using `wp-cli i18n make-pot`, output committed,
with a CI check that it is current. This fixes a live latent bug as a side
effect.

`_n()` plural forms cannot be safely overridden by a flat single-string map;
plurals are listed read-only in the browser in the first iteration.

## 11. What becomes toggleable

- **Homepage:** the 4 modules (`next_games`, `standings_snippet`, `new_here`,
  `latest_news`) plus per-state order, replacing the hardcoded matrix in
  `blueline_homepage_module_order()` — including its special case for
  `registration_open` *and* `is_playing`.
- **Chrome:** sponsor bar, utility nav, footer widget areas, footer trust block.
- **Account:** the 6 dashboard cards (claim notice, my team, next game, season
  stats, registration, billing group).
- **SportsPress:** standings extra-stats default state, roster layout,
  event-state display.

Season state itself stays computed, never a manual override — that was a
deliberate earlier decision ("nobody edits this page twice a year").

## 12. Testing

Joining the existing 153 PHPUnit tests:

- sanitizer round-trip per field type
- contrast validator **asserted against `contrast-rules.json`**, so a rule
  added for CI cannot be silently missed at runtime
- defaults parser run against the real `style.css`
- diff-only CSS emission; **a default install emits zero inline CSS**
- schema migration from v1
- gettext override lookup, including the empty-map short circuit
- team-colour derivation against *overridden* ink/paper (§7.1 item 2)

`tools/check-contrast.mjs` must continue to pass unchanged after the rule table
moves to JSON.

## 13. Phasing

| Phase | Scope |
|---|---|
| **P1** | Storage, schema, defaults parser, panel shell, export/import/reset, CSS emitter |
| **P2** | Brand tab, shared contrast rule table, PHP validator, block/acknowledge flow, typography, §7.1 mirror fixes |
| **P3** | Layout + Sections tabs, module ordering, all toggles |
| **P4** | `.pot` pipeline, string registry, label browser, gettext filters |

## 14. Risks

1. **The panel weakens the design contract.** Mitigated by §3's scope boundary,
   §8's gate, presets, and per-tab reset — but the residual risk is real and
   accepted.
2. **Autoloaded option size.** Label overrides on a large site could grow. The
   map stores only explicit overrides; if it exceeds a threshold, move to
   non-autoloaded with an object-cache read.
3. **`gettext` filter cost.** Runs on every translated string site-wide.
   Short-circuits make it O(1), but it must be benchmarked, not assumed.
4. **Rule-table extraction touches a currently-green CI gate.** `check-contrast.mjs`
   works today; refactoring it to read JSON risks regressing it. Its output must
   be byte-compared before and after.
5. **Team-colour derivation** (§7.1 item 2) is the subtlest correctness hole and
   the one most likely to ship broken.
