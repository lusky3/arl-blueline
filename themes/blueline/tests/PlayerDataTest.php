<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/player-data.php';

/**
 * Unit tests.
 */
final class PlayerDataTest extends TestCase {

	/**
	 * Test case.
	 */
	public function test_missing_stats_are_zero_filled(): void {
		$this->assertSame(
			array(
				'gp'  => 0,
				'g'   => 0,
				'a'   => 0,
				'pim' => 0,
			),
			blueline_normalize_player_stats( array() )
		);
	}

	/**
	 * Test case.
	 */
	public function test_string_values_are_cast_to_int(): void {
		$out = blueline_normalize_player_stats(
			array(
				'gp'  => '14',
				'g'   => '3',
				'a'   => '7',
				'pim' => '2',
			)
		);
		$this->assertSame(
			array(
				'gp'  => 14,
				'g'   => 3,
				'a'   => 7,
				'pim' => 2,
			),
			$out
		);
	}

	/**
	 * Test case.
	 */
	public function test_unknown_keys_are_dropped(): void {
		$out = blueline_normalize_player_stats(
			array(
				'gp'   => 5,
				'hits' => 99,
			)
		);
		$this->assertArrayNotHasKey( 'hits', $out );
		$this->assertSame( 5, $out['gp'] );
	}

	/**
	 * Test case.
	 */
	public function test_empty_string_becomes_zero_not_null(): void {
		$out = blueline_normalize_player_stats( array( 'g' => '' ) );
		$this->assertSame( 0, $out['g'] );
	}
}
