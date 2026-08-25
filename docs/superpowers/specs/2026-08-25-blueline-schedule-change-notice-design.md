# Schedule-change notice

Status: approved, ready for implementation plan.

## Context

Third of three planned account-linked personalization features (see the first
two, already merged: `2026-08-25-blueline-highlight-mine-design.md` and
`2026-08-25-blueline-floating-next-game-design.md`). This one: notify a
signed-in, claimed visitor when their next game's own schedule details change
— rescheduled, moved venue, opponent changed — not just a generic "something
was touched."

**Superseding this spec's own original premise**: the initial 3-feature pitch
described this as "compare `post_modified` against a per-user last-seen
marker." Research before writing this spec found that's unreliable: nothing in
this theme touches `post_modified` outside a normal edit-screen save, but
SportsPress's own results-entry meta box runs *inside* that same `save_post`
flow — so an admin who only enters a score after the game bumps
`post_modified` identically to an admin who reschedules it. `post_modified`
cannot tell those apart. The real signal is a diff of the fields that actually
constitute "the schedule": date/time (`post_date`), venue (`sp_venue` term),
and opponent (`sp_team` meta) — which, conveniently, are exactly the fields
`blueline_get_player_next_event()` already fetches and returns for the
floating widget.

Also superseding the original "per-user last-seen marker": this codebase's
established pattern for "has this visitor seen X" is 100% client-side
`localStorage` (both the announcement banner and the floating next-game
widget), never server-side user meta. This feature follows that same
precedent rather than introducing a new persistence mechanism.

**Scope correction**: "My Schedule" (`blueline_account_my_schedule_endpoint()`)
currently renders only the single next game — there is no "list every
upcoming game" feature anywhere in this codebase. Building one would be a
separate, larger feature nothing here actually needs; this notice is
therefore scoped to the next game only, matching what's actually shown today.

## Design

### Fingerprint

Extend `blueline_get_player_next_event( int $player_id ): ?array`'s return
array with one new key, `fingerprint` — a hash of the event's own
`timestamp`, `venue_term_id`, `opponent_team_id`, and `is_home` (the fields
already in the array; no new query). Adding a key to an existing array is
backward-compatible with every current consumer (`blueline_account_render_next_game()`,
the floating widget) — verify no existing test asserts this array's exact key
set, and update it if one does.

The value compared and stored client-side is `event_id . ':' . fingerprint`,
not the fingerprint alone — this is what lets the JS distinguish "this is a
genuinely new next game" (different `event_id`, e.g. last week's game
happened and a later one is now "next") from "this is the SAME game and its
details changed" (same `event_id`, different `fingerprint`). Only the second
case is a "change" worth calling out; the first is normal, expected
progression and needs no special notice.

### Floating widget (extends the already-merged feature)

`floating-next-game.js`'s dismiss comparison changes from a bare `event_id`
match to parsing the stored `event_id:fingerprint` pair:

- Stored value matches current exactly → fully dismissed, hidden (today's
  existing behavior, unchanged).
- Stored `event_id` matches, `fingerprint` differs → same game, changed since
  last seen: show the widget (never hidden by a stale dismissal) with a
  distinct "Updated" indicator.
- Stored `event_id` differs (or nothing stored yet) → show normally, no
  "Updated" indicator — this is just the next game, not a change to one
  already seen.

Dismissing writes the current `event_id:fingerprint` pair, same as today.

### My Account "next game" card

`blueline_account_render_next_game()` (used identically by both the dashboard
and My Schedule) is not dismissible — it's permanent account content, not
ambient chrome — but gets the same "Updated" treatment: if the visitor has
a stored `event_id:fingerprint` pair whose `event_id` matches this event and
whose `fingerprint` differs, show a small "Updated since you last checked"
note above the game details.

Viewing this card also WRITES the current `event_id:fingerprint` pair (not
just reads it) — visiting My Account and seeing "Updated" here counts as
having seen it, so the floating widget elsewhere doesn't keep flagging the
same already-acknowledged change. This is the one real judgment call in this
design: the alternative (read-only here, so both surfaces nag independently)
seemed like needless redundancy for the same underlying fact.

## Non-goals

- No handling of a full multi-game schedule — next game only, per the scope
  correction above.
- No specific "what changed" text (e.g. "moved from Saturday to Sunday") — that
  would require storing the previous field values, not just a hash of them.
  "Updated since you last checked," with the current (correct) details right
  there, is the whole message.
- No server-side "seen" state, no email/push notification — purely the same
  client-side localStorage pattern already established.

## Testing

- The new fingerprint computation is a small pure function over already-known
  scalar inputs — unit-test it directly (not through
  `blueline_get_player_next_event()`'s own WP-Query-heavy resolution, which
  stays live-verified only per this codebase's established precedent).
- `assets/src/js/floating-next-game.test.mjs` equivalent coverage for the new
  event_id/fingerprint parsing and the three comparison outcomes above.
- Full local suite (`npm run check`) must stay green.
- Live verification on staging: reschedule a real event's time/venue for a
  claimed test account and confirm the "Updated" state appears in both
  surfaces, then confirm viewing the account card clears it for the widget too.
