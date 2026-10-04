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
	 *
	 * @param int    $id            The post ID of the team.
	 * @param string $title         The team title.
	 * @param bool   $has_thumbnail Whether the team has a featured image.
	 */
	private function team( int $id, string $title, bool $has_thumbnail = false ): void {
		$state                 = &blueline_test_state();
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
				'chrome_footer_teams'            => false,
				'chrome_team_directory_position' => 'flyout',
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

	/**
	 * Every configured team having a broken/missing permalink is the same
	 * "nothing to show" case as no teams being configured at all -- bail
	 * before printing anything, not an empty <details> shell that opens
	 * onto nothing.
	 */
	public function test_renders_nothing_when_every_team_has_no_permalink(): void {
		$state                  = &blueline_test_state();
		$state['posts'][115100] = array(
			'status'    => 'publish',
			'permalink' => '',
			'type'      => 'sp_team',
			'title'     => 'Mammoth',
		);
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100' );

		ob_start();
		blueline_render_team_flyout();
		$html = (string) ob_get_clean();

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * D-23/B-04: the flyout is position:fixed, so its DOM spot only sets tab
	 * order -- it is printed straight after the site header (after the skip
	 * link, before main content), not after the footer.
	 */
	public function test_flyout_is_printed_after_the_header_not_the_footer(): void {
		$strip  = static function ( string $file ): string {
			$code = '';
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$code .= is_array( $token ) ? $token[1] : $token;
			}
			return $code;
		};
		$header = $strip( __DIR__ . '/../header.php' );
		$footer = $strip( __DIR__ . '/../footer.php' );

		$this->assertStringNotContainsString( 'blueline_render_team_flyout(', $footer );
		$site_header = strpos( $header, 'blueline_site_header()' );
		$flyout      = strpos( $header, 'blueline_render_team_flyout()' );
		$this->assertNotFalse( $site_header );
		$this->assertNotFalse( $flyout );
		$this->assertGreaterThan( $site_header, $flyout );
		$this->assertLessThan( strpos( $header, 'blueline_render_announcement()' ), $flyout );
	}
}
