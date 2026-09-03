# Team Flyout Menu Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the admin control panel a second display position for the theme's
team directory — a sitewide left-edge flyout of gently bobbing crest bubbles —
alongside the existing footer directory, chosen via a new dropdown.

**Architecture:** A new admin setting (`chrome_team_directory_position`,
`'footer'` or `'flyout'`) makes the existing `blueline_footer_team_directory()`
and a new `blueline_render_team_flyout()` mutually exclusive. Both read the
same admin-configured team list (`blueline_league_menu_team_ids()`,
unchanged) and are gated by the same master on/off (`chrome_footer_teams`).
The flyout is a zero-JavaScript `<details>`/`<summary>` disclosure — the
same pattern the account nav's Billing dropdown already uses in this
codebase — with CSS driving a hover-reveal on non-touch input on top of the
native click/tap toggle.

**Tech Stack:** PHP 8.3 (WordPress/WooCommerce/SportsPress Pro theme), plain
CSS (no preprocessor; PostCSS via `wp-scripts build` bundles
`assets/src/css/*.css` into `assets/dist/index.css`), PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-09-03-blueline-team-flyout-design.md`

## Global Constraints

- Master toggle stays `chrome_footer_teams`, unrenamed, unmigrated — it now
  gates BOTH positions at once (spec: "Admin control panel").
- Reuse `blueline_league_menu_team_ids()` (`inc/sportspress.php`) unchanged
  as the only team-list data source — no new query, no new option.
- The flyout needs no JavaScript for open/close — `<details>`/`<summary>`,
  matching `woocommerce/myaccount/navigation.php`'s existing Billing
  dropdown precedent in this codebase.
- Scrollbar hidden on the flyout's scrollable panel (`scrollbar-width: none`
  + the `::-webkit-scrollbar` pair) — explicit instruction; scroll itself
  (wheel/trackpad/touch) must stay fully functional.
- A team with no featured image renders `blueline_leaf_mark()` (`inc/template-tags.php:300`),
  not a blank circle — DESIGN.md's "never an empty container" rule.
- The bob animation gets a `prefers-reduced-motion: reduce` off-switch —
  sitewide convention, no exceptions.
- Reuse `--bl-z-floating-widget` (`style.css`) for the flyout's z-index —
  same "sitewide persistent overlay chrome" tier as the existing floating
  next-game widget, no new z-index variable.
- Every new/changed PHP file passes `composer lint` and `composer test`;
  every new/changed CSS file passes `npm run lint:css`.

---

## Task 1: Add the team-directory position setting, and make the footer directory respect it

**Files:**
- Modify: `themes/blueline/inc/settings/defaults.php`
- Modify: `themes/blueline/inc/template-tags.php:984-1002` (`blueline_footer_team_directory()`)
- Test: `themes/blueline/tests/TeamDirectoryPositionTest.php` (create)
- Test: `themes/blueline/tests/ChromeSectionsTest.php` (extend)

**Interfaces:**
- Produces: `blueline_settings( 'chrome_team_directory_position' )` returns
  `'footer'` (default) or `'flyout'` — a plain string, read the same way
  every other settings field already is (`inc/settings/store.php`'s
  `blueline_settings()`).
- Consumes: `blueline_settings_schema()`, `blueline_settings_defaults()`,
  `blueline_section_enabled()` (all pre-existing, `inc/settings/*.php`).

- [ ] **Step 1: Write the failing test for the new setting's default and schema shape**

Create `themes/blueline/tests/TeamDirectoryPositionTest.php`:

```php
<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';

/**
 * Covers the `chrome_team_directory_position` setting: a two-choice field
 * (`footer`/`flyout`) that decides which of the two team-directory
 * renderers is active, independent of the `chrome_footer_teams` master
 * on/off it sits beside.
 */
final class TeamDirectoryPositionTest extends TestCase {

	/**
	 * Reset the stored option before each test so defaults are real
	 * defaults, not leftovers from a previous test.
	 */
	protected function setUp(): void {
		delete_option( BLUELINE_SETTINGS_OPTION );
	}

	/**
	 * An install that has never touched this field gets 'footer' -- the
	 * position the theme has always rendered, so installing this field
	 * changes no rendered output until an admin actually edits it.
	 */
	public function test_defaults_to_footer(): void {
		$this->assertSame( 'footer', blueline_settings( 'chrome_team_directory_position' ) );
	}

	/**
	 * An admin can store 'flyout' and read it back.
	 */
	public function test_flyout_can_be_selected(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_team_directory_position' => 'flyout' ) );

		$this->assertSame( 'flyout', blueline_settings( 'chrome_team_directory_position' ) );
	}

	/**
	 * The field is declared as a `choices` field restricted to exactly
	 * 'footer'/'flyout' -- SettingsDefaultsTest's generic schema-shape
	 * tests already assert every choices field's default is one of its own
	 * choices and every text/textarea field declares a `placeholders` key;
	 * this test pins the two literal choice keys themselves, which nothing
	 * else in the suite checks by name.
	 */
	public function test_choices_are_exactly_footer_and_flyout(): void {
		$schema = blueline_settings_schema();

		$this->assertArrayHasKey( 'chrome_team_directory_position', $schema );
		$this->assertSame(
			array( 'footer', 'flyout' ),
			array_keys( $schema['chrome_team_directory_position']['choices'] )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd themes/blueline && composer test -- --filter TeamDirectoryPositionTest`
Expected: FAIL — `blueline_settings( 'chrome_team_directory_position' )` returns `null`
(the key doesn't exist in `blueline_settings_defaults()` yet), and
`$schema['chrome_team_directory_position']` doesn't exist.

- [ ] **Step 3: Add the schema field and default value**

In `themes/blueline/inc/settings/defaults.php`, inside `blueline_settings_schema()`,
add this entry to the `$schema` array — right after the `foreach ( blueline_section_definitions() as $key => $def ) { ... }`
loop that generates the Sections-tab booleans (around line 419, just before
`return $schema;`):

```php
	// Not part of the auto-generated Sections-tab loop above: this field
	// needs `choices`, which that loop only ever emits as `type: 'section'`
	// booleans. Shares `chrome_footer_teams`'s tab and group so it renders
	// directly beside it in the admin UI.
	$schema['chrome_team_directory_position'] = array(
		'type'         => 'text',
		'tab'          => 'sections',
		'group'        => 'Site chrome',
		'label'        => 'Team directory position',
		'help'         => 'Only matters while the team directory above is on.',
		'placeholders' => array(),
		'choices'      => array(
			'footer' => 'Footer directory',
			'flyout' => 'Flyout menu',
		),
	);
```

In the same file, inside `blueline_settings_defaults()`, add the default —
right before the `foreach ( blueline_section_definitions() as $key => $unused_def )`
loop that sets every section toggle to `true` (around line 510, right after
`'occasions' => array(),`):

```php
		// Matches the position this theme has always rendered -- installing
		// this field must not move anything for an install that has never
		// opened the Sections tab.
		'chrome_team_directory_position' => 'footer',
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd themes/blueline && composer test -- --filter TeamDirectoryPositionTest`
Expected: PASS (3 tests, 3+ assertions)

- [ ] **Step 5: Run the full suite to confirm no regressions**

Run: `cd themes/blueline && composer test`
Expected: PASS — in particular `SettingsDefaultsTest` (the new field must
satisfy its generic shape assertions) and `SchemaFieldCoverageTest` (the new
field needs a real, non-test consumer — Step 7 below provides one).

- [ ] **Step 6: Write the failing test for the footer directory's new position guard**

In `themes/blueline/tests/ChromeSectionsTest.php`, add this test method
(inside the `final class ChromeSectionsTest extends TestCase { ... }` body,
near the existing `test_the_footer_team_directory_*` methods):

```php
	/**
	 * With `chrome_footer_teams` ON but the position set to 'flyout', the
	 * footer directory renders nothing -- the two positions are mutually
	 * exclusive, not both-on-by-default.
	 */
	public function test_the_footer_team_directory_yields_to_the_flyout_position(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_team_directory_position' => 'flyout' ) );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100' );

		ob_start();
		blueline_footer_team_directory();
		$html = (string) ob_get_clean();

		$this->assertSame( '', trim( $html ) );
	}
```

- [ ] **Step 7: Run the test to verify it fails**

Run: `cd themes/blueline && composer test -- --filter test_the_footer_team_directory_yields_to_the_flyout_position`
Expected: FAIL — the directory still renders, since nothing reads the new
setting yet.

- [ ] **Step 8: Add the position guard to `blueline_footer_team_directory()`**

In `themes/blueline/inc/template-tags.php`, inside `blueline_footer_team_directory()`
(currently starting at line 984), add the guard right after the existing
`chrome_footer_teams` check:

```php
function blueline_footer_team_directory() {
	if ( ! blueline_section_enabled( 'chrome_footer_teams' ) ) {
		return;
	}

	if ( 'footer' !== blueline_settings( 'chrome_team_directory_position' ) ) {
		return;
	}

	if ( ! function_exists( 'blueline_league_menu_team_ids' ) ) {
		return;
	}
	// ...rest of the function is unchanged...
```

(Only the new `if ( 'footer' !== ... )` block is added — everything else in
the function, including the existing `$team_ids = blueline_league_menu_team_ids();`
line and everything after it, stays exactly as it is today.)

- [ ] **Step 9: Run the test to verify it passes**

Run: `cd themes/blueline && composer test -- --filter test_the_footer_team_directory_yields_to_the_flyout_position`
Expected: PASS

- [ ] **Step 10: Run the full suite**

Run: `cd themes/blueline && composer test && composer lint`
Expected: both PASS. In particular the two pre-existing
`test_the_footer_team_directory_can_be_switched_off` and
`test_the_footer_team_directory_still_renders_when_enabled` tests must still
pass unchanged — the new guard must not affect the `position === 'footer'`
(default) path at all.

- [ ] **Step 11: Commit**

```bash
git add themes/blueline/inc/settings/defaults.php themes/blueline/inc/template-tags.php themes/blueline/tests/TeamDirectoryPositionTest.php themes/blueline/tests/ChromeSectionsTest.php
git commit -m "feat(blueline): add the team-directory position setting

Off/Footer/Flyout, via the existing chrome_footer_teams master toggle
plus a new chrome_team_directory_position choices field. The footer
directory now yields when the position is set to 'flyout' -- the
renderer for that position is added in the next task."
```

---

## Task 2: Create the team flyout renderer

**Files:**
- Create: `themes/blueline/inc/team-flyout.php`
- Modify: `themes/blueline/functions.php:67` (require, right after `inc/floating-next-game.php`)
- Modify: `themes/blueline/footer.php` (call site, right after `blueline_render_floating_next_game();`)
- Test: `themes/blueline/tests/TeamFlyoutTest.php` (create)

**Interfaces:**
- Consumes: `blueline_section_enabled()`, `blueline_settings()` (`inc/settings/store.php`),
  `blueline_league_menu_team_ids(): int[]` (`inc/sportspress.php`),
  `blueline_sp_title( int $id ): string` (`inc/sportspress.php`),
  `blueline_leaf_mark( string $extra_class = '' ): void` (`inc/template-tags.php:300`,
  echoes an inline SVG, no return value).
- Produces: `blueline_render_team_flyout(): void` — echoes the flyout markup,
  or nothing at all (same "renders nothing at all" shape as
  `blueline_render_floating_next_game()` and `blueline_footer_team_directory()`).

- [ ] **Step 1: Write the failing tests**

Create `themes/blueline/tests/TeamFlyoutTest.php`:

```php
<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/team-flyout.php';

/**
 * Covers blueline_render_team_flyout(): the flyout position's own
 * renderer, mutually exclusive with blueline_footer_team_directory()
 * (LeagueMenuTeamsTest, ChromeSectionsTest) via the position setting added
 * in the previous task.
 */
final class TeamFlyoutTest extends TestCase {

	/**
	 * Reset hooks/options/posts and re-register the front-end blanking
	 * filter the theme installs at load -- same setUp() LeagueMenuTeamsTest
	 * uses, since this file also reads `sportspress_league_menu_teams`
	 * through the same blanking filter.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
		blueline_test_reset_options();
		blueline_test_reset_hooks();
		add_filter( 'option_sportspress_league_menu_teams', 'blueline_sp_blank_frontend_option' );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_team_directory_position' => 'flyout' ) );
	}

	/**
	 * Register $id as a published sp_team with a title and permalink, with
	 * or without a featured image.
	 */
	private function team( int $id, string $title, bool $has_thumbnail = false ): void {
		$state           = &blueline_test_state();
		$state['posts'][ $id ] = array(
			'status'       => 'publish',
			'permalink'    => 'https://example.test/team/' . $id,
			'type'         => 'sp_team',
			'title'        => $title,
			'thumbnail_id' => $has_thumbnail ? $id + 9000 : 0,
		);
	}

	/**
	 * Renders nothing with the master toggle off, even with the position
	 * set to 'flyout' and teams configured -- the master toggle still gates
	 * both positions at once.
	 */
	public function test_renders_nothing_when_the_master_toggle_is_off(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'chrome_footer_teams'             => false,
				'chrome_team_directory_position'  => 'flyout',
			)
		);
		$this->team( 115100, 'Mammoth' );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100' );

		ob_start();
		blueline_render_team_flyout();
		$html = (string) ob_get_clean();

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * Renders nothing when the position is left at the default ('footer')
	 * -- this renderer is exclusively the 'flyout' position's own.
	 */
	public function test_renders_nothing_when_the_position_is_footer(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_team_directory_position' => 'footer' ) );
		$this->team( 115100, 'Mammoth' );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100' );

		ob_start();
		blueline_render_team_flyout();
		$html = (string) ob_get_clean();

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * Renders nothing when no league menu is configured -- same
	 * "never an empty container" contract the footer directory already
	 * has (LeagueMenuTeamsTest covers blueline_league_menu_team_ids()
	 * itself returning an empty array for this case).
	 */
	public function test_renders_nothing_with_no_teams_configured(): void {
		ob_start();
		blueline_render_team_flyout();
		$html = (string) ob_get_clean();

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * The happy path: master on, position 'flyout', teams configured --
	 * renders the <details> disclosure with a real link per team.
	 */
	public function test_renders_a_link_per_configured_team(): void {
		$this->team( 115100, 'Mammoth' );
		$this->team( 111510, 'Puck Dynasty' );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100', '111510' );

		ob_start();
		blueline_render_team_flyout();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<details class="bl-team-flyout">', $html );
		$this->assertStringContainsString( 'href="https://example.test/team/115100"', $html );
		$this->assertStringContainsString( 'Mammoth', $html );
		$this->assertStringContainsString( 'href="https://example.test/team/111510"', $html );
		$this->assertStringContainsString( 'Puck Dynasty', $html );
	}

	/**
	 * A team with no featured image gets the leaf-mark fallback
	 * (blueline_leaf_mark()) instead of a blank circle -- DESIGN.md's
	 * "never an empty container" rule, same fallback the account Player
	 * Profile bio card and claim card already use for this exact case.
	 */
	public function test_a_team_with_no_crest_gets_the_leaf_mark_fallback(): void {
		$this->team( 115100, 'Mammoth', false );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100' );

		ob_start();
		blueline_render_team_flyout();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bl-leaf-mark', $html );
	}

	/**
	 * A team WITH a featured image does not fall back to the leaf mark --
	 * proves the branch is conditional, not unconditional. (The crest
	 * <img> itself is not asserted here: get_the_post_thumbnail() has no
	 * stub in tests/bootstrap.php, the same limitation
	 * blueline_footer_team_directory()'s own tests already work around --
	 * this codebase's convention is to verify the real-photo path live,
	 * covered in Task 4.)
	 */
	public function test_a_team_with_a_crest_does_not_get_the_leaf_mark_fallback(): void {
		$this->markTestSkipped( 'get_the_post_thumbnail() has no test stub -- see this test\'s own docblock.' );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd themes/blueline && composer test -- --filter TeamFlyoutTest`
Expected: FAIL — `inc/team-flyout.php` doesn't exist yet, so
`require_once` at the top of the test file fatals.

- [ ] **Step 3: Create `themes/blueline/inc/team-flyout.php`**

```php
<?php
/**
 * The team flyout: a sitewide, left-edge <details> disclosure of team
 * crests, gently bobbing, one of two positions the team directory can
 * render in (the other being blueline_footer_team_directory(),
 * inc/template-tags.php) -- see docs/superpowers/specs/2026-09-03-blueline-
 * team-flyout-design.md.
 *
 * Deliberately its own file, same rationale inc/floating-next-game.php's
 * own docblock already gives for that file: sitewide chrome consumed from
 * footer.php on every template, not a My Account dashboard module, even
 * though it touches team data blueline_league_menu_team_ids() (inc/
 * sportspress.php) also backs.
 *
 * Needs no JavaScript for open/close: <details>/<summary> is the trigger,
 * the same zero-JS pattern woocommerce/myaccount/navigation.php's own
 * Billing dropdown already establishes in this codebase. assets/src/css/
 * team-flyout.css drives the hover-reveal on top of the native click/tap
 * toggle.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Print the team flyout, or nothing at all.
 *
 * Renders only when the team directory is switched on
 * (`chrome_footer_teams`), its position is set to `flyout`
 * (`chrome_team_directory_position`), and at least one team is configured
 * -- the same "never an empty container" contract every other sitewide
 * chrome renderer in this theme already follows.
 *
 * @return void
 */
function blueline_render_team_flyout(): void {
	if ( ! blueline_section_enabled( 'chrome_footer_teams' ) ) {
		return;
	}

	if ( 'flyout' !== blueline_settings( 'chrome_team_directory_position' ) ) {
		return;
	}

	if ( ! function_exists( 'blueline_league_menu_team_ids' ) ) {
		return;
	}

	$team_ids = blueline_league_menu_team_ids();

	if ( ! $team_ids ) {
		return;
	}
	?>
	<details class="bl-team-flyout">
		<summary>
			<span class="bl-team-flyout__label"><?php esc_html_e( 'Teams', 'blueline' ); ?></span>
		</summary>
		<nav class="bl-team-flyout__panel" aria-label="<?php esc_attr_e( 'Teams', 'blueline' ); ?>">
			<ul class="bl-team-flyout__list">
				<?php foreach ( $team_ids as $team_id ) : ?>
					<?php
					$name = function_exists( 'blueline_sp_title' ) ? blueline_sp_title( $team_id ) : get_the_title( $team_id );
					$link = get_permalink( $team_id );

					if ( ! $link ) {
						continue;
					}
					?>
					<li class="bl-team-flyout__item">
						<a class="bl-team-flyout__link" href="<?php echo esc_url( $link ); ?>">
							<span class="bl-team-flyout__crest">
								<?php if ( has_post_thumbnail( $team_id ) ) : ?>
									<?php
									/*
									 * Decorative here, same reasoning as
									 * blueline_footer_team_directory()'s own
									 * crest: the team name is the link's real
									 * accessible name via the screen-reader-
									 * text span below, so alt text here would
									 * make it announce twice.
									 */
									echo get_the_post_thumbnail(
										$team_id,
										'thumbnail',
										array(
											'alt'         => '',
											'loading'     => 'lazy',
											'aria-hidden' => 'true',
										)
									);
									?>
								<?php else : ?>
									<?php blueline_leaf_mark(); ?>
								<?php endif; ?>
							</span>
							<span class="screen-reader-text"><?php echo esc_html( $name ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</details>
	<?php
}
```

- [ ] **Step 4: Wire the require into `functions.php`**

In `themes/blueline/functions.php`, right after the existing
`require_once BLUELINE_DIR . '/inc/floating-next-game.php';` line (line 67):

```php
require_once BLUELINE_DIR . '/inc/team-flyout.php';
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd themes/blueline && composer test -- --filter TeamFlyoutTest`
Expected: PASS (5 tests run, 1 skipped, matching the docblock on the skipped
test)

- [ ] **Step 6: Wire the call site into `footer.php`**

In `themes/blueline/footer.php`, right after the existing
`blueline_render_floating_next_game();` line, before `wp_footer();`:

```php
/*
 * The flyout position of the team directory -- see inc/team-flyout.php's
 * own docblock. Same "persistent overlay chrome, not in-flow content"
 * placement as the floating next-game widget directly above; prints
 * nothing unless the team directory is on AND its position is set to
 * 'flyout' (blueline_render_team_flyout()'s own guard).
 */
blueline_render_team_flyout();
```

- [ ] **Step 7: Run the full suite**

Run: `cd themes/blueline && composer test && composer lint`
Expected: both PASS.

- [ ] **Step 8: Commit**

```bash
git add themes/blueline/inc/team-flyout.php themes/blueline/functions.php themes/blueline/footer.php themes/blueline/tests/TeamFlyoutTest.php
git commit -m "feat(blueline): add the team flyout's PHP renderer

blueline_render_team_flyout(), wired into footer.php beside the
existing floating next-game widget -- same sitewide, position:fixed
shape. Zero-JavaScript <details>/<summary> trigger. Not yet styled;
CSS is the next task."
```

---

## Task 3: Style the flyout

**Files:**
- Create: `themes/blueline/assets/src/css/team-flyout.css`
- Modify: `themes/blueline/assets/src/css/index.css` (`@import`)

**Interfaces:**
- Consumes: the exact class names Task 2 already emits — `.bl-team-flyout`,
  `.bl-team-flyout__label`, `.bl-team-flyout__panel`, `.bl-team-flyout__list`,
  `.bl-team-flyout__item`, `.bl-team-flyout__link`, `.bl-team-flyout__crest`
  — plus the design tokens `--bl-z-floating-widget`, `--bl-space-*`,
  `--bl-content-surface`, `--bl-content-border`, `--bl-content-bg`,
  `--bl-content-text`, `--bl-ice`, `--bl-ink`, `--bl-font-display`,
  `--bl-text-sm`, `--bl-radius-card` (all pre-existing, `style.css`).

- [ ] **Step 1: Create `themes/blueline/assets/src/css/team-flyout.css`**

```css
/*
 * Blueline — sitewide team flyout (the "flyout" position of the team
 * directory; the other position, blueline_footer_team_directory(), has no
 * CSS of its own beyond .bl-footer__teams* in footer.css).
 *
 * <details>/<summary> needs no JavaScript for the click/tap toggle -- the
 * same pattern woocommerce/myaccount/navigation.php's own Billing dropdown
 * already establishes in this theme. The browser's own UA stylesheet hides
 * every non-<summary> child while closed; .bl-team-flyout__panel below
 * overrides that back to `display: block` permanently so opacity/transform
 * can animate it instead, driven by :hover (desktop) and [open] (the
 * native click/tap toggle, works everywhere including touch) rather than
 * the browser's own abrupt show/hide.
 *
 * --bl-z-floating-widget (style.css's centralized scale), reused rather
 * than a new z-index variable: the same "sitewide persistent overlay
 * chrome" tier as the existing floating next-game widget.
 */

.bl-team-flyout {
	position: fixed;
	top: 50%;
	left: 0;
	transform: translateY(-50%);
	z-index: var(--bl-z-floating-widget);
}

.bl-team-flyout summary {
	list-style: none;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 96px;
	background: var(--bl-content-surface);
	border: 1px solid var(--bl-content-border);
	border-inline-start: none;
	border-radius: 0 var(--bl-radius-card) var(--bl-radius-card) 0;
}

.bl-team-flyout summary::-webkit-details-marker {
	display: none;
}

.bl-team-flyout summary:hover,
.bl-team-flyout summary:focus-visible,
.bl-team-flyout[open] summary {
	background: var(--bl-ice);
}

.bl-team-flyout__label {
	writing-mode: vertical-rl;
	transform: rotate(180deg);
	font-family: var(--bl-font-display);
	font-weight: 700;
	font-style: italic;
	text-transform: uppercase;
	font-size: var(--bl-text-sm);
	letter-spacing: 0.06em;
	color: var(--bl-content-text);
}

.bl-team-flyout summary:hover .bl-team-flyout__label,
.bl-team-flyout summary:focus-visible .bl-team-flyout__label,
.bl-team-flyout[open] summary .bl-team-flyout__label {
	color: var(--bl-ink);
}

.bl-team-flyout__panel {
	display: block;
	position: absolute;
	top: 50%;
	left: 0;
	transform: translateY(-50%) translateX(-12px);
	opacity: 0;
	pointer-events: none;
	box-sizing: border-box;
	max-height: min(70vh, 480px);
	width: 96px;
	overflow-y: auto;
	overflow-x: hidden;
	padding: var(--bl-space-4) var(--bl-space-2) var(--bl-space-4) calc(34px + var(--bl-space-2));
	transition: opacity 0.2s ease, transform 0.2s ease;
	scrollbar-width: none;
}

.bl-team-flyout__panel::-webkit-scrollbar {
	display: none;
}

.bl-team-flyout[open] .bl-team-flyout__panel {
	opacity: 1;
	pointer-events: auto;
	transform: translateY(-50%) translateX(0);
}

@media (hover: hover) {

	.bl-team-flyout:hover .bl-team-flyout__panel {
		opacity: 1;
		pointer-events: auto;
		transform: translateY(-50%) translateX(0);
	}
}

.bl-team-flyout__list {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: var(--bl-space-4);
	margin: 0;
	padding: 0;
	list-style: none;
}

.bl-team-flyout__crest {
	display: block;
	width: 56px;
	height: 56px;
	border-radius: 50%;
	overflow: hidden;
	box-sizing: border-box;
	background: var(--bl-content-bg);
	border: 2px solid var(--bl-content-border);
	padding: 4px;
	animation-name: bl-team-flyout-bob;
	animation-timing-function: ease-in-out;
	animation-iteration-count: infinite;
}

.bl-team-flyout__crest img,
.bl-team-flyout__crest svg {
	display: block;
	width: 100%;
	height: 100%;
	object-fit: contain;
}

/*
 * Three-way stagger via :nth-child, not per-element inline styles: this
 * markup has no JavaScript to compute per-item random values (Global
 * Constraints), and a fixed 3-phase cycle already reads as organic enough
 * at this item count -- confirmed against the brainstorming mockup, which
 * used JS-randomized values purely because that was easiest in a throwaway
 * prototype, not because the production version needs it.
 */
.bl-team-flyout__item:nth-child(3n+1) .bl-team-flyout__crest {
	animation-delay: 0s;
	animation-duration: 3.6s;
}

.bl-team-flyout__item:nth-child(3n+2) .bl-team-flyout__crest {
	animation-delay: 0.6s;
	animation-duration: 4.2s;
}

.bl-team-flyout__item:nth-child(3n) .bl-team-flyout__crest {
	animation-delay: 1.2s;
	animation-duration: 3.9s;
}

.bl-team-flyout__link:hover .bl-team-flyout__crest,
.bl-team-flyout__link:focus-visible .bl-team-flyout__crest {
	border-color: var(--bl-ice);
	animation-play-state: paused;
}

@keyframes bl-team-flyout-bob {

	0%,
	100% {
		transform: translateY(0);
	}

	50% {
		transform: translateY(-6px);
	}
}

@media (prefers-reduced-motion: reduce) {

	.bl-team-flyout__crest {
		animation: none;
	}

	.bl-team-flyout__panel {
		transition: none;
	}
}

@media (max-width: 480px) {

	.bl-team-flyout__panel {
		width: 84px;
	}

	.bl-team-flyout__crest {
		width: 48px;
		height: 48px;
	}
}
```

- [ ] **Step 2: Wire the `@import` into `index.css`**

In `themes/blueline/assets/src/css/index.css`, add this line directly after
the existing `@import "./floating-next-game.css";`:

```css
@import "./team-flyout.css";
```

- [ ] **Step 3: Build and lint**

Run: `cd themes/blueline && npm run build && npm run lint:css`
Expected: build succeeds, lint reports no errors.

- [ ] **Step 4: Run the full PHP suite once more (build must not have touched PHP behavior)**

Run: `cd themes/blueline && composer test`
Expected: PASS, same counts as Task 2's Step 7.

- [ ] **Step 5: Commit**

```bash
git add themes/blueline/assets/src/css/team-flyout.css themes/blueline/assets/src/css/index.css themes/blueline/assets/dist/
git commit -m "feat(blueline): style the team flyout

Hover-reveal on non-touch input layered on top of <details>'s native
click/tap toggle, hidden scrollbar (scroll stays functional), a
three-phase CSS-only bob stagger, and a prefers-reduced-motion
off-switch."
```

---

## Task 4: Live verification on staging

**Files:** none (deploy + browser verification only)

- [ ] **Step 1: Deploy to staging**

Run: `./scripts/deploy-theme.sh staging`
Expected: `Success: Rewrite rules flushed.` / `deployed staging` (no new
rewrite endpoints were added this feature, so the flush is a no-op here,
but the script always runs it).

- [ ] **Step 2: Set the position to "Flyout menu" in the admin control panel**

In the WordPress admin (Appearance → Blueline Settings, or wherever this
theme's settings page is registered — confirm via `inc/settings/page.php`
if the menu location isn't obvious), Sections tab: confirm "Footer team
directory" is on, set "Team directory position" to "Flyout menu", save.

- [ ] **Step 3: Verify the footer directory disappeared and the flyout appeared**

Visit the homepage (or any front-end page). Confirm: no team crest list
near the footer any more; a small "Teams" tab sits flush to the left edge,
vertically centered.

- [ ] **Step 4: Verify hover-open on desktop**

With a mouse, hover the tab (don't click). Confirm the panel slides open
smoothly, showing bobbing crest bubbles, scrollable, no visible scrollbar
track. Move the mouse away — confirm it closes again.

- [ ] **Step 5: Verify tap-toggle on mobile**

Using the browser's device-emulation (or a real narrow viewport, e.g.
≤480px), tap the tab. Confirm the panel opens (smaller crest size at this
width, per the `@media (max-width: 480px)` rule). Tap the tab again —
confirm it closes.

- [ ] **Step 6: Verify keyboard reachability**

Tab through the page with a keyboard only (no mouse). Confirm the "Teams"
`<summary>` receives focus and can be activated with Enter/Space to open
the panel, and each team link inside is individually reachable via Tab once
open, with a visible focus ring.

- [ ] **Step 7: Verify `prefers-reduced-motion`**

In the browser's dev tools, emulate `prefers-reduced-motion: reduce` (Chrome
DevTools: Rendering tab → "Emulate CSS media feature
prefers-reduced-motion"). Reopen the flyout — confirm the crest bubbles no
longer bob (sit still), while hover/focus still rings the active one.

- [ ] **Step 8: Verify the master toggle still turns off both positions at once**

In the admin, turn "Footer team directory" off entirely (leave the position
at "Flyout menu"). Reload the front end — confirm neither the footer
directory nor the flyout tab appears anywhere.

- [ ] **Step 9: Restore the admin setting to its pre-verification state**

Set "Team directory position" back to "Footer directory" (or whatever the
site's real, pre-feature admin configuration was) and confirm "Footer team
directory" is back on — this feature ships available, not switched on for
every visitor, unless the site owner explicitly opts into the new position.

- [ ] **Step 10: Report findings**

If every check in Steps 3-8 passed, the feature is verified working end to
end. If anything didn't match, fix it, redeploy, and re-verify just that
step before continuing.
