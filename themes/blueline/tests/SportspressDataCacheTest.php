<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * PERF-01/PERF-03: the generation-keyed caches behind team rosters and league
 * tables, and the save hooks that retire them.
 */
final class SportspressDataCacheTest extends TestCase {

	/**
	 * Reset every in-memory store before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
		blueline_test_reset();
	}

	/**
	 * Whether blueline_sp_cache_bump() is queued on shutdown.
	 *
	 * @return bool
	 */
	private function bump_queued(): bool {
		foreach ( $GLOBALS['bl_test_hooks']['shutdown'] ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( 'blueline_sp_cache_bump' === $callback['cb'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Keys vary by every part and by generation, so a bump retires them all.
	 */
	public function test_key_changes_with_parts_and_generation(): void {
		$key = blueline_sp_cache_key( 'table', array( 12, '', 1 ) );

		$this->assertSame( 'bl_table_0_12__1', $key );
		$this->assertNotSame( $key, blueline_sp_cache_key( 'table', array( 13, '', 1 ) ) );

		blueline_sp_cache_bump();

		$this->assertSame( 'bl_table_1_12__1', blueline_sp_cache_key( 'table', array( 12, '', 1 ) ) );
	}

	/**
	 * Saving a SportsPress post queues a bump; saving anything else does not.
	 */
	public function test_only_sportspress_post_types_invalidate(): void {
		blueline_test_register_post( 501, 'publish' );
		$state                       = &blueline_test_state();
		$state['posts'][501]['type'] = 'post';

		blueline_sp_cache_on_post_change( 501 );
		$this->assertFalse( $this->bump_queued() );

		$state['posts'][501]['type'] = 'sp_event';
		blueline_sp_cache_on_post_change( 501 );
		$this->assertTrue( $this->bump_queued() );
	}

	/**
	 * Term and option changes invalidate only for SportsPress names.
	 */
	public function test_terms_and_options_invalidate_only_for_sportspress(): void {
		blueline_sp_cache_on_term_change( 1, 1, 'category' );
		blueline_sp_cache_on_option_change( 'blogname' );
		$this->assertFalse( $this->bump_queued() );

		blueline_sp_cache_on_option_change( 'sportspress_table_rows' );
		$this->assertTrue( $this->bump_queued() );
	}

	/**
	 * A cached stat line is served as-is; a player missing from the cache is
	 * computed and written back alongside it.
	 */
	public function test_roster_stats_read_through_cache(): void {
		$key   = blueline_sp_cache_key( 'roster_stats', array( 77, 0 ) );
		$line  = array(
			'gp'  => 14,
			'g'   => 2,
			'a'   => 3,
			'pim' => 4,
		);
		$zeros = array(
			'gp'  => 0,
			'g'   => 0,
			'a'   => 0,
			'pim' => 0,
		);
		set_transient( $key, array( 66 => $line ), 900 );

		$stats = blueline_roster_season_stats( 77, array( 66, 67, '67', 0 ) );

		$this->assertSame(
			array(
				66 => $line,
				67 => $zeros,
			),
			$stats
		);
		$this->assertSame(
			array(
				66 => $line,
				67 => $zeros,
			),
			get_transient( $key )
		);
	}

	/**
	 * A precomputed line renders the same five cells, PTS derived.
	 */
	public function test_render_uses_precomputed_stats(): void {
		ob_start();
		blueline_render_roster_stats(
			66,
			array(
				'gp'  => 14,
				'g'   => 2,
				'a'   => 3,
				'pim' => 4,
			)
		);
		$html = ob_get_clean();

		$this->assertSame(
			'<td class="bl-sp-roster__stat">14</td><td class="bl-sp-roster__stat">2</td><td class="bl-sp-roster__stat">3</td><td class="bl-sp-roster__stat">5</td><td class="bl-sp-roster__stat">4</td>',
			$html
		);
	}
}
