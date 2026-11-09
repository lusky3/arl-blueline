<?php
/**
 * The team roster fits a phone without sideways scrolling.
 *
 * At 375px the Player column held name, photo and position badge on one
 * nowrap line (~190px) because `.bl-sp-roster__name-cell { white-space: normal }`
 * lost to the more specific `.sp-data-table td`, which pushed the G / A / PTS
 * columns off screen. These checks keep that rule specific, and keep the phone
 * rules that give the Player column room. The layout itself is checked in a
 * browser; here the stylesheet is source-scanned.
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
	 * The name cell may wrap, with a selector that beats `.sp-data-table td`.
	 */
	public function test_the_name_cell_wrap_rule_outranks_the_data_table_cell_rule(): void {
		$css = $this->css();

		$this->assertMatchesRegularExpression( '/\.bl-sp-roster td\.bl-sp-roster__name-cell\s*\{\s*white-space:\s*normal;/', $css );
		$this->assertDoesNotMatchRegularExpression( '/^\.bl-sp-roster__name-cell\s*\{/m', $css, 'a bare-class rule loses to .sp-data-table td' );
	}

	/**
	 * Phones get tighter cells and a text-size name.
	 */
	public function test_phone_rules_tighten_cells_and_name(): void {
		$css = $this->css();

		$this->assertMatchesRegularExpression( '/@media \(max-width: 700px\) \{.*?table\.bl-sp-roster :is\(th, td\) \{\s*padding-inline:/s', $css );
		$this->assertMatchesRegularExpression( '/@media \(max-width: 700px\) \{.*?\.bl-sp-roster__name \{\s*font-size: var\(--bl-text-base\);/s', $css );
	}

	/**
	 * The photo, and the empty slot that lines up photo-less rows, go on small phones.
	 */
	public function test_photo_and_its_slot_are_dropped_on_small_phones(): void {
		$css = $this->css();

		$this->assertMatchesRegularExpression( '/@media \(max-width: 480px\) \{\s*\.bl-sp-roster__photo \{\s*display: none;/', $css );
		$this->assertMatchesRegularExpression( '/@media \(max-width: 480px\) \{.*?::before \{\s*content: none;/s', $css );
	}
}
