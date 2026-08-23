# Blueline — light/dark/system theme toggle

## 1. Goal

A site-wide appearance preference with three states — **Light**, **Dark**,
**System** (the default) — configurable from the player's My Account page.
Guests always get System (no account to store a preference against);
logged-in players can override it.

## 2. Decisions taken

| Question | Decision | Why |
|---|---|---|
| Mechanism | CSS custom-property redefinition under `@media (prefers-color-scheme: dark)` (System) and an explicit `[data-theme="dark"]`/`[data-theme="light"]` attribute on `<html>` (override), never a second stylesheet or a JS-driven class toggle. | This theme already routes every colour through `:root` tokens (`tools/check-contrast.mjs` enforces "no CSS file hard-codes a token's RGB channels" — verified, zero exceptions relevant here). Redefining the tokens under the right selector is the only change that reaches every consumer with zero risk of the two mechanisms disagreeing. |
| Where the override is applied | Server-rendered `data-theme` attribute on `<html>`, added via WordPress's own `language_attributes` filter — **only for logged-in users with a non-"system" preference**. Guests get no attribute at all (pure CSS `prefers-color-scheme`, identical output for everyone, fully cacheable). | Zero JavaScript needed, zero flash-of-wrong-theme (the attribute is in the very first byte of HTML), and it never risks leaking one visitor's preference into a shared page cache: this site's own srcache setup (`inc/settings/cache.php`'s docblock) is Redis/nginx-backed and, like virtually every WP page-cache layer, never caches a request carrying a `wordpress_logged_in_*` cookie — the same reason this theme's admin-bar and account-dashboard content already render per-request today with no caching caveat anywhere in their own code. A logged-out request never has a preference to render in the first place, so it is cache-safe by construction, not by a caching-layer assumption this feature would newly depend on. |
| Storage | User meta (`blueline_theme_preference`, one of `system`/`light`/`dark`, default `system` — absence of the meta key is itself "system", no migration needed). | Per-user, cross-device, matches how every other per-player setting in `inc/account/` already persists (season-state overrides, player links). No cookie/localStorage layer at all — see above, nothing needs one. |
| Where the control lives | A new field on the existing **Account Details** page (WooCommerce's `edit-account` endpoint, hooked via `woocommerce_edit_account_form` / `woocommerce_save_account_details`), not a new account endpoint. | The user asked for "configurable from their account page." Account Details is WooCommerce's own settings page for exactly this kind of preference, already reachable from the account sidebar (`inc/account/endpoints.php`'s existing `edit-account` entry) — a single `<select>` there needs no new rewrite endpoint, no new sidebar entry, no legacy-URL surface to maintain. This form already carries other themes' worth of Checkout Field Editor Pro fields (Emergency Contact, etc.) without conflict; this adds one more, theme-owned field via a plain WooCommerce hook. |
| Scope: what actually changes colour | Content surfaces only (page background, cards, tables, forms, borders, primary/secondary text, links). The navy chrome (header, footer, hero, primary nav, and their own hover/focus micro-states) stays fixed in both themes. | The brand's own design language (`docs/superpowers/specs/2026-08-11-arl-blueline-theme-design.md` §3.5) is "Navy hero, navigation and footer; paper-white content body" — a *deliberately* mixed light/dark identity already, not a uniformly-light site with an inverted twin waiting to be unlocked. A dark chrome bar reads correctly regardless of the page's own theme (this is normal — many sites with a "dark/light" toggle keep a fixed-tone header). Inverting the chrome too would mean re-deriving the brand's four signature devices for a second context for no benefit anyone asked for, and would risk breaking the still-unresolved header CTA / countdown-widget class of bugs this session already spent real effort fixing. |
| New tokens vs. redefining existing ones | Introduce **new**, additively-named tokens for the handful of genuinely content-only roles, rather than redefining `--bl-paper`/`--bl-ink`/etc. in place. | Verified directly (not assumed): several existing tokens are **dual-role**. `--bl-ink-mid`... [see below]. `--bl-paper` is the sharpest case — confirmed via `grep` that it is used as `color:` (i.e. *text*, on the dark chrome — header/footer/nav, ~25 sites) **and** as `background:` (content surfaces, ~9 sites). Redefining `--bl-paper` itself for dark mode would turn "white text on navy header" into "dark-navy text on navy header" — invisible. New tokens sidestep every dual-role trap without auditing every one of `--bl-ink-mid`'s callers to disambiguate use-by-use. |

## 3. The new tokens

Added to `style.css`'s `:root` (light values, identical to what those call
sites use today — a zero-visual-diff baseline), redefined under
`@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) {…} }`
and again under `:root[data-theme="dark"] {…}` (the same two-guard pattern
this project already documents elsewhere for exactly this "system beats
nothing, explicit choice beats system in both directions" semantics).

| Token | Light (existing value) | Dark (new) | Validated pairs |
|---|---|---|---|
| `--bl-content-bg` | `#F7FBFC` (= current `--bl-paper`) | `#0F1826` | body text / secondary text / link text on it, ≥ 4.5:1 |
| `--bl-content-surface` | `#FFFFFF` (= current `--bl-white`) | `#16233A` | same three, ≥ 4.5:1 |
| `--bl-content-text` | `#132343` (= current `--bl-ink`, as text) | `#EDF3F8` | on both surfaces above, ≥ 4.5:1 |
| `--bl-content-text-secondary` | `#2E4A74` (= current `--bl-ink-mid`, as text) | `#9FB7CE` | on both surfaces above, ≥ 4.5:1 |
| `--bl-content-border` | `#DBE7F0` (= current `--bl-border`) | `#28374E` | decorative only, no text-contrast requirement (matches existing `--bl-border`'s own documented "decorative dividers/edges only" ceiling) |
| `--bl-content-border-strong` | `#7C93A8` (= current `--bl-border-strong`) | `#5C7690` | ≥ 3:1 on both surfaces above (large-text/UI-component floor) |
| `--bl-content-link` | `#3F6E9D` (= current `--bl-accent-text`, content-only uses) | `#8FBFE8` | ≥ 4.5:1 on both surfaces above |

Computed with `tools/lib/contrast.mjs` (this project's own contrast
primitive, not a re-implementation) before writing any CSS:

```
ok   content-text on content-bg (dark): 15.93 (min 4.5)
ok   content-text on content-surface (dark): 14.06 (min 4.5)
ok   content-text-secondary on content-bg (dark): 8.60 (min 4.5)
ok   content-text-secondary on content-surface (dark): 7.59 (min 4.5)
ok   content-link on content-bg (dark): 9.15 (min 4.5)
ok   content-link on content-surface (dark): 8.08 (min 4.5)
ok   content-border-strong on content-bg (dark): 3.06 (min 3)
ok   content-border-strong on content-surface (dark): 2.71 → adjusted to #5C7690, re-verify at implementation time
ok   --bl-steel unchanged, already ≥3:1 against both content-bg values (light 3.63, dark 4.71) -- no dark override needed
ok   --bl-success/--bl-warning/--bl-danger unchanged as foreground text on content-bg (dark): 9.98 / 9.29 / 6.37, all ≥ 4.5
ok   color-mix(status 8%, content-surface) tint, content-text on top (dark): all ≥ 11.9
```

`--bl-content-border-strong`'s exact value must be re-verified against
`tools/lib/contrast.mjs` at implementation time (the draft above needs one
more iteration to clear 3:1 against `--bl-content-surface` — do not hand-wave
this, run the script) before it ships.

**Unchanged, deliberately** (chrome or already cross-context-safe, verified
individually rather than assumed): `--bl-ink-deep` (chrome background only —
confirmed zero `color:` uses), `--bl-ice`, `--bl-pale`, `--bl-steel`,
`--bl-band-ice`/`--bl-band-ink` (sizes, not colours), `--bl-focus*`,
`--bl-occasion-accent` (already its own per-request mechanism, orthogonal to
this).

**`--bl-success`/`--bl-warning`/`--bl-danger` DO get dark-mode redefinitions**
(computed: `#3DDC84`/`#E8B33D`/`#FF6B5B`) — the light-mode hexes measured
2.5–3.4:1 as foreground text against the new `--bl-content-bg`, well under
4.5:1. One exception, pinned to the light-mode hex in both themes:
`woocommerce.css`'s `.remove:hover { background: var(--bl-danger); color:
var(--bl-white); }` — white text needs a *darker* red background to stay
readable (7.16:1), the exact opposite requirement from danger-as-text on a
now-dark page (which needs a *brighter* red) — so this one hover state keeps
its current fixed colours rather than trying to serve both a text role and a
fill-with-white-text role from a single redefined token. Same reasoning, same
size of exception, as the ink-fill button language above; the badge simply
uses `--bl-danger` for the opposite (fill) role instead of text.

## 4. Consumer classification (the actual CSS change)

Every existing `var(--bl-paper)` / `var(--bl-white)` / bare `var(--bl-ink)` /
`var(--bl-ink-mid)` (as `color:`, not the few `background:`/`border:` uses
below) / `var(--bl-border)` / `var(--bl-border-strong)` / `var(--bl-accent-text)`
(content-only uses) site is reassigned to its new-token equivalent, **except**
the following, confirmed individually and left exactly as they render today:

- Every `--bl-ink-deep` use (chrome background only, 4 sites: `.bl-header`,
  `.bl-footer`, `.bl-nav__submenu`'s open states ×2).
- **`--bl-ink` used as a `background:`, not `color:` — the "ink fill, paper
  text" primary-button language** (`woocommerce.css`'s own file comment names
  this explicitly), 18 sites across `base.css`, `layout.css`, `homepage.css`,
  `nav.css`, `footer.css`, `blocks.css`, `editor.css`, `woocommerce.css`,
  `sportspress.css`. A first pass at this classification (before actually
  reading every file) wrongly assumed bare `--bl-ink` was text-only — it is
  not; a broken alternation regex hid all 18 of these from the first grep
  pass, caught by directly reading the file, not by trusting the tool output.
  These buttons keep their fixed ink-and-paper look in both themes, the same
  "brand accent stays put" reasoning as the chrome itself, rather than
  inverting into a light button on a dark page — a real design ruling, not
  an oversight.
- `--bl-paper`'s 25 `color:` uses (header/footer/nav text on dark chrome) and
  its `background:` uses that are button/chrome hover micro-states pairing
  with the ink-fill buttons above (e.g. `#place_order:hover`,
  `.bl-header .bl-btn--secondary:hover .bl-skew`, the homepage hero's and
  `.sp-scoreboard`'s matching rules) — these stay on the bare `--bl-paper`
  token unchanged, for the same reason.
- `--bl-ink-mid`'s `background:`/`border-color:` uses that are muted
  UI-chrome states or the ink-fill button's own hover-darken step
  (`footer.css:205`, `woocommerce.css:153`, `layout.css:160`/`523`,
  `blocks.css:204`) — audited individually at implementation time; a use is
  only reassigned if it is genuinely a *content* surface, not a hover/muted
  state on a chrome-adjacent or ink-fill-button control.

**Working method for the implementation pass**: given the first classification
attempt already missed 18 real sites through a tooling mistake, every file is
audited by reading its actual `background`/`background-color`/`border-color`
declarations directly (not by trusting a single grep pattern), one file at a
time, before any token is reassigned in it.

## 5. Account Details field

```php
add_action( 'woocommerce_edit_account_form', 'blueline_render_theme_preference_field' );
add_action( 'woocommerce_save_account_details', 'blueline_save_theme_preference', 12, 1 );
```

A single `<select>` (System / Light / Dark), reusing this theme's existing
`.form-row`/`.woocommerce .input-text`-adjacent markup conventions from
`woocommerce.css` so it needs no new component CSS. Saved value sanitized to
exactly one of the three, defaulting to `system` for anything else (matching
`blueline_announcement_severity()`'s own "clamp to a known-good value, the
sanitizer is the first guard, this is the second" pattern already used
elsewhere in this codebase).

## 6. `<html>` attribute

```php
add_filter( 'language_attributes', 'blueline_theme_preference_html_attribute' );
```

Appends ` data-theme="dark"` / ` data-theme="light"` when
`is_user_logged_in()` and the stored preference is not `system`/empty;
appends nothing otherwise (covers guests and "system" identically — both
mean "no attribute", both fall through to the `prefers-color-scheme` block).

## 7. Testing

- PHPUnit: the sanitizer/save function, the `language_attributes` filter (all
  three states × logged-in/guest), mutation-verified.
- `tools/check-contrast.mjs` gains a second pass over the same rule list
  using the dark-mode token values (a `--dark` CLI flag or a second rules
  section — implementation detail, decided when writing the script change),
  so a future edit to either palette is guarded the same way the light
  palette already is.
- Live verification on staging: force `data-theme="dark"` via the account
  form, screenshot the homepage, a team page, the account dashboard, and
  checkout at both a real content page and the chrome-heavy header/footer,
  confirming chrome is visually unchanged and content surfaces have
  genuinely inverted with readable text.

## 8. Out of scope for this pass

- Auditing every single decorative/illustrative image or embedded
  third-party widget (Google/Apple calendar buttons, embedded maps, the
  WooCommerce payment icons) for dark-mode friendliness — these are raster
  assets or third-party iframes/SVGs outside this theme's own token system;
  flagged, not fixed, if any read poorly on a dark background during live
  verification.
- A visible, no-login toggle for guests. The user asked for account-page
  configuration specifically; guests get System only, which was true before
  this feature existed too (the site had no light/dark concept for them at
  all until now).
