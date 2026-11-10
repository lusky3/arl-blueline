<?php
/**
 * The event page fits a phone.
 *
 * Three things were wrong at 360-375px: the details row (Date / Time /
 * Division / Season), the result table and the box score held
 * `min-width: max-content` and scrolled sideways, hiding Season, Outcome and
 * the stats; and a past-meeting card's title sometimes sat beside the floated
 * crests and sometimes below them, breaking a long name mid-word. The layout
 * itself is checked in a browser; here the stylesheet is source-scanned.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * See this file's own docblock.
 */
final class EventPageMobileTest extends TestCase {

	/**
	 * Read the SportsPress stylesheet source.
	 *
	 * @return string
	 */
	private function css(): string {
		return (string) file_get_contents( __DIR__ . '/../assets/src/css/sportspress.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
	}

	/**
	 * The three event tables may narrow below their content on phones.
	 */
	public function test_event_tables_fill_their_box_on_phones(): void {
		$this->assertMatchesRegularExpression(
			'/@media \(max-width: 700px\) \{\s*table\.sp-event-details,\s*table\.sp-event-results,\s*table\.sp-event-performance \{\s*width: 100%;\s*min-width: 0;/',
			$this->css()
		);
	}

	/**
	 * The details row is shown two by two, each label over its value.
	 */
	public function test_event_details_are_a_two_by_two_grid_on_phones(): void {
		$css = $this->css();

		$this->assertMatchesRegularExpression( '/table\.sp-event-details \{\s*display: grid;\s*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/', $css );
		$this->assertMatchesRegularExpression( '/table\.sp-event-details :is\(thead, tbody, tr\) \{\s*display: contents;/', $css );
		$this->assertMatchesRegularExpression( '/table\.sp-event-details th:nth-child\(-n\+2\) \{\s*grid-row: 1;.*?table\.sp-event-details td:nth-child\(-n\+2\) \{\s*grid-row: 2;/s', $css );
	}

	/**
	 * The box score drops Position and PIM on phones, and keeps the stat columns.
	 */
	public function test_box_score_drops_position_and_pim_only(): void {
		$css = $this->css();

		$this->assertMatchesRegularExpression( '/table\.sp-event-performance :is\(th, td\):is\(\.data-position, \.data-pim\) \{\s*display: none;/', $css );
		$this->assertDoesNotMatchRegularExpression( '/sp-event-performance[^{]*\.data-(g|a|ga|name|number)\b[^{]*\{\s*display: none/', $css );
	}

	/**
	 * On small phones a past-meeting card has small crests and its title below them.
	 */
	public function test_past_meeting_card_layout_is_fixed_on_small_phones(): void {
		$css = $this->css();

		$this->assertMatchesRegularExpression( '/@media \(max-width: 480px\) \{\s*\.sp-event-blocks \.team-logo \{\s*width: 44px;/', $css );
		$this->assertMatchesRegularExpression( '/@media \(max-width: 480px\) \{.*?\.sp-event-blocks \.sp-event-title \{\s*clear: both;/s', $css );
	}
}
