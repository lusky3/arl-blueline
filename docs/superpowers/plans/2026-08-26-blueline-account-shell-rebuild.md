# Account Shell Rebuild Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the My Account page's 240px left-sidebar nav with a horizontal pill bar (a collapsible "Account & Billing" disclosure inline in that bar), reorganize the dashboard into a primary/secondary card hierarchy, and verify WooCommerce's stock form chrome on the pages it still renders natively.

**Architecture:** This theme already has a nav template override (`woocommerce/myaccount/navigation.php`), a group-tagging helper (`blueline_account_nav_items()`), and a group-config source of truth (`blueline_account_endpoints()`). This plan changes one endpoint's group assignment, rewrites the nav template's markup/CSS from a vertical sidebar list to a horizontal pill bar with billing collapsed into a `<details>` dropdown, reorganizes the dashboard template into two card rows, and deletes the now-redundant on-dashboard billing link list. No new data model, no new endpoints.

**Tech Stack:** PHP 8.3 / WordPress / WooCommerce (theme `rookiehockey-blueline`, root `themes/blueline/`), PHPUnit, plain CSS (no preprocessor), vanilla JS (webpack-bundled).

**Spec:** `docs/superpowers/specs/2026-08-26-blueline-account-shell-rebuild-design.md`

## Global Constraints

- Staging (Staging-host) only — this repo never targets production.
- Run `composer lint`, `./vendor/bin/phpunit`, and `npm run check` before every commit that touches PHP, CSS, or JS; all must pass.
- Follow this session's established cadence: PR against `main`, merge only on green CI, then redeploy to staging and live-verify.
- No admin toggle between old and new shell — this is a direct replacement.
- Do not touch YITH Advanced Refund System, `woocommerce/myaccount/my-refund-requests.php`, or any Preferences/Player Profile work (separate specs).

---

### Task 1: Give `edit-account` its own `'account'` nav group

**Files:**
- Modify: `themes/blueline/inc/account/endpoints.php:62-66`
- Modify: `themes/blueline/tests/AccountEndpointsTest.php:130-135`
- Create: `themes/blueline/tests/AccountNavItemsTest.php`

**Interfaces:**
- Consumes: `blueline_account_endpoints(): array<string, array{label:string, group:string, order:int}>` (existing, `inc/account/endpoints.php`); `blueline_account_nav_items( array $menu_items ): array<int, array{endpoint:string, label:string, group:?string}>` (existing, `inc/account/dashboard.php`).
- Produces: `edit-account`'s config now has `'group' => 'account'` instead of `'group' => 'billing'`. Task 2 depends on this — its nav template must NOT render `edit-account` inside the Billing disclosure.

- [ ] **Step 1: Write the failing test for `blueline_account_nav_items()`**

Create `themes/blueline/tests/AccountNavItemsTest.php`:

```php
<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see inc/template-tags.php's identical disable.
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/endpoints.php';
require_once __DIR__ . '/../inc/account/dashboard.php';

/**
 * Covers blueline_account_nav_items() -- previously untested despite backing
 * the live nav template (woocommerce/myaccount/navigation.php).
 */
final class AccountNavItemsTest extends TestCase {

	/**
	 * dashboard and customer-logout are WooCommerce-owned menu items with no
	 * entry in blueline_account_endpoints() -- they must tag as group null,
	 * not throw or silently vanish.
	 */
	public function test_woocommerce_owned_items_get_a_null_group(): void {
		$items = blueline_account_nav_items(
			array(
				'dashboard'       => 'Dashboard',
				'customer-logout' => 'Log out',
			)
		);

		$this->assertSame( 'dashboard', $items[0]['endpoint'] );
		$this->assertNull( $items[0]['group'] );
		$this->assertSame( 'customer-logout', $items[1]['endpoint'] );
		$this->assertNull( $items[1]['group'] );
	}

	/**
	 * edit-account must carry the 'account' group -- not 'billing' -- so the
	 * nav template can render it as a top-level pill, never inside the
	 * Billing disclosure.
	 */
	public function test_edit_account_carries_the_account_group(): void {
		$items = blueline_account_nav_items( array( 'edit-account' => 'Account Details' ) );

		$this->assertSame( 'account', $items[0]['group'] );
	}

	/**
	 * A real billing endpoint still carries the 'billing' group.
	 */
	public function test_a_billing_endpoint_carries_the_billing_group(): void {
		$items = blueline_account_nav_items( array( 'edit-address' => 'Addresses' ) );

		$this->assertSame( 'billing', $items[0]['group'] );
	}

	/**
	 * Order and label pass through unchanged from $menu_items -- this
	 * function only adds the group tag, it never reorders or relabels.
	 */
	public function test_order_and_labels_pass_through_unchanged(): void {
		$items = blueline_account_nav_items(
			array(
				'dashboard' => 'Dashboard',
				'my-team'   => 'My Team',
			)
		);

		$this->assertSame( array( 'dashboard', 'my-team' ), array_column( $items, 'endpoint' ) );
		$this->assertSame( array( 'Dashboard', 'My Team' ), array_column( $items, 'label' ) );
	}
}
```

- [ ] **Step 2: Run the new test file to verify it fails**

Run: `./vendor/bin/phpunit --filter AccountNavItemsTest`
Expected: FAIL on `test_edit_account_carries_the_account_group` (currently returns `'billing'`, not `'account'`) — the other three tests should already pass since they don't touch `edit-account`.

- [ ] **Step 3: Update the existing group-enumeration test**

In `themes/blueline/tests/AccountEndpointsTest.php`, find `test_every_endpoint_has_a_label()` (~line 130-135). It currently asserts:

```php
$this->assertContains( $cfg['group'], array( 'league', 'billing' ) );
```

Change to:

```php
$this->assertContains( $cfg['group'], array( 'league', 'billing', 'account' ) );
```

Add a new test directly after it in the same file:

```php
	/**
	 * P1 sub-project (account shell rebuild): edit-account moved out of the
	 * Billing group so it renders as a top-level nav pill, not inside the
	 * Billing disclosure.
	 */
	public function test_edit_account_is_not_in_the_billing_group(): void {
		$e = blueline_account_endpoints();

		$this->assertSame( 'account', $e['edit-account']['group'] );
	}
```

- [ ] **Step 4: Run the updated/new tests to verify they still fail**

Run: `./vendor/bin/phpunit --filter "AccountEndpointsTest|AccountNavItemsTest"`
Expected: FAIL — `test_edit_account_is_not_in_the_billing_group` and `test_edit_account_carries_the_account_group` both fail against current code.

- [ ] **Step 5: Make the config change**

In `themes/blueline/inc/account/endpoints.php`, change the `'edit-account'` entry (~line 62-66):

```php
		'edit-account'    => array(
			'label' => __( 'Account Details', 'blueline' ),
			'group' => 'billing',
			'order' => 100,
		),
```

to:

```php
		'edit-account'    => array(
			'label' => __( 'Account Details', 'blueline' ),
			'group' => 'account',
			'order' => 100,
		),
```

- [ ] **Step 6: Run the full endpoints/nav test suite to verify it passes**

Run: `./vendor/bin/phpunit --filter "AccountEndpointsTest|AccountNavItemsTest"`
Expected: PASS, all tests.

- [ ] **Step 7: Run the full PHPUnit suite to check for regressions**

Run: `./vendor/bin/phpunit`
Expected: PASS. Pay particular attention to any test that iterates `blueline_account_endpoints()` expecting only `'league'`/`'billing'` groups elsewhere in the suite (grep first: `grep -rn "'league', 'billing'" tests/`) — fix any other hardcoded two-value group list the same way as Step 3.

- [ ] **Step 8: Commit**

```bash
git add themes/blueline/inc/account/endpoints.php themes/blueline/tests/AccountEndpointsTest.php themes/blueline/tests/AccountNavItemsTest.php
git commit -m "feat(blueline): give edit-account its own account nav group

Moves edit-account out of the billing group so the account-shell nav
rebuild can render it as a top-level pill instead of inside the Billing
disclosure. Adds the first dedicated test coverage for
blueline_account_nav_items(), previously untested."
```

---

### Task 2: Rebuild the nav as a horizontal pill bar with a Billing disclosure

**Files:**
- Modify: `themes/blueline/woocommerce/myaccount/navigation.php` (full rewrite)
- Modify: `themes/blueline/assets/src/css/account.css:20-90` (nav section) and `:453-492` (delete the billing-card CSS)
- Modify: `themes/blueline/assets/src/js/table-scroll.js:38-41` (add the nav pill list to `CONTAINERS`)
- Modify: `themes/blueline/woocommerce/myaccount/dashboard.php:45` (delete the billing-group call site)
- Modify: `themes/blueline/inc/account/dashboard.php` (delete `blueline_account_render_billing_group()`, ~line 902-960)

**Interfaces:**
- Consumes: `blueline_account_nav_items()` (Task 1's `'account'` group), `wc_get_account_endpoint_url()`, `wc_get_account_menu_item_classes()` (all existing WooCommerce/theme functions, unchanged signatures).
- Produces: nothing new consumed by later tasks — this is a leaf, presentational change.

**Design note carried from the spec's self-review**: the horizontally-scrolling pill list and the Billing `<details>` dropdown must NOT share one `overflow-x: auto` container. This codebase's own `.sp-league-table` CSS already documents why: setting `overflow-x` to anything but `visible` forces the browser to compute `overflow-y` as `auto` too, even if you explicitly set `overflow-y: visible` — there is no per-axis escape. If the Billing `<details>`'s expanding panel lived inside the scrolling pill list, that forced vertical `auto`/clip would cut the open panel off. So the pill list and the Billing disclosure are **siblings** inside `<nav>`, not parent/child — only the pill list scrolls; the Billing disclosure always stays fully visible and its panel opens via `position: absolute`, entirely outside the scrolling element's box.

- [ ] **Step 1: Rewrite the nav template**

Replace the full contents of `themes/blueline/woocommerce/myaccount/navigation.php`:

```php
<?php
/**
 * The Blue Line My Account nav: a horizontal row of pill tabs, with the
 * billing group collapsed into a <details> dropdown that sits alongside
 * (not inside) the scrolling pill row -- see this file's own inline note
 * on why those two must be siblings, not parent/child. Overrides
 * WooCommerce's own generic, ungrouped `myaccount/navigation.php`.
 *
 * wc_get_account_menu_items() already aggregates every account tab --
 * core, this theme's own, and plugin-added ones (e.g. YITH Advanced Refund
 * System's 'refund-requests') -- through the `woocommerce_account_menu_items`
 * filter chain. blueline_account_nav_items() (inc/account/dashboard.php)
 * tags each item with the 'group' blueline_account_endpoints()
 * (inc/account/endpoints.php) assigns its slug: 'league', 'billing',
 * 'account', or null for the two WooCommerce-owned items not in that map
 * (dashboard, customer-logout). A future plugin adding a new tab needs no
 * change here -- it appears automatically; only its group in
 * blueline_account_endpoints() decides whether it renders as a top-level
 * pill or inside the Billing dropdown.
 *
 * The pill row reuses .bl-table-scroll (assets/src/js/table-scroll.js,
 * sportspress.css's [data-fade-start]/[data-fade-end] mask rules) for its
 * mobile horizontal-scroll edge cue -- the same mechanism this theme
 * already uses for wide tables, not a new scroll affordance.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_navigation' );

$blueline_nav_items = function_exists( 'blueline_account_nav_items' )
	? blueline_account_nav_items( wc_get_account_menu_items() )
	: array();

$blueline_pill_items    = array_values( array_filter( $blueline_nav_items, static fn( $item ) => 'billing' !== $item['group'] ) );
$blueline_billing_items = array_values( array_filter( $blueline_nav_items, static fn( $item ) => 'billing' === $item['group'] ) );
?>
<nav class="woocommerce-MyAccount-navigation bl-account-nav" aria-label="<?php esc_attr_e( 'Account', 'blueline' ); ?>">
	<ul class="bl-account-nav__pills bl-table-scroll">
		<?php foreach ( $blueline_pill_items as $blueline_nav_item ) : ?>
			<li class="<?php echo esc_attr( wc_get_account_menu_item_classes( $blueline_nav_item['endpoint'] ) ); ?>">
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( $blueline_nav_item['endpoint'] ) ); ?>">
					<?php echo esc_html( $blueline_nav_item['label'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $blueline_billing_items ) : ?>
		<details class="bl-account-nav__billing">
			<summary><?php esc_html_e( 'Account & Billing', 'blueline' ); ?></summary>
			<ul class="bl-account-nav__billing-panel">
				<?php foreach ( $blueline_billing_items as $blueline_nav_item ) : ?>
					<li class="<?php echo esc_attr( wc_get_account_menu_item_classes( $blueline_nav_item['endpoint'] ) ); ?>">
						<a href="<?php echo esc_url( wc_get_account_endpoint_url( $blueline_nav_item['endpoint'] ) ); ?>">
							<?php echo esc_html( $blueline_nav_item['label'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
</nav>
<?php

do_action( 'woocommerce_after_account_navigation' );

/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */
```

- [ ] **Step 2: Replace the nav CSS section**

In `themes/blueline/assets/src/css/account.css`, replace lines 20-90 (from `body.woocommerce-account .bl-main--woocommerce .bl-container {` through the end of the `.bl-account-nav__list li.is-active a` rule) with the block below. This deliberately drops the old `.woocommerce-MyAccount-navigation { position: sticky; }` rule (originally lines 42-49) rather than carrying it forward: that rule made sense for a tall vertical sidebar staying in view while a long dashboard scrolled past it, but the nav is now a single horizontal bar at the top of the page, where sticking it to the viewport would mean it competes for vertical space with the site's own sticky header. If a live check in Task 4 finds the nav scrolling out of view is a real problem, that's a `position: sticky` addition to make deliberately then, with the site header's height accounted for in `top` — not something to guess at here.

```css
body.woocommerce-account .bl-main--woocommerce .bl-container {
	padding-block: var(--bl-space-6) var(--bl-space-8);
}

/* ---------------------------------------------------------------------
 * Nav bar -- a horizontal row of pills, with the Billing group collapsed
 * into a <details> dropdown that sits alongside (not inside) the scrolling
 * pill row. See woocommerce/myaccount/navigation.php's own docblock for
 * why those two must be siblings: overflow-x: auto on a shared container
 * would force overflow-y to auto too, clipping the open Billing panel.
 * ------------------------------------------------------------------- */

.bl-account-nav {
	display: flex;
	align-items: center;
	gap: var(--bl-space-3);
	margin-block-end: var(--bl-space-6);
}

.bl-account-nav__pills {
	display: flex;
	flex-wrap: nowrap;
	align-items: center;
	gap: var(--bl-space-2);
	margin: 0;
	padding-block: var(--bl-space-1);
	list-style: none;
	flex: 1 1 auto;
	min-width: 0;
	overflow-x: auto;
	-webkit-overflow-scrolling: touch;
}

.bl-account-nav__pills li {
	flex: 0 0 auto;
}

.bl-account-nav__pills a {
	display: inline-block;
	white-space: nowrap;
	padding: var(--bl-space-2) var(--bl-space-4);
	border-radius: 999px;
	color: var(--bl-content-text);
	text-decoration: none;
}

.bl-account-nav__pills a:hover,
.bl-account-nav__pills a:focus-visible {
	background: var(--bl-surface-sunken);
}

.bl-account-nav__pills li.is-active a {
	background: var(--bl-ice);
	color: var(--bl-ink);
	font-weight: 600;
}

.bl-account-nav__billing {
	position: relative;
	flex: 0 0 auto;
}

.bl-account-nav__billing summary {
	list-style: none;
	cursor: pointer;
	white-space: nowrap;
	padding: var(--bl-space-2) var(--bl-space-4);
	border-radius: 999px;
	color: var(--bl-content-text);
}

.bl-account-nav__billing summary::-webkit-details-marker {
	display: none;
}

.bl-account-nav__billing summary:hover,
.bl-account-nav__billing summary:focus-visible {
	background: var(--bl-surface-sunken);
}

.bl-account-nav__billing[open] summary {
	background: var(--bl-ice);
	color: var(--bl-ink);
	font-weight: 600;
}

.bl-account-nav__billing-panel {
	position: absolute;
	inset-block-start: 100%;
	inset-inline-end: 0;
	z-index: 1;
	margin: var(--bl-space-2) 0 0;
	padding: var(--bl-space-2);
	min-width: 12rem;
	list-style: none;
	background: var(--bl-content-surface);
	border: 1px solid var(--bl-content-border);
	border-radius: var(--bl-radius-card);
	box-shadow: var(--bl-shadow-card);
}

.bl-account-nav__billing-panel li + li {
	border-block-start: 1px solid var(--bl-content-border);
}

.bl-account-nav__billing-panel a {
	display: block;
	padding: var(--bl-space-2) var(--bl-space-3);
	color: var(--bl-content-text);
	text-decoration: none;
	border-radius: var(--bl-radius);
	white-space: nowrap;
}

.bl-account-nav__billing-panel a:hover,
.bl-account-nav__billing-panel a:focus-visible {
	background: var(--bl-surface-sunken);
}

.bl-account-nav__billing-panel li.is-active a {
	background: var(--bl-ice);
	color: var(--bl-ink);
	font-weight: 600;
}
```

This removes the old `≥900px` two-column grid (`grid-template-columns: 240px minmax(0, 1fr)`) and the old `.bl-account-nav__group-title`/`.bl-account-nav__list`/`.bl-account-nav__list--plain` rules entirely — the nav is a single horizontal bar at every breakpoint now, not a sidebar that only collapses below 900px.

- [ ] **Step 3: Delete the now-dead billing-card CSS**

In `themes/blueline/assets/src/css/account.css`, delete the entire `/* Account & billing */` section (the `.bl-account-module--billing` and `.bl-account-billing__list` rules, originally ~line 453-485 before Step 2's edit shifts line numbers — locate by content, not line number, since Step 2 already changed the file's length). Search for `.bl-account-module--billing` and remove that whole comment block plus both rule sets.

- [ ] **Step 4: Add the pill row to `table-scroll.js`'s scroll-fade `CONTAINERS`**

In `themes/blueline/assets/src/js/table-scroll.js`, find the `CONTAINERS` constant (~line 38-41):

```javascript
const CONTAINERS = [
	'.bl-table-scroll',
	'.sp-scrollable-table-wrapper',
	'table.bl-table-self-scroll',
].join( ',' );
```

No change needed here — `.bl-account-nav__pills` already carries the `.bl-table-scroll` class from Step 1's markup, so it's already covered by the existing `'.bl-table-scroll'` selector. Confirm this by reading the file; do not add a redundant selector.

- [ ] **Step 5: Delete `blueline_account_render_billing_group()` and its call site**

In `themes/blueline/inc/account/dashboard.php`, delete the entire `blueline_account_render_billing_group()` function and its preceding docblock (search for `function blueline_account_render_billing_group`, ~line 902-960).

In `themes/blueline/woocommerce/myaccount/dashboard.php`, delete line 45:

```php
blueline_account_render_billing_group();
```

- [ ] **Step 6: Manually verify no other caller references the deleted function**

Run: `grep -rn "blueline_account_render_billing_group\|bl-account-module--billing\|bl-account-billing__list" themes/blueline/`
Expected: no matches (Step 3 and Step 5 should have removed every reference).

- [ ] **Step 7: Build and run the full check suite**

Run: `cd themes/blueline && npm run build && npm run check && composer lint && ./vendor/bin/phpunit`
Expected: all green. `npm run build` must succeed with no missing-selector or syntax errors before `npm run check`'s CSS lint runs against the built output.

- [ ] **Step 8: Commit**

```bash
git add themes/blueline/woocommerce/myaccount/navigation.php themes/blueline/woocommerce/myaccount/dashboard.php themes/blueline/assets/src/css/account.css themes/blueline/inc/account/dashboard.php themes/blueline/assets/dist
git commit -m "feat(blueline): rebuild the account nav as a horizontal pill bar

Replaces the 240px sidebar nav with a horizontal pill row; the Billing
group collapses into a <details> dropdown that sits alongside (not
inside) the scrolling pill list, avoiding the overflow-x/overflow-y
clipping conflict this codebase already hit once with .sp-league-table.
Deletes the now-redundant on-dashboard billing link list -- Billing
links live only in the nav now."
```

---

### Task 3: Reorganize the dashboard into primary/secondary card rows

**Files:**
- Modify: `themes/blueline/woocommerce/myaccount/dashboard.php:29-45`
- Modify: `themes/blueline/assets/src/css/account.css` (add a new grid section)

**Interfaces:**
- Consumes: `blueline_account_render_next_game()`, `blueline_account_render_my_team()`, `blueline_account_render_season_stats()`, `blueline_account_render_registration()`, `blueline_account_render_claim_card()`, `blueline_account_render_claim_notice()` — all existing, unchanged signatures (`inc/account/dashboard.php`).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Wrap the render calls in primary/secondary containers**

Replace lines 29-45 of `themes/blueline/woocommerce/myaccount/dashboard.php`:

```php
blueline_account_render_claim_notice();

$blueline_dashboard_user_id   = get_current_user_id();
$blueline_dashboard_player_id = function_exists( 'blueline_get_linked_player_id' )
	? blueline_get_linked_player_id( $blueline_dashboard_user_id )
	: null;

if ( $blueline_dashboard_player_id ) {
	blueline_account_render_next_game( $blueline_dashboard_player_id );
	blueline_account_render_my_team( $blueline_dashboard_player_id );
	blueline_account_render_season_stats( $blueline_dashboard_player_id );
} else {
	blueline_account_render_claim_card( $blueline_dashboard_user_id );
}

blueline_account_render_registration( $blueline_dashboard_user_id, $blueline_dashboard_player_id );
blueline_account_render_billing_group();
```

with:

```php
blueline_account_render_claim_notice();

$blueline_dashboard_user_id   = get_current_user_id();
$blueline_dashboard_player_id = function_exists( 'blueline_get_linked_player_id' )
	? blueline_get_linked_player_id( $blueline_dashboard_user_id )
	: null;
?>
<div class="bl-account-dashboard__primary">
	<?php
	if ( $blueline_dashboard_player_id ) {
		blueline_account_render_next_game( $blueline_dashboard_player_id );
		blueline_account_render_my_team( $blueline_dashboard_player_id );
	} else {
		blueline_account_render_claim_card( $blueline_dashboard_user_id );
	}
	?>
</div>
<div class="bl-account-dashboard__secondary">
	<?php
	if ( $blueline_dashboard_player_id ) {
		blueline_account_render_season_stats( $blueline_dashboard_player_id );
	}
	blueline_account_render_registration( $blueline_dashboard_user_id, $blueline_dashboard_player_id );
	?>
</div>
<?php
```

Note the one behavior-preserving detail: `blueline_account_render_registration()` still renders unconditionally for both claimed and unclaimed users (unchanged from today), it just now lives in the secondary-row wrapper alongside season stats when there are both.

- [ ] **Step 2: Add the two-row grid CSS**

Append to `themes/blueline/assets/src/css/account.css` (after the module-card-shell section, before "Claim card"):

```css
/* ---------------------------------------------------------------------
 * Dashboard hierarchy -- primary row (claim card, or next-game + my-team
 * side by side) leads; secondary row (season stats, registration) follows
 * in smaller cards. Each .bl-account-module inside these wrappers keeps
 * its own margin-block-end, so a single-item row (e.g. the claim card
 * alone) needs no special-casing here.
 * ------------------------------------------------------------------- */

@media (min-width: 700px) {

	.bl-account-dashboard__primary,
	.bl-account-dashboard__secondary {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 0 var(--bl-space-5);
		align-items: start;
	}

	.bl-account-dashboard__primary .bl-account-module,
	.bl-account-dashboard__secondary .bl-account-module {
		margin-block-end: 0;
	}

	.bl-account-dashboard__primary,
	.bl-account-dashboard__secondary {
		margin-block-end: var(--bl-space-5);
	}
}
```

`700px` matches this file's own existing `480px`/`900px` breakpoints in spirit (a mid-size cutover between the 480px stat-tile collapse and the (now-removed) 900px sidebar breakpoint) — confirm against a live check in Task 4 whether 700px is the right cutover for this specific two-card row, and adjust if cards feel cramped or too spaced at common tablet widths (768px, 820px) during manual QA.

- [ ] **Step 3: Build and run the full check suite**

Run: `cd themes/blueline && npm run build && npm run check && composer lint && ./vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add themes/blueline/woocommerce/myaccount/dashboard.php themes/blueline/assets/src/css/account.css themes/blueline/assets/dist
git commit -m "feat(blueline): reorganize the account dashboard into primary/secondary rows

Next game and My Team lead as a primary two-up row; Season stats and
Registration follow as a smaller secondary row. Matches the account
shell rebuild's tightened information hierarchy now that the billing
links live only in the nav."
```

---

### Task 4: Live-verify the rebuilt shell and WooCommerce form chrome on staging

**Files:** none expected — this is a verification task. Only touch files if a real, specific visual gap turns up; if so, fix it in the most directly relevant existing file (`account.css` for account-page-specific issues, `woocommerce.css` for a general form-chrome gap that would affect other WooCommerce pages too) and re-run the full check suite before committing.

**Interfaces:** none — this task consumes the finished output of Tasks 1-3 and produces nothing further tasks depend on.

- [ ] **Step 1: Deploy this branch to staging for live verification**

```bash
cd themes/blueline && npm run build
cd ../.. && ./scripts/deploy-theme.sh staging
```

- [ ] **Step 2: Verify the nav bar, logged in as the `bl-test-verify` account (or another known test account), at desktop width**

Check: pills render in a horizontal row; the active page's pill is visibly highlighted (`--bl-ice` fill); the "Account & Billing" dropdown opens on click, lists Registrations/Store Credit/Refund requests/Payment Methods/Addresses, and does NOT list Account Details (Task 1 moved it out); "Account Details" itself renders as its own top-level pill; clicking a billing link inside the open dropdown navigates correctly and the dropdown's own active-item highlight works when landing back on a billing page.

- [ ] **Step 3: Verify the nav bar at mobile width (~375px)**

Check: the pill row scrolls horizontally when it doesn't fit; the edge-fade cue (from `.bl-table-scroll`) appears only on the side(s) with actual hidden content, matching this theme's existing table-scroll behavior elsewhere; the Billing dropdown panel is NOT clipped when open (this is the specific failure mode Task 2's design note exists to prevent — confirm it directly, don't assume the CSS reasoning holds without checking).

- [ ] **Step 4: Verify the dashboard hierarchy**

Check, for a claimed test account: Next game and My Team render side by side (desktop) / stacked (mobile) as the primary row; Season stats and Registration render side by side (desktop) / stacked (mobile) as the secondary row below. For an unclaimed test account: the claim card renders alone in the primary-row position, Registration still renders in the secondary row beneath it.

- [ ] **Step 5: Verify WooCommerce form chrome on Edit Account, Addresses, and Payment Methods**

Load each of the three pages and check inputs, selects, and buttons render with this theme's existing card-based styling (dark navy/ink buttons, themed input borders) rather than unstyled browser defaults. Per the spec, this should already be true site-wide via `woocommerce.css`'s existing `.form-row`/`.input-text`/`.button` rules — if any of the three pages shows an actual visual gap (an unstyled element, a layout break), note the exact selector and fix it by extending the existing `woocommerce.css` rule, not by adding page-specific overrides, since these rules are already intentionally unscoped to work everywhere WooCommerce renders a form.

- [ ] **Step 6: If any fix was needed in Steps 2-5, run the full check suite and commit it**

Run: `cd themes/blueline && npm run build && npm run check && composer lint && ./vendor/bin/phpunit`

```bash
git add -A
git commit -m "fix(blueline): <describe the specific live-verification fix>"
```

If no fix was needed, skip this step — Task 4 produces no commit in that case.

---

## Final steps (after all tasks)

- [ ] Run `cd themes/blueline && npm run check && composer lint && ./vendor/bin/phpunit` one more time on the fully assembled branch.
- [ ] Push the branch and open a PR against `main` via `gh pr create`, describing the shell rebuild and linking the design spec.
- [ ] Watch CI; merge only on green, matching this session's established cadence.
- [ ] Redeploy to staging (`npm run build` then `./scripts/deploy-theme.sh staging`) and re-verify Task 4's checklist against the merged, deployed result.
