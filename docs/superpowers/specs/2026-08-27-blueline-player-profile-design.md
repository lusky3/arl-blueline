# Player Profile tab — design spec

## Context

This is sub-project 3, the last of the `/account` redesign arc. Sub-project 1 (shell rebuild) and sub-project 2 (Preferences page) are both merged. This spec adds a "Player Profile" tab holding the claimed player's bio (photo, jersey number, position, from `sp_player`) and six registration-checkout fields already confirmed to sync to user meta via WCFE Pro (WooCommerce Checkout Field Editor Pro): skill level, emergency contact name/number, position, jersey size, and gender.

### What's already decided, not open for re-litigation

- These six fields render **read-only**, with a link to Edit Account for changes — WCFE Pro already renders them as editable fields there (confirmed live on staging). This matches the exact "read-only + link elsewhere to change it" pattern sub-project 2 already established for linked-team.
- Date of birth, requested team, and partner-request checkout fields (all order-scoped, not user-meta-scoped) stay **out of scope** for this sub-project.
- No new backend resolver work — every field here is a plain `get_user_meta()` read against real, already-populated data (confirmed live: user 99's `arl_gender`, `arl_position`, `arl_division`, `arl_emergency_contact`, `arl_emergency_number`, `arl_jerseysize` are all full, human-readable strings like `"4 - Beginner – Intermediate"`, safe to render via `esc_html()` with no lookup table).

## Goals

- Give a claimed player a single place to see their own bio and registration details at a glance.
- Reuse existing helpers and idioms exhaustively — this sub-project adds zero new data-access patterns.

## Non-goals

- Any editing capability for the six registration fields (out of scope; Edit Account already has it).
- Date of birth, requested team, partner requests (deferred indefinitely, per prior decisions).
- Any change to `sp_player` itself, to the roster/team pages, or to the claim flow.

## Architecture

### New endpoint

Add to `blueline_account_endpoints()` (`inc/account/endpoints.php`):

```php
'player-profile' => array(
	'label' => __( 'Player Profile', 'blueline' ),
	'group' => 'league',
	'order' => 25,
),
```

`'league'` group (not a new one) — this is genuinely team/player content, matching `my-team`/`my-schedule`'s own group. `order: 25` sits it between `my-schedule` (20) and `preferences` (30), reflecting player-identity content preceding site-experience settings. As with `preferences`, no nav template change is needed — any non-`'billing'` group renders as a top-level pill automatically.

Rewrite endpoint and content-action registration follow the exact `my-team`/`my-schedule`/`preferences` pattern already established three times over in this codebase.

**Deploy note, carried forward from sub-project 2's live-verification finding**: `scripts/deploy-theme.sh` now runs `wp rewrite flush` automatically on staging deploys, so this new endpoint should work on first deploy without the manual flush sub-project 2 needed.

### Page content

One new file, `inc/account/player-profile.php`, following the established one-file-per-feature convention:

1. **Bio section**: claimed player's photo (`has_post_thumbnail( $player_id )` / `get_the_post_thumbnail( $player_id, 'thumbnail' )` — the exact idiom already used identically in three places in this codebase: `sportspress/team-lists.php:119-120,255-256` and `inc/sportspress.php:1456-1457`; no wrapper function exists and none should be invented here), jersey number (`blueline_player_jersey_number( $player_id )`, `inc/account/player-data.php:209-213`, reads `sp_number` post meta directly), and player name (`get_the_title( $player_id )`). CSS reuses `.bl-sp-roster__photo` (`sportspress.css:499-509`) — the established player-headshot styling, not `.bl-account-team__crest` (that's team-crest/badge styling, a different visual purpose).
2. **Registration details section**: the six `get_user_meta()` reads — skill level (`arl_division`), position (`arl_position`), jersey size (`arl_jerseysize`), gender (`arl_gender`), emergency contact name (`arl_emergency_contact`), emergency contact number (`arl_emergency_number`) — each rendered as a plain label/value pair, `esc_html()`'d directly (confirmed live: these are already complete, human-readable display strings, not codes needing a lookup table). A single "Edit these details" link to `/account/edit-account/` covers all six, rather than one link per field.

Both sections reuse `blueline_account_module_start()`/`_end()`/`_empty_state_html()` — the same card-chrome helpers every other account section already uses.

### Unclaimed-user handling

Like `my-team`/`my-schedule`, landing on this tab while unclaimed should not be a dead end: reuse the existing `blueline_account_render_claim_card( $user_id, 'player-profile' )` fallback (the same pattern `blueline_account_my_team_endpoint()`/`blueline_account_my_schedule_endpoint()` already use), which requires adding `'player-profile'` as a new context key to `blueline_claim_card_context_hint()` (`inc/account/dashboard.php`) with its own one-line hint string, matching the existing `'my-team'`/`'my-schedule'` cases exactly.

### What if the six user-meta fields are simply empty?

A real, not-hypothetical case: `bl-test-verify` (the synthetic test account) has none of these six keys set at all — only players who've gone through a real WCFE Pro checkout have them. `get_user_meta()` returns `''` for an unset key, not null, so each field needs its own "not provided" fallback text rather than rendering a blank value next to its label. This is a per-field empty state, not a whole-section empty state (unlike the bio section, which is either fully present, once a player is claimed, or the whole tab shows the claim card).

## Data flow

No new data model, no new storage, no new resolvers. Every read is either an existing player-data helper or a direct `get_user_meta()` call against already-populated WCFE Pro data.

## Error handling / resilience

- Bio section: `has_post_thumbnail()` false (no photo) renders a fallback mark, matching the existing `.bl-account-team__crest--fallback` idiom's *pattern* (leaf-mark fallback for a missing image) — reuse that same fallback visual treatment, adapted to the player-photo context rather than the team-crest one.
- Registration section: each empty field gets its own inline "not provided" text, not a whole-section empty state.
- Unclaimed user: the existing claim-card fallback, zero new code beyond a new context-hint string.

## Testing

- PHPUnit: endpoint registration (mirroring `AccountEndpointsTest.php`'s existing coverage), the new `'player-profile'` group in `AccountNavItemsTest.php` (mirroring the `'preferences'` test sub-project 2's final review added — this precedent is now established three times over and should not be skipped a fourth time), and the per-field "value or not-provided fallback" rendering logic as a pure, extracted function (following `blueline_preferences_team_summary()`'s own precedent from sub-project 2).
- No new JS — this tab is pure server-rendered content, no client-side interactivity.
- Manual/visual QA: bio section renders correctly for a real claimed test account with actual WCFE Pro data (not `bl-test-verify`, which has none — the plan must identify a real registrant account, e.g. the same user 99 checked during this spec's research, or find/create one on staging), the registration section's "not provided" fallback renders correctly for `bl-test-verify` (which has none of the six fields set), and the unclaimed-state claim card renders correctly.

## Rollout

Staging (Staging-host) only. Same cadence as sub-projects 1 and 2: subagent-driven-development execution, PR against `main`, merge on green CI, redeploy to staging (rewrite-flush now automatic), live-verify in a real browser using a real registrant account for the populated-data path and `bl-test-verify` for the empty-fields path.
