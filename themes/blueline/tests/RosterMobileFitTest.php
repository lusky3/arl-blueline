<?php
/**
 * The team roster fits a phone without sideways scrolling.
 *
 * At 375px the Player column held name, photo and position badge on one
 * nowrap line (~190px) because `.bl-sp-roster__name-cell { white-space: normal }`
 * lost to the more specific `.sp-data-table td`, which pushed the G / A / PTS
 * columns off screen. These checks keep that rule specific, and keep the phone
 * rules that give the Player column room, each inside the media query it
 * belongs to and nowhere else. The layout itself is checked in a browser; here
 * the stylesheet is source-scanned.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * See this file's own docblock.
 */
final class RosterMobileFitTest extends TestCase {

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
	 * The stylesheet has several `@media (max-width: 480px)` and `(max-width: 700px)`
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

		foreach ( array( '@media (max-width: 700px)', '@media (max-width: 480px)' ) as $prelude ) {
			foreach ( $this->blocks( $css, $prelude ) as $block ) {
				$css = str_replace( $block, '', $css );
			}
		}

		return $css;
	}

	/**
	 * The name cell may wrap, with a selector that beats `.sp-data-table td`.
	 */
	public function test_the_name_cell_wrap_rule_outranks_the_data_table_cell_rule(): void {
		$css = $this->css();

		$this->assertMatchesRegularExpression( '/\.bl-sp-roster td\.bl-sp-roster__name-cell\s*\{\s*white-space:\s*normal;/', $css );
		$this->assertDoesNotMatchRegularExpression( '/^\.bl-sp-roster__name-cell\s*\{/m', $css, 'a bare-class rule loses to .sp-data-table td' );
	}

	/**
	 * Phones (700px and below) get tighter cells and a text-size name.
	 */
	public function test_phone_rules_tighten_cells_and_name(): void {
		$this->assertTrue( $this->media_has( '@media (max-width: 700px)', '/table\.bl-sp-roster :is\(th, td\) \{\s*padding-inline: var\(--bl-space-2\);/' ) );
		$this->assertTrue( $this->media_has( '@media (max-width: 700px)', '/\.bl-sp-roster__name \{\s*font-size: var\(--bl-text-base\);/' ) );
	}

	/**
	 * Small phones (480px and below) drop the photo and the empty slot that lines up photo-less rows.
	 */
	public function test_photo_and_its_slot_are_dropped_on_small_phones(): void {
		$this->assertTrue( $this->media_has( '@media (max-width: 480px)', '/\.bl-sp-roster__photo \{\s*display: none;/' ) );
		$this->assertTrue(
			$this->media_has(
				'@media (max-width: 480px)',
				'/\.bl-sp-roster:has\(\.bl-sp-roster__photo\) \.bl-sp-roster__name:not\(:has\(\.bl-sp-roster__photo\)\)::before \{\s*content: none;/'
			)
		);
	}

	/**
	 * None of the phone rules apply at every width: the photo, its slot and the tighter cells stay on desktop.
	 */
	public function test_the_phone_rules_do_not_leak_to_wider_screens(): void {
		$outside = $this->outside_media();

		$this->assertDoesNotMatchRegularExpression( '/\.bl-sp-roster__photo \{\s*display: none;/', $outside );
		$this->assertDoesNotMatchRegularExpression( '/table\.bl-sp-roster :is\(th, td\) \{/', $outside );
		$this->assertDoesNotMatchRegularExpression( '/\.bl-sp-roster__name:not\(:has\(\.bl-sp-roster__photo\)\)::before \{\s*content: none;/', $outside );
	}
}
