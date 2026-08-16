<?php
/**
 * Covers Task 4 of the P1b-panel-completion plan: the four site-chrome
 * section toggles (`chrome_sponsors`, `chrome_utility_nav`,
 * `chrome_footer_trust`, `chrome_footer_teams`) actually withhold what they
 * claim to control.
 *
 * `chrome_sponsors` is guarded differently from the other three, and
 * differently from fix round 1's first attempt at it. That first attempt
 * only pointed `sportspress_header_sponsors_selector` at an unmatchable
 * selector, on the theory that SportsPress would then have nowhere to
 * prepend its sponsor markup -- verified against the installed plugin
 * (SportsPress_Sponsors::header(), sportspress-pro's own
 * includes/sportspress-sponsors/sportspress-sponsors.php) to be wrong:
 * `header()` prints `.sp-header-sponsors` and runs the relocation script
 * regardless of what that selector resolves to, gated ONLY by
 * `get_option( 'sportspress_header_sponsors_limit', 0 )`. An unmatchable
 * selector just left the markup stranded wherever `wp_footer` printed it
 * instead of hidden. blueline_sp_header_sponsors_limit() (inc/sportspress.php)
 * is the real gate: it forces that option to 0 on the front end when the
 * toggle is off, so `header()`'s own `if ( $limit )` never opens at all.
 * blueline_header_sponsors_selector() stays as defence in depth only -- see
 * its own docblock.
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
		$GLOBALS['bl_test_is_admin']           = false;
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
	 * matches nothing on the page -- defence in depth only; the real gate
	 * is blueline_sp_header_sponsors_limit() below.
	 */
	public function test_the_sponsor_selector_is_unmatchable_when_disabled(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_sponsors' => false ) );

		$selector = blueline_header_sponsors_selector( '.default' );

		$this->assertNotSame( '.bl-header__sponsors', $selector );
		$this->assertNotSame( '', $selector, 'an empty selector risks a library treating it as "no selector given, use my own default"' );
	}

	/**
	 * Fix round 1, Important 3: `.bl-header__sponsors--disabled` (the first
	 * attempt's disabled-selector literal) was a BEM modifier of a class
	 * this theme really emits -- the single most likely future edit to that
	 * div (adding a state class to it) would have silently matched it again
	 * and re-enabled injection. Guards that the literal never reappears as
	 * live code anywhere in the theme's real (non-test) source.
	 *
	 * .php files are run through PHP's own tokenizer first, with every
	 * T_COMMENT/T_DOC_COMMENT token discarded, the same technique
	 * SchemaFieldCoverageTest and IncRequireCoverageTest use for the
	 * identical reason: this very method's own docblock, two paragraphs up,
	 * names the retired literal for explanatory purposes, and a raw
	 * substring scan would trip on that comment in inc/sportspress.php
	 * (which documents the retirement) as readily as on a real re-add of
	 * the class -- discovered exactly this way when this test was first
	 * written. .css/.js files are scanned as plain text: this theme's CSS
	 * comments are rare, and a BEM modifier written into a CSS file, inside
	 * a comment or not, is far more likely to be a real (if dormant)
	 * selector than TEMPLATE-file prose about it.
	 */
	public function test_the_theme_source_never_emits_the_old_disabled_sponsor_class(): void {
		$root     = dirname( __DIR__ );
		$excluded = array( '/vendor/', '/node_modules/', '/tests/', '/.git/', '/assets/dist/' );

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path = $file->getPathname();

			foreach ( $excluded as $skip ) {
				if ( false !== strpos( $path, $skip ) ) {
					continue 2;
				}
			}

			$extension = strtolower( $file->getExtension() );
			if ( ! in_array( $extension, array( 'php', 'css', 'js' ), true ) ) {
				continue;
			}

			$src = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

			if ( 'php' === $extension ) {
				$stripped = '';
				foreach ( token_get_all( $src ) as $token ) {
					if ( is_array( $token ) ) {
						if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
							continue;
						}
						$stripped .= $token[1];
					} else {
						$stripped .= $token;
					}
				}
				$src = $stripped;
			}

			$this->assertStringNotContainsString(
				'bl-header__sponsors--disabled',
				$src,
				"$path still emits the retired .bl-header__sponsors--disabled literal as live code"
			);
		}
	}

	/**
	 * The real gate fix round 1 introduced is
	 * blueline_sp_header_sponsors_limit(): on the front end, with the
	 * toggle off, it forces `sportspress_header_sponsors_limit` to 0 -- the
	 * exact value SportsPress_Sponsors::header() checks (`if ( $limit )`,
	 * confirmed in the installed plugin) before printing anything at all.
	 */
	public function test_the_sponsor_limit_is_forced_to_zero_on_the_front_end_when_disabled(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_sponsors' => false ) );

		$this->assertSame( 0, blueline_sp_header_sponsors_limit( 5 ) );
	}

	/**
	 * Companion accept path: with the toggle left on (the default), the
	 * real stored limit passes through untouched.
	 */
	public function test_the_sponsor_limit_passes_through_on_the_front_end_when_enabled(): void {
		$this->assertSame( 5, blueline_sp_header_sponsors_limit( 5 ) );
	}

	/**
	 * Scoped to the front end only, matching blueline_sp_blank_frontend_option()'s
	 * own convention: the Sponsors settings screen must keep showing/editing
	 * the site's actual saved limit even while the control-panel toggle is
	 * off, or turning that toggle off would look like the admin's own
	 * SportsPress setting silently changed.
	 */
	public function test_the_sponsor_limit_is_untouched_in_the_admin_even_when_disabled(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_sponsors' => false ) );
		$GLOBALS['bl_test_is_admin'] = true;

		$this->assertSame( 5, blueline_sp_header_sponsors_limit( 5 ) );

		$GLOBALS['bl_test_is_admin'] = false;
	}

	/**
	 * The 64px `.bl-header__sponsors` reservation (header.css) is skipped
	 * outright when `chrome_sponsors` is off, rather than printed and left
	 * to the CSS/JS collapse assets/src/js/sponsors.js performs for a
	 * DIFFERENT case (the slot being off site-wide via SportsPress's own
	 * settings). Fix round 1, Critical 1: nothing tested the rendered
	 * header at all before this -- only the returned selector string, which
	 * proved nothing about whether the slot div itself still printed.
	 */
	public function test_the_sponsor_slot_div_is_not_emitted_when_disabled(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_sponsors' => false ) );

		$html = $this->render_header();

		$this->assertStringNotContainsString( 'bl-header__sponsors', $html );
	}

	/**
	 * Companion accept path: with the toggle left on (the default), the
	 * slot div still renders.
	 */
	public function test_the_sponsor_slot_div_still_renders_when_enabled(): void {
		$html = $this->render_header();

		$this->assertStringContainsString( 'bl-header__sponsors', $html );
	}
}
