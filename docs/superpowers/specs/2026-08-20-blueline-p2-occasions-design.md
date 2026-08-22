# Blueline P2 — Occasions, colour correctness, and observability

Companion to `docs/superpowers/specs/2026-08-13-blueline-control-panel-design.md`
(the original spec — §7 Occasions, §8 Team colours, §9-13 remain authoritative
for anything this document doesn't override) and
`docs/superpowers/decisions/2026-08-17-blueline-p1b-decisions.md` (P1b's
decision record, whose §6 "Outstanding" scopes P2 as: colour control,
Occasions, `aa_acknowledgements`, deploy drift, and the dependent Site Health
fields). P1 (the Appearance → Blueline control panel: content, sections,
links, commerce, banner, break-glass, export/import, snapshots, cache purge,
WP-CLI, Site Health) is merged and out of scope here.

This document exists because three things the original spec left genuinely
ambiguous needed a human decision before a plan could be written against
them — §3 below records what was decided and why. Everything else here is
organization and sequencing of what §7/§8 of the original spec already
designed; it is not a redesign.

---

## 1. Scope

**In scope**, per the decision record's §6, sequenced by dependency:

- **Phase 2.0 — Foundation.** `tools/tokens.json`, extending
  `tools/contrast-rules.json` to its fourth declared consumer
  (`inc/team-colors.php`), the `--bl-occasion-accent` token, a real
  `aa_acknowledgements` write path, and the shared "inputs hash" primitive
  deploy-drift and acknowledgement-invalidation both depend on.
- **Phase 2.1 — Occasions.** The model, scheduling, resolution, motifs,
  cron boundary purge, and editor parity described in the original spec's
  §7, built on Phase 2.0.
- **Phase 2.2 — Correctness & observability.** The original spec's §8 team-
  colours fix, `_validated_against` deploy-drift revalidation, the Site
  Health fields that depend on all of the above, and `wp blueline settings
  occasions`.

**Confirmed out of scope**, restated from the original spec's §3/§4 because
it is easy to mis-scope from the decision record's shorthand "colour
control" alone: raw editing of brand-palette tokens, custom CSS, signature
geometry, per-season-state module ordering, uploadable motifs, and any
kind of per-team revalidation sweep. The *only* new tunable colour surface
P2 introduces is the single occasion-accent hex per occasion. "Colour
control" in the decision record's §6 means the §8 backend correctness fix
to `blueline_readable_foreground()`, not a palette-editing UI — the
original spec's §3 ("Holiday depth: Accent + motif layer. Brand palette
stays") and §4 ("drop raw tokens... custom CSS") already settled this.

## 2. Relationship to P0, and a correction against the current codebase

The original spec's §11 phasing table lists P0 (AA fixes, rule-table
extension, `color-mix` rules, CI, test bootstrap) as a prerequisite for P2,
and states "P2 depends on P0.3 and P0.4." **Verified directly against
`inc/team-colors.php` before writing the plan** (not inferred from the
original spec or the decision record, both of which predate this and are
stale on this point): `tools/contrast-rules.json` exists with a populated
`thresholds` object and rule set, and two things the original spec's §8
and this document's earlier draft described as still-needed P2 work are
**already shipped and tested**, landed as P0-style live-WCAG-failure
fixes rather than waiting for a formal "P2 colour control" epic:

- `blueline_contrast_threshold()` already reads `contrast-rules.json`'s
  `thresholds.body`/`thresholds.large` (commit `987862a`), with a logged
  fallback to 4.5/3.0 if the file is missing or malformed (commit
  `48d21e5`). §4.2 below is **done, not a task**.
- `blueline_readable_foreground()` already reports pass/fail via a
  `$passes` out-parameter, and `blueline_team_color_set()` already treats
  a failing best-effort foreground as "no usable colour" (returns `array()`
  rather than shipping the least-bad pick) — `tests/TeamColorsTest.php`
  covers both the passing and failing cases directly. §6.1 below is
  **done, not a task**.

What's genuinely still missing, confirmed by direct search of `inc/`: no
`tools/tokens.json`, no `occasion` reference anywhere, `aa_acknowledgements`
exists only as an import-time discard target with nothing writing to it,
and no `_validated_against` reference anywhere. Phases 2.0/2.1 below are
accurate; only 2.2's team-colours item was stale.

## 3. Decisions taken for this document

| Question | Decision | Why |
|---|---|---|
| Does an admin get to acknowledge (Advanced tier) a failing occasion accent, or is §7.3's "fail closed" absolute for Occasions? | **Acknowledgement applies to Occasions too.** | Matches the original spec's §3 blanket rule ("AA failures: Block; acknowledgement only behind Advanced"), and makes `aa_acknowledgements` and its Site Health reporting (§6.9) non-dead infrastructure. §7.3's "fail closed" describes the *unattended scheduled-activation* moment specifically — see §5.4 below for the mechanism. |
| Full P2 scope (colour control + Occasions + `aa_acknowledgements` + deploy drift) in one plan, or Occasions alone first? | **Full scope**, phased internally. | User decision. Keeps the shared "inputs hash" primitive (used by both acknowledgement invalidation and deploy-drift revalidation) designed once instead of twice. |
| One spec covering all of P2, or three separate spec→plan cycles? | **One spec, three phase sections** (this document). | User decision — keeps cross-phase dependencies (the inputs-hash primitive, `aa_acknowledgements` shape) visible in one place. Implementation plans may still be written and executed one phase at a time. |

## 4. Phase 2.0 — Foundation

### 4.1 `tools/tokens.json`

A committed manifest giving every `:root` token in `style.css` a `type`,
`group`, `tier`, `bounds`, and `tunable` flag, per the original spec's §7.4.
Unknown or new tokens default `tunable: false` — this is what makes the
original spec's §4 non-goals (raw palette editing, `--bl-focus*`,
`--bl-skew`, `--bl-band-*`, `--bl-surface*` aliases) structural rather than
a promise the panel could accidentally break.

At the end of Phase 2.0, exactly one token is marked `tunable: true`:
`--bl-occasion-accent` (new, default `var(--bl-ice)`, consumers enumerated
per §7.2: CTA ribbon fill, signature band, motif). No brand-palette token
becomes tunable in P2 — see §1.

**Scope-down against the original spec's §6.1.1**, verified by direct
search of `inc/` (no `extractRootTokens`-equivalent exists in PHP today —
only `blueline_stylesheet_version()`, the filemtime cache key): §6.1.1
calls for a full PHP port of the JS `:root` parser, handling `clamp()`,
`rgba()`, multi-declaration lines, comments, and recursive `var()`
resolution — sized for the original, larger "raw token editing" ambition
where many tokens needed default-value parsing for a full colour-editing
UI. That ambition is a confirmed non-goal (§1). The only thing P2 actually
needs resolved is `--bl-occasion-accent`'s own default, which is one
`var()` hop to a token (`--bl-ice`) that is itself a plain hex literal.
Building the general parser now would be premature generality for a need
that doesn't exist yet. Phase 2.0 instead adds a narrowly-scoped
`blueline_occasion_accent_default()` (§4.1 task) that resolves exactly
that one chain. If a future phase needs to resolve arbitrary `:root`
tokens at runtime, the general parser can be built then, against a real
second caller.

### 4.2 `contrast-rules.json`'s fourth consumer — already done

**No task here.** Per §2's correction, `inc/team-colors.php` already
reads `contrast-rules.json`'s `thresholds.body`/`thresholds.large` via
`blueline_contrast_threshold()` (commits `987862a`, `48d21e5`), matching
the original spec's §7.3 ("four consumers, not three"). Retained as a
section so a future reader of this document doesn't go looking for the
gap this once described.

### 4.3 The inputs-hash primitive

A single function, `blueline_settings_inputs_hash()`, returning a hash of
`{blueline_stylesheet_version() /* filemtime */, hash of contrast-rules.json's
rules+thresholds}`. Both consumers below depend on it:

- **`aa_acknowledgements`** entries store the inputs-hash at the moment of
  acknowledgement. An entry whose stored hash no longer matches the current
  hash is treated as invalid — the acknowledgement it represents no longer
  covers current reality.
- **`_validated_against`** (Phase 2.2) stores the same hash for the whole
  settings option, so `init` can cheaply detect "something the validators
  depend on has changed" before deciding whether to re-run them.

Building this once in Phase 2.0 means Phase 2.2's deploy-drift work is
"call the thing that already exists," not a second design.

### 4.4 `aa_acknowledgements` — real storage, not just a discard target

Currently `aa_acknowledgements` exists only as a key that import.php
unconditionally strips (`inc/settings/import.php:209`) — nothing writes to
it yet. Phase 2.0 makes it real: a map of acknowledgement id → `{rule_id,
ratio, user_id, date, inputs_hash, scope}`, where `scope` names what was
acknowledged (e.g. `occasion:canada-day`). Import continues to discard it
unconditionally — per the original spec's §6.8, consent is not something a
file can assert on an admin's behalf, and this document does not revisit
that.

### 4.5 The occasion AA-override mechanism

This is the concrete answer to §3's first decision:

- **At save time** (panel, Advanced tier), if an occasion's accent fails
  the contrast gate, the save blocks with the same pattern §6.5 already
  established for other AA-gated fields (blocking notice, `aria-invalid`,
  the acknowledgement checkbox stating the consequence). Checking it and
  saving writes an `aa_acknowledgements` entry scoped to that occasion, with
  the current inputs-hash.
- **At resolution time** (every request, per §7.5) and **at the cron
  boundary purge** (§7.8), before an occasion's accent/motif is applied,
  the resolver checks: is there a live acknowledgement scoped to this
  occasion, covering this exact accent value, whose inputs-hash still
  matches current? If yes, the occasion activates despite the failing
  ratio. If no — either no acknowledgement exists, or one exists but its
  inputs-hash is stale (§4.3) — the occasion is treated as unacknowledged:
  it does not activate, falls back to brand default, and raises the
  persistent notice plus Site Health critical item the original spec's
  §7.3 describes. This is what makes "fail closed" true at the unattended
  midnight-boundary moment while still letting an admin ship a considered
  exception during business hours.
- A stale acknowledgement is not silently deleted — it stays visible in
  Site Health (§6.9's "every live AA acknowledgement") until either the
  admin re-acknowledges or the occasion's accent is changed to something
  that passes outright.

## 5. Phase 2.1 — Occasions

Builds on Phase 2.0. Implements the original spec's §7 as written, with no
changes from that document except where this section says otherwise.

**Split into two plans**, since the backend (model, resolution, motifs,
cron purge, editor parity) is fully testable with zero UI, and the Panel UI
(§7's colour input, live contrast readout, motif picker, the AA-override
checkbox) is substantial, genuinely new UI work with no existing scaffolding
to extend — confirmed by direct research: no `type="color"` input,
`aria-live` region, or contrast-readout JS exists anywhere in this theme
yet; Phase 2.0 built only the backend contrast primitives. **2.1a
(backend)** ships everything below with no admin-facing surface at all.
**2.1b (panel UI)** — a separate plan — adds the Occasions tab once 2.1a's
model exists to edit.

**Ruling: `occasions` is a reserved key for 2.1a, not a schema field.**
Registering it as a schema field (`type => 'occasions'`, `tab =>
'occasions'`) would make `blueline_settings_tab_slugs()` (which derives the
admin tab list purely from schema `tab` values present) create an
"Occasions" tab immediately — before 2.1b builds anything to render in it,
and before `blueline_settings_render_field()`/`blueline_sanitize_field()`
have a branch for the new type, which either breaks or shows a
half-functional tab. A reserved key (matching the `aa_acknowledgements`
mechanism exactly: added to `BLUELINE_SETTINGS_RESERVED_KEYS`, its own
branch in `blueline_settings_sanitize_callback()`, its own
`blueline_sanitize_occasions()` validator) carries no tab risk and needs no
UI to exist correctly. Unlike `aa_acknowledgements` — which is deliberately
absent from `blueline_settings_defaults()` so it never appears in
`blueline_settings()`'s return — `occasions` **does** get a default
(`'occasions' => array()`) there, because the front-end resolution engine
needs to read it via `blueline_settings( 'occasions' )`. No existing tab's
form ever names `occasions` in its `_posted_fields`, so
`blueline_settings_merge()`'s already-generic carry-forward logic protects
it with zero changes to that function. 2.1b decides how the admin UI
actually edits this reserved key — likely bespoke UI outside the generic
per-field tab loop, matching how substantially custom the colour/motif/
contrast-readout controls already need to be regardless.

**Ruling: the four shipped occasions are a read-only preset catalog, not
pre-populated live entries.** The model has no `enabled` field, and "all
disabled until an admin enables them" doesn't fit an `auto`/`force_on`/
`force_off` `mode` cleanly — an `auto` entry sitting in the stored array
during its real calendar window would activate whether or not anyone ever
looked at the panel. Resolving this the way that keeps the model exactly as
specified (no invented `enabled` field): `blueline_settings_defaults()`'s
`occasions` default is a genuinely empty `array()`; the four presets (Canada
Day, Remembrance Day, Christmas, New Year) live as a separate, read-only
catalog function (e.g. `blueline_occasion_presets(): array`) that 2.1b's UI
reads from for an "add from preset" affordance. Nothing in 2.1a's resolver
ever sees a preset unless 2.1b (or WP-CLI) copies one into the real stored
array first.

- **Model** (§7.1): `id`, `label`, `type` (decorative | commemorative),
  `window` (`start_md`/`end_md`, recurring annually, inclusive), `accent`,
  `motif` (none | maple-leaf | poppy | snowflake | sparkle), `line`
  (optional, no placeholders permitted), `mode` (auto | force_on |
  force_off). Stored flat inside `blueline_settings['occasions']` — a map
  keyed by `id` — consistent with this project's standing decision to keep
  `blueline_settings` flat rather than adopt the original spec's §6.1
  nested shape.
- **Shipped defaults** (§7.1): Canada Day, Remembrance Day, Christmas, New
  Year — all disabled (`mode` unset/auto with no enabling admin action)
  until an admin turns one on.
- **Resolution, precedence, timezone** (§7.5): resolved per request from
  the settings option; `force_on` beats `auto`; `commemorative` beats
  `decorative`; then earliest start; then slug. Dates compare in site
  timezone (`wp_timezone()`), not UTC.
- **Commemorative is a distinct type** (§7.6): no accent shift by default,
  only the `poppy` motif permitted, suppresses any decorative occasion and
  the announcement banner's urgent styling for its duration.
- **Motifs** (§7.7): shipped, enumerated SVG set, never uploadable, static
  only (no animation), decorative motifs `aria-hidden`, poppy carries an
  accessible name.
- **The scheduled-activation cache problem** (§7.8): one WP-Cron event at
  the next window boundary purges srcache and reschedules; cron is used
  only for the purge, never for correctness — the occasion itself is
  computed per request, so a missed cron delays visibility but never
  produces a wrong result.
- **Editor parity** (§7.9): via the `block_editor_settings_all` filter,
  appending to `$settings['styles']` with selector `:root,
  .editor-styles-wrapper` — explicitly not `enqueue_block_editor_assets`
  (§7.9 documents why that was tried and reverted before).
- **Panel UI**: a new Occasions tab. Per-occasion editor exposes label
  (existing curated-copy validation applies), window, accent (colour input
  + labelled hex field per §6.5), motif (select), optional line, and mode.
  The AA-override mechanism (§4.5) surfaces inline exactly where an accent
  fails, using the panel's existing blocking-notice pattern — no new UI
  pattern is introduced.

### 5.1 Phase 2.1b — Occasions panel UI: rulings

2.1a shipped with zero admin-facing surface (§5's addendum above). This
section rules on five gaps direct research against the current codebase
surfaced — none of the existing panel conventions map cleanly onto a
map-keyed, schema-less Occasions tab, and each gap is a real code decision,
not something existing infrastructure already answers.

**Ruling: the admin never types an `id`; it is always server-derived and
de-duplicated.** `occasions` is a map keyed by a stable string id (`design
spec §5`'s Model), unlike `band_photos`'s index-based repeater (whose
`renumber()` JS keeps a contiguous 0-based array, not a namespace admins can
collide in). Two rows submitted under the same string key would silently
collide in PHP's own associative array before `blueline_sanitize_occasions()`
ever runs — nothing existing prevents this, and there is no picker UI for a
raw slug either. Resolving this without touching `blueline_sanitize_occasions()`
(already shipped, reviewed, and tested in 2.1a — this ruling adds no new
requirement to it): the panel's own save handler assigns each row's `id` as
`sanitize_title( $label )`, then de-duplicates the whole submitted batch
against both itself and the currently-stored `occasions` array (excluding
whichever stored entry this exact row is editing) with an incrementing
numeric suffix — the same collision-handling shape WordPress's own
`wp_unique_post_slug()` already uses for post slugs, not a new pattern. A
row editing an existing occasion keeps its existing `id` unless the admin
retypes the label enough to change the derived slug, in which case it is
treated as a rename (old key removed, new key added) — 2.1b's plan must
decide the exact UI affordance for this (e.g. an admin-visible, editable
"slug" field pre-filled from the label, rather than a fully hidden
derivation) but the *server-side de-duplication* is settled here regardless
of that UI choice, so it isn't reopened per-task.

**Ruling: the Occasions tab's own save is a narrow, explicit carve-out in
the existing sanitize callback — not a second save path.**
`blueline_settings_sanitize_callback()` currently drops ANY reserved key
outright whenever the submission's `_tab` is non-empty (`inc/settings/page.php`,
by design — the comment there is explicit that this exists because no
*existing* tab's generic per-field form ever legitimately submits a reserved
key). A schema-less Occasions tab breaks that assumption on purpose: its
form posts `blueline_settings[_tab] = 'occasions'` plus
`blueline_settings[occasions] = <the whole map>` as one opaque value — never
through `_posted_fields` per-field carry-forward, since `occasions` isn't a
scalar schema field. The fix is a narrow, explicit exception tied to this
one tab name (`'occasions' === $tab`), not a general loosening of "reserved
keys never survive a tab-scoped submission" for every other reserved key —
`_schema` and `aa_acknowledgements` keep the existing absolute rule
unchanged. This keeps the single existing Settings API save mechanism
(`options.php`, one POST per tab, existing nonce/capability handling) rather
than introducing a second `admin-post.php`-style path with its own security
plumbing to build and maintain. `blueline_settings_merge()` needs no change:
since the Occasions tab's own submission *does* include `occasions`, it is
present in `$new_value` and simply becomes the new stored value after
sanitization — the carry-forward logic (for keys *absent* from a submission)
never triggers on this tab's own save, exactly as it already doesn't today.

**Ruling: the tab itself is one explicit, named exception to "purely
schema-derived," not a generalized plugin point.** `blueline_settings_tab_slugs()`,
`blueline_settings_tab_label()`, and the generic per-field `<form>` body in
`blueline_settings_render_page()` have no existing mechanism for a tab with
zero schema fields — every current tab is 100% schema-field-driven. 2.1b
adds `'occasions'` to the tab-slugs list as one literal, explicitly-commented
exception (not a new "custom tabs" registration system nobody else needs
yet — YAGNI), a label-map entry, and one `if ( 'occasions' === $current_tab )`
branch in the page renderer that calls a bespoke `blueline_settings_render_occasions_tab()`
instead of the generic field loop. This matches how every other special case
in this codebase (`aa_acknowledgements`, the delete-all-data section) was
added as a narrow, named branch rather than a new abstraction.

**Ruling: the live contrast readout is genuine client-side JS, with a
duplicated (not imported) ratio calculation covered by a PHP↔JS parity
test.** `tools/lib/contrast.mjs` already implements the identical WCAG math
`inc/team-colors.php`'s `blueline_contrast_ratio()` uses, and the existing
admin JS precedent (`assets/src/js/settings-photos.js`) is a plain,
build-step-free source file with progressive enhancement (JS absent → the
feature still functions, only the enhancement goes inert) — both make a
genuinely live, JS-computed readout consistent with how this panel already
works, not a new architectural pattern. Ruled against reusing
`tools/lib/contrast.mjs` directly via `<script type="module">`: that file is
dev/build tooling, not a runtime asset, and serving it to the browser mixes
those two concerns for no real benefit. Instead, 2.1b's admin JS
(`assets/src/js/settings-occasions.js`) gets its own small, self-contained
copy of the luminance/ratio math (~15 lines), and — because this is exactly
the kind of silent-drift risk a code comment in `contrast.mjs` already
flags for a different function (`mixSrgb`) without ever landing a test — a
parity test comparing this JS copy's output against `blueline_contrast_ratio()`'s
PHP output at a fixed set of hex pairs is required, not optional. Without
JS, the occasion still saves correctly and a post-save, server-rendered
contrast readout (computed via the same PHP function, from the just-saved
stored value) is shown — so the feature degrades to "accurate but not
live," never to "broken."

**Ruling: the AA-override checkbox's save-time behavior is per-occasion,
symmetric, and cleans up orphans.** Occasions save as one whole map per
request, unlike the existing "Delete all Blueline data" checkbox (a single
global action with no per-row concept) — that pattern's markup/gating
structure (an explanatory blocking notice + an explicit confirm checkbox,
enforced server-side) is reused, but its all-or-nothing semantics are not:
for every occasion in the submitted batch, compute its effective accent's
contrast against `BLUELINE_TOKEN_INK`; if it fails and that row's own
override checkbox was checked, call `blueline_record_acknowledgement()` for
scope `occasion:{id}` with the effective accent value and the current
`blueline_settings_inputs_hash()`; if it now passes, or the checkbox is
unchecked, call `blueline_remove_acknowledgement()` for that scope
(idempotent when nothing was recorded). Additionally, on every Occasions-tab
save, any stored acknowledgement whose scope starts with `occasion:` but
whose id is no longer present in the submitted map is removed too — without
this, deleting or renaming an occasion would leave its acknowledgement
orphaned in storage forever, silently growing `aa_acknowledgements` with
dead entries. This is the save-side half of the contract 2.1a's resolver
already reads from (`blueline_acknowledgement_covers()`'s value+hash match) —
the resolver itself needs no change; this ruling only decides how the write
side finally populates what it already knows how to read.

## 6. Phase 2.2 — Correctness & observability

### 6.1 Team colours (original spec §8) — already done

**No task here.** Per §2's correction, `blueline_readable_foreground()`
already reports pass/fail via its `$passes` out-parameter, and
`blueline_team_color_set()` already falls back to "no usable colour"
(`return array()`) when neither ink nor paper reaches AA on a team's
primary colour — tested directly in `tests/TeamColorsTest.php`. The
original spec's "`BLUELINE_TOKEN_INK`/`_PAPER` become resolver calls"
framing is superseded by the confirmed non-goal against raw token editing
(§1): there is no admin-tunable "effective value" for ink/paper to
resolve against, so the literal constants — already build-guarded to
match `style.css` — are correct as they stand. Because occasions never
touch `--bl-ink`/`--bl-paper` (§7.2, §7.4), and this item is closed, there
is no per-team revalidation sweep anywhere in P2 (§1, non-goal, restated).

### 6.2 `_validated_against` — deploy-drift revalidation

Stores `blueline_settings_inputs_hash()`'s value (§4.3) for the settings
option as a whole. On `init`: if the current hash differs from
`_validated_against`, re-run validation for everything that depends on it
— occasion accents (§4.5), team colours (§6.1), and every live
`aa_acknowledgements` entry — and raise a notice naming orphaned and
newly-defaulted tokens. The `blueline_stylesheet_version()` transient
already invalidates the *defaults cache* (original spec §6.1.1); this is
the separate, additional *verdict* re-validation the original spec's §6.7
calls for.

### 6.3 Site Health

Extends the existing `debug_information` panel (already reporting schema
version, override counts, advanced on/off per P1) with: active occasion,
and every live AA acknowledgement (rule, ratio, user, date, scope) —
including ones currently invalidated by drift, marked as such.

### 6.4 WP-CLI

`wp blueline settings occasions` — list/enable/disable/force, per the
original spec's §6.9 command list (`export|import|validate|reset|
flush-cache|occasions`; the first five already exist from P1).

### 6.5 Phase 2.2 rulings

Direct research against the current codebase surfaced six gaps none of
§6.2-6.4's existing infrastructure answers outright. Ruled here so the plan
can be written without reopening them per-task.

**Ruling: `_validated_against` is a fifth reserved key, following the
`_schema`/`aa_acknowledgements` shape, not the `occasions` shape.**
`_schema` and `aa_acknowledgements` are both excluded from
`blueline_settings_defaults()`'s return and validated by their own branch
in `blueline_settings_sanitize_callback()`'s reserved-key dispatch — the
established pattern for "internal bookkeeping the option carries but no
ordinary reader ever needs back." `occasions` is the *exception* to that
shape (it gets a real default because the front-end resolver needs
`blueline_settings( 'occasions' )` to return something) and is not the
template here: nothing reads `_validated_against` back through
`blueline_settings()`. A separate option or transient was considered and
rejected — it would need its own export/import/snapshot handling to travel
with the rest of the settings option the way `_schema` already does
(`inc/cli/settings-command.php`'s `export` already includes `_schema`
verbatim), for no offsetting benefit; reusing the existing reserved-key
machinery is zero new infrastructure, not a new one.

**Ruling: exactly one new classification function is the sole source both
the drift notice and Site Health read from — no duplicated logic.**
`blueline_occasions_classify_acknowledgements(): array` (return: a map of
`scope => 'valid' | 'orphaned' | 'stale'`) walks
`blueline_stored_acknowledgements()`'s full map once. For each
`occasion:{id}` scope: if `{id}` is absent from `blueline_settings(
'occasions' )` entirely, classify `orphaned` (the occasion was deleted or
renamed since the acknowledgement was recorded — report only, never
delete: `blueline_remove_acknowledgement()`'s own docblock already
establishes "a stale `inputs_hash` is NOT a valid reason to call this
function," and drift discovery is not a stronger reason than staleness
itself). Otherwise, resolve that occasion's current effective accent and
check `blueline_acknowledgement_covers()` against it with the CURRENT
`blueline_settings_inputs_hash()`; a `false` result classifies `stale`
("newly-defaulted" in the spec's own words: on its next resolution this
occasion silently falls through to `blueline_occasion_accent_default()`
instead of the admin's acknowledged colour, or fails to activate at all if
even the default now fails contrast — never a wrong render, per §4.5's
fail-closed guarantee, but a silent *loss of the admin's override*,
exactly the drift the spec's notice exists to surface). Team colours need
no entry in this walk at all — §6.1 already established they self-report
pass/fail live with no acknowledgement concept to go stale.

**Ruling: the drift check hooks `admin_init`, not the literal global
`init` the spec's prose names — same cheap-guard structure as
`blueline_settings_migrate()`, scoped narrower on purpose.**
`blueline_settings_migrate()`'s existing `init` hook is deliberately
universal (its own docblock: `admin_init` "never fires for anonymous,
cron, REST or WP-CLI traffic," and migration correctness must hold before
*anything* reads the option) — but drift-revalidation's correctness need
is different in kind: `blueline_resolve_active_occasion()` already
recomputes contrast and acknowledgement coverage from scratch on every
request regardless of whether this check has ever run (§4.5's fail-closed
guarantee holds unconditionally already). This check's only job is
*surfacing* drift to an admin via a notice and a Site Health field — pure
diagnostics, with zero front-end/cron/REST/WP-CLI consumer. Computing
`blueline_settings_inputs_hash()` costs real filesystem stats + a hash,
unlike `blueline_settings_migrate()`'s O(1) integer compare guard, so
paying it on every anonymous front-end request for a value nothing on the
front end reads back would be pure waste. `admin_init` — not
`update_option_`/`add_option_` (2.1a's own boundary-purge pattern) either,
since this must catch drift from causes OTHER than a settings write (a
deploy touching `style.css`'s mtime, an edited `contrast-rules.json`) —
matches the spec's intent ("automatic, not cron-scheduled") while
respecting the exact reason `blueline_settings_migrate()` chose `init`
over `admin_init` (a reason that doesn't apply here).

**Ruling: `_validated_against` updates immediately after classification,
whether or not any entry classified `orphaned`/`stale` — the notice is a
one-time surface, not a persistent nag.** On an `admin_init` request where
the current hash differs from stored `_validated_against`: classify (per
the ruling above), render the notice if the classification found anything
non-`valid`, then update `_validated_against` to the current hash
regardless. The next request's cheap hash-compare then short-circuits
until the *next* real drift — matching how a one-time, actionable notice
should behave, not a recurring one an admin has to dismiss every visit.

**Ruling: the drift notice renders a `<ul>` inside its `<section
class="notice ...">` — the first admin notice in this codebase naming a
variable-length list, not the fixed one-or-two-slot `sprintf`/`printf`
every existing notice uses.** No existing precedent conflicts with this;
a `<ul>` of labeled items inside a `<section>` is still not a bare `<div>`
and needs no new markup pattern beyond what the panel's own per-row
AA-override notice already uses for its `<section>` wrapper.

**Ruling: the Site Health "AA acknowledgements" field is one multi-line
string value, one line per entry, "(needs re-review)" appended to any
`stale`-classified line** — matching every existing field in
`inc/settings/site-health.php`, all of which are plain strings; no field
in that file has ever carried a nested array, and inventing that shape now
for one field would be a bigger, riskier departure than formatting a list
as delimited text the way WP core's own Site Health screen already
expects a multi-line field value to look. An empty map renders as a
single `'None recorded.'`-style line, matching how every other field's
empty/off state in this file already reads as plain, unremarkable text
rather than an absent field.

**Ruling: `wp blueline settings occasions` is one subcommand taking an
action positional argument (`list`, `enable <id>`, `disable <id>`, `force
<id>`), not four separately-registered subcommands** — matching how the
spec names it as a single command with four listed behaviors, not four
command names. Verb-to-mode mapping (nowhere stated in either spec
document, resolved here since three verbs must map onto exactly the three
existing `blueline_occasion_modes()` values with no fourth invented):
`enable` → `mode = 'auto'` (let it run on its own calendar window, the
"normal" state), `disable` → `mode = 'force_off'` (never eligible,
regardless of window), `force` → `mode = 'force_on'` (always eligible,
"preview it now" — the same phrase 2.1a's own `blueline_occasion_modes()`
docblock already uses for `force_on`). `enable`/`disable`/`force` on an
`<id>` present in `blueline_occasion_presets()` but absent from the
currently *stored* `occasions` map copies that preset in first (with the
requested mode applied) — the CLI equivalent of the panel's "add from
preset" affordance; refusing to would make the CLI strictly less capable
than the panel already is for no stated reason. An `<id>` matching neither
a stored occasion nor a preset is a `WP_CLI::error()`, matching every
existing subcommand's not-found handling convention.

## 7. Testing

Per the original spec's §10, applied per phase:

- **Phase 2.0**: `tokens.json` parser/validator round-trip; the fourth
  consumer's threshold values match the other three (regression guard
  against `team-colors.php` drifting back to its own constants);
  `aa_acknowledgements` write/invalidate round-trip against a mutated
  inputs-hash; every *tunable* token (i.e. `--bl-occasion-accent`) covered
  by ≥ 1 contrast rule, failing if not.
- **Phase 2.1**: occasion resolution across timezone boundaries, overlaps,
  and leap years; fail-closed on an invalid/unacknowledged occasion;
  fail-open on a validly-acknowledged one; the cron boundary purge fires
  exactly at the window edge; editor-parity selector/token-name assertions.
- **Phase 2.2**: `_validated_against` triggers re-validation exactly on a
  stylesheet or rules-table change, not on unrelated saves; Site Health
  reports a drift-invalidated acknowledgement distinctly from a live one.
  (Team-colours pass/fail testing is already covered by
  `tests/TeamColorsTest.php` — §6.1.)
- **Whole-suite, every phase**: the full test suite and the smoke suite
  pass with non-default settings applied (an occasion active, an
  acknowledgement present), not only at defaults — per the original spec's
  §10 standing requirement.

## 8. Risks

Carried forward from the original spec's §13 where still applicable, plus:

1. **The acknowledgement-invalidation mechanism (§4.5) is new design, not
   drawn from the original spec's literal text** — it resolves a real
   contradiction between §3 and §7.3, but it is the one part of this
   document that is genuinely novel rather than restated. Worth an extra
   look in review.
2. Occasion accents multiply the contrast surface by the number of enabled
   occasions (original spec's risk 2) — unchanged, still bounded by §7.2's
   enumerated consumer list.
3. The srcache purge remains unverifiable on staging (original spec's risk
   3) — first real validation of the cron-boundary purge is production.
4. No CI exists (original spec's risk 5, still true as of this writing) —
   every gate here is still a manual `npm run check` / `npm run
   check:oracle` step.

## 9. Documentation deliverables

Per the original spec's §12: `DESIGN.md`'s hex table needs restating as
defaults-vs-invariants once `--bl-occasion-accent` exists; `PRODUCT.md`'s
"accessibility is a floor" needs the acknowledgement mechanism named
concretely (§4.5, not just "behind Advanced"); the cutover checklist gains
occasion scheduling and the srcache boundary-purge verification step; an
in-panel first-run help pane for the new Occasions tab.

### 9.1 Rulings

Direct research against the current codebase surfaced five gaps in the
above. Ruled here so the plan can be written without reopening them
per-task.

**Ruling: the Occasions tab's help pane is a permanent, non-dismissible
`<details>`/`<summary>` disclosure — no new dismissal infrastructure.**
No dismissible/first-run notice pattern exists anywhere in `inc/settings/*.php`
today; the only analog in the theme (`inc/announcement.php`'s
content-fingerprint + `localStorage` scheme) is front-end, per-browser, and
architecturally unrelated to an admin-side "has this admin seen this"
flag — reusing it, or inventing a WP-core-style `is-dismissible` +
AJAX + user-meta flow (also never used in this theme), would be new
infrastructure built for exactly one page. A native `<details>` element
needs zero JS, zero new storage, and is inherently unobtrusive
(collapsed by default, always available, never nags) — which fits "the
audience is volunteers and there is otherwise no onboarding" better than
either a notice that vanishes forever after one click or new
per-admin-user dismissal state to build and maintain for a single help
pane.

**Ruling: the new help pane replaces, not supplements, the Occasions
tab's existing one-sentence intro paragraph.** `blueline_settings_render_occasions_tab()`'s
current `<p class="description">` already explains, briefly, what
occasions do and when they activate — the new pane's own intro folds
that sentence in rather than duplicating it as a second, overlapping
explanation stacked above or below the disclosure.

**Ruling: `DESIGN.md`'s restated table gains one new row for
`--bl-occasion-accent` and one new pointer sentence — it is not expanded
to enumerate all 31 rules in `tools/contrast-rules.json`.** The new
row's Hex column reads as "resolves to `--bl-ice` (`#74C0E1`) by default;
admin-settable per occasion" and its "On paper" column names the rule id
(`ink-on-occasion-accent`, ≥4.5:1) plus the acknowledged-exception
fallback (§4.5) — a fixed ratio number would misrepresent a value that is
neither fixed nor unconditionally enforced. A new sentence, near the
table, states plainly that `tools/contrast-rules.json` is the enforced
contract (all 31 rules, including several — focus, border,
success/warning/danger — this table has never listed) and that this
table is a reference subset, not the contract itself. Restating the
whole table as a full mirror of the JSON file would just create a second
copy of the same information to keep in sync, which is the opposite of
"point the contract at `tools/contrast-rules.json`."

**Ruling: `PRODUCT.md`'s concrete mechanism-naming sentence goes in the
"Accessibility requirements" section, not into principle #5's own
one-line statement.** Principle #5 ("Accessibility is a floor, not a
finish") is a punchy, absolute philosophy statement in a numbered list of
five principles; lengthening it to carry an exception mechanism's
mechanics would dilute the rhetorical point it exists to make. The
"Accessibility requirements" section immediately below it is already
procedural/mechanism-oriented (CI gate, keyboard traversal, focus
visibility, skip link) — a natural, unforced place to add one factual
sentence naming the acknowledgement mechanism (an admin-facing,
explicitly-consented, logged exception — never a silent bypass) without
touching principle #5's own wording at all.

**Ruling: the cutover checklist gains exactly two new numbered items,
sequenced after the existing item 6 (the general srcache purge) and
before the existing item 8 (final smoke test).** (a) Verify
`BLUELINE_SRCACHE_PURGE`'s shared-Redis-instance prerequisites — pointing
at `DESIGN.md`'s own existing four manual verification steps — are
confirmed BEFORE that flag is ever flipped to `true` in production,
since item 6 as currently worded covers the mechanism's existence but not
this precondition. (b) Verify the occasion's WP-Cron boundary-purge event
(§4.5/§7.8) actually fires correctly against production specifically,
since this is exactly the case the design spec's own risk #3 already
flags as unverifiable on staging. The existing checklist's closing
"Sequence matters" paragraph gets one added clause extending its
purge-ordering guidance to cover both new items.
