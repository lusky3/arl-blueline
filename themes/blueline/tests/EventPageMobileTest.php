<?php
/**
 * The event page fits a phone.
 *
 * Three things were wrong at 360-375px: the details row (Date / Time /
 * Division / Season), the result table and the box score held
 * `min-width: max-content` and scrolled sideways, hiding Season, Outcome and
 * the stats; and a past-meeting card's title sometimes sat beside the floated
 * crests and sometimes below them, breaking a long name mid-word. These checks
 * keep each fix in the media query it belongs to and nowhere else, so desktop
 * layouts are untouched. The layout itself is checked in a browser; here the
 * stylesheet is source-scanned.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * See this file's own docblock.
 */
final class EventPageMobileTest extends TestCase {

	private const PHONE = '@media (max-width: 700px)';
	private const SMALL = '@media (max-width: 480px)';

	/**
	 * Read the SportsPress stylesheet source.
	 *
	 * @return string
	 */
	private function css(): string {
		return (string) file_get_contents( __DIR__ . '/../assets/src/css/sportspress.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
	}

	/**
	 * The contents of every at-rule that starts with a prelude, each up to its matching closing brace.
	 *
	 * The stylesheet has several `@media (max-width: 700px)` and `(max-width: 480px)`
	 * blocks, so a rule is looked for in each of them, never across them.
	 *
	 * @param string $css     Stylesheet source.
	 * @param string $prelude The at-rule's opening text, e.g. "@media (max-width: 700px)".
	 * @return string[]
	 */
	private function blocks( string $css, string $prelude ): array {
		$found  = array();
		$offset = 0;
		$len    = strlen( $css );

		while ( false !== ( $start = strpos( $css, $prelude . ' {', $offset ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- scanning for each occurrence.
			$open  = (int) strpos( $css, '{', $start );
			$depth = 0;

			for ( $i = $open; $i < $len; $i++ ) {
				if ( '{' === $css[ $i ] ) {
					++$depth;
				} elseif ( '}' === $css[ $i ] && 0 === --$depth ) {
					break;
				}
			}

			$found[] = substr( $css, $open + 1, $i - $open - 1 );
			$offset  = $i + 1;
		}

		return $found;
	}

	/**
	 * Whether any block of a media query matches a pattern.
	 *
	 * @param string $prelude The at-rule's opening text.
	 * @param string $pattern A regular expression.
	 * @return bool
	 */
	private function media_has( string $prelude, string $pattern ): bool {
		foreach ( $this->blocks( $this->css(), $prelude ) as $block ) {
			if ( 1 === preg_match( $pattern, $block ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The stylesheet with every media block cut out, i.e. the rules that apply at every width.
	 *
	 * @return string
	 */
	private function outside_media(): string {
		$css = $this->css();

		foreach ( array( self::PHONE, self::SMALL ) as $prelude ) {
			foreach ( $this->blocks( $css, $prelude ) as $block ) {
				$css = str_replace( $block, '', $css );
			}
		}

		return $css;
	}

	/**
	 * The three event tables may narrow below their content on phones.
	 */
	public function test_event_tables_fill_their_box_on_phones(): void {
		$this->assertTrue(
			$this->media_has(
				self::PHONE,
				'/table\.sp-event-details,\s*table\.sp-event-results,\s*table\.sp-event-performance \{\s*width: 100%;\s*min-width: 0;/'
			)
		);
	}

	/**
	 * The details row is shown two by two, each label over its value.
	 */
	public function test_event_details_are_a_two_by_two_grid_on_phones(): void {
		$this->assertTrue( $this->media_has( self::PHONE, '/table\.sp-event-details \{\s*display: grid;\s*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/' ) );
		$this->assertTrue( $this->media_has( self::PHONE, '/table\.sp-event-details :is\(thead, tbody, tr\) \{\s*display: contents;/' ) );
		$this->assertTrue( $this->media_has( self::PHONE, '/table\.sp-event-details th:nth-child\(-n\+2\) \{\s*grid-row: 1;.*?table\.sp-event-details td:nth-child\(-n\+2\) \{\s*grid-row: 2;/s' ) );
	}

	/**
	 * The box score drops Position and PIM on phones, and keeps the stat columns.
	 */
	public function test_box_score_drops_position_and_pim_only_on_phones(): void {
		$this->assertTrue( $this->media_has( self::PHONE, '/table\.sp-event-performance :is\(th, td\):is\(\.data-position, \.data-pim\) \{\s*display: none;/' ) );

		foreach ( $this->blocks( $this->css(), self::PHONE ) as $block ) {
			$this->assertDoesNotMatchRegularExpression( '/sp-event-performance[^{]*\.data-(g|a|ga|name|number)\b[^{]*\{\s*display: none/', $block );
		}
	}

	/**
	 * On small phones a past-meeting card has small crests and its title below them.
	 */
	public function test_past_meeting_card_layout_is_fixed_on_small_phones(): void {
		$this->assertTrue( $this->media_has( self::SMALL, '/\.sp-event-blocks \.team-logo \{\s*width: 44px;/' ) );
		$this->assertTrue( $this->media_has( self::SMALL, '/\.sp-event-blocks \.team-logo img \{\s*width: 100%;/' ) );
		$this->assertTrue( $this->media_has( self::SMALL, '/\.sp-event-blocks \.sp-event-title \{\s*clear: both;/' ) );
	}

	/**
	 * None of the phone rules apply at every width: desktop keeps its table, columns and crests.
	 */
	public function test_the_phone_rules_do_not_leak_to_wider_screens(): void {
		$outside = $this->outside_media();

		$this->assertDoesNotMatchRegularExpression( '/table\.sp-event-performance :is\(th, td\):is\(\.data-position, \.data-pim\)/', $outside );
		$this->assertDoesNotMatchRegularExpression( '/table\.sp-event-details \{\s*display: grid;/', $outside );
		$this->assertDoesNotMatchRegularExpression( '/table\.sp-event-details :is\(thead, tbody, tr\)/', $outside );
		$this->assertDoesNotMatchRegularExpression( '/\.sp-event-blocks \.sp-event-title \{\s*clear: both;/', $outside );
		$this->assertDoesNotMatchRegularExpression( '/\.sp-event-blocks \.team-logo \{\s*width: 44px;/', $outside );
	}
}
