# Sitewide floating "next game" widget

Status: approved, ready for implementation plan.

## Context

Second of three planned personalization features built on the claimed-player
account link (see `2026-08-25-blueline-highlight-mine-design.md` for the first,
already merged, which added `blueline_current_user_player_id()` /
`blueline_current_user_team_ids()`). This one: a small, persistent, always-visible
element on every front-end page showing the signed-in, claimed visitor's next
game, so a visitor "just visits the site and right away sees the next game"
without hunting for it.

The underlying data already exists and needs no new logic: `blueline_get_player_next_event( int $player_id ): ?array`
(`inc/account/player-data.php`) — already used by the My Account "My next game"
module (`blueline_account_render_next_game()`) — returns
`{event_id, timestamp, venue, venue_term_id, opponent_team_id, is_home}` or `null`.

## Non-goals

- No "hide this because you're already looking at the relevant page/team" logic —
  no precedent for it in this codebase, and it's real added complexity for
  marginal benefit. The widget shows or it doesn't, based only on whether there's
  a next game to show.
- Not a variant/reuse of the My Account "My next game" module's markup (that's a
  full account-dashboard-styled module block; this is a small floating chip). They
  share only the underlying data call.
- No new "hide me forever" preference beyond the existing per-event dismiss.

## Design

### Visibility

Shows only when ALL of:
- Visitor is logged in.
- `blueline_current_user_player_id()` resolves (claimed).
- `blueline_get_player_next_event( $player_id )` returns a real event (not null —
  i.e. there IS an upcoming game).

Hidden entirely — not an empty state — for logged-out, logged-in-unclaimed, and
claimed-with-no-upcoming-game (off-season). A persistent widget with nothing
concrete to show is clutter, not information.

### Placement and interaction

This theme has no existing `position: fixed`/floating UI precedent (the one
existing sitewide element, the announcement banner, is in-flow) and does not use
a `wp_footer` action hook — sitewide elements are called directly from a
template. This widget follows that same "call it directly" convention, from
`footer.php` near the existing `wp_footer()` call (the natural home for
persistent overlay chrome, even though the announcement banner happens to live in
`header.php` for its own, unrelated in-flow reasons).

`position: fixed`, given a new `--bl-z-floating-widget: 45` token added to this
theme's centralized z-index scale in `style.css` (currently `--bl-z-nav-submenu:
40` → `--bl-z-drawer: 50` → `--bl-z-header-controls: 55` → `--bl-z-header: 60` →
`--bl-z-skip-link: 100`) — placed between the submenu and the mobile nav drawer,
so opening navigation is never visually obscured by this widget.

Content is always visible on render — no expand/collapse click required, matching
"right away sees the next game." Shows: date/time, opponent (`vs`/`@` per
`is_home`, matching `blueline_account_render_next_game()`'s own phrasing), and
venue if known — the same fields that function already formats, reused rather
than reformatted differently.

### Dismissal

Reuses the exact pattern already established by `assets/src/js/announcement.js`:
a fingerprint attribute rendered server-side, compared against a `localStorage`
value on load (wrapped in try/catch, degrading to "never dismissed" on any
failure), hidden client-side post-paint rather than blocking render. The
fingerprint here is the event's own `event_id` (not a content hash) — so
dismissing this week's game notice does not suppress next week's; the widget
naturally reappears once `blueline_get_player_next_event()` returns a different
event.

### Settings

One new toggle key in `blueline_section_definitions()` (`inc/settings/sections.php`),
e.g. `floating_next_game`, independent of the existing `account_next_game` key —
an admin may want the My Account module without the sitewide widget, or vice
versa. Defaults to enabled, matching every other section's default (per
`blueline_section_enabled()`'s existing "unset means true" contract).

## Testing

- No new pure-function logic beyond what Task 1 of the highlight-mine feature
  already added and tested (`blueline_current_user_player_id()`) — this feature
  is template/markup work consuming already-tested data, so it gets live
  verification only, per this codebase's established precedent (same trade-off
  as `league-table.php`/`team-lists.php` in the previous feature).
- Full local suite (`npm run check`) must stay green.
- Live verification on staging with a real claimed-player account before this is
  considered done: confirm the widget appears/disappears correctly across the
  visibility states above, confirm dismiss-and-reappear-on-new-event behavior,
  confirm it doesn't visually collide with the mobile nav drawer when open.

## Accessibility

- The widget must be reachable and dismissible via keyboard, with a visible focus
  state — it's new, persistent, interactive chrome on every page, so it needs the
  same care given to the header/nav's existing focus management.
- Must not trap focus or dodge screen-reader users; a `role="status"` (not
  `role="alert"` — this is ambient info, not an urgent interruption, unlike the
  claim nudge from the previous feature) is appropriate so assistive tech isn't
  interrupted by it on every page load.
