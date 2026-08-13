# Blueline Control Panel & Occasions — Design Spec

**Status:** DRAFT — awaiting user review
**Date:** 2026-08-13
**Supersedes:** `2026-08-12-blueline-control-panel-design.md` (rejected)
**Review evidence:** `2026-08-12-blueline-control-panel-review.md`
**Theme:** `themes/blueline` (standalone, classic, text-domain `blueline`)

---

## 1. Problem

The theme has no settings surface. Verified: zero matches for
`customize_register`, `get_theme_mod`, `register_setting` or `add_theme_page`.
(Core's own screens do already provide the custom logo, three nav-menu
locations and five widget areas — those stay where they are.)

Concretely, a volunteer cannot today:

- change the contact email — hardcoded at `inc/template-tags.php:571`;
- fix a link when a page is renamed — **17** hardcoded `home_url()` paths, two
  of which already disagree about where Contact Us lives (`/contact-us` in the
  offseason hero vs `/arl-league-info/contact-us` in the footer);
- post "Sunday's games cancelled — rink flooded" anywhere prominent;
- hide a homepage module that reads empty;
- recover when SportsPress data is late and the homepage advertises the wrong
  season state;
- survive the WooCommerce Registration category being recreated —
  `BLUELINE_REGISTRATION_TERM_ID = 91` is hardcoded across four files, and if it
  stops resolving `blueline_decide_season_state()` returns `offseason` and the
  site **stops selling registrations**;
- mark Remembrance Day, Canada Day or Christmas.

## 2. Goal

An admin control panel covering site content, section presence, and scheduled
**Occasions** (holiday/commemorative treatments) — without weakening the
theme's accessibility guarantees, and while *strengthening* them where the
review found them already broken.

## 3. Decisions taken

| Question | Decision | Date |
|---|---|---|
| Panel home | Custom admin page, **Appearance → Blueline** | 08-12 |
| Audience | **Admins only.** The Advanced toggle is a "here be dragons" disclosure affordance, **not** a privilege boundary | 08-13 |
| Labels | **Curated copy fields**, not a gettext override map | 08-13 |
| AA failures | Block; acknowledgement only behind Advanced | 08-12 |
| Holiday depth | **Accent + motif layer.** Brand palette stays | 08-13 |
| Holiday activation | **Scheduled dates, with manual override** | 08-13 |
| Overall scope | Panel + occasions; drop raw tokens, geometry, module ordering, gettext map, custom CSS | 08-13 |

Because only administrators use the panel, the capability is **`manage_options`
throughout**. This dissolves the review's finding 2.4 outright: `options.php`
hardcodes `manage_options`, so the Settings API works unmodified with no
`option_page_capability_*` filter.

**The Advanced toggle must state in its own UI that it is a warning, not a
lock.** Anyone who can reach the panel can flip it.

## 4. Non-goals

Not exposed, by decision: custom CSS (revisit later), raw editing of all
tokens, signature geometry (`--bl-skew`, `--bl-band-*`), per-season-state module
*ordering* (presence only), and a gettext override map for all 187 strings —
WordPress already supports that via a `.mo` in
`wp-content/languages/themes/blueline-en_CA.mo` or Loco Translate.

Not exposed, structurally: scroll wrappers, empty states, focus rings, skip
links, escaping. These are correctness. §7.4 enforces the boundary with a
denylist rather than a promise — the rejected draft made the same claim and
then handed the panel `--bl-focus`.

Out of scope: multisite network settings, front-end editing, uploadable motifs.

---

## 5. Phase 0 — prerequisite accessibility fixes (must land first)

The review found live AA failures. Shipping colour control on top of them would
certify them as validated defaults.

**P0.1 — `--bl-border-strong` fails 1.4.11.** `#C3D6E4` is **1.49:1** on
`--bl-white` and 1.43 on paper. It is the sole identifier of every form field,
`select`, `textarea`, search box, select2 control, pagination chip and the
standings toggle (18 uses). Needs ≥ 3:1. Darkening it is a visible design
change and must be reviewed as one.

**P0.2 — the focus ring fails 1.4.11 on dark chrome.** `--bl-accent-text`
`#3F6E9D` is **2.91:1** on `--bl-ink` (hero, scoreboard). Needs ≥ 3:1.

**P0.3 — split the focus token.** `--bl-focus: 3px solid var(--bl-accent-text)`
is a *shorthand*, so no table of hex pairs can validate its colour. Split into
`--bl-focus-width` / `--bl-focus-style` / `--bl-focus-color`. This is a hard
prerequisite for P2: without it, changing the accent silently changes every
focus ring, which is how the rejected draft let the gate be satisfied by
darkening a token until the ring vanished.

**P0.4 — extend the contrast rule table.** Today's 11 pairs ignore
`--bl-success` / `--bl-warning` / `--bl-danger` (made load-bearing by commit
`bef3bbd`), `--bl-border` / `--bl-border-strong`, `--bl-white` and the focus
colour. Add rules for each, on each ground it is actually rendered against —
including `--bl-white`, not just `--bl-paper`, because zebra table rows
alternate (`sportspress.css:160-166`) and half the STRK cells sit on white.

**P0.5 — add a derived-value rule type.** Six notice components sit on
`color-mix(in srgb, var(--token) 8%, var(--bl-white))`. Neither guard can see a
computed value. Add `{ fg, bg: { mix: [tokenA, pct, tokenB] } }`, implemented
once and shared (§7.3).

**P0.6 — de-duplicate literal `rgba()`.** `account.css:396-404`,
`homepage.css:210`, `woocommerce.css:411` and `style.css:62-63` hard-code the
RGB channels of `--bl-success`, `--bl-warning`, `--bl-ice` and `--bl-ink`.
Convert to `color-mix()` so a token change moves both halves of the pairing.
Add a lint forbidding reintroduction.

**P0.7 — the dead `--bl-team-accent` rule.** Its only consumer,
`.bl-sp-hero--team .bl-sp-hero__flag` (`sportspress.css:771-773`), matches
nothing — `bl-sp-hero__flag` is emitted solely by the *player* hero
(`inc/sportspress.php:793`). And it is wrong: the accent is derived against
`--bl-paper` but painted on a team-primary fill, giving **1.00:1** for any dark
team colour. Fix or remove; do not leave a latent 1.4.3 failure behind a
selector someone will later make live.

**P0.8 — CI.** There is **no CI in this repository** — no `.github`, no
`.gitlab-ci.yml`, no `.circleci`, no `Jenkinsfile`. `npm run tokens:check`,
`composer test` and `lint:css` are manual. Everything below that says "gate"
requires CI to exist. Build it, or restate every gate as a documented manual
step. This is a real work item the rejected draft assumed away three times.

**P0.9 — test bootstrap.** `tests/bootstrap.php` stubs `add_filter` as a
**no-op**, `apply_filters` **ignores filters entirely**, `wp_kses` **always
strips every tag**, and has no `get_option`/`update_option`/transient stubs.
Every settings test below is vacuous until this is fixed.

---

## 6. Phase 1 — the control panel

### 6.1 Storage

**One** option, `blueline_settings`, autoload `'auto'` (let core's
`wp_max_autoloaded_option_size` heuristic stay armed; do **not** pass the
6.7-deprecated `'yes'`). Sub-keys: `content`, `sections`, `links`, `commerce`,
`occasions`, `advanced_enabled`, `aa_acknowledgements`, `_schema`,
`_validated_against`.

One settings option, not two: content and sections are written, read, exported,
migrated and cache-purged together. Two options would mean two sanitize
filters, two purge hooks, and a window where schema and content disagree
mid-migration. (§6.8's save snapshots are a separate, **non-autoloaded** option
— a write-only history that is never read on the front end.)

### 6.1.1 Where defaults come from

Token defaults are parsed from the `:root` block of `style.css` at runtime, so
there is no second source of truth to drift. Cached in a transient keyed on
`blueline_stylesheet_version()` (a `filemtime`), which invalidates on every
save of that file. `tokens.json` (§7.4) carries *type, tier and bounds* — never
values.

The PHP port of `extractRootTokens()` must handle what the real stylesheet
actually contains, none of which the current JS version was written for:

- **4 `var()` aliases** (`--bl-surface: var(--bl-white)`, `--bl-surface-sunken`,
  `--bl-surface-inverse`, `--bl-focus`) — these need recursive resolution before
  any contrast rule can read them. `check-contrast.mjs`'s `token()` matches only
  `#rrggbb` and **throws** on anything else, so these do not resolve today.
  Alias resolution and value normalisation are part of the **shared contract**,
  not per-consumer behaviour — otherwise §7.3's "one table" claim is false again.
- 3 lines packing **multiple declarations** (`style.css:51-53`)
- 5 `clamp()` values containing commas; 2 `rgba()`; 2 quoted font stacks
- 9 trailing `/* … */` comments after the semicolon
- `--bl-white` is uppercase `#FFFFFF` — the JS lowercases and expands 3-digit
  hex, and the PHP must match exactly or parity silently inverts

One live parser bug to fix rather than faithfully reproduce:
`source.indexOf(':root')` matches the **prose inside a comment** at
`editor.css:17`, not the real block at line 44. It works today only because no
`{` intervenes.

Parsing failure falls back to a named hardcoded set that must cover every token
the rule table references — otherwise the validator throws in exactly the
malformed-stylesheet case the fallback exists to survive.

**Derived CSS is never stored.** The rejected draft cached the emitted `:root`
string inside the option, which turned a data-integrity problem into an
escaping problem: any write not going through the admin form — WP-CLI, import,
DB restore — would put unvalidated bytes into `wp_head`. Resolve on read from
the validated token map; memoise in the object cache (Redis is present) keyed on
`hash(settings) + blueline_stylesheet_version()`.

### 6.2 Validation lives in the option, not the form

All sanitization and the contrast gate run inside
`sanitize_option_blueline_settings` (installed automatically by
`register_setting`) and `pre_update_option_blueline_settings`. That way **every**
write path traverses them: the panel, WP-CLI, JSON import, a migration, another
plugin. The rejected draft validated in the form handler only.

### 6.3 Controls

**Content** — ~15 curated copy fields: contact email, the "New here?" heading
and blurb, CTA labels, empty-state lines, footer trust text, announcement text.

Each field declares its **allowed placeholder set**. This is not optional
polish: 40 `sprintf`/`printf` call sites exist, and on PHP 8.3 `sprintf` throws
rather than warns —

```
sprintf("%1$s · Week %2$d", "Fall")  → ArgumentCountError
sprintf("save 50% today", "x")       → ValueError: Unknown format specifier "t"
```

so a volunteer typing **"save 50% today"** into a field feeding `sprintf` is an
uncaught fatal on the front end. The validator extracts the conversion-spec
multiset from the field's declared contract and rejects any mismatch, including
a bare `%` not written as `%%`. **Hard block at both tiers** — this is
correctness, so by §4 it is not tunable.

Sanitizer is `sanitize_text_field()`, not `wp_kses_post()`. Copy is echoed
through `esc_html()`/`esc_attr()` at every call site, so permitted markup would
render as literal visible tag text; 29 sites are attribute contexts.

**Sections** — presence toggles: 4 homepage modules, sponsor bar, utility nav,
4 footer widget areas, footer trust block, 6 account cards, standings
extra-stats default, roster layout, event-state display. **Presence only, never
order** — the module order matrix encodes the `registration_open ∧ is_playing`
bug fix and a drag-list invites re-breaking it.

Floors (WCAG 2.4.5 requires two ways to find content):

- at least one homepage module must remain enabled;
- the Register CTA cannot be disabled while `blueline_season_state()` is
  `registration_open`;
- disabling a section whose widget area is non-empty warns and names the widget
  count. `sidebar-1` and `footer-2` hold live production content; a toggle must
  never delete widget data.

**Ownership boundary, stated in the UI:** widgets own *content*; the panel owns
*presence and labels*. `bl-homepage-new-here` already exists as the volunteer
seam for that module's body copy.

**Links** — maps the 17 hardcoded `home_url()` paths to page IDs, falling back
to the current literal. Fixes the Contact Us disagreement as a side effect. A
page ID survives a rename; a path does not.

**Commerce** — the registration category, replacing
`BLUELINE_REGISTRATION_TERM_ID = 91`. Falls back to 91 when unset.

**Announcement banner** — text, optional link, date window, dismissible
(cookie), severity (info/urgent). Renders above the hero.

**Season-state break-glass** — force a state, with a mandatory expiry date and
a persistent admin notice while active. State stays computed by default; this
is failure recovery, not routine use.

### 6.4 Build

**WordPress Settings API + `add_theme_page`.** No React, no third webpack
entry, no REST layer. The theme has **zero React** today — `index.js` and
`editor.js` are CSS imports plus vanilla progressive-enhancement scripts, and
`package.json` has one devDependency. Core supplies nonce, capability check and
`sanitize_callback` (which *is* the validator), and `settings_errors()` is the
block-with-specific-message UX §7.3 needs.

The live contrast readout is ~40 lines of vanilla JS enqueued on this screen
only. It is **advisory**; the PHP validator is authoritative.

### 6.5 Panel accessibility

The panel is a WordPress admin screen and meets the same AA bar as the theme:
colour inputs are `<input type="color">` **plus** a labelled hex text field
(2.1.1); ratio readouts are `aria-live="polite"`, debounced (4.1.3); pass/fail
is never colour-alone — always text, e.g. "Fail — 3.1:1, needs 4.5:1" (1.4.1);
blocking errors carry `aria-invalid` + `aria-describedby` and focus moves to an
error summary (3.3.1, 3.3.3); the acknowledgement checkbox label states the
consequence, not "I understand" (3.3.2). Tabs are plain links, not an ARIA tab
widget.

### 6.6 Cache invalidation

**The site runs a Redis-backed nginx srcache, and the R1 verification record
states `wo clean --fastcgi` does not touch that layer.** Because settings affect
page HTML, the stale artifact *is* every cached page — there is no URL to bust.
No purge code exists in the theme today.

Required: `update_option_blueline_settings` → `blueline_flush_page_cache()`,
implemented against the Redis Object Cache drop-in
(`SCAN MATCH nginx-cache:*<host>*` + `UNLINK`) behind
`function_exists`/`method_exists` guards. If unavailable, the panel **must**
show a persistent post-save notice with the exact purge command.

**Staging has no page-cache layer, so this cannot be validated there.** Say so
in the deploy notes; otherwise "it works on staging" will be taken as proof.

### 6.7 Lifecycle

- **Activation:** writes nothing. This is what makes "a default install emits
  zero inline CSS" true.
- **Migration:** runs on `init`, not `admin_init` — the latter never fires for
  anonymous, cron, REST or WP-CLI traffic, and on a volunteer league the window
  between a deploy and the first admin login is unbounded. Guarded by a cheap
  integer compare and a `wp_cache_add` lock.
- **Forward-only.** A stored `_schema` *newer* than the code must refuse to
  write, still render, and show a notice — never silently downgrade. Rollback
  to `rookie-child` is a live scenario.
- **Deactivation preserves settings.** Themes have no uninstall hook, so ship an
  explicit "Delete all Blueline data" action plus a WP-CLI equivalent, and add
  it to the cutover checklist.
- **Deploy drift:** `_validated_against` stores the stylesheet filemtime and the
  rules-table hash. When either changes, re-resolve and **re-validate** on
  `init`, invalidate acknowledgements whose inputs moved, and raise a notice
  naming orphaned and newly-defaulted tokens. The filemtime transient
  invalidates the *defaults cache*, not the *verdict*.

### 6.8 Export / import

JSON, both directions, `manage_options` + nonce. Import is a trust boundary:
re-runs the **identical** sanitizer and contrast gate against the *destination's*
`style.css`; rejects any payload whose `_schema` exceeds the code's; drops
unknown keys; **discards `aa_acknowledgements` and `advanced_enabled`
unconditionally** (otherwise a crafted file arrives pre-excused); bounds size
and nesting depth; shows a diff preview before applying. Export documents that
it contains no credentials.

Timestamped save snapshots (last 10, non-autoloaded) give real undo — cheaper
than versioning, and strictly better than "reset", which for content fields
would discard everything anyone ever wrote.

### 6.9 WP-CLI and observability

`wp blueline settings export|import|validate|reset|flush-cache|occasions`.
§6.8's premise is staging→production travel, and that will otherwise happen via
`wp option update`, i.e. the one path that bypasses the panel. Make the
supported path the validated path.

Site Health `debug_information`: schema version, override counts, advanced
on/off, active occasion, and every live AA acknowledgement with rule, ratio,
user and date. The rejected draft recorded an audit trail with no reader.

---

## 7. Phase 2 — Occasions

### 7.1 Model

An **Occasion** is a named, scheduled overlay. It never changes layout, module
order, CTA logic or season state — only accent, motif and an optional line.

```
id            slug
label         "Canada Day"
type          decorative | commemorative
window        start_md, end_md (recurring annually, inclusive)
accent        one hex — feeds --bl-occasion-accent only
motif         none | maple-leaf | poppy | snowflake | sparkle
line          optional short string (no placeholders permitted)
mode          auto | force_on | force_off
```

Shipped defaults, all disabled until an admin enables them: Canada Day,
Remembrance Day, Christmas, New Year.

### 7.2 Why the accent is a new token

The occasion accent feeds **`--bl-occasion-accent` only** — a new token
defaulting to `--bl-ice`, consumed in an enumerated set of places: the CTA
ribbon fill, the signature band, the motif.

It must **not** repurpose `--bl-accent-text`, because that token also colours
`--bl-focus`. That coupling is exactly how the rejected draft let an admin
satisfy the gate while deleting every focus ring on the dark chrome (5.13 →
10.17 on paper while the ring fell to 1.47 on ink). P0.3 breaks the coupling;
this keeps it broken.

Enumerating the consumers keeps the validation surface small enough to be
exhaustive rather than aspirational.

### 7.3 Pre-flight validation — why the gate now earns its keep

The argument for cutting colour control was that a committed palette changes
approximately never. **Scheduled occasions change it five or six times a year,
automatically, with nobody present at the moment of change.** A Christmas red
that fails on white would otherwise ship itself at midnight on December 1st.

So: every occasion's accent is validated **when saved**, against the same rule
table, and re-validated whenever `style.css` or the rules table changes
(§6.7). An occasion that fails **does not activate** — it falls back to brand
default and raises a persistent notice plus a Site Health critical item.
**Fail closed.**

The rule table lives in `tools/contrast-rules.json` with **four** consumers, not
three — `inc/team-colors.php` already duplicates the thresholds as
`BLUELINE_CONTRAST_BODY` / `_LARGE` and must read the shared table too.

Scoping the "one table" claim honestly: of the guard's 14 checks, **11 are
contrast pairs and move to JSON**. Three cannot — two source-regex mirror checks
and a CSS-structural assertion about `sportspress.css`. They stay in JS. And
because §8 changes `BLUELINE_TOKEN_INK`/`_PAPER` from literals to resolver
calls, `check-contrast.mjs:145-153` — which regex-matches
`const NAME = '#hex'` and **`throw`s** when absent — must be rewritten to assert
the resolver's *fallback* constant. The rejected draft simultaneously required
this change and required the script to "pass unchanged".

### 7.4 Token type manifest

A committed `tokens.json` gives every `:root` token a `type`, `group`, `tier`,
`bounds` and `tunable` flag. Unknown or new tokens default to **non-tunable**.

This is what makes §4's boundary real: `--bl-focus*`, `--bl-skew`,
`--bl-band-*`, `--bl-container` and the `--bl-surface*` aliases are marked
non-tunable, so the parser never hands them to the panel. Tier-gating is not a
bound; the rejected draft gated geometry behind Advanced and thereby still
shipped a field that could set `skewX(-45deg)`, which transforms the **hit
region** — `nav.css:299-315` records a real click-collision at −11°.

The emitted CSS is built by a strict serializer that can only produce
`--bl-[a-z0-9-]+: <value matched against its declared type>;`, with a
post-serialisation assertion that the string contains exactly one balanced
`{…}`. Without this, a value containing `}` escapes the block and appends
arbitrary rules to every page — including `:focus-visible{outline:none}`.

### 7.5 Resolution, precedence, timezone

Resolved per request from the small settings option — cheap, and correctness
never depends on cron. Dates compare in **site timezone** (`wp_timezone()`,
America/Toronto), not UTC.

Precedence when windows overlap: `force_on` beats `auto`; `commemorative` beats
`decorative`; then earliest start; then slug, so the result is deterministic.

`force_off` on an active occasion is the "pull it now" lever.

### 7.6 Commemorative is a distinct type, not a palette

Remembrance Day is an observance, not a holiday theme. For a Canadian hockey
league, a festive reskin would read as tone-deaf. `type: commemorative`
therefore: applies no accent shift by default, permits only the `poppy` motif,
renders its line prominently, and **suppresses any decorative occasion and the
announcement banner's urgent styling** for its duration.

### 7.7 Motifs

A shipped, enumerated SVG set — never uploadable, which keeps an upload/XSS
surface out of the design. **Static only, no animation**, avoiding 2.2.2 and
2.3.1 entirely. Decorative motifs are `aria-hidden`; the poppy carries an
accessible name.

### 7.8 The scheduled-activation cache problem

Activation is date-driven, so there is **no save event to hook** at the moment
an occasion begins. With srcache in front, the change would appear whenever the
cache happens to expire, not at midnight.

Resolution: a single WP-Cron event scheduled at the next window boundary that
purges srcache and reschedules. Cron on a low-traffic site is request-triggered
and unreliable, so it is used **only for the purge** — the occasion itself is
computed per request (§7.5), so a missed cron delays the visible change but
never produces a wrong one.

### 7.9 Editor parity

Occasion CSS reaches the block editor canvas via the **`block_editor_settings_all`**
filter, appending `array( 'css' => $css, '__unstableType' => 'theme' )` to
`$settings['styles']`.

**Not `enqueue_block_editor_assets`** — `inc/enqueue.php:124-142` documents at
length that this hook was tried, leaked bare `body`/`a`/`h1-h4` rules onto the
wp-admin chrome, and was reverted; and that WordPress only clones an
admin-enqueued stylesheet into the canvas iframe when its rules contain
`.wp-block` or `.editor-styles-wrapper`, which a pure `:root{}` block matches
neither. The rejected draft proposed reintroducing exactly that bug.

The emitted selector must be `:root, .editor-styles-wrapper` to match
`editor.css:44-45`, and must emit only tokens `editor.css` already declares, or
the parity check flags names `style.css` does not define.

---

## 8. Team colours

`BLUELINE_TOKEN_INK` / `_PAPER` become resolver calls reading effective values.

Scoping this accurately, against the rejected draft's overstatement: only
`_PAPER` reaches `blueline_darken_to_contrast()`, and derived values are emitted
as **literal hex**, so they stay self-consistent. The real exposure is that
`blueline_readable_foreground()` "returns the better of the two even when
neither reaches the requested threshold" (`team-colors.php:127-132`) — silently.
It must return a pass/fail, and a team colour set whose best foreground is
< 4.5:1 must fall back to theme tokens via the documented "no usable colour"
path.

Because occasions never touch `--bl-ink`/`--bl-paper` (§7.2, §7.4), this is
bounded work, not a per-team revalidation sweep.

---

## 9. Collisions — who wins

| Mechanism | Relationship |
|---|---|
| `simple-css` plugin | ~6,918 chars of sitewide `!important`, plugin-owned. **Beats the panel.** The UI must say so where relevant. |
| WP core Additional CSS | Also `!important`-capable; also wins. Part of why custom CSS is out of scope. |
| Code Snippet #9 | Hooks `the_title` at priority 999. A *different* filter, so it owns three My Account `<h1>`s the panel does not. Recommend retiring it at cutover. |
| `wc_get_account_menu_items()` | Owns account menu labels; `dashboard.php` reads through it deliberately. |
| Customizer site identity | Owns logo and site title. The panel **links out**, never duplicates. |

---

## 10. Testing

Requires P0.9 first. Then: sanitizer round-trip per field type; **placeholder
validator** (the fatal in §6.3); contrast validator asserted against
`contrast-rules.json`, plus the inverse test — every *tunable* colour token must
be covered by ≥ 1 rule, failing if not; `color-mix` arithmetic identical across
its consumers; defaults parser against the real `style.css`; occasion resolution
across timezone boundaries, overlaps and leap years; fail-closed on an invalid
occasion; schema migration and the newer-schema refusal; import rejecting
acknowledgements.

Plus: a default install emits zero inline CSS; the full 153-test suite and the
25-check smoke suite pass **with non-default settings applied**, not only at
defaults; and a jest entry for the panel's advisory readout.

Note `scripts/smoke-staging.sh:65-66` asserts the literals `0577da` and
`woocommerce-message` are absent from the page body — a token set to `#0577da`
fails smoke. Left as-is: that guard is correct and the collision is a feature.

---

## 11. Phasing

| Phase | Scope | Ships |
|---|---|---|
| **P0** | The AA fixes, rule-table extension, `color-mix` rules, `rgba` de-dup, dead accent rule, CI, test bootstrap | Two live WCAG failures fixed |
| **P1** | Panel: content, sections, links, commerce, banner, break-glass, export/import, snapshots, cache purge, WP-CLI, Site Health | Volunteers stop needing a developer |
| **P2** | Occasions: token manifest, `--bl-occasion-accent`, motifs, scheduling, pre-flight validation, boundary purge, editor parity | Holiday theming |

P0 is independently valuable and has no dependency on the panel. P1 ships user-
visible value without any colour control. P2 depends on P0.3 and P0.4.

## 12. Documentation deliverables

`DESIGN.md`'s hex table stops being a contract the moment occasions ship —
restate as defaults vs invariants and point the contract at
`tools/contrast-rules.json`. `PRODUCT.md`'s "accessibility is a floor" needs the
acknowledgement mechanism named. The cutover checklist gains settings migration
and the srcache purge. Plus an in-panel first-run help pane — the audience is
volunteers and there is otherwise no onboarding.

## 13. Risks

1. **P0.1/P0.2 are visible design changes**, not silent fixes. They need a
   design review of their own.
2. **Occasion accents multiply the contrast surface** by the number of enabled
   occasions. §7.2's enumerated consumer list is what keeps this finite —
   if that list grows, the guarantee weakens.
3. **The srcache purge is unverifiable on staging** (§6.6). First real
   validation is production.
4. **`check-contrast.mjs` must change** (§7.3), so "byte-compare its output" is
   not an available safety net. Its 14 checks must be re-derived and re-asserted
   by hand once.
5. **No CI exists** (P0.8). Until it does, every gate is a manual step someone
   must remember.
