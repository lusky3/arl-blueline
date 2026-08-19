# Blueline P1b — Panel Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the control panel's Phase 1 scope — section presence toggles with safety floors, the announcement banner, the season-state break-glass, the remaining copy fields, a repair path for already-stored bad values, and save snapshots — so a volunteer can run the site without a developer.

**Architecture:** Everything lands in the existing settings system: one option (`BLUELINE_SETTINGS_OPTION`), a flat schema in `inc/settings/defaults.php`, a type-dispatch sanitizer in `inc/settings/sanitize.php`, and a Settings-API page in `inc/settings/page.php`. Two new schema types (`section` and `date`) join the existing `text`/`email`/`page_id`/`term_id`/`bool`/`band_photos`. Section toggles are read at render time through one helper so a floor can never be bypassed by a caller that forgot to check.

**Tech Stack:** WordPress 6.9 classic theme, PHP 8.3, PHPUnit 12, `@wordpress/scripts` (webpack + Playwright), phpcs/WPCS. No React, no REST endpoint, no new build entry.

**Spec:** `docs/superpowers/specs/2026-08-13-blueline-control-panel-design.md` (§6.3 Controls, §6.7 Lifecycle, §6.8 Export/import)

## Global Constraints

- **`phpcs:ignoreFile` is forbidden.** Line-level `phpcs:ignore <sniff> -- <reason>` only.
- **Every write path is validated.** `sanitize_option_{$option}` is wired at file scope, not from `admin_init` — WP-CLI and direct `update_option()` must hit the same validator.
- **Sanitizer is `sanitize_text_field()`, never `wp_kses_post()`.** Every copy field is echoed through `esc_html()`/`esc_attr()`; 29 of 40 call sites are attribute contexts.
- **Every `text`/`textarea` field MUST declare `placeholders`,** even as `array()`. `SettingsDefaultsTest` enforces it. A stray `%` in a field feeding `sprintf` is a PHP 8.3 fatal, not a warning.
- **No admin notice may be a `<div>`.** A third-party declutter plugin removes any `div` whose class contains `notice`, client-side. `NoticeDivGuardTest` enforces this. Use `<p class="notice ...">`.
- **The page body never scrolls horizontally.** Hard invariant at 360px.
- **Presence only, never order.** The homepage module order matrix encodes the `registration_open ∧ is_playing` bug fix; no task in this plan exposes ordering.
- **`_posted_fields` must list every field a tab owns,** or clearing any field on that tab silently reverts on the next save from any tab.
- **Widgets own content; the panel owns presence and labels.** A toggle must never delete widget data.
- **Run `npm run check` before every commit.** It is lint:css + lint:js + test:js + tokens:check + phpunit + phpcs.

---

### Task 1: Section toggle foundation

**Files:**
- Modify: `themes/blueline/inc/settings/defaults.php` (schema + defaults)
- Modify: `themes/blueline/inc/settings/sanitize.php` (`section` type)
- Modify: `themes/blueline/inc/settings/page.php` (tab label, renderer reuse)
- Create: `themes/blueline/inc/settings/sections.php`
- Modify: `themes/blueline/functions.php` (require the new file)
- Test: `themes/blueline/tests/SettingsSectionsTest.php`

**Interfaces:**
- Produces: `blueline_section_definitions(): array` — key => `array{label:string, group:string, floor?:string}`
- Produces: `blueline_section_enabled( string $key ): bool` — the ONLY read path
- Produces: `blueline_sanitize_section( $value, array $field ): bool`

- [ ] **Step 1: Write the failing test**

```php
public function test_a_section_defaults_to_enabled_when_unset(): void {
	$this->assertTrue( blueline_section_enabled( 'module_next_games' ) );
}

public function test_an_unknown_section_key_is_never_enabled(): void {
	$this->assertFalse( blueline_section_enabled( 'not_a_section' ) );
}

public function test_every_definition_has_a_label_and_group(): void {
	foreach ( blueline_section_definitions() as $key => $def ) {
		$this->assertNotSame( '', trim( $def['label'] ), "$key has no label" );
		$this->assertNotSame( '', trim( $def['group'] ), "$key has no group" );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd themes/blueline && ./vendor/bin/phpunit --filter SettingsSectionsTest`
Expected: FAIL, "Call to undefined function blueline_section_enabled()"

- [ ] **Step 3: Create `inc/settings/sections.php`**

```php
<?php
/**
 * Section presence toggles: which parts of the site render at all.
 *
 * PRESENCE ONLY, NEVER ORDER. blueline_homepage_module_order() encodes the
 * registration_open-and-playing fix, and a drag-list invites re-breaking it.
 *
 * Every consumer reads through blueline_section_enabled() rather than calling
 * blueline_settings() directly, so a floor cannot be bypassed by a caller that
 * forgot it exists.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every toggleable section.
 *
 * @return array<string,array{label:string,group:string}>
 */
function blueline_section_definitions(): array {
	return array(
		'module_next_games'        => array( 'label' => 'Next games', 'group' => 'Homepage' ),
		'module_standings_snippet' => array( 'label' => 'Standings', 'group' => 'Homepage' ),
		'module_new_here'          => array( 'label' => 'Never played? Perfect.', 'group' => 'Homepage' ),
		'module_latest_news'       => array( 'label' => 'Latest news', 'group' => 'Homepage' ),
		'chrome_sponsors'          => array( 'label' => 'Header sponsor slot', 'group' => 'Site chrome' ),
		'chrome_utility_nav'       => array( 'label' => 'Account links in the header', 'group' => 'Site chrome' ),
		'chrome_footer_trust'      => array( 'label' => 'Footer contact block', 'group' => 'Site chrome' ),
		'chrome_footer_teams'      => array( 'label' => 'Footer team directory', 'group' => 'Site chrome' ),
		'account_next_game'        => array( 'label' => 'My next game', 'group' => 'My Account' ),
		'account_my_team'          => array( 'label' => 'My team', 'group' => 'My Account' ),
		'account_season_stats'     => array( 'label' => 'My season', 'group' => 'My Account' ),
		'account_registration'     => array( 'label' => 'Registration status', 'group' => 'My Account' ),
	);
}

/**
 * Whether $key renders. Unknown keys are never enabled: a typo must fail
 * closed rather than silently render something nothing controls.
 *
 * @param string $key A key from blueline_section_definitions().
 * @return bool
 */
function blueline_section_enabled( string $key ): bool {
	if ( ! isset( blueline_section_definitions()[ $key ] ) ) {
		return false;
	}

	$value = function_exists( 'blueline_settings' ) ? blueline_settings( $key ) : null;

	// Unset means enabled: every section shipped visible, and an install that
	// has never opened this tab must look exactly as it did before.
	return null === $value ? true : (bool) $value;
}
```

- [ ] **Step 4: Add the schema entries and defaults**

In `inc/settings/defaults.php`, inside `blueline_settings_schema()`, before the Commerce tab:

```php
		// Sections tab -- generated from one definition list so a new section
		// needs no second edit here.
```

then immediately after the `return array(` block's closing in that function, replace the plain `return array( ... );` with a build step:

```php
	$schema = array( /* ...existing entries unchanged... */ );

	foreach ( blueline_section_definitions() as $key => $def ) {
		$schema[ $key ] = array(
			'type'  => 'section',
			'tab'   => 'sections',
			'label' => $def['label'],
			'group' => $def['group'],
		);
	}

	return $schema;
```

and in `blueline_settings_defaults()`, the same way:

```php
	foreach ( blueline_section_definitions() as $key => $unused ) {
		$defaults[ $key ] = true;
	}
```

- [ ] **Step 5: Add the sanitizer branch**

In `inc/settings/sanitize.php`, in `blueline_sanitize_field()`, beside the `bool` branch:

```php
	if ( 'section' === $type ) {
		return (bool) $value;
	}
```

- [ ] **Step 6: Render sections on their own tab**

In `inc/settings/page.php`, add `'sections' => __( 'Sections', 'blueline' )` to the `$labels` map in `blueline_settings_tab_label()`, and render a `section` field with the same markup as `bool` (hidden `0` companion, bare checkbox, description).

- [ ] **Step 7: Require the file**

In `themes/blueline/functions.php`, beside the other `inc/settings/*` requires:

```php
require_once BLUELINE_DIR . '/inc/settings/sections.php';
```

- [ ] **Step 8: Update the three pinned guards**

`SettingsSanitizeTest::test_every_schema_default_validates_through_the_sanitizer` pins the field count; `SettingsDefaultsTest` pins the known-type list (add `'section'`); `SettingsPageTest::test_tab_slugs_reflect_schema_order` pins tab order (add `'sections'`). Each is a deliberate edit, not a loosening.

- [ ] **Step 9: Run the full gate**

Run: `cd themes/blueline && npm run check`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add themes/blueline/inc/settings themes/blueline/functions.php themes/blueline/tests
git commit -m "feat(blueline): section presence toggles"
```

---

### Task 2: Homepage modules honour their toggles, with the floor

**Files:**
- Modify: `themes/blueline/inc/homepage-modules.php:701-715` (`blueline_homepage_module_order()`)
- Test: `themes/blueline/tests/HomepageModuleOrderTest.php`

**Interfaces:**
- Consumes: `blueline_section_enabled()` from Task 1
- Produces: `blueline_homepage_module_order()` returns only enabled modules, never empty

WCAG 2.4.5 requires two ways to find content, so an empty homepage is not a
configuration a volunteer is allowed to reach by unticking boxes.

- [ ] **Step 1: Write the failing test**

```php
public function test_disabled_modules_are_dropped(): void {
	$state                                             = &blueline_test_state();
	$state['options']['blueline_settings']['module_latest_news'] = false;

	$this->assertNotContains( 'latest_news', blueline_homepage_module_order( 'in_season' ) );
}

public function test_the_last_enabled_module_cannot_be_removed(): void {
	$state = &blueline_test_state();

	foreach ( blueline_section_definitions() as $key => $unused ) {
		$state['options']['blueline_settings'][ $key ] = false;
	}

	$order = blueline_homepage_module_order( 'in_season' );

	$this->assertNotEmpty( $order, 'an empty homepage is not a reachable configuration' );
}

public function test_the_order_matrix_is_otherwise_untouched(): void {
	$this->assertSame(
		array( 'next_games', 'standings_snippet', 'new_here', 'latest_news' ),
		blueline_homepage_module_order( 'registration_open', array( 'is_playing' => true ) )
	);
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit --filter HomepageModuleOrderTest`
Expected: FAIL on the first two; the third passes already and is the regression guard.

- [ ] **Step 3: Filter at the end of `blueline_homepage_module_order()`**

Replace both `return` statements with a single filtered exit:

```php
	$order = $orders[ $state ] ?? $orders['offseason'];

	if ( 'registration_open' === $state && ! empty( $state_data['is_playing'] ) ) {
		$order = array( 'next_games', 'standings_snippet', 'new_here', 'latest_news' );
	}

	$enabled = array_values(
		array_filter(
			$order,
			static function ( $module ) {
				return blueline_section_enabled( 'module_' . $module );
			}
		)
	);

	/*
	 * THE FLOOR. WCAG 2.4.5 wants two ways to find content, and a homepage
	 * with no modules has none. Rather than refuse the save -- which would
	 * mean an admin cannot untick the last box even temporarily -- the render
	 * path keeps the first module of the state's own order. The panel says so
	 * beside the toggles.
	 */
	return $enabled ? $enabled : array_slice( $order, 0, 1 );
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/phpunit --filter HomepageModuleOrderTest`
Expected: PASS

- [ ] **Step 5: Run the gate and commit**

```bash
cd themes/blueline && npm run check
git add themes/blueline/inc/homepage-modules.php themes/blueline/tests
git commit -m "feat(blueline): homepage modules honour section toggles, with a floor"
```

---

### Task 3: Account cards honour their toggles

**Files:**
- Modify: `themes/blueline/woocommerce/myaccount/dashboard.php:37-44`
- Test: `themes/blueline/tests/AccountSectionsTest.php`

**Interfaces:**
- Consumes: `blueline_section_enabled()` from Task 1

The claim card and the claim notice are deliberately NOT toggleable: they are
how an unlinked player becomes linked, and hiding them strands that player with
no route forward.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_disabled_account_card_does_not_render(): void {
	$state                                                     = &blueline_test_state();
	$state['options']['blueline_settings']['account_my_team']   = false;

	ob_start();
	blueline_account_render_my_team( 66 );
	$html = (string) ob_get_clean();

	$this->assertSame( '', trim( $html ) );
}

public function test_the_claim_card_has_no_toggle(): void {
	$this->assertArrayNotHasKey( 'account_claim_card', blueline_section_definitions() );
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit --filter AccountSectionsTest`
Expected: FAIL, the module renders regardless.

- [ ] **Step 3: Guard each renderer at its top**

In `inc/account/dashboard.php`, first line of `blueline_account_render_next_game()`, `_my_team()`, `_season_stats()` and `_registration()`:

```php
	if ( ! blueline_section_enabled( 'account_next_game' ) ) {
		return;
	}
```

(with the matching key per function). Guarding inside the renderer rather than
at the call site means a future template that calls it directly cannot bypass
the toggle.

- [ ] **Step 4: Run the tests, the gate, and commit**

```bash
./vendor/bin/phpunit --filter AccountSectionsTest
npm run check
git add themes/blueline/inc/account/dashboard.php themes/blueline/tests
git commit -m "feat(blueline): account cards honour section toggles"
```

---

### Task 4: Site chrome toggles

**Files:**
- Modify: `themes/blueline/inc/template-tags.php` (utility nav, footer trust, footer teams)
- Modify: `themes/blueline/inc/sportspress.php` (header sponsor selector)
- Test: `themes/blueline/tests/ChromeSectionsTest.php`

**Interfaces:**
- Consumes: `blueline_section_enabled()` from Task 1

- [ ] **Step 1: Write the failing test**

```php
public function test_the_footer_team_directory_can_be_switched_off(): void {
	$state = &blueline_test_state();
	$state['options']['blueline_settings']['chrome_footer_teams'] = false;
	$state['options']['sportspress_league_menu_teams']            = array( '115100' );

	ob_start();
	blueline_footer_team_directory();
	$html = (string) ob_get_clean();

	$this->assertSame( '', trim( $html ) );
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit --filter ChromeSectionsTest`
Expected: FAIL

- [ ] **Step 3: Guard each surface**

`blueline_footer_team_directory()` gains an early return on
`chrome_footer_teams`; the utility-nav block in `blueline_site_header()` gains
`&& blueline_section_enabled( 'chrome_utility_nav' )` beside its existing
`has_nav_menu( 'utility' )`; the footer trust column checks
`chrome_footer_trust`; and `blueline_header_sponsors_selector()` returns an
unmatchable selector when `chrome_sponsors` is off, so SportsPress injects into
nothing rather than the theme hiding a slot it already paid to load.

- [ ] **Step 4: Run the tests, the gate, and commit**

```bash
./vendor/bin/phpunit --filter ChromeSectionsTest
npm run check
git add themes/blueline/inc themes/blueline/tests
git commit -m "feat(blueline): site chrome section toggles"
```

---

### Task 5: Widget-area toggles never delete widget data

**Files:**
- Modify: `themes/blueline/inc/settings/page.php` (the warning)
- Test: `themes/blueline/tests/WidgetAreaWarningTest.php`

**Interfaces:**
- Produces: `blueline_section_widget_warning( string $key ): string` — '' when there is nothing to warn about

`sidebar-1` and `footer-2` hold live production content. Switching a section
off must be reversible and must never touch the widget store.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_populated_widget_area_warns_with_its_count(): void {
	$state                             = &blueline_test_state();
	$state['active_sidebars']['footer-2'] = 3;

	$warning = blueline_section_widget_warning( 'chrome_footer_trust' );

	$this->assertStringContainsString( '3', $warning );
}

public function test_an_empty_widget_area_produces_no_warning(): void {
	$this->assertSame( '', blueline_section_widget_warning( 'chrome_footer_trust' ) );
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit --filter WidgetAreaWarningTest`
Expected: FAIL

- [ ] **Step 3: Implement, and render it beside the toggle**

```php
/**
 * A warning naming the live widget count behind a section, or '' when the
 * area is empty.
 *
 * Switching a section off hides it; it never touches the widget store. Saying
 * so, with the count, is what stops an admin assuming their widgets were
 * deleted and rebuilding them.
 *
 * @param string $key A section key.
 * @return string
 */
function blueline_section_widget_warning( string $key ): string {
	$areas = array(
		'chrome_footer_trust' => 'footer-2',
	);

	if ( ! isset( $areas[ $key ] ) || ! is_active_sidebar( $areas[ $key ] ) ) {
		return '';
	}

	return sprintf(
		/* translators: %d: number of widgets in the area. */
		__( 'This area holds %d widget(s). Switching it off hides them; nothing is deleted.', 'blueline' ),
		blueline_active_widget_count( $areas[ $key ] )
	);
}
```

- [ ] **Step 4: Run the tests, the gate, and commit**

```bash
./vendor/bin/phpunit --filter WidgetAreaWarningTest
npm run check
git add themes/blueline/inc/settings themes/blueline/tests
git commit -m "feat(blueline): warn when a toggled section holds live widgets"
```

---

### Task 6: Announcement banner

**Files:**
- Modify: `themes/blueline/inc/settings/defaults.php` (5 fields, `content` tab)
- Modify: `themes/blueline/inc/settings/sanitize.php` (`date` type)
- Create: `themes/blueline/inc/announcement.php`
- Modify: `themes/blueline/functions.php`
- Modify: `themes/blueline/assets/src/css/base.css`
- Modify: `themes/blueline/assets/src/js/index.js` + create `assets/src/js/announcement.js`
- Test: `themes/blueline/tests/AnnouncementTest.php`

**Interfaces:**
- Produces: `blueline_announcement_visible( ?int $now = null ): bool`
- Produces: `blueline_render_announcement(): void`

Fields: `announcement_text` (`text`, `placeholders => array()`),
`announcement_link` (`page_id`), `announcement_from` / `announcement_to`
(`date`), `announcement_severity` (`text`, validated to info|urgent).

- [ ] **Step 1: Write the failing test**

```php
public function test_no_text_means_no_banner(): void {
	$this->assertFalse( blueline_announcement_visible() );
}

public function test_a_window_in_the_future_is_not_yet_visible(): void {
	$this->set_announcement( 'Ice is out Friday', '2026-09-01', '2026-09-30' );

	$this->assertFalse( blueline_announcement_visible( strtotime( '2026-08-15' ) ) );
}

public function test_a_window_that_has_passed_is_no_longer_visible(): void {
	$this->set_announcement( 'Ice is out Friday', '2026-09-01', '2026-09-30' );

	$this->assertFalse( blueline_announcement_visible( strtotime( '2026-10-02' ) ) );
}

public function test_an_open_ended_window_stays_visible(): void {
	$this->set_announcement( 'Ice is out Friday', '', '' );

	$this->assertTrue( blueline_announcement_visible( strtotime( '2030-01-01' ) ) );
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit --filter AnnouncementTest`
Expected: FAIL, "Call to undefined function blueline_announcement_visible()"

- [ ] **Step 3: Implement the window**

```php
function blueline_announcement_visible( ?int $now = null ): bool {
	$text = (string) blueline_settings( 'announcement_text' );

	if ( '' === trim( $text ) ) {
		return false;
	}

	$now  = $now ?? time();
	$from = (string) blueline_settings( 'announcement_from' );
	$to   = (string) blueline_settings( 'announcement_to' );

	// Dates are stored as Y-m-d and compared in SITE time, not UTC: an admin
	// setting "until the 30th" means the 30th where the league plays.
	if ( '' !== $from && $now < blueline_site_timestamp( $from . ' 00:00:00' ) ) {
		return false;
	}

	if ( '' !== $to && $now > blueline_site_timestamp( $to . ' 23:59:59' ) ) {
		return false;
	}

	return true;
}
```

- [ ] **Step 4: Render it above the hero**

`blueline_render_announcement()` prints a `<p class="bl-announce ...">` — **not
a `div`**, per the global constraint — with a dismiss `<button>` when
dismissible. `assets/src/js/announcement.js` stores the dismissal in
`localStorage` keyed by a hash of the text, so editing the announcement makes
it reappear for everyone who dismissed the previous one.

- [ ] **Step 5: Run the tests, the gate, and commit**

```bash
./vendor/bin/phpunit --filter AnnouncementTest
npm run check
git add themes/blueline/inc themes/blueline/assets themes/blueline/tests
git commit -m "feat(blueline): announcement banner with a date window"
```

---

### Task 7: Season-state break-glass

**Files:**
- Modify: `themes/blueline/inc/settings/defaults.php` (2 fields)
- Modify: `themes/blueline/inc/season-state.php`
- Test: `themes/blueline/tests/SeasonStateOverrideTest.php`

**Interfaces:**
- Consumes: `blueline_season_state()`
- Produces: an override that **expires**, and a persistent admin notice while active

Failure recovery, not routine use. An override with no expiry becomes the
site's permanent state and nobody remembers it is set — so the expiry is
mandatory and an override past it is ignored rather than honoured.

- [ ] **Step 1: Write the failing test**

```php
public function test_an_override_replaces_the_computed_state(): void {
	$this->set_override( 'playoffs', '2026-12-31' );

	$this->assertSame( 'playoffs', blueline_season_state( strtotime( '2026-08-15' ) ) );
}

public function test_an_expired_override_is_ignored(): void {
	$this->set_override( 'playoffs', '2026-01-01' );

	$this->assertNotSame( 'playoffs', blueline_season_state( strtotime( '2026-08-15' ) ) );
}

public function test_an_override_without_an_expiry_is_ignored(): void {
	$this->set_override( 'playoffs', '' );

	$this->assertNotSame( 'playoffs', blueline_season_state( strtotime( '2026-08-15' ) ) );
}

public function test_an_unknown_state_is_ignored(): void {
	$this->set_override( 'world_cup', '2026-12-31' );

	$this->assertNotSame( 'world_cup', blueline_season_state( strtotime( '2026-08-15' ) ) );
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/phpunit --filter SeasonStateOverrideTest`
Expected: FAIL

- [ ] **Step 3: Apply the override at the top of `blueline_season_state()`**

Validate against the same five known states the hero uses, require a future
expiry, and fall through to the computed state otherwise.

- [ ] **Step 4: Add the persistent admin notice**

A `<p class="notice notice-warning">` (never a `div`) on every admin screen
while an override is active, naming the forced state and its expiry date.

- [ ] **Step 5: Run the tests, the gate, and commit**

```bash
./vendor/bin/phpunit --filter SeasonStateOverrideTest
npm run check
git add themes/blueline/inc themes/blueline/tests
git commit -m "feat(blueline): season-state break-glass with a mandatory expiry"
```

---

### Task 8: The remaining copy fields

**Files:**
- Modify: `themes/blueline/inc/settings/defaults.php`
- Modify: `themes/blueline/inc/homepage-modules.php:1105`
- Modify: `themes/blueline/inc/account/dashboard.php` (empty-state lines)
- Test: `themes/blueline/tests/SettingsDefaultsTest.php`

Four fields, each with `'placeholders' => array()`:
`module_new_here_heading`, `module_new_here_cta`,
`account_empty_next_game`, `account_empty_stats`.

- [ ] **Step 1: Write the failing test**

```php
public function test_the_new_here_heading_comes_from_settings(): void {
	$state = &blueline_test_state();
	$state['options']['blueline_settings']['module_new_here_heading'] = 'Never skated? Come anyway.';

	ob_start();
	blueline_homepage_module_new_here();
	$html = (string) ob_get_clean();

	$this->assertStringContainsString( 'Never skated? Come anyway.', $html );
}
```

- [ ] **Step 2: Run it, implement, run it again**

Replace the hardcoded `__( 'Never played? Perfect.', 'blueline' )` at
`inc/homepage-modules.php:1105` with `blueline_settings( 'module_new_here_heading' )`,
keeping the current string as the default so nothing changes visually.

- [ ] **Step 3: Update the pinned field count and commit**

```bash
npm run check
git add themes/blueline
git commit -m "feat(blueline): remaining curated copy fields"
```

---

### Task 9: A repair path for already-stored bad values

**Files:**
- Modify: `themes/blueline/inc/settings/store.php`
- Modify: `themes/blueline/inc/cli/settings-command.php` (`repair` subcommand)
- Test: `themes/blueline/tests/SettingsRepairTest.php`

**Interfaces:**
- Produces: `blueline_settings_repair( array $stored ): array{settings:array, repaired:string[]}`

A value arriving via `wp db import` or a direct DB edit never passes through
the sanitizer. Today it stays broken forever and, for a field feeding
`sprintf`, takes the front end down. Reading is the wrong place to fix it —
that would silently mask the problem on every request — so this is an explicit,
reported repair.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_stored_value_that_would_fatal_is_replaced_by_its_default(): void {
	$result = blueline_settings_repair(
		array( 'hero_registration_headline' => 'save 50% today' )
	);

	$this->assertSame(
		blueline_settings_defaults()['hero_registration_headline'],
		$result['settings']['hero_registration_headline']
	);
	$this->assertContains( 'hero_registration_headline', $result['repaired'] );
}

public function test_a_valid_store_is_returned_untouched_and_reports_nothing(): void {
	$result = blueline_settings_repair( blueline_settings_defaults() );

	$this->assertSame( array(), $result['repaired'] );
}
```

- [ ] **Step 2: Run it, implement, run it again**

Walk the schema, run each stored value through `blueline_sanitize_field()`, and
replace anything returning `WP_Error` with that field's default, recording the
key. `wp blueline settings repair` prints the list and exits non-zero when it
changed anything, so it is usable as a deploy check.

- [ ] **Step 3: Gate and commit**

```bash
npm run check
git add themes/blueline
git commit -m "feat(blueline): repair path for already-stored invalid settings"
```

---

### Task 10: Save snapshots and import diff preview

**Files:**
- Create: `themes/blueline/inc/settings/snapshots.php`
- Modify: `themes/blueline/inc/settings/page.php` (restore UI)
- Modify: `themes/blueline/inc/cli/settings-command.php` (`--dry-run` on import)
- Test: `themes/blueline/tests/SettingsSnapshotsTest.php`

**Interfaces:**
- Produces: `blueline_settings_snapshot_take( array $settings ): void` (keeps the last 5)
- Produces: `blueline_settings_snapshot_list(): array`
- Produces: `blueline_settings_diff( array $from, array $to ): array`

- [ ] **Step 1: Write the failing test**

```php
public function test_only_the_last_five_snapshots_are_kept(): void {
	for ( $i = 0; $i < 8; $i++ ) {
		blueline_settings_snapshot_take( array( 'footer_heading' => 'v' . $i ) );
	}

	$this->assertCount( 5, blueline_settings_snapshot_list() );
}

public function test_a_diff_names_only_what_changed(): void {
	$diff = blueline_settings_diff(
		array( 'footer_heading' => 'The League', 'contact_email' => 'a@b.c' ),
		array( 'footer_heading' => 'The ARL',    'contact_email' => 'a@b.c' )
	);

	$this->assertSame( array( 'footer_heading' ), array_keys( $diff ) );
}
```

- [ ] **Step 2: Run it, implement, run it again**

Snapshots are taken in `blueline_settings_merge()` on
`pre_update_option_{$option}`, before the new value is written — so the
snapshot is the state being replaced, which is what a restore needs.

- [ ] **Step 3: Wire `--dry-run` onto import**

`wp blueline settings import --dry-run` prints the diff and writes nothing.

- [ ] **Step 4: Gate and commit**

```bash
npm run check
git add themes/blueline
git commit -m "feat(blueline): settings snapshots and import diff preview"
```

---

## Self-Review

**Spec coverage (§6.3, §6.7, §6.8):** Content copy — Task 8. Sections with
floors — Tasks 1-5. Links — already complete (verified: no hardcoded
`home_url()` paths remain except the site root). Commerce — already complete.
Announcement banner — Task 6. Break-glass — Task 7. Export/import — already
complete; the diff preview it was missing is Task 10. Snapshots — Task 10.
Cache purge, WP-CLI, Site Health — already complete. Repair path (P1a deferred
item 5) — Task 9.

**Not in this plan, deliberately:** colour control and Occasions are P2 and get
their own plan; §6.1's nested storage shape stays flat, since flattening was
already shipped and re-nesting would be a migration with no user-visible value.

**Known gap carried forward:** P1a deferred items 2 (reserved-key handling has
no single owner), 7 (`delete_option()` bypasses the purge), 8 (the purge
constant is unverified infra) and 10 (`page.php` parses on every front-end
request). None blocks this plan; all belong to a cleanup pass.
