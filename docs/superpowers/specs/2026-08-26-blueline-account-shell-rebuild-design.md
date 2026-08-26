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

**Correction from this spec's original draft**: a nav template override, a grouping mechanism, and a demoted on-dashboard billing list all already exist in this codebase. This section describes the actual starting point and the delta this sub-project makes, not a from-scratch build.

Today: `woocommerce/myaccount/navigation.php` already overrides WooCommerce's default nav. It calls `blueline_account_nav_items()` (`inc/account/dashboard.php`), which tags each item from `wc_get_account_menu_items()` with a `group` read from `blueline_account_endpoints()` (`inc/account/endpoints.php`) — `'league'` for `my-team`/`my-schedule`, `'billing'` for `registrations`/`store-credit`/`refund-requests`/`payment-methods`/`edit-address`/`edit-account`, and `null` for the two WooCommerce-owned items (`dashboard`, `customer-logout`) that aren't in that map at all. The template splits the ordered list into contiguous same-group runs and renders each as its own `<ul>`, with an `<h2>` heading for the two named groups (`'My League'`, `'Account & Billing'`) and no heading for `null`-group runs. `wc_get_account_menu_items()` is what aggregates plugin-added tabs (YITH ARS's `refund-requests`) into this list in the first place — that part of the original spec's reasoning holds, it just already exists rather than needing to be added.

Separately, `inc/account/dashboard.php`'s `blueline_account_render_billing_group()` renders the SAME billing-group endpoints again, as a plain link list, inline on the dashboard page itself (below the personalization cards) — this is the "demoted quiet link list" referenced elsewhere in this spec.

### The actual delta

1. **Move `edit-account` out of the Billing group**, into its own explicit `'account'` group (not `null` — a distinct, self-documenting value, rather than relying on it happening to land adjacent to `customer-logout`'s `null` group in the final ordered list). `tests/AccountEndpointsTest.php`'s `test_every_endpoint_has_a_label()` currently asserts every endpoint's group is exactly `'league'` or `'billing'`; it needs to allow `'account'` too.
2. **Nav moves from a 240px left sidebar to a horizontal top bar**, at every breakpoint — not pill-styled items still living in the existing sidebar column. This replaces `body.woocommerce-account .bl-main--woocommerce .bl-container`'s current `grid-template-columns: 240px minmax(0, 1fr)` (≥900px) with a single-column layout: nav row, then content below. This is what the approved Option B mockup showed and what "pill nav" means throughout this spec — every top-level item (`dashboard`, `'league'`, `'account'` groups) renders as a pill in that horizontal bar.
3. **Convert the Billing run to a `<details>/<summary>` disclosure**, sitting inline in that same top bar rather than as a separate vertical list. Today it's an always-visible `<ul>` with an `<h2>` heading; this becomes a collapsed-by-default disclosure using the same heading text as its `<summary>`. The `'league'`-group items lose their own `<h2>` ("My League") entirely — they become plain pills like every other top-level item, since a heading has no clear home in a horizontal bar the way it did in a vertical sidebar list.
4. **Mobile**: the pill bar scrolls horizontally rather than wrapping, reusing the existing `.bl-table-scroll` class and its edge-fade JS/CSS (`assets/src/js/table-scroll.js`'s `CONTAINERS` list, `sportspress.css`'s `[data-fade-start]`/`[data-fade-end]` mask rules) rather than building a new scroll affordance from scratch.
5. **Delete `blueline_account_render_billing_group()`** (`inc/account/dashboard.php`) and its call site in `woocommerce/myaccount/dashboard.php`. The dashboard no longer renders a second copy of the billing links — they exist only in the nav's Billing disclosure now.

### Dashboard

`woocommerce/myaccount/dashboard.php` currently calls the render functions in a flat sequence: claim notice, then either (next-game, my-team, season-stats) or the claim card, then registration, then the billing group. This becomes two wrapped groups:

- **Primary row**: the claim nudge when unclaimed; otherwise Next game (with its existing schedule-change "Updated" indicator) and My Team, side by side.
- **Secondary row**, smaller cards: Season stats and Registration status. Registration renders here regardless of claim status (unchanged from today — it's not gated on having a linked player).
- The billing render call is deleted outright (see delta item 5 above), not moved into either row.

### WooCommerce form chrome

Already substantially done sitewide: `assets/src/css/woocommerce.css` themes `.form-row`/`.input-text`/`select`/`textarea` and `.woocommerce a.button`/`button.button`/`input.button` with no page-specific scoping, so Edit Account, Addresses, and Payment Methods already inherit this theme's card-based input and button styling today. This sub-project's form-chrome work is a live-verification pass against those three pages on staging, not a blind restyle — fix only whatever a real gap turns up.

## Data flow

No new data model. This is a presentation-layer change consuming:
- `wc_get_account_menu_items()` for nav contents (existing WooCommerce API), via the existing `blueline_account_nav_items()` helper.
- The existing dashboard render functions already built this session (`blueline_account_render_next_game()`, `blueline_account_render_my_team()`, etc.) — reorganized, not rewritten.

No new user meta, no new endpoints, no new storage.

## Error handling / resilience

- The Billing disclosure and horizontal scroll are both native browser behavior — no JS dependency for baseline function.
- A new plugin-added tab appears in the nav automatically via the existing WooCommerce filter; the only manual step for a future maintainer is choosing which group (`'league'`, `'billing'`, `'account'`, or none) a new slug belongs to in `blueline_account_endpoints()` — `blueline_account_nav_items()` and the nav template need no changes for a new tab to appear correctly.

## Testing

- `tests/AccountEndpointsTest.php`: update `test_every_endpoint_has_a_label()` to allow the new `'account'` group value, and add a test asserting `edit-account`'s group is `'account'`, not `'billing'`.
- No new JS test coverage needed — `<details>` and horizontal scroll (via the existing `.bl-table-scroll` mechanism) require no JS for their base behavior.
- Manual/visual QA across mobile, tablet, and desktop breakpoints, and of Edit Account/Addresses/Payment Methods chrome, since this is primarily presentational work.

## Rollout

Staging (Staging-host) only, matching this repo's established scope. Build via a delegated implementer in an isolated worktree, task review, PR against `main`, merge on green CI, redeploy to staging, and live-verify — matching this session's established "Plan, Build, Review, Merge on Green CI" cadence.
