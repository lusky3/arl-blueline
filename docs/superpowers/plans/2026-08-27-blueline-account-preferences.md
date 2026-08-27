# Preferences Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a new "Preferences" tab to `/account` holding the claimed player/team (read-only), the appearance toggle (reusing the existing shared renderer/AJAX path), and a "show next game widget again" control — and remove the now-redundant theme-preference field from Edit Account.

**Architecture:** One new endpoint registered the same way `my-team`/`my-schedule` already are, one new small render file (`inc/account/preferences.php`) following this codebase's one-file-per-feature convention, a small multi-instance fix to `footer-theme-toggle.js` so the existing toggle renderer works safely in two places at once, and one new small JS module for the widget-reset button. No new data model.

**Tech Stack:** PHP 8.3 / WordPress / WooCommerce (theme `rookiehockey-blueline`, root `themes/blueline/`), PHPUnit, plain CSS, vanilla JS (webpack-bundled).

**Spec:** `docs/superpowers/specs/2026-08-27-blueline-account-preferences-design.md`

## Global Constraints

- Staging (Staging-host) only.
- Run `composer lint`, `./vendor/bin/phpunit`, and `npm run check` before every commit that touches PHP, CSS, or JS; all must pass.
- No new unlink/re-claim capability — read-only display plus a contact-the-league link only.
- Verify the final result live in a real browser before considering this done, not curl alone — sub-project 1's final review found bugs (a CSS specificity conflict with WooCommerce core) that no static check caught.

---

### Task 1: Register the `preferences` endpoint and its rewrite rule

**Files:**
- Modify: `themes/blueline/inc/account/endpoints.php:26-67` (the `blueline_account_endpoints()` array) and `:294-296` (`blueline_register_account_rewrite_endpoints()`)
- Modify: `themes/blueline/tests/AccountEndpointsTest.php`

**Interfaces:**
- Produces: a `'preferences'` key in `blueline_account_endpoints()` with `group: 'preferences'`, `order: 30` — consumed by Task 2's content handler and automatically picked up by the existing nav template (no nav template change needed; confirmed it renders any non-`'billing'` group as a top-level pill with zero per-group logic).

- [ ] **Step 1: Write the failing test**

In `themes/blueline/tests/AccountEndpointsTest.php`, add:

```php
	/**
	 * Sub-project 2 (Preferences page): a new top-level endpoint, grouped
	 * separately from league/billing/account since it's neither team content
	 * nor account administration -- it's site-experience settings.
	 */
	public function test_preferences_endpoint_exists_with_its_own_group(): void {
		$e = blueline_account_endpoints();

		$this->assertArrayHasKey( 'preferences', $e );
		$this->assertSame( 'preferences', $e['preferences']['group'] );
		$this->assertSame( 'Preferences', $e['preferences']['label'] );
	}
```

Also find `test_every_endpoint_has_a_label()` (asserts `$cfg['group']` is one of `array( 'league', 'billing', 'account' )`) and add `'preferences'` to that array, the same way sub-project 1 added `'account'`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/phpunit --filter AccountEndpointsTest`
Expected: FAIL — `preferences` key doesn't exist yet.

- [ ] **Step 3: Add the endpoint config**

In `blueline_account_endpoints()`, add after the `'my-schedule'` entry (before `'registrations'`):

```php
		'preferences'     => array(
			'label' => __( 'Preferences', 'blueline' ),
			'group' => 'preferences',
			'order' => 30,
		),
```

- [ ] **Step 4: Register the rewrite endpoint**

In `blueline_register_account_rewrite_endpoints()` (~line 294-296), add a third line matching the existing two:

```php
	add_rewrite_endpoint( 'preferences', EP_ROOT | EP_PAGES );
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit --filter AccountEndpointsTest`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 7: Commit**

```bash
git add themes/blueline/inc/account/endpoints.php themes/blueline/tests/AccountEndpointsTest.php
git commit -m "feat(blueline): register the Preferences account endpoint

Adds a new top-level nav pill and rewrite endpoint for /account/preferences/,
grouped separately from league/billing/account since it holds
site-experience settings, not team content or account administration."
```

---

### Task 2: Build the Preferences page content — linked team and page shell

**Files:**
- Create: `themes/blueline/inc/account/preferences.php`
- Modify: `themes/blueline/functions.php:56-61` (add a `require_once` line for the new file, alongside the existing `inc/account/*.php` list)
- Create: `themes/blueline/tests/AccountPreferencesTest.php`

**Interfaces:**
- Consumes: `blueline_current_user_player_id(): ?int` (`inc/account/player-data.php:599`), `blueline_get_player_team( int $player_id ): ?array` returning `{team_id:int, name:string, logo_id:?int, division:string, number:?string}` (`inc/account/player-data.php:296-314`), `blueline_contact_url(): string` (`inc/template-tags.php:836`), `blueline_account_module_start()`/`blueline_account_module_end()`/`blueline_account_module_empty_state_html()` (`inc/account/dashboard.php`, the existing card-chrome helpers — reuse these rather than inventing new markup for this page's sections).
- Produces: `blueline_account_preferences_endpoint()`, hooked to `woocommerce_account_preferences_endpoint`, the content handler for the new tab. Task 3 appends the appearance and widget-reset sections to this same function.

- [ ] **Step 1: Write the failing test**

Create `themes/blueline/tests/AccountPreferencesTest.php`:

```php
<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see inc/template-tags.php's identical disable.
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/preferences.php';

/**
 * Covers the pure "what team summary to show" decision behind the
 * Preferences page's linked-team section.
 */
final class AccountPreferencesTest extends TestCase {

	/**
	 * A null team (unclaimed, or claimed but currently rosterless) must
	 * produce a null summary, not throw or fabricate placeholder data.
	 */
	public function test_null_team_produces_null_summary(): void {
		$this->assertNull( blueline_preferences_team_summary( null ) );
	}

	/**
	 * A real team array passes through as a formatted summary line.
	 */
	public function test_real_team_produces_a_summary_line(): void {
		$summary = blueline_preferences_team_summary(
			array(
				'team_id'  => 42,
				'name'     => 'Puck Dynasty',
				'logo_id'  => null,
				'division' => 'Division 1',
				'number'   => '99',
			)
		);

		$this->assertSame( 'Puck Dynasty · Division 1 · #99', $summary );
	}

	/**
	 * A team with no division or number omits those segments rather than
	 * rendering an empty "· ·".
	 */
	public function test_summary_omits_missing_segments(): void {
		$summary = blueline_preferences_team_summary(
			array(
				'team_id'  => 42,
				'name'     => 'Puck Dynasty',
				'logo_id'  => null,
				'division' => '',
				'number'   => null,
			)
		);

		$this->assertSame( 'Puck Dynasty', $summary );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit --filter AccountPreferencesTest`
Expected: FAIL — `preferences.php` and `blueline_preferences_team_summary()` don't exist yet.

- [ ] **Step 3: Create `inc/account/preferences.php`**

```php
<?php
/**
 * The Preferences page: everything that affects a signed-in player's site
 * experience but isn't account/billing administration -- linked team
 * (read-only), appearance, and next-game widget visibility.
 *
 * Design spec: docs/superpowers/specs/2026-08-27-blueline-account-
 * preferences-design.md.
 *
 * No self-service unlink/re-claim exists in this codebase --
 * blueline_link_player_to_user() (inc/account/player-link.php) explicitly
 * rejects re-linking an already-linked account -- so the linked-team
 * section here is read-only, with a contact-the-league link for anyone who
 * needs to change it, the same pattern the claim card already uses for its
 * own "no candidates found" state.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * A human-readable "Team · Division · #Number" summary of
 * blueline_get_player_team()'s row, omitting any segment that's empty --
 * the same idiom blueline_format_candidate_detail() (inc/account/
 * dashboard.php) uses for the claim card's own disambiguating line.
 *
 * @param array{team_id:int, name:string, logo_id:?int, division:string, number:?string}|null $team blueline_get_player_team()'s result, or null.
 * @return string|null Null when $team itself is null.
 */
function blueline_preferences_team_summary( ?array $team ): ?string {
	if ( null === $team ) {
		return null;
	}

	$number = $team['number'] ?? '';

	$parts = array_filter(
		array(
			$team['name'],
			$team['division'] ?? '',
			'' !== $number ? sprintf(
				/* translators: %s: jersey number. */
				__( '#%s', 'blueline' ),
				$number
			) : '',
		),
		static fn( $part ) => '' !== $part
	);

	return implode( ' · ', $parts );
}

/**
 * The linked-team section: read-only summary, or an unclaimed/rosterless
 * message with a contact-the-league link.
 */
function blueline_render_preferences_team_section(): void {
	blueline_account_module_start( 'preferences-team', __( 'Linked team', 'blueline' ) );

	$player_id = function_exists( 'blueline_current_user_player_id' ) ? blueline_current_user_player_id() : null;
	$team      = $player_id ? blueline_get_player_team( $player_id ) : null;
	$summary   = blueline_preferences_team_summary( $team );

	if ( null === $summary ) {
		$contact = function_exists( 'blueline_contact_url' ) ? blueline_contact_url() : home_url( '/' );
		$message = sprintf(
			wp_kses(
				/* translators: 1: opening <a> tag to the Contact Us page, 2: closing </a> tag. */
				__( 'No team linked yet. %1$sContact the league%2$s if you need this set or changed.', 'blueline' ),
				array( 'a' => array( 'href' => array() ) )
			),
			'<a href="' . esc_url( $contact ) . '">',
			'</a>'
		);
		blueline_account_module_empty_state_html( $message );
	} else {
		?>
		<p class="bl-preferences-team__summary"><?php echo esc_html( $summary ); ?></p>
		<?php
	}

	blueline_account_module_end();
}

add_action( 'woocommerce_account_preferences_endpoint', 'blueline_account_preferences_endpoint' );
/**
 * Content for /account/preferences/. Task 3 appends the appearance and
 * widget-visibility sections after the team section built here.
 */
function blueline_account_preferences_endpoint(): void {
	blueline_render_preferences_team_section();
}
```

- [ ] **Step 4: Wire the file into the theme's loader**

In `themes/blueline/functions.php`, `inc/account/*.php` files are loaded via an explicit list of `require_once` lines (~line 56-61):

```php
require_once BLUELINE_DIR . '/inc/account/endpoints.php';
require_once BLUELINE_DIR . '/inc/account/player-link.php';
require_once BLUELINE_DIR . '/inc/account/player-data.php';
require_once BLUELINE_DIR . '/inc/account/dashboard.php';
require_once BLUELINE_DIR . '/inc/account/avatars.php';
require_once BLUELINE_DIR . '/inc/account/theme-preference.php';
```

Add a new line after the `theme-preference.php` one:

```php
require_once BLUELINE_DIR . '/inc/account/preferences.php';
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `./vendor/bin/phpunit --filter AccountPreferencesTest`
Expected: PASS.

- [ ] **Step 6: Add minimal CSS for the summary line**

In `themes/blueline/assets/src/css/account.css`, add near the other small text-summary rules (e.g. next to `.bl-account-registration__season`):

```css
.bl-preferences-team__summary {
	margin: 0;
	font-weight: 600;
}
```

- [ ] **Step 7: Build and run the full check suite**

Run: `cd themes/blueline && npm run build && npm run check && composer lint && ./vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add themes/blueline/inc/account/preferences.php themes/blueline/tests/AccountPreferencesTest.php themes/blueline/assets/src/css/account.css themes/blueline/assets/dist themes/blueline/functions.php
git commit -m "feat(blueline): add the Preferences page's linked-team section

Read-only summary of the claimed player's team, matching the claim
card's own empty-state pattern (a contact-the-league link) since no
self-service way to change a linked player exists in this codebase."
```

---

### Task 3: Add the appearance section and fix `footer-theme-toggle.js` for multi-instance use

**Files:**
- Modify: `themes/blueline/assets/src/js/footer-theme-toggle.js:177-227` (`initFooterThemeToggle()`)
- Modify: `themes/blueline/inc/account/preferences.php` (append the appearance section to `blueline_account_preferences_endpoint()`)

**Interfaces:**
- Consumes: `blueline_render_theme_toggle(): void` (`inc/account/theme-preference.php:228-260`) — call it directly, no new PHP needed for the toggle itself.
- Produces: nothing new consumed by later tasks.

**Why this task exists**: `blueline_render_theme_toggle()` renders markup carrying `data-bl-theme-toggle`. Since the footer renders on every page including this new Preferences page, calling that same function a second time here means TWO elements on one page carry that same data attribute. `initFooterThemeToggle()` currently does `document.querySelector('[data-bl-theme-toggle]')` — singular, first match only — so the second instance (whichever one isn't first in DOM order) would render but never get click handlers wired up. This task fixes that.

**No new test file needed for this fix**: `footer-theme-toggle.test.mjs` (existing) covers `clampPreference()` and `resolveToggleAction()` — both pure functions, both untouched by this change. The fix itself is a structural refactor (one function split into a per-instance initializer plus a loop over `querySelectorAll` instead of `querySelector`), with no new decision logic to unit-test in isolation; this file's own established convention (confirmed by reading its current test file — it has no DOM-simulation tests at all, only pure-function ones) doesn't fabricate a fake-DOM test for this class of change. The actual proof that both instances work is Task 5's live verification (Step 4: confirm the Preferences toggle and the footer toggle both work and stay in sync). Run the existing tests as a regression check after the change (Step 2 below), not as TDD red/green for new behavior.

- [ ] **Step 1: Fix `initFooterThemeToggle()` for multiple instances**

Replace (in `themes/blueline/assets/src/js/footer-theme-toggle.js`):

```javascript
function initFooterThemeToggle() {
	const container = document.querySelector( '[data-bl-theme-toggle]' );

	if ( ! container ) {
		return;
	}

	const mode = container.getAttribute( 'data-bl-theme-toggle-mode' );
	const buttons = Array.from(
		container.querySelectorAll( '[data-bl-theme-toggle-option]' )
	);

	if ( 'account' !== mode ) {
		setPressedState( buttons, readGuestPreference() );
	}

	buttons.forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const requested = button.getAttribute(
				'data-bl-theme-toggle-option'
			);
			const { value, persist } = resolveToggleAction( mode, requested );

			if ( 'localStorage' === persist ) {
				writeGuestPreference( value );
				applyTheme( value );
				setPressedState( buttons, value );
				return;
			}

			saveViaAjax( container, value, () => {
				applyTheme( value );
				setPressedState( buttons, value );
			} );
		} );
	} );
}
```

with:

```javascript
/**
 * Wire up ONE `[data-bl-theme-toggle]` instance. Split out from
 * initFooterThemeToggle() below so that function can initialize every
 * matching instance on the page, not just the first -- this page's footer
 * toggle and, on /account/preferences/, a second instance both carry the
 * same data attribute, and both need independently working click handlers.
 *
 * @param {Element} container One `[data-bl-theme-toggle]` element.
 */
function initThemeToggleInstance( container ) {
	const mode = container.getAttribute( 'data-bl-theme-toggle-mode' );
	const buttons = Array.from(
		container.querySelectorAll( '[data-bl-theme-toggle-option]' )
	);

	if ( 'account' !== mode ) {
		setPressedState( buttons, readGuestPreference() );
	}

	buttons.forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const requested = button.getAttribute(
				'data-bl-theme-toggle-option'
			);
			const { value, persist } = resolveToggleAction( mode, requested );

			if ( 'localStorage' === persist ) {
				writeGuestPreference( value );
				applyTheme( value );
				setPressedState( buttons, value );
				return;
			}

			saveViaAjax( container, value, () => {
				applyTheme( value );
				setPressedState( buttons, value );
			} );
		} );
	} );
}

function initFooterThemeToggle() {
	document
		.querySelectorAll( '[data-bl-theme-toggle]' )
		.forEach( initThemeToggleInstance );
}
```

No export change needed: the file's closing block is `module.exports = { clampPreference, resolveToggleAction };` — only the two pure functions, neither renamed nor removed by this change.

- [ ] **Step 2: Run the existing tests as a regression check**

Run: `cd themes/blueline && node --test assets/src/js/footer-theme-toggle.test.mjs`
Expected: PASS (all existing `clampPreference`/`resolveToggleAction` tests unaffected).

- [ ] **Step 3: Append the appearance section to the Preferences page**

In `themes/blueline/inc/account/preferences.php`, change `blueline_account_preferences_endpoint()` from:

```php
function blueline_account_preferences_endpoint(): void {
	blueline_render_preferences_team_section();
}
```

to:

```php
function blueline_account_preferences_endpoint(): void {
	blueline_render_preferences_team_section();

	blueline_account_module_start( 'preferences-appearance', __( 'Appearance', 'blueline' ) );
	blueline_render_theme_toggle();
	blueline_account_module_end();
}
```

- [ ] **Step 4: Remove the theme-preference field from Edit Account**

In `themes/blueline/inc/account/theme-preference.php`, delete the `add_action( 'woocommerce_edit_account_form', 'blueline_render_theme_preference_field' );` line and the entire `blueline_render_theme_preference_field()` function, AND the `add_action( 'woocommerce_save_account_details', 'blueline_save_theme_preference', 12, 1 );` line and the entire `blueline_save_theme_preference()` function. Leave `blueline_get_theme_preference()`, `blueline_persist_theme_preference()`, `blueline_theme_preference_html_attribute()`, `blueline_ajax_save_theme_preference()`, `blueline_render_theme_toggle()`, and `blueline_render_guest_theme_bootstrap_script()` untouched — those are shared infrastructure the footer toggle and this new Preferences section both still depend on.

Update the file's own top docblock: it currently says "configurable from My Account for a logged-in player, AND from a sitewide footer toggle" — change "My Account" wording to reflect the field moved from Edit Account to the new Preferences page specifically, so a future reader isn't sent looking for it on the wrong tab.

- [ ] **Step 5: Remove the now-dead test coverage for the deleted functions**

`themes/blueline/tests/ThemePreferenceTest.php` has two test blocks, each under its own `// ----- blueline_..._preference_field()/blueline_save_theme_preference() -----` section-comment header, that exist ONLY to cover the two functions Step 4 deletes:

- The `// blueline_render_theme_preference_field()` block (~lines 72-151): `test_field_marks_the_stored_preference_as_selected()`, `test_field_defaults_to_system_selected_when_nothing_is_stored()`, `test_field_renders_exactly_the_three_known_preferences()`, `test_field_labels_the_select_for_accessibility()`.
- The `// blueline_save_theme_preference()` block (~lines 153-191): `test_save_persists_a_valid_submitted_value()`, `test_save_clamps_an_unrecognised_submitted_value_to_system()`, `test_save_defaults_to_system_when_the_field_is_missing_entirely()`.

Delete both blocks' test methods and their section-comment headers. **Do NOT delete the shared `private function render( callable $renderer ): string` helper** (defined inside the first block, ~lines 76-86) — it's reused by the toggle tests (`test_toggle_renders_...`) and bootstrap-script tests (`test_bootstrap_script_renders_...`) later in the same file; move it to sit just before whichever test block now comes first if removing its original block would otherwise leave it orphaned mid-file.

Also check for `test_save_theme_preference_uses_the_shared_persist_function()` (~line 274-290) — this one directly calls `blueline_save_theme_preference( 7 )` to prove the save handler delegates to `blueline_persist_theme_preference()`. Since `blueline_save_theme_preference()` itself is being deleted, this specific test must go too (the delegation property it proved no longer has a function to prove it about — `blueline_ajax_save_theme_preference()` already has its own, separate persist-delegation test coverage lower in the same file, so this isn't a coverage gap, just a redundant test for now-removed code).

- [ ] **Step 6: Build and run the full check suite**

Run: `cd themes/blueline && npm run build && npm run check && composer lint && ./vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add themes/blueline/assets/src/js/footer-theme-toggle.js themes/blueline/inc/account/preferences.php themes/blueline/inc/account/theme-preference.php themes/blueline/tests/ThemePreferenceTest.php themes/blueline/assets/dist
git commit -m "feat(blueline): appearance toggle on Preferences, remove it from Edit Account

Fixes footer-theme-toggle.js's element selection so
blueline_render_theme_toggle() works correctly wherever it's called
from, not just the footer. The Edit Account field is redundant now
that the toggle lives on Preferences and in the footer, both backed
by the same shared persistence path."
```

---

### Task 4: Next-game widget reset control

**Files:**
- Create: `themes/blueline/assets/src/js/preferences-widget-reset.js`
- Create: `themes/blueline/assets/src/js/preferences-widget-reset.test.mjs`
- Modify: `themes/blueline/inc/account/preferences.php` (append the widget-reset section)
- Modify: `themes/blueline/assets/src/js/index.js` (wire the new module into the build — check how `floating-next-game.js`/`account-next-game.js` are included there and match it)
- Modify: `themes/blueline/assets/src/css/account.css`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing consumed by later tasks — this is the plan's last content task.

- [ ] **Step 1: Write the failing test**

Create `themes/blueline/assets/src/js/preferences-widget-reset.test.mjs`:

```javascript
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { WIDGET_DISMISSED_KEY } = require( './preferences-widget-reset.js' );

test( 'WIDGET_DISMISSED_KEY matches the key floating-next-game.js actually uses', () => {
	// This is the one thing that must never silently drift: if
	// floating-next-game.js's own dismissed-state key ever changes, this
	// button's whole purpose breaks with no visible error anywhere.
	assert.equal( WIDGET_DISMISSED_KEY, 'blueline:next-game-dismissed' );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd themes/blueline && node --test assets/src/js/preferences-widget-reset.test.mjs`
Expected: FAIL — the file doesn't exist yet.

- [ ] **Step 3: Create the module**

```javascript
/**
 * Blueline — "Show next game widget again" button on the Preferences page.
 *
 * The floating next-game widget (assets/src/js/floating-next-game.js) hides
 * itself once a visitor dismisses it, by comparing the current game's
 * event_id:fingerprint pair against WIDGET_DISMISSED_KEY in this browser's
 * own localStorage -- see that file's own docblock. This button just clears
 * that one key; it does not need to touch `blueline:next-game-seen` (PR
 * #24's key for the separate "Updated since you last checked" indicator),
 * since that key never affects visibility, only whether the indicator
 * shows.
 *
 * WIDGET_DISMISSED_KEY MUST match floating-next-game.js's own DISMISSED_KEY
 * exactly -- kept in sync by convention (cross-referenced in both files'
 * docblocks) and this file's own test, the same trade-off this theme's
 * other localStorage-key-sharing files already make (see footer-theme-
 * toggle.js's own docblock on the same pattern for its STORAGE_KEY).
 */

const WIDGET_DISMISSED_KEY = 'blueline:next-game-dismissed';

/**
 * Clear the dismissed-widget key. A failure to clear is not worth
 * surfacing beyond the button simply not working this once -- the same
 * degrade-silently convention every localStorage access in this theme
 * follows (see assets/src/js/announcement.js).
 *
 * @return {boolean} Whether the clear actually succeeded.
 */
function resetDismissedWidget() {
	try {
		window.localStorage.removeItem( WIDGET_DISMISSED_KEY );
		return true;
	} catch {
		return false;
	}
}

if ( typeof document !== 'undefined' ) {
	const button = document.querySelector( '[data-bl-widget-reset]' );

	if ( button ) {
		button.addEventListener( 'click', () => {
			const succeeded = resetDismissedWidget();

			if ( succeeded ) {
				const confirmation = document.querySelector(
					'[data-bl-widget-reset-confirmation]'
				);

				if ( confirmation ) {
					confirmation.hidden = false;
				}
			}
		} );
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { WIDGET_DISMISSED_KEY, resetDismissedWidget };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd themes/blueline && node --test assets/src/js/preferences-widget-reset.test.mjs`
Expected: PASS.

- [ ] **Step 5: Wire the module into the build**

`themes/blueline/assets/src/js/index.js` is a flat list of plain side-effecting imports, one per line, e.g.:

```javascript
import './floating-next-game.js';
import './account-next-game.js';
import './footer-theme-toggle.js';
```

Add a new line after the `footer-theme-toggle.js` import:

```javascript
import './preferences-widget-reset.js';
```

- [ ] **Step 6: Append the widget-reset section to the Preferences page**

In `themes/blueline/inc/account/preferences.php`, change `blueline_account_preferences_endpoint()` to:

```php
function blueline_account_preferences_endpoint(): void {
	blueline_render_preferences_team_section();

	blueline_account_module_start( 'preferences-appearance', __( 'Appearance', 'blueline' ) );
	blueline_render_theme_toggle();
	blueline_account_module_end();

	blueline_account_module_start( 'preferences-widget', __( 'Next game widget', 'blueline' ) );
	?>
	<p class="bl-preferences-widget__intro">
		<?php esc_html_e( 'If you’ve dismissed the floating next-game widget, you can bring it back here.', 'blueline' ); ?>
	</p>
	<button type="button" class="bl-btn bl-btn--secondary" data-bl-widget-reset>
		<span class="bl-skew"><span><?php esc_html_e( 'Show next game widget again', 'blueline' ); ?></span></span>
	</button>
	<p class="bl-preferences-widget__confirmation" data-bl-widget-reset-confirmation hidden>
		<?php esc_html_e( 'Done — it will show again on your next page view.', 'blueline' ); ?>
	</p>
	<?php
	blueline_account_module_end();
}
```

Note: use plain straight quotes/apostrophes and an em dash character directly in the PHP source (not the `’`/`—` escape sequences shown above, which are only there to survive being embedded in this plan document) — write `’` and `—` literally, matching how this codebase's other user-facing copy strings are written elsewhere in `inc/account/dashboard.php` (check a few for the exact character choices already established, e.g. curly vs straight apostrophes).

- [ ] **Step 7: Add CSS for the confirmation message's `[hidden]` state**

In `themes/blueline/assets/src/css/account.css`, add (this codebase's established `[hidden]` specificity fix — check `.bl-account-next-game__updated[hidden] { display: none; }` for the exact precedent and match its pattern):

```css
.bl-preferences-widget__confirmation {
	margin: var(--bl-space-3) 0 0;
	color: var(--bl-content-text-secondary);
	font-size: var(--bl-text-sm);
}

.bl-preferences-widget__confirmation[hidden] {
	display: none;
}
```

- [ ] **Step 8: Build and run the full check suite**

Run: `cd themes/blueline && npm run build && npm run check && composer lint && ./vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add themes/blueline/assets/src/js/preferences-widget-reset.js themes/blueline/assets/src/js/preferences-widget-reset.test.mjs themes/blueline/assets/src/js/index.js themes/blueline/inc/account/preferences.php themes/blueline/assets/src/css/account.css themes/blueline/assets/dist
git commit -m "feat(blueline): add the next-game widget reset control to Preferences

Clears blueline:next-game-dismissed on click, bringing the floating
widget back on the next page view. Does not touch the separate
next-game-seen key, which only governs the 'Updated' indicator."
```

---

### Task 5: Live-verify the finished Preferences page

**Files:** none expected — verification task. Only touch files if a real, specific gap turns up; fix in the most directly relevant file and re-run the full check suite before committing.

**Interfaces:** none.

- [ ] **Step 1: Deploy to staging**

```bash
cd themes/blueline && npm run build
cd .. && ./scripts/deploy-theme.sh staging
```

- [ ] **Step 2: Verify the nav pill**

Log in as a test account. Confirm "Preferences" appears as its own top-level pill between "My Schedule" and the "Account & Billing" dropdown, and that it highlights as active when on that page.

- [ ] **Step 3: Verify the linked-team section**

For a claimed test account: confirm the team summary line renders correctly (name, division, jersey number). For an unclaimed account (or by temporarily testing the empty-state path): confirm the contact-the-league message and link render correctly, matching the claim card's own established empty-state look.

- [ ] **Step 4: Verify the appearance toggle works from Preferences AND stays in sync with the footer**

Click a theme option on the Preferences page's toggle. Confirm: (a) the page's own `<html data-theme>` updates immediately, (b) scrolling to the footer shows the SAME toggle now showing the same pressed state (since both read the same underlying user meta value on page load, and the AJAX save is shared), (c) reloading the page shows the new preference applied server-side (no flash of the old theme), confirming the save actually persisted, not just the in-page visual state.

- [ ] **Step 5: Verify the widget-reset button**

Get the floating next-game widget into a dismissed state (dismiss it from wherever it's showing), navigate to Preferences, click "Show next game widget again," confirm the confirmation message appears, then navigate to another page and confirm the widget is showing again.

- [ ] **Step 6: Verify Edit Account no longer shows an Appearance field**

Load `/account/edit-account/` and confirm the Appearance `<select>` that used to be there is gone, with no layout gap or leftover empty form-row.

- [ ] **Step 7: If any fix was needed, run the full check suite and commit it**

Run: `cd themes/blueline && npm run build && npm run check && composer lint && ./vendor/bin/phpunit`

```bash
git add -A
git commit -m "fix(blueline): <describe the specific live-verification fix>"
```

If no fix was needed, skip this step.

---

## Final steps (after all tasks)

- [ ] Run `cd themes/blueline && npm run check && composer lint && ./vendor/bin/phpunit` one more time on the fully assembled branch.
- [ ] Push the branch and open a PR against `main` via `gh pr create`, describing the Preferences page and linking the design spec.
- [ ] Watch CI; merge only on green.
- [ ] Redeploy to staging (`npm run build` then `./scripts/deploy-theme.sh staging`) and re-verify Task 5's checklist against the merged, deployed result in a real browser.
