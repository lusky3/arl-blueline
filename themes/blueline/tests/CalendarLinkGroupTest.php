<?php
/**
 * The add-to-calendar link pairs ("Apple / Outlook", "Google") are named as a
 * group (inc/sportspress/calendar.php), so a screen reader hears what the two
 * links do, not two bare words.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/account/dashboard.php';

/**
 * See this file's own docblock.
 */
final class CalendarLinkGroupTest extends TestCase {

	/**
	 * With a visible label on the page, the group is named by it.
	 */
	public function test_a_visible_label_names_the_group_by_id(): void {
		$this->assertSame(
			' role="group" aria-labelledby="bl-cal-label-1"',
			blueline_calendar_group_attrs( 'bl-cal-label-1', true )
		);
	}

	/**
	 * Without one, the group carries the text itself, escaped.
	 */
	public function test_text_names_the_group_directly(): void {
		$this->assertSame(
			' role="group" aria-label="Add this game to your calendar"',
			blueline_calendar_group_attrs( 'Add this game to your calendar' )
		);
		$this->assertStringNotContainsString( '<b>', blueline_calendar_group_attrs( 'A <b>"quoted"</b>' ) );
	}

	/**
	 * Publish a calendar for team 115100, so it has links to subscribe to.
	 */
	private function team_with_a_calendar(): void {
		blueline_test_reset_state();
		$state                      = &blueline_test_state();
		$state['post_types']        = array( 'sp_calendar', 'sp_team' );
		$state['posts'][115101]     = array(
			'status'    => 'publish',
			'permalink' => 'https://example.test/calendar/mammoth-arl',
			'type'      => 'sp_calendar',
			'title'     => 'mammoth-arl',
		);
		$state['post_meta'][115101] = array( 'sp_team' => '115100' );
	}

	/**
	 * Assert a rendered calendar block is one named group: its group label points at an element that exists
	 * and carries the visible words, and both links sit inside it.
	 *
	 * @param string $html  The rendered block.
	 * @param string $css_class The block's class.
	 * @param string $words What the visible label says.
	 */
	private function assertNamedGroup( string $html, string $css_class, string $words ): void {
		$this->assertSame( 1, preg_match( '#<div class="' . $css_class . '" data-calendar-links role="group" aria-labelledby="([^"]+)">(.*?)</div>#s', $html, $group ), "$css_class is a named group" );
		$this->assertSame( 1, preg_match( '#<span class="' . $css_class . '__label" id="' . preg_quote( $group[1], '#' ) . '">([^<]*)</span>#', $html, $label ), "$css_class: the label the group points at exists" );
		$this->assertSame( $words, $label[1] );
		$this->assertStringContainsString( 'data-calendar="apple"', $group[2] );
		$this->assertStringContainsString( 'data-calendar="google"', $group[2] );
	}

	/**
	 * The team page and the account cards both name their group by a visible label (the event hero names
	 * its group by text, see EventHeroRenderTest).
	 */
	public function test_the_team_page_and_the_account_card_name_their_link_pair_as_a_group(): void {
		$this->team_with_a_calendar();

		ob_start();
		blueline_render_team_calendar_links( 115100 );
		$team = (string) ob_get_clean();

		ob_start();
		blueline_account_render_team_calendar( 115100, 'Subscribe to your team:' );
		$account = (string) ob_get_clean();

		$this->assertNamedGroup( $team, 'bl-sp-team-calendar', 'Take your schedule with you:' );
		$this->assertNamedGroup( $account, 'bl-account-calendar', 'Subscribe to your team:' );
	}

	/**
	 * Two blocks on one page get different label ids, so each group points at its own label.
	 */
	public function test_two_groups_on_one_page_do_not_share_a_label_id(): void {
		$this->team_with_a_calendar();

		ob_start();
		blueline_render_team_calendar_links( 115100 );
		blueline_render_team_calendar_links( 115100 );
		$html = (string) ob_get_clean();

		preg_match_all( '#aria-labelledby="([^"]+)"#', $html, $ids );
		$this->assertCount( 2, $ids[1] );
		$this->assertNotSame( $ids[1][0], $ids[1][1] );
	}
}
