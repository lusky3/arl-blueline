<?php
/**
 * Covers Task 4 of the P1b-panel-completion plan: the four site-chrome
 * section toggles (`chrome_sponsors`, `chrome_utility_nav`,
 * `chrome_footer_trust`, `chrome_footer_teams`) actually withhold what they
 * claim to control.
 *
 * `chrome_sponsors` is guarded differently from the other three: rather than
 * an early return inside a renderer, blueline_header_sponsors_selector()
 * (inc/sportspress.php) hands SportsPress an unmatchable selector when the
 * toggle is off, so SportsPress's own enqueue never has anywhere to inject
 * into -- see that function's own docblock for why hiding an already-loaded
 * slot with CSS was rejected.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/template-tags.php';

/**
 * Covers the section-toggle guard on each of the four site-chrome surfaces.
 */
final class ChromeSectionsTest extends TestCase {

	/**
	 * Reset every stateful stub this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_nav_menu_locations'] = array();
	}

	/**
	 * Render blueline_site_footer() and capture its output.
	 *
	 * @return string
	 */
	private function render_footer(): string {
		ob_start();
		blueline_site_footer();
		return (string) ob_get_clean();
	}

	/**
	 * Render blueline_site_header() and capture its output.
	 *
	 * @return string
	 */
	private function render_header(): string {
		ob_start();
		blueline_site_header();
		return (string) ob_get_clean();
	}

	/**
	 * The brief's own test, verbatim in spirit: a footer team directory that
	 * would otherwise render (a real league menu is configured) renders
	 * nothing at all once `chrome_footer_teams` is switched off.
	 */
	public function test_the_footer_team_directory_can_be_switched_off(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_footer_teams' => false ) );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100' );

		ob_start();
		blueline_footer_team_directory();
		$html = (string) ob_get_clean();

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * Companion accept path: with the toggle left on (the default), a
	 * configured league menu still renders -- proves the guard added above
	 * the existing `blueline_league_menu_team_ids()` check didn't also
	 * silently break the untouched-install case LeagueMenuTeamsTest already
	 * covers.
	 */
	public function test_the_footer_team_directory_still_renders_when_enabled(): void {
		$state                  = &blueline_test_state();
		$state['posts'][115100] = array(
			'status'    => 'publish',
			'permalink' => 'https://example.test/team/115100',
			'type'      => 'sp_team',
			'title'     => 'Mammoth',
		);
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100' );

		ob_start();
		blueline_footer_team_directory();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Mammoth', $html );
	}

	/**
	 * The footer's "The League" trust column (contact, location, FAQs,
	 * legal) disappears entirely when `chrome_footer_trust` is off, while
	 * the rest of the footer (bottom bar, leaf mark, copyright) is
	 * unaffected -- this is one column of a larger footer, not the whole
	 * function.
	 */
	public function test_the_footer_trust_column_can_be_switched_off(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_footer_trust' => false ) );

		$html = $this->render_footer();

		$this->assertStringNotContainsString( 'bl-footer__column--trust', $html );
		$this->assertStringNotContainsString( 'bl-footer__location', $html );
		$this->assertStringContainsString( 'bl-footer__bottom', $html );
	}

	/**
	 * Companion accept path: with the toggle left on (the default), the
	 * trust column still renders -- proves the new conditional wrapper
	 * didn't also silently remove it for every untouched install, which is
	 * exactly the regression FooterAndHeroSettingsRenderTest would have
	 * caught if this test file didn't also cover it directly.
	 */
	public function test_the_footer_trust_column_still_renders_when_enabled(): void {
		$html = $this->render_footer();

		$this->assertStringContainsString( 'bl-footer__column--trust', $html );
	}

	/**
	 * The header's utility nav (account links) disappears when
	 * `chrome_utility_nav` is off, even though a real menu IS assigned to
	 * the 'utility' theme location -- proving the new
	 * `blueline_section_enabled()` check actually gates the block, not
	 * merely that an unassigned location renders nothing (which
	 * `has_nav_menu()` alone already guaranteed before this task).
	 */
	public function test_the_utility_nav_can_be_switched_off_even_with_a_menu_assigned(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_utility_nav' => false ) );
		$GLOBALS['bl_test_nav_menu_locations'] = array( 'utility' );

		$html = $this->render_header();

		$this->assertStringNotContainsString( 'bl-utility-nav', $html );
	}

	/**
	 * Companion accept path: with the toggle left on (the default) and a
	 * menu assigned to 'utility', the nav still renders.
	 */
	public function test_the_utility_nav_still_renders_when_enabled_and_assigned(): void {
		$GLOBALS['bl_test_nav_menu_locations'] = array( 'utility' );

		$html = $this->render_header();

		$this->assertStringContainsString( 'bl-utility-nav', $html );
	}

	/**
	 * The existing has_nav_menu( 'utility' ) check is untouched: with no
	 * menu assigned to that location at all, the nav still renders nothing,
	 * regardless of the toggle.
	 */
	public function test_the_utility_nav_still_renders_nothing_with_no_menu_assigned(): void {
		$html = $this->render_header();

		$this->assertStringNotContainsString( 'bl-utility-nav', $html );
	}

	/**
	 * With `chrome_sponsors` on (the default), SportsPress is pointed at the
	 * real sponsor slot.
	 */
	public function test_the_sponsor_selector_is_the_real_slot_when_enabled(): void {
		$this->assertSame( '.bl-header__sponsors', blueline_header_sponsors_selector( '.default' ) );
	}

	/**
	 * With `chrome_sponsors` off, SportsPress is pointed at a selector that
	 * matches nothing on the page -- not the real slot hidden with CSS,
	 * which would still make every visitor download the sponsor markup for
	 * a slot the admin turned off.
	 */
	public function test_the_sponsor_selector_is_unmatchable_when_disabled(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_sponsors' => false ) );

		$selector = blueline_header_sponsors_selector( '.default' );

		$this->assertNotSame( '.bl-header__sponsors', $selector );
		$this->assertNotSame( '', $selector, 'an empty selector risks a library treating it as "no selector given, use my own default"' );
	}
}
