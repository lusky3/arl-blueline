# Homepage Standings — "Pin Your Team" — Design & Implementation Plan

> **Status:** proposed, not yet approved for implementation. Written after shipping the
> division tab strip (see `git log --grep "show every division on the homepage standings"`),
> which fixed "which division" but not "which row is mine." This is that second half.
>
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans once this plan is approved and Task 0's open
> questions are resolved. Do not start Task 1 before Task 0.

**Goal:** A returning visitor who has already told the site which team is theirs sees one line
on the homepage — *"Ice Wolves — 3rd in Division 3 — 8-2-1, 17 pts ▲"* — instead of scanning a
table, on every visit from then on, with zero login and zero server-side identity.

**Why this is a separate feature, not an extension of the tab strip:** the tab strip
(already shipped) is a pure, zero-JS, fully server-rendered mechanism — every division's table
sits in the HTML on first paint. This feature requires knowing *who the visitor is* (which team
they picked), which only exists in the browser's own `localStorage`, never on the server at
request time. That forces a client-side fetch-after-load step the tab strip never needed, and a
small new piece of server infrastructure (a REST endpoint) this theme doesn't have at all today.
Bundling the two into one plan would have hidden that jump in complexity.

## The user-facing flow

1. **Nothing pinned (today's default, unchanged):** the homepage shows the tab strip exactly as
   shipped. Each team name row in the currently-open panel gets a small pin control next to it.
2. **Visitor taps the pin next to their team:** `localStorage` remembers that team's post ID.
   No page reload, no navigation.
3. **Any later page load, this same browser:** the module fetches that team's current
   rank/record/points (see REST endpoint below) and replaces the tab strip with the one-line
   summary, plus a small trend glyph (▲ moved up / ▼ moved down / – unchanged) versus the rank
   this same browser last saw for that team. A quiet "Not your team?" link un-pins and reverts
   to the tab strip.
4. **Tapping the one-line summary** expands the full compact division table beneath it — reusing
   the existing tab-strip panel markup for that team's division, just collapsed by default.

## Why a REST endpoint, and the resulting UX cost

`localStorage` cannot be read on the server, so the *first* HTML the server sends can never know
a team is pinned — it always renders the tab strip (or the today's empty state) first, exactly as
now. A small script then reads `localStorage` after load and, if a team is pinned, calls a new
REST route to get that team's live standing, then swaps the module's visible content client-side.

**This means every visit with a pinned team shows a brief flash of the tab strip before the
one-line summary replaces it**, unless mitigated. Accept this cost consciously — do not try to
eliminate it by, e.g., blocking render on the fetch (that would break the zero-JS guarantee the
tab strip earns for everyone else) or reading cookies server-side (this project has explicitly
kept anonymous, login-free browsing; do not introduce a tracking cookie to avoid this flash).
Task 5 below covers the mitigation that IS in scope: reserve layout height via CSS so the swap
doesn't cause a visible jump, and consider a brief skeleton/loading state.

## Confirmed technical facts (resolved before writing tasks, not assumptions)

- **`SP_League_Table::data()` returns rows keyed by team post ID.** Confirmed by reading this
  theme's own `sportspress/league-table.php`, which already loops `foreach ( $data as $team_id
  => $row )` — no plugin-internals spelunking needed, and no live `wp eval` spike required for
  this specific question. `$row` carries `name`, `pos`, and the raw stat columns
  (`w`/`l`/`tie`/`ot`/`gf`/`ga`/etc, mirroring the columns `league-table.php`'s own `$labels`
  already names).
- **No REST route exists anywhere in this theme yet** (`grep -rl register_rest_route inc/`
  is empty). This plan establishes the theme's first one — Task 2 picks a namespace
  (`blueline/v1`, WordPress's own conventional shape) since there is no existing convention to
  match instead.
- **The season-resolution machinery already exists and is reused, not rebuilt.**
  `blueline_homepage_current_standings_tables()` (`inc/homepage-modules.php`, shipped with the
  tab strip) already returns every current-season division's `sp_table` id — the new resolver
  below iterates that same list rather than inventing a second "what's the current season"
  answer.

## Remaining open questions — resolve these in Task 0, before writing any code

1. **Scope of "pinned team": this homepage module only, or a site-wide concept?** Recommend
   scoping strictly to this module for now (a `bl_pinned_team` key used nowhere else) rather than
   growing it into a cross-cutting "My Team" identity reused on, say, the My Account dashboard —
   that would be a materially bigger feature with its own design questions (does a logged-in
   player's linked team auto-pin? does it need to survive across devices, meaning it can no
   longer be `localStorage`-only?). If a unified concept is wanted later, this module's storage
   key should be treated as a migration source, not extended in place.
2. **What if the visitor's browser has no rank to compare against yet** (first pin, or
   `localStorage` was cleared since)? The trend glyph must show "–" (no claim), never a naive
   "worse than zero" ▼. Task 3's `compareRank()` needs this as an explicit, tested case, not an
   incidental default.
3. **What if a team appears in more than one current-season table** (an edge case; no evidence
   this happens today, but the "Division 1/2" combined-table naming seen in the archive suggests
   it's at least historically possible)? Resolve by taking the FIRST match in
   `blueline_homepage_current_standings_tables()`'s own order (same order the tab strip already
   uses) — deterministic, and consistent with "the first tab a visitor would have found this team
   in anyway." Needs an explicit test, not just documentation.
4. **Should the pin control appear on the tab-strip panels themselves, or is a lighter-weight,
   fully separate approach cleaner** (e.g., a small JS pass that finds each panel's team-name
   cells post-render via a `data-team-id` attribute already on the row, rather than editing
   `blueline_homepage_standings_tabs()`'s PHP output)? Recommend the `data-team-id` attribute
   approach: add it once, in the tab-strip's own row markup, and let the pin *behavior* live
   entirely in JS — keeps the PHP module free of pin-specific markup/copy, so this feature can be
   reverted by deleting JS/CSS files alone if it doesn't work out, without touching
   `inc/homepage-modules.php` again.

## Architecture

- **`inc/homepage-modules.php`** gets one new function, `blueline_homepage_team_standings_row(
  int $team_id ): ?array` — walks `blueline_homepage_current_standings_tables()`'s tables (in
  their existing order), and for the first one whose `SP_League_Table` data includes `$team_id`,
  returns `['team_name' => string, 'division_label' => string, 'pos' => int, 'record' => string,
  'points' => int]`. Returns `null` if the team isn't in any current table (graduated, dropped,
  or a stale pin from a past season). Like `blueline_homepage_current_standings_tables()` itself,
  this is WP-Query/plugin-object-heavy and gets **live verification, not a PHPUnit test** — this
  project's own established precedent (see `blueline_homepage_current_standings_tables()`'s and
  `VenueLabelTest.php`'s docblocks for the same trade-off). The `record` string (`"8-2-1"`) is
  built from the same `w`/`l`/`tie`/`ot` fields `sportspress/league-table.php`'s own synthesised
  "Record" column already derives — do not re-invent that logic twice; extract it to a small
  shared, *pure* helper both call, and unit-test the helper.
- **A new `inc/rest-standings.php`**, `require_once`'d from `functions.php` (mandatory — this
  repo's `tests/IncRequireCoverageTest.php` fails the build otherwise). Registers
  `blueline/v1/team-standing` (GET, one required int arg `team_id`), calling
  `blueline_homepage_team_standings_row()` and shaping its result (or a `{"found": false}` body)
  as JSON. Public, read-only, no auth, no nonce (nothing is mutated) — but validate `team_id` is
  a real, published `sp_team` post before doing any lookup, and rate-considerations below.
- **Caching:** wrap the resolver's result in a short-lived transient keyed by `team_id` (5–10
  minutes is plenty — standings don't change mid-game-night at the granularity a homepage widget
  needs). Matches this codebase's existing transient-caching convention elsewhere
  (`blueline_current_sp_season_term_id()`).
- **JS:** extends `assets/src/js/standings-tabs.js` (or a new sibling `standings-pin.js`, decide
  in Task 0 based on how large the combined file gets — prefer one file while it stays under
  roughly 150 lines, matching this project's existing file-granularity habits) with: pin-button
  click handling (writes `bl_pinned_team` to `localStorage`), an on-load check that fetches
  `/wp-json/blueline/v1/team-standing?team_id=…` when a team is pinned, a pure `compareRank(
  previousPos, currentPos ): '▲'|'▼'|'–'` function (unit-tested per open question 2), and the
  DOM swap between tab-strip and one-line-summary views.
- **CSS:** a `.bl-standings-pin` control (small, unobtrusive, matching the existing
  `.bl-sp-standings-toggle-label` pill language) and a `.bl-standings-summary` one-line layout,
  new trend-glyph colors drawn from the existing `--bl-success`/`--bl-danger` tokens (already
  contrast-verified elsewhere in `tools/contrast-rules.json` — reuse those rules, don't invent
  new ones unless the exact background differs).

## Accessibility

The module's visible content changes without a page navigation (tab strip → one-line summary,
and vice versa on un-pin). Wrap the swap target in `aria-live="polite"` so assistive tech
announces the change, and make sure the swap doesn't move keyboard focus unexpectedly. The pin
control needs a real accessible name per team (`aria-label="Pin {team name}"`), not an icon alone.

## Failure modes — every one of these must degrade to today's tab strip, never to blank

- `localStorage` disabled or private browsing: pin never persists; every visit is a fresh
  tab-strip visit. (Matches `standings-tabs.js`'s existing try/catch convention exactly.)
- REST fetch fails, times out, or returns `{"found": false}` (team dropped from the current
  season): stay on the tab strip; do not show an error message in this small a module.
- JavaScript disabled entirely: pin control and one-line summary never engage; the
  fully-server-rendered tab strip is the whole experience, same as any other visitor.
- A pinned team ID from last season simply won't `team_id` match anything current — the REST
  endpoint's `{"found": false}` path handles this the same as a graduated team, no special case
  needed.

## Suggested task breakdown (once Task 0's open questions are answered and this plan is approved)

- [ ] **Task 0: Resolve the four open questions above** and update this plan in place with the
      answers before writing any code (a plan whose own open questions are still open is not
      ready for `subagent-driven-development`'s pre-flight scan).
- [ ] **Task 1: Extract the shared `record` formatter** (`"W-L-T[-OT]"` from raw stat fields) out
      of `sportspress/league-table.php` into a small, pure, unit-tested function both it and the
      new resolver call — a prerequisite for Task 2, not optional groundwork.
- [ ] **Task 2: `blueline_homepage_team_standings_row()` + the REST route + its tests** (live
      verification for the resolver, real PHPUnit coverage for the pure record-formatter and for
      the route's input-validation/shape, per this project's established split).
- [ ] **Task 3: Pin-button markup** — add the `data-team-id` attribute to the tab strip's row
      markup (a small, additive change to `blueline_homepage_standings_tabs()`'s existing output)
      per open question 4's resolution.
- [ ] **Task 4: Client-side pin/fetch/render/trend logic**, including the pure, unit-tested
      `compareRank()` function and the `aria-live` swap.
- [ ] **Task 5: CSS for the pinned summary, trend glyphs, and the layout-shift mitigation**
      (reserved height / skeleton state) called out above, contrast-verified against existing
      `tools/contrast-rules.json` rules.
- [ ] **Task 6: Live verification on staging** (this session's established practice for every
      SportsPress-data-dependent feature) — confirm a real team's pin/fetch/summary/trend/un-pin
      cycle end to end, plus the graduated-team and JS-disabled degradation paths, before calling
      this done.

**Rough sizing:** comparable effort to the tab strip just shipped, plus new REST infrastructure
this theme doesn't have today — expect this to be a meaningfully larger piece of work, not a
quick follow-on.
