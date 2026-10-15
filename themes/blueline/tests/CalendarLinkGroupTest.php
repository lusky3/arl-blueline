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
	 * The event hero, the team page and the account cards all name their group.
	 */
	public function test_every_calendar_link_pair_is_a_named_group(): void {
		$hero    = (string) file_get_contents( __DIR__ . '/../inc/sportspress/heroes.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
		$team    = (string) file_get_contents( __DIR__ . '/../inc/sportspress/calendar.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
		$account = (string) file_get_contents( __DIR__ . '/../inc/account/dashboard.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( 'class="sp-scoreboard__calendar" data-calendar-links<?php echo blueline_calendar_group_attrs(', $hero );
		$this->assertStringContainsString( 'class="bl-sp-team-calendar" data-calendar-links<?php echo blueline_calendar_group_attrs(', $team );
		$this->assertStringContainsString( 'class="bl-account-calendar" data-calendar-links<?php echo blueline_calendar_group_attrs(', $account );
	}
}
