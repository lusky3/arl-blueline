# Preferences page — design spec

## Context

This is sub-project 2 of the `/account` redesign arc. Sub-project 1 (account shell rebuild — the horizontal pill nav, Billing dropdown, dashboard hierarchy) is merged. This spec adds a new "Preferences" tab holding everything that affects the site experience rather than account/billing administration: the claimed player/team (read-only), the appearance (theme) preference, and a way to bring back the floating next-game widget after dismissing it.

Originally brainstormed as part of the wider `/account` redesign conversation, before sub-project 1 shipped and before PR #27 (the sitewide footer theme toggle) shipped. Both changed the ground this spec builds on — see "Corrections from the original brainstorm" below.

### Corrections from the original brainstorm

1. **No self-service way to change a linked player exists.** The original framing was "read-only display of the claimed player/team, plus a link into the existing claim flow to change it." `blueline_link_player_to_user()` (`inc/account/player-link.php`) explicitly rejects re-linking an already-linked account (`user_already_linked` error), and the claim card UI only ever renders for an unlinked user — there is no code path for a linked user to unlink or re-claim. Building one is real new scope nobody asked for. This spec instead matches the claim card's own existing pattern for "no candidates found": read-only display, plus a "contact the league" link (via `blueline_contact_url()`) for anyone who needs to change it.
2. **The theme toggle already has a reusable PHP renderer, but its JS isn't safely reusable yet.** PR #27 built `blueline_render_theme_toggle()` (`inc/account/theme-preference.php`) as a real, callable-from-anywhere render function, and a shared AJAX save path (`wp_ajax_blueline_save_theme_preference`) — so Preferences does not need a second theme-preference save mechanism. But `footer-theme-toggle.js`'s `initFooterThemeToggle()` selects its target element with `document.querySelector('[data-bl-theme-toggle]')` — singular, first-match-only. Since the footer renders on every page including Preferences, a second `blueline_render_theme_toggle()` call would find its markup rendered but never wired up. This spec includes fixing that JS to initialize every matching instance, not just the first.
3. **"Clear localStorage keys" (plural) simplifies to one key.** `blueline:next-game-dismissed` governs the widget's visibility; `blueline:next-game-seen` only governs whether the "Updated since you last checked" indicator shows. A "show widget again" control only needs to clear the dismissed key.

## Goals

- One discoverable page for every setting that affects how the site looks/behaves for a signed-in player: linked team (read-only), appearance, and next-game widget visibility.
- Reuse existing mechanisms (the theme-preference AJAX path, the existing team-lookup helper, the existing localStorage keys) rather than building parallel ones.
- Remove the theme-preference field from Edit Account now that it has a better, more central home.

## Non-goals

- Any new unlink/re-claim capability (see correction 1 above) — out of scope for this spec entirely.
- Any change to the claim card, the dashboard, or the nav shell beyond registering one new endpoint.
- DOB/requested-team/partner-request checkout-field surfacing (that's sub-project 3's scope, not this one).

## Architecture

### New endpoint

Add to `blueline_account_endpoints()` (`inc/account/endpoints.php`):

```php
'preferences' => array(
	'label' => __( 'Preferences', 'blueline' ),
	'group' => 'preferences',
	'order' => 30,
),
```

`'preferences'` is a new, distinct group value — not reused from `'league'`/`'billing'`/`'account'`. This costs nothing structurally: `navigation.php` already renders any non-`'billing'` group as a top-level pill with no per-group template logic, confirmed in sub-project 1's final state. `order: 30` places it right after `my-schedule` (20) and before the billing-group endpoints (50+), reflecting that this is personalization content, not account administration — consistent with the dashboard's own "league content leads, billing trails" ordering principle.

A new WooCommerce rewrite endpoint (`add_rewrite_endpoint( 'preferences', EP_ROOT | EP_PAGES )`) and its content-rendering action (`add_action( 'woocommerce_account_preferences_endpoint', ... )`) follow the exact pattern `my-team`/`my-schedule` already established in this same file.

### Page content

Three sections, each its own small render function in a new `inc/account/preferences.php` file (matching this codebase's established one-file-per-feature convention — `theme-preference.php`, `player-link.php`, `avatars.php` are all this shape):

1. **Linked team** — read-only. Reads `blueline_current_user_player_id()`, then `blueline_get_player_team()` (`inc/account/player-data.php`) for `{name, division, logo_id, number}` — reusing the existing data helper, not `blueline_account_render_my_team()`'s full roster/record rendering, which is more than a settings summary needs. Displays team name, division, jersey number. If unclaimed, shows the same messaging pattern the claim card already uses for its own empty state (a `blueline_contact_url()` link), since Preferences shouldn't duplicate the claim flow itself — an unclaimed user lands on the dashboard's claim card first anyway.
2. **Appearance** — calls `blueline_render_theme_toggle()` directly (existing function, no new PHP). Requires the JS fix below to actually work.
3. **Next game widget** — a single button, "Show next game widget again," visible unconditionally (no state to check server-side, since dismissal is client-only). Clicking it clears `localStorage['blueline:next-game-dismissed']` and gives on-page confirmation feedback (a brief inline message, not a page reload) via a new small JS module.

### JS changes

- `footer-theme-toggle.js`: change `initFooterThemeToggle()`'s `document.querySelector('[data-bl-theme-toggle]')` to `document.querySelectorAll(...)` with the existing init logic wrapped in a loop over every match, so both the footer's instance and a Preferences-page instance each get wired up independently. No change to the AJAX/localStorage logic itself — only the element-selection and initialization loop.
- New `assets/src/js/preferences-widget-reset.js`: a small, self-contained module. Reads a `[data-bl-widget-reset]` button, on click calls `localStorage.removeItem('blueline:next-game-dismissed')` wrapped in try/catch (matching this codebase's established localStorage-access convention), and reveals a `[hidden]` confirmation message sibling element. No new markup convention — reuses the "render unconditionally, `hidden` attribute, JS reveals" pattern already used for the next-game "Updated" indicator.

### Edit Account cleanup

Remove `blueline_render_theme_preference_field()` (the `woocommerce_edit_account_form` hook) and `blueline_save_theme_preference()` (the `woocommerce_save_account_details` hook) from `inc/account/theme-preference.php`, since the setting now lives on Preferences (and the footer). `blueline_get_theme_preference()`, `blueline_persist_theme_preference()`, the AJAX handler, and `blueline_render_theme_toggle()` are all untouched — those are shared infrastructure the footer toggle and Preferences both depend on.

## Data flow

No new storage. Everything reads/writes through mechanisms that already exist:
- Theme preference: existing user meta key + existing AJAX handler.
- Linked team: existing `sp_user` player-meta link, read via existing helpers.
- Widget visibility: existing `localStorage` key, client-side only.

## Error handling / resilience

- Preferences page itself requires no JS to render correctly (all three sections render their current state server-side or unconditionally).
- The theme toggle degrades exactly as it already does elsewhere (guest vs. logged-in branching is irrelevant here — Preferences is only reachable when logged in, so this is always the "account" AJAX-backed mode, never the guest localStorage mode).
- The widget-reset button's `localStorage` access is try/catch-wrapped; a failure means the click silently does nothing rather than erroring, matching the codebase's established convention for this class of feature.

## Testing

- PHPUnit: endpoint registration (mirroring existing `AccountEndpointsTest.php` coverage for `my-team`/`my-schedule`), and the linked-team read-only render function's empty/unclaimed-state branch.
- JS: a `.test.mjs` for `footer-theme-toggle.js`'s updated multi-instance init logic (pure function extraction, following `floating-next-game.test.mjs`'s established pattern), and one for the new widget-reset module's pure "what to clear" logic.
- Manual/visual QA: the new pill appears correctly in the nav; the theme toggle on Preferences and in the footer both work and stay in sync (since they share one AJAX endpoint and one user-meta value); the widget-reset button actually brings the dismissed widget back on the next page load.

## Rollout

Staging (Staging-host) only. Same cadence as sub-project 1: subagent-driven-development execution, PR against `main`, merge on green CI, redeploy to staging, live-verify in a real browser (not curl alone — sub-project 1's final review found real bugs curl-based checks couldn't catch).
