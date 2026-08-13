# Control Panel Spec — Consolidated Review Findings

**Date:** 2026-08-12
**Reviews:** 5 independent agents (fact-check, WordPress platform, accessibility, completeness, product/implementability)
**Subject:** `2026-08-12-blueline-control-panel-design.md`
**Verdict:** **the draft spec must be re-scoped and rewritten, not patched.**

Every finding below was re-verified by the controller against the code or by
running it. Findings the reviewers asserted but that did not survive
verification are recorded in §6.

---

## 1. Defects that would have shipped a broken site

### 1.1 A label edit can fatal the homepage — CRITICAL

40 `sprintf`/`printf` call sites; ~20 translatable strings carry placeholders
(`'%1$s · Week %2$d'`, `'Next game: %1$s — %2$s'`, `'Jersey #%s'`). On PHP 8.3,
verified empirically:

```
sprintf("%1$s · Week %2$d", "Fall")  → ArgumentCountError: 3 arguments are required, 2 given
sprintf("save 50% today", "x")       → ValueError: Unknown format specifier "t"
```

A volunteer typing **"save 50% today"** into any label field produces an
uncaught fatal on the front end, from a settings screen that reported success.
The draft's only stated protection was `wp_kses_post`, which does nothing here.

**Required:** a msgid-aware validator that extracts the conversion-spec multiset
from the *original* string and rejects any replacement that differs (including a
bare `%` not written as `%%`). This is correctness, so by the draft's own §3 it
is a hard block at *both* tiers, not an Advanced-tier warning.

### 1.2 The contrast gate can be satisfied by making the site less accessible — CRITICAL

`--bl-accent-text` is governed by exactly one rule: ≥ 4.5:1 on `--bl-paper`.
A *minimum on a light ground* — so the gate rewards darkening. The same token is
the colour of `--bl-focus`, which `base.css:104-107` applies to `:focus-visible`
globally, including on the `--bl-ink-deep` header, nav drawer, dropdown panels
and footer, and the `--bl-ink` hero and scoreboard.

Verified:

| `--bl-accent-text` | on paper (the rule) | on ink | on ink-deep |
|---|---|---|---|
| `#3F6E9D` (today) | 5.13 ✓ | **2.91** | 3.35 |
| `#23415C` | **10.17 ✓✓** | **1.47** | **1.69** |

Darkening improves the gate and deletes every focus indicator on the dark
chrome. WCAG 2.4.7, 1.4.11 — certified passing.

**Required:** split `--bl-focus` into width/style/**colour** (a shorthand token
can never be validated by a table of hex pairs) and add rules against *every*
ground the ring is drawn on.

### 1.3 Two AA failures already exist, independent of the panel — HIGH

- **Focus ring on `--bl-ink` is 2.91:1 today**, below 1.4.11's 3:1 floor.
- **`--bl-border-strong` `#C3D6E4` is 1.49:1 on `--bl-white`** and 1.43 on paper.
  It is the sole identifier of every form field, `select`, `textarea`,
  search box, select2 control, pagination chip and the standings toggle
  (18 uses). 1.4.11 requires 3:1; this is the canonical example in that
  criterion's own Understanding document.

These are pre-existing and should be fixed regardless of whether the panel is
built. Fixing `--bl-border-strong` must happen *before* any panel locks the
current value in as a validated default.

### 1.4 The rule table ignores the tokens most likely to be edited — HIGH

11 rules cover ink / ink-deep / ink-mid / accent-text / steel / ice / pale /
paper. **Uncovered and editable:**

- `--bl-success` / `--bl-warning` / `--bl-danger` — made load-bearing by commit
  `bef3bbd` (this session's side-stripe fix). `#FF0000`, the obvious thing a
  volunteer types for "danger", is **4.00:1 on white** and fails, silently.
- `--bl-border` / `--bl-border-strong` — see 1.3.
- `--bl-white` and the `--bl-surface*` aliases — the background of every card,
  every zebra table row, every form field, every notice tint. Safe today only
  *by accident*, because white is lighter than paper.
- `--bl-focus` — see 1.2.

A gate that certifies a palette while ignoring its notice, border, focus and
card-background colours is **worse than no gate**, because it carries authority.

### 1.5 `color-mix()` grounds are invisible to a hex-pair validator — HIGH

Six notice components — the site's entire error/success surface, rewritten in
`bef3bbd` — sit on `color-mix(in srgb, var(--token) 8%, var(--bl-white))`
(`account.css:118,125,130`; `woocommerce.css:57,64,69`). Neither the JS guard
(regex-matches `#rrggbb` only) nor the proposed PHP validator can see a computed
value.

Worse, several derived values hard-code a token's **literal RGB**:
`account.css:396-404` (`rgba(31,122,77,0.08)` = success),
`homepage.css:210` (ice), `woocommerce.css:411` (ink), `style.css:62-63` (both
shadows). Override the token and the text colour moves while its background
stays.

**Required:** a derived-value rule type in the table; convert every literal
`rgba()` that mirrors a token to `color-mix()`; lint against reintroduction.

---

## 2. Platform errors in the draft

| # | Draft says | Truth |
|---|---|---|
| 2.1 | One filter pair on `gettext`/`gettext_with_context` "covers **all** call forms" | **False.** `_n()` → `ngettext`; `_nx()` → `ngettext_with_context`. Neither hooked. 3 real call sites silently uncovered. |
| 2.2 | Hooks global `gettext`; "O(1) so it's cheap" | Wrong axis. The docs warn the global hook *always* runs. Since WP 5.5 the domain-suffixed `gettext_blueline` fires anyway — hooking it costs **zero** for every foreign-domain string on a Woo + SportsPress site. |
| 2.3 | Editor injection via `enqueue_block_editor_assets` | **False, and a reverted regression.** `inc/enqueue.php:124-142` documents that this hook prints into the wp-admin document and leaked bare `body`/`a`/`h1-h4` rules onto the edit screen; the canvas iframe only receives styles containing `.wp-block`/`.editor-styles-wrapper`. Correct hook: `block_editor_settings_all`, appending to `$settings['styles']`. The emitted selector must be `:root, .editor-styles-wrapper` to match `editor.css:44-45`. |
| 2.4 | Settings API page at `edit_theme_options` | **False.** `wp-admin/options.php` hardcodes `manage_options`. Needs `option_page_capability_{$group}`. |
| 2.5 | "Nothing for Redis srcache to hold stale" | Inverted. Because the CSS is inline, **every cached page** is the stale artifact, with no URL to bust. The R1 verification record states `wo clean --fastcgi` does not touch that layer. No purge code exists. Staging has no page cache, so this appears first on production. |
| 2.6 | Migration on `admin_init` | Never fires for anonymous, cron, REST or WP-CLI. On a volunteer league the window between deploy and first admin login is unbounded. |
| 2.7 | "The rules live only in `check-contrast.mjs`" | **False.** `BLUELINE_CONTRAST_BODY = 4.5` / `LARGE = 3.0` already exist in `inc/team-colors.php:45,48` with 7 test references. Four consumers, not three. |
| 2.8 | Three "CI" references | **No CI exists in this repo.** No `.github`, `.gitlab-ci.yml`, `.circleci` or `Jenkinsfile`. `npm run tokens:check` and `composer test` are manual. Building CI is an uncosted work item. |
| 2.9 | Advanced tier gated on `manage_options` | A **no-op**. `edit_theme_options` is Administrator-only by default, so everyone who can open the panel already has `manage_options`. An honour-system UI toggle presented as an access control. |
| 2.10 | `wp_kses_post` for labels | Wrong sanitizer. Labels are echoed through `esc_html()`/`esc_attr()` at every call site, so permitted markup renders as **literal visible tag text**; 29 sites are attribute contexts. Use `sanitize_text_field()` + the 1.1 placeholder check. |

---

## 3. Contradictions inside the draft

1. **§3 vs §9.** §3 declares focus rings structural and non-tunable; §9 grants
   Advanced "raw editing of **all** tokens" — and `--bl-focus` /
   `--bl-focus-offset` are `:root` tokens (`style.css:66-67`). The parser hands
   the panel the exact thing §3 forbids.
2. **§3 vs custom CSS.** Custom CSS can override `html{overflow-x:clip}`,
   `.screen-reader-text`, `.skip-link:focus`, `:focus-visible` and the
   `.bl-table-scroll` wrappers — i.e. every item §3 lists as protected.
3. **§7.1 vs §12.** §7.1 requires `BLUELINE_TOKEN_INK`/`_PAPER` to become
   resolver calls. `check-contrast.mjs:145-153` matches them by source regex and
   **`throw`s** when absent — killing the whole script, not failing one check.
   §12's "must continue to pass unchanged" and §14 risk 4's "byte-compare the
   output" are therefore both unsatisfiable.
4. **A spacing token re-opens a structural guarantee.** `homepage.css:218-239`
   records that a negative offset on `--bl-space-5` produced real horizontal
   page scroll at 360px that `html{overflow-x:clip}` did **not** swallow
   (confirmed live, ~14px). A "unit whitelist" that admits a negative length
   brings back the first constraint §3 names.
5. Broken cross-references: §7 cites "§11" for a test in §12; §3 cites "§8" for
   tiers defined in §9.

---

## 4. Omissions

- **The test plan would be largely vacuous.** `tests/bootstrap.php` stubs
  `add_filter` as a **no-op**, `apply_filters` **ignores filters entirely**,
  `wp_kses` **always strips every tag**, and there are no `get_option` /
  `update_option` / transient stubs. The sanitizer and migration tests have no
  option store to run against.
- **Advanced custom CSS breaks the existing smoke suite.**
  `scripts/smoke-staging.sh:65-66` asserts the literals `0577da` and
  `woocommerce-message` are **absent from the page body**. Custom CSS echoes
  into `wp_head`. Setting any token to `#0577da` also fails.
- **Deploy-time staleness.** The `filemtime` transient invalidates the *defaults
  cache*, not the *stored diff* or the *AA verdict*. After a deploy that edits
  `style.css`: overrides silently pin old values, orphaned tokens persist, and
  every previously-passing override is now measured against a background that no
  longer exists.
- **Import is an unspecified trust boundary.** A crafted JSON can set
  `advanced_enabled`, pre-populate `aa_acknowledgements` so a failing palette
  arrives pre-excused, and carry custom CSS — never touching the UI gate.
  Export embeds user IDs and timestamps.
- **Acknowledgements cannot be invalidated.** Keyed on rule id, they survive an
  edit to a *different* token that changes the same rule's inputs. Must be keyed
  on a fingerprint of the rule definition **plus every token value it reads**.
- **Collisions with four existing label/CSS owners** — `simple-css`
  (`!important`, wins), WP core Additional CSS, Code Snippet #9 (`the_title` at
  priority 999 — a different filter, so the Labels tab would change the document
  `<title>` and not the `<h1>`, recreating a bug already fixed), and
  `wc_get_account_menu_items()`.
- **The existing widget-area escape hatch.** Disabling `new_here` orphans
  whatever a volunteer put in `bl-homepage-new-here`; `sidebar-1` and `footer-2`
  hold live production content.
- **No section-toggle floor** — nothing prevents a blank homepage, or disabling
  the Register CTA during `registration_open`. WCAG 2.4.5 requires two ways to
  find content.
- **Non-colour tokens are unguarded**: `--bl-skew` transforms the *hit region*
  (`nav.css:299-315` records a real click-collision at −11°), `--bl-focus-offset`
  gets clipped by `overflow:hidden` ancestors (2.4.11), `--bl-space-*` underpins
  two documented 2.5.8 target-size fixes, `--bl-text-*` in `px` breaks 1.4.4.
- **A non-colour, non-length token value containing `}` escapes the `:root{}`
  block** and appends arbitrary rules to every page. `--bl-focus`,
  `--bl-shadow-*`, `--bl-font-*` have no type in the draft's sanitizer list.
- **The panel's own accessibility** is unspecified: keyboard-operable colour
  input with a text hex field (2.1.1), ratio readout announced (4.1.3),
  pass/fail not colour-only (1.4.1), errors associated with fields (3.3.1).
- **The panel's own strings** are in the `blueline` domain, so the Labels tab
  can rename its own Save button.
- No documentation deliverable; DESIGN.md's hex table stops being a contract the
  moment the panel ships.
- No observability: the acknowledgement audit trail has no reader.
- No WP-CLI, despite §5's whole premise being staging→production travel.

---

## 5. Corrected facts

| Draft | Correct |
|---|---|
| "137 translatable strings" | **187** `blueline`-domain gettext calls, **162 unique msgids** (AST-counted). ~312 including the `sportspress`/`woocommerce` domains used in the theme's own template overrides. |
| "63 non-`__()` occurrences" | **79** in the `blueline` domain. |
| "the theme uses `__()`, `_e()`, …, `_x()`" | `_e()` is used **zero** times. `_x()` is used **zero** times in the `blueline` domain. `esc_html__` (16), `esc_attr__` (5) and `_nx` (1) were omitted. |
| "`languages/` missing → fixes a live latent bug" | A **no-op**. `load_theme_textdomain()` returns `false` silently; the theme ships no translations. Still needed for a string registry — but not a bug fix. |
| "editor.css duplicates 26 tokens byte-for-byte" | Comparison is **normalised**; `--bl-white` is `#FFFFFF` in style.css and `#fff` in editor.css. |
| "the 6 account dashboard cards (claim notice, …)" | 7 renderers; the claim **notice** is transient `$_GET` feedback, not a card. The claim **card** is the sixth. |
| "no settings surface at all" | Overstated: custom-logo, 3 nav-menu locations and 5 widget areas are already admin-editable via core screens. |
| "the rule table has three consumers" | **Four** — `inc/team-colors.php` already duplicates the thresholds. |

---

## 6. Reviewer claims that did NOT survive verification

- **The team-colours risk was overstated in the draft, and the reviewers'
  correction is itself incomplete.** `BLUELINE_TOKEN_INK` never reaches
  `blueline_darken_to_contrast()` — only `_PAPER` does, as a call-site argument.
  Derived values are emitted as **literal hex**, so they stay self-consistent
  whatever the tokens become; an ink override causes *brand drift*, not contrast
  failure. And the only paper-derived output, `--bl-team-accent`, has **no live
  CSS consumer**: its sole rule is `.bl-sp-hero--team .bl-sp-hero__flag`
  (`sportspress.css:771-773`), and `bl-sp-hero__flag` is emitted only by the
  *player* hero (`inc/sportspress.php:793`). The real defect is that the accent
  is derived against **paper** but rendered on a **team-primary fill** — for any
  dark team colour `blueline_darken_to_contrast()` returns the input unchanged,
  giving 1.00:1 against its own background. Currently dead code; a latent bug to
  fix on its own merits, not a panel blocker.
- **One reviewer asserted the missing `languages/` dir is a live bug.** It is
  not — see §5. The fact-check reviewer traced the fallback path correctly.
- **String-count disagreements** (137 / 187 / 312 / 190) are domain-scoping
  artifacts, not contradictions. §5 gives the reconciled numbers.

---

## 7. Recommendation

Two reviewers independently concluded **re-scope**, and the evidence supports
them. The draft front-loads its most expensive machinery — a shared rule table
across four consumers, a second WCAG implementation in PHP, a third in JS, an
acknowledgement audit trail, three mirror fixes — in service of **colour**, the
control a league with a CI-asserted committed palette will use approximately
never. It back-loads copy editing, the thing volunteers actually want, behind a
`.pot` + registry + gettext machine that duplicates what WordPress already does
via a `.mo` drop-in.

Meanwhile the genuinely valuable, genuinely missing admin controls are absent
from the draft entirely:

- **17 hardcoded `home_url()` paths** — and two of them disagree about where
  Contact Us lives (`/contact-us` in the offseason hero vs
  `/arl-league-info/contact-us` in the footer). One is already wrong or riding a
  redirect. Renaming a page silently 404s the site's primary CTA.
- **`BLUELINE_REGISTRATION_TERM_ID = 91`** — a hardcoded WooCommerce term ID
  feeding `blueline_decide_season_state()` across four files. Recreate that
  category and the homepage reads `offseason` and **stops selling**.
- **`play@rookiehockey.ca`** hardcoded at `inc/template-tags.php:571`.
- **No announcement banner.** "Sunday's games cancelled — rink flooded" is the
  most common real request at a beer league and today requires a blog post that
  lands three modules down.
- **No season-state break-glass.** State is correctly computed, but if
  SportsPress data is late the homepage lies with no lever.

**Recommended shape — decision required from the user before rewriting:**

- **Phase A — Control Panel (Settings API, no React, no webpack entry).**
  Section toggles; ~10 named copy fields incl. contact email; page/link mapping
  for the hardcoded paths; registration category setting; announcement banner;
  season-state break-glass; timestamped save snapshots; export/import; srcache
  purge on save.
- **Phase B — Brand, small.** Three semantic colour fields behind a
  `sanitize_callback`. Requires first: fixing `--bl-border-strong` and the focus
  ring (§1.3), splitting `--bl-focus`, extending the rule table (§1.4), and
  resolving the §7.1/§12 contradiction (§3.3).
- **Standalone, independent of both:** create `languages/` + `npm run i18n:pot`;
  fix the Contact Us path disagreement; fix or remove the dead
  `--bl-team-accent` rule.
- **Recommend dropping:** custom CSS (voids the AA gate, duplicates two existing
  mechanisms, breaks smoke), raw token editing, geometry controls, per-state
  module ordering, the gettext override map, the acknowledgement audit trail,
  and the React admin app.
