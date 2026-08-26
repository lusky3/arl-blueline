# Account shell rebuild — design spec

## Context

This is sub-project 1 of a three-part `/account` redesign:

1. **Account shell rebuild** (this spec) — a theme-owned nav/shell replacing WooCommerce's default My Account chrome, a reorganized dashboard, and a mobile pass.
2. **Preferences page** (next spec) — a new tab holding linked-team (read-only, links to the existing claim flow), the appearance (theme) preference migrated off Edit Account, and a "show next game widget again" control.
3. **Player Profile tab** (final spec) — a new tab with the claimed player's bio (from `sp_player`: photo, jersey number, position) plus skill level, emergency contact name/number, position, jersey size, and gender, all read via `get_user_meta()` (WCFE Pro already syncs these six checkout fields to user meta). Date of birth joins this set once its own WCFE Pro "User Meta Data" checkbox is flipped and a one-time backfill script runs. Requested team and partner-request fields stay out of scope for all three sub-projects.

Each sub-project gets its own plan → build → review → merge cycle, in the order above, since 2 and 3 add tabs to the nav this spec establishes.

### Why YITH doesn't factor into this

Two separate YITH plugins were investigated during brainstorming:

- **YITH WooCommerce Customize My Account Page**: already fully replaced by this theme's own code (`inc/account/endpoints.php`, `inc/account/avatars.php`). No removal work — the only remaining trace is a legacy WP Admin Code Snippet renaming a tab label, a manual wp-admin cleanup task outside this codebase.
- **YITH Advanced Refund System**: stays active. It powers `woocommerce/myaccount/my-refund-requests.php` (the "Refund requests" tab) with its own data model, and there is no simpler native replacement for a self-service refund-request workflow. This spec does not touch it — see "Architecture" below for how its tab keeps working without any special handling.

## Goals

- Replace WooCommerce's default My Account nav/shell with a theme-owned one — no admin toggle, direct replacement.
- Modernize WooCommerce's stock chrome (nav, forms) to match this theme's existing card-based visual language.
- Tighten the dashboard's information hierarchy now that Preferences and Player Profile (sub-projects 2–3) will absorb some of what it currently carries.
- A dedicated mobile/responsive pass on the nav and dashboard grid.

## Non-goals

- Preferences page and Player Profile tab (their own specs).
- Any change to YITH Advanced Refund System or the refund-requests tab's own content/logic.
- Surfacing date of birth, requested team, or partner-request checkout fields (deferred; see sub-project 3 above).
- The legacy YITH WCMAP Code Snippet cleanup (a manual wp-admin task, not a code change).

## Architecture

WooCommerce aggregates every account tab — core tabs, this theme's own (`my-team`, `my-schedule`), and plugin-added ones (YITH ARS's `refund-requests`) — through the `woocommerce_account_menu_items` filter, consumable via `wc_get_account_menu_items()`. This shell rebuild adds exactly one new template override, `woocommerce/myaccount/navigation.php`, that consumes that already-aggregated list and renders it differently. It does not need to know about YITH ARS specifically, and a future plugin adding a new tab will appear in the list automatically — the only manual work per new tab is deciding whether it renders top-level or inside the Billing group (see below), which the template documents inline as a maintenance note.

### Nav rendering

- Every item from `wc_get_account_menu_items()` renders as a top-level pill tab, **except** the exact set of slugs `blueline_render_billing_group()` (`inc/account/dashboard.php`) lists today — those render inside one grouped "Billing" disclosure instead. Since `blueline_render_billing_group()` is removed from the dashboard entirely in this same change (see "Dashboard" below), its slug list moves wholesale into the new nav template as that list's sole owner going forward — the function itself is deleted, not left behind as a second copy of the list.
- `edit-account` (Account Details) and `customer-logout` (Log Out) are never grouped — always top-level pills, matching how they render today.
- The Billing group is a native `<details>/<summary>` disclosure — no JS, keyboard-accessible and screen-reader-friendly by default, degrades gracefully with JS disabled.

### Dashboard

- Primary row: the claim nudge when unclaimed; otherwise Next game (with its existing schedule-change "Updated" indicator) and My Team, side by side.
- Secondary row, smaller cards: Season stats and Registration status.
- The Billing link list (`blueline_render_billing_group()`) is removed from the dashboard entirely — it now lives only in the nav's Billing group, so the dashboard shows purely personal content, not a mix of personalization cards and account-admin links.

### Mobile

- The pill nav becomes a horizontally-scrollable strip on narrow screens — same markup, `overflow-x` handling matching this codebase's existing horizontal-scroll precedent (`table-scroll.js`/`standings-tabs.js`), not a second, dropdown-based mobile nav.
- Primary and secondary dashboard card rows both collapse to a single column below this theme's existing tablet breakpoint (match whatever `sportspress.css`/`account.css` already standardize on — don't introduce a new breakpoint value).

### WooCommerce form chrome

- Edit Account, Addresses, and Payment Methods (the pages WooCommerce still renders natively) get their inputs/selects/buttons restyled to the theme's existing card look, extending the current `woocommerce.css` overrides rather than rewriting them.

## Data flow

No new data model. This is a presentation-layer change consuming:
- `wc_get_account_menu_items()` for nav contents (existing WooCommerce API).
- The existing dashboard render functions already built this session (`blueline_account_render_next_game()`, `blueline_account_render_my_team()`, etc.) — reorganized, not rewritten.

No new user meta, no new endpoints, no new storage.

## Error handling / resilience

- The Billing disclosure and horizontal scroll are both native browser behavior — no JS dependency for baseline function.
- A new plugin-added tab appears in the nav automatically via the existing WooCommerce filter; the only manual step for a future maintainer is choosing top-level vs. Billing-group placement for that new slug, documented inline in the nav template.

## Testing

- One PHPUnit test covering the pure "which slugs belong in the Billing group" decision (extracted as a testable function, not inlined in the template).
- No new JS test coverage needed — `<details>` and horizontal scroll require no JS for their base behavior.
- Manual/visual QA across mobile, tablet, and desktop breakpoints, since this is primarily presentational work.

## Rollout

Staging (Staging-host) only, matching this repo's established scope. Build via a delegated implementer in an isolated worktree, task review, PR against `main`, merge on green CI, redeploy to staging, and live-verify — matching this session's established "Plan, Build, Review, Merge on Green CI" cadence.
