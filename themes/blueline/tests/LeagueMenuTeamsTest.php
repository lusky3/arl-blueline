<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/template-tags.php';

/**
 * Unit tests for the footer team directory's data source.
 */
final class LeagueMenuTeamsTest extends TestCase {

	/**
	 * Reset hooks/options/posts and re-register the front-end blanking filter
	 * the theme installs at load, so each test starts from the real arrangement.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
		blueline_test_reset_options();
		$GLOBALS['bl_test_hooks'] = array();
		add_filter( 'option_sportspress_league_menu_teams', 'blueline_sp_blank_frontend_option' );
	}

	/**
	 * Register $id as a published sp_team with a title.
	 *
	 * @param int    $id    Post id.
	 * @param string $title Team name.
	 * @param string $status Post status.
	 * @return void
	 */
	private function team( int $id, string $title, string $status = 'publish' ): void {
		$state                 = &blueline_test_state();
		$state['posts'][ $id ] = array(
			'status'    => $status,
			'permalink' => 'https://example.test/team/' . $id,
			'type'      => 'sp_team',
			'title'     => $title,
		);
	}

	/**
	 * The stored option is a serialised list of id STRINGS, in the order the
	 * admin arranged them; that order is the league's own and must survive.
	 */
	public function test_returns_configured_team_ids_in_admin_order(): void {
		$this->team( 115100, 'Mammoth' );
		$this->team( 79, 'Pylons' );
		$this->team( 2469, 'Spartans' );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '2469', '115100', '79' );

		$this->assertSame( array( 2469, 115100, 79 ), blueline_league_menu_team_ids() );
	}

	/**
	 * THE load-bearing one. The three option_* filters exist to stop
	 * SportsPress' League Menu prepending crest links to <body> ahead of the
	 * skip link. Reading the raw value means lifting one of them, and if it is
	 * not put back the plugin's own renderer wakes up again and the
	 * accessibility defect it was disabled for returns -- silently, because
	 * nothing else in the theme reads that option.
	 */
	public function test_the_blanking_filter_is_restored_after_reading(): void {
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array();

		blueline_league_menu_team_ids();

		$this->assertSame(
			'',
			apply_filters( 'option_sportspress_league_menu_teams', array( '115100' ) ),
			'the front-end blanking filter must still be attached after a read'
		);
	}

	/**
	 * A team pulled from the site (deleted, drafted, folded mid-season) must
	 * drop out rather than render a dead link in the footer.
	 */
	public function test_unpublished_and_missing_teams_are_dropped(): void {
		$this->team( 115100, 'Mammoth' );
		$this->team( 79, 'Pylons', 'draft' );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100', '79', '999999' );

		$this->assertSame( array( 115100 ), blueline_league_menu_team_ids() );
	}

	/**
	 * Nothing in SportsPress' own UI stops a team being added to the list
	 * twice, and a duplicated crest reads as a bug to anyone looking at it.
	 */
	public function test_duplicate_entries_are_collapsed(): void {
		$this->team( 115100, 'Mammoth' );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100', '115100' );

		$this->assertSame( array( 115100 ), blueline_league_menu_team_ids() );
	}

	/**
	 * An unconfigured league menu must yield nothing, so the footer renders no
	 * empty container.
	 */
	public function test_unconfigured_option_yields_no_teams(): void {
		$this->assertSame( array(), blueline_league_menu_team_ids() );

		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = '';
		$this->assertSame( array(), blueline_league_menu_team_ids() );
	}

	/**
	 * Junk in the stored array must not become a team id.
	 */
	public function test_non_scalar_and_zero_entries_are_ignored(): void {
		$this->team( 115100, 'Mammoth' );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '0', '', array( 'x' ), '115100' );

		$this->assertSame( array( 115100 ), blueline_league_menu_team_ids() );
	}

	/**
	 * With nothing configured the footer prints no markup at all, rather than
	 * an empty nav landmark a screen reader would still announce.
	 */
	public function test_directory_renders_nothing_when_unconfigured(): void {
		ob_start();
		blueline_footer_team_directory();
		$html = (string) ob_get_clean();

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * The rendered directory names each team and links it, and carries its own
	 * accessible name so it is distinguishable from the primary nav landmark.
	 */
	public function test_directory_renders_a_labelled_nav_with_team_links(): void {
		$this->team( 115100, 'Mammoth' );
		$this->team( 79, 'Pylons' );
		$GLOBALS['bl_test_options']['sportspress_league_menu_teams'] = array( '115100', '79' );

		ob_start();
		blueline_footer_team_directory();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aria-label="Teams"', $html );
		$this->assertStringContainsString( 'Mammoth', $html );
		$this->assertStringContainsString( 'Pylons', $html );
		$this->assertStringContainsString( 'https://example.test/team/115100', $html );
	}
}
