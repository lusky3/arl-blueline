<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/homepage-modules.php';

/**
 * Regression coverage for blueline_homepage_standings_tables_sort(), split
 * out of blueline_homepage_current_standings_tables() specifically because
 * a peer review caught this exact bug: WordPress's own `orderby => 'title'`
 * query sorts "Division 1".."Division 9" correctly as plain strings, but
 * puts "Division 10" before "Division 2" once a season reaches double
 * digits -- silently misordering the homepage tab strip
 * (blueline_homepage_standings_tabs()) built to handle exactly that scale
 * (assets/src/css/homepage.css's :nth-of-type pairing goes up to 12).
 */
final class HomepageStandingsTablesSortTest extends TestCase {

	/**
	 * A table entry with a given label; the id is never asserted on in
	 * these tests, so it's always 0.
	 *
	 * @param string $label The table's tab label.
	 * @return array{id:int, label:string}
	 */
	private function table( string $label ): array {
		return array(
			'id'    => 0,
			'label' => $label,
		);
	}

	/**
	 * The bug this function exists to fix: plain string order would put
	 * "10" and "11" before "2".."9".
	 */
	public function test_double_digit_divisions_sort_numerically_not_as_strings(): void {
		$tables = array(
			$this->table( '11' ),
			$this->table( '2' ),
			$this->table( '1' ),
			$this->table( '10' ),
		);

		$sorted = array_column( blueline_homepage_standings_tables_sort( $tables ), 'label' );

		$this->assertSame( array( '1', '2', '10', '11' ), $sorted );
	}

	/**
	 * The older lettered-group convention still sorts alphabetically.
	 */
	public function test_lettered_groups_sort_alphabetically(): void {
		$tables = array(
			$this->table( 'C' ),
			$this->table( 'A' ),
			$this->table( 'B' ),
		);

		$sorted = array_column( blueline_homepage_standings_tables_sort( $tables ), 'label' );

		$this->assertSame( array( 'A', 'B', 'C' ), $sorted );
	}

	/**
	 * A lettered subdivision ("4B") sorts by its own leading digit run, not
	 * as a whole string that happens to start the same way as "10".
	 */
	public function test_lettered_subdivision_sorts_by_its_leading_number(): void {
		$tables = array(
			$this->table( '10' ),
			$this->table( '4B' ),
			$this->table( '4A' ),
		);

		$sorted = array_column( blueline_homepage_standings_tables_sort( $tables ), 'label' );

		$this->assertSame( array( '4A', '4B', '10' ), $sorted );
	}

	/**
	 * An already-sorted, single-digit-only list (this site's own current
	 * scale) is left in the same order -- confirms the fix doesn't disturb
	 * the common case.
	 */
	public function test_single_digit_divisions_already_in_order_are_unchanged(): void {
		$tables = array(
			$this->table( '1' ),
			$this->table( '2' ),
			$this->table( '3' ),
		);

		$sorted = array_column( blueline_homepage_standings_tables_sort( $tables ), 'label' );

		$this->assertSame( array( '1', '2', '3' ), $sorted );
	}
}
