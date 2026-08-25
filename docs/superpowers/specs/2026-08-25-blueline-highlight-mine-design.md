# "Highlight Mine" — account-linked personalization on League Tables and Player Lists

Status: approved, ready for implementation plan.

## Context

The site already lets a logged-in visitor link their WordPress account to a real
`sp_player` post — a name-matching claim flow in My Account (`inc/account/player-link.php`,
`inc/account/dashboard.php`), not a manual team picker. Once linked, the theme can
already derive that visitor's current team(s) via existing, reusable helpers:

- `blueline_get_linked_player_id( int $user_id ): ?int` — null means unclaimed.
- `blueline_get_player_team( int $player_id ): ?array` / `blueline_player_current_team_id()`
  / `blueline_player_current_team_ids()` (plural — a player can have more than one
  current team, e.g. across divisions).

This spec covers the first of three planned personalization features built on that
link (see the other two: a sitewide next-game widget, and a schedule-change notice —
each gets its own spec/plan when its turn comes). This one: **highlight the visitor's
own team on League Tables, and their own row on their team's Player List, plus a
notice nudging unclaimed logged-in visitors to link their player.**

A separate, earlier attempt at team personalization ("Pin Your Team" — an anonymous,
localStorage-based per-team REST lookup) was built, reviewed, and deliberately
scrapped: the tab-strip already remembers a visitor's last-viewed division via
localStorage, so that anonymous mechanism solved a problem that didn't exist. This
spec is unrelated to it — everything here is gated behind login + a claimed player,
never anonymous state.

## Non-goals

- No changes to the anonymous division tab-strip (`standings-tabs.js`) — it already
  does what it needs to.
- No new "which team am I" UI — the existing claim flow is the only path to link a
  player, this spec only *reads* that link.
- No handling of "no player exists to claim" — that's already covered by the
  existing claim card's empty state (`blueline_account_module_empty_state_html()`).

## Design

### 1. Resolver helpers (`inc/account/player-data.php`)

Two small, pure-composition helpers, alongside the existing ones in this file:

```php
function blueline_current_user_player_id(): ?int {
	$user_id = get_current_user_id();
	return $user_id ? blueline_get_linked_player_id( $user_id ) : null;
}

function blueline_current_user_team_ids(): array {
	$player_id = blueline_current_user_player_id();
	return $player_id ? blueline_player_current_team_ids( $player_id ) : array();
}
```

Both return empty/null for logged-out or unclaimed visitors — every call site checks
that, not `is_user_logged_in()` directly, so "logged in but unclaimed" and "logged
out" collapse to the same "nothing to highlight" branch.

### 2. League Table row highlight (`sportspress/league-table.php`)

This file is already a full theme override of SportsPress's own template — not a
shortcode-attribute pass-through — so every place a league table renders (the
homepage module, the `/standings` page, anywhere else `[league_table]` appears)
picks this up automatically with one change in one file.

Existing behavior: a row gets `.sp-highlight` when `$highlight === $team_id`, where
`$highlight` is either an explicit shortcode attribute or a static, admin-chosen
`sp_highlight` post meta on the table — an editorial "featured team for this table"
concept, unrelated to any specific viewer. **This is deliberately not reused or
overloaded for "my team"** — the two can coexist on the same table without
conflict, but they mean different things and a table's admin-chosen highlight must
never be silently overridden by a viewer's own team.

New: a second, independent class, `bl-sp-row--mine`, added when the row's team ID is
in `blueline_current_user_team_ids()`. Styled via `blueline_team_color_style_attr()`
— the same AA-contrast-guarded per-team-color helper `single-team.php` already uses
— scoped to the row's own cells, not the whole page. No JS; works with JS disabled,
consistent with this theme's established server-rendered-first convention.

A player on multiple current teams needs no special resolution: each row is checked
independently against the visitor's full team-ID set, so if their teams appear in
different tables (or even the same one), each relevant row gets marked. No
"which one wins" logic anywhere.

### 3. Player List row marker (`sportspress/team-lists.php`)

This template only ever renders on a team's *own* page (SportsPress core's
`team_content()` hook), which already carries that team's color via
`single-team.php`'s own `blueline_team_color_style_attr()` call on `<main>` — so
team-color tinting here would color the *entire* roster uniformly and distinguish
nothing. Instead: a plain "You" badge/pill next to the name of the row whose player
ID equals `blueline_current_user_player_id()`.

Two separate code paths need the identical treatment (confirmed distinct loops,
not shared): the primary `SP_Player_List`-driven loop, and the fallback roster loop
used when a table has no admin-configured roster (`blueline_get_team_roster()`).

### 4. Claim notice

A lightweight variant of the existing `.bl-account-notice` pattern (own
`role="alert" tabindex="-1"` section, focus-moved-on-load per this theme's own
`assets/src/js/account.js` convention, dismissible) — not the full claim-card UI
with candidate matching, just a one-line nudge linking into the My Account claim
flow. Shown only for logged-in visitors with no linked player, on the two pages
where the highlight above would otherwise apply: the `/standings` page, and a
team's own page (`single-team.php`, above the roster).

## Testing

- `blueline_current_user_player_id()` / `blueline_current_user_team_ids()`: pure
  composition of already-tested helpers — unit-tested with the existing fake-post
  test harness (see `RestStandingsTest.php` for the established pattern), no live
  WP/SportsPress objects needed.
- `league-table.php` / `team-lists.php` rendering: WP-Query/SportsPress-object-heavy
  — live-verified only, per this codebase's established precedent (same trade-off
  documented on `blueline_homepage_team_standings_row()` and `VenueLabelTest.php`).
- Claim notice: covered by existing `tests/NoticeDivGuardTest.php`'s ban on
  notice-like `<div>`s, applies automatically to any new notice using the
  established markup pattern.
- Full local suite (`npm run check`) must stay green; live verification against a
  real claimed-player account on staging before considering this feature done.

## Accessibility

- `bl-sp-row--mine` and the "You" badge must not rely on color alone — the badge
  carries its own text ("You"), and the row highlight is a background/border
  treatment additive to the existing accessible row markup, not a replacement of it.
- Claim notice follows the exact focus-management and `role="alert"` convention
  already established and tested for the existing account notices.
