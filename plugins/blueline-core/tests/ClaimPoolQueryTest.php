<?php
/**
 * Unit tests for the claim-pool query, run against the get_posts() model in tests/stubs/player-link.php.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';

/**
 * Covers blueline_current_season_unclaimed_player_ids(): who the pool offers for a claim.
 */
final class ClaimPoolQueryTest extends TestCase {

	/**
	 * Reset the fake-WordPress state and the request memos.
	 */
	protected function setUp(): void {
		parent::setUp();
		blueline_test_reset();
		blueline_test_reset_state();
		blueline_core_test_seed_claim_pool(
			5,
			'Cody Lusk',
			array(
				100 => 'Cody Lusk',
				101 => 'Wayne Gretzky',
				102 => 'Mario Lemieux',
			)
		);
	}

	/**
	 * Drop the fake $wpdb and the recorded queries.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['bl_core_test_claim_pool_queries'] );
		parent::tearDown();
	}

	/**
	 * Players with a team and no link are all offered.
	 */
	public function test_unlinked_players_with_a_team_are_in_the_pool(): void {
		$this->assertSame( array( 100, 101, 102 ), blueline_current_season_unclaimed_player_ids( 5 ) );
	}

	/**
	 * A player linked to a different user is never offered.
	 */
	public function test_a_player_linked_to_another_user_is_excluded(): void {
		$state = &blueline_test_state();
		$state['post_meta'][101][ BLUELINE_PLAYER_USER_META ] = '9';

		$this->assertSame( array( 100, 102 ), blueline_current_season_unclaimed_player_ids( 5 ) );
	}

	/**
	 * The claimant's own link stays in the pool, so re-checking it still works.
	 */
	public function test_the_claimants_own_link_stays_in_the_pool(): void {
		$state = &blueline_test_state();
		$state['post_meta'][101][ BLUELINE_PLAYER_USER_META ] = '5';

		$this->assertSame( array( 100, 101, 102 ), blueline_current_season_unclaimed_player_ids( 5 ) );
		$this->assertSame( array( 100, 102 ), blueline_current_season_unclaimed_player_ids( 6 ) );
	}

	/**
	 * A '' or '0' sp_user row is the "unclaimed" shape and does not exclude a player.
	 */
	public function test_placeholder_sp_user_rows_do_not_exclude_a_player(): void {
		$state = &blueline_test_state();
		$state['post_meta'][100][ BLUELINE_PLAYER_USER_META ] = '';
		$state['post_meta'][101][ BLUELINE_PLAYER_USER_META ] = '0';

		$this->assertSame( array( 100, 101, 102 ), blueline_current_season_unclaimed_player_ids( 5 ) );
	}

	/**
	 * A player with no team is not current, whatever else is true of them.
	 */
	public function test_a_player_without_a_current_team_is_excluded(): void {
		$state                                      = &blueline_test_state();
		$state['post_meta'][100]['sp_current_team'] = '0';
		unset( $state['post_meta'][101]['sp_current_team'] );

		$this->assertSame( array( 102 ), blueline_current_season_unclaimed_player_ids( 5 ) );
	}

	/**
	 * An unpublished player is not offered (get_posts() defaults to published posts).
	 */
	public function test_an_unpublished_player_is_excluded(): void {
		$state                         = &blueline_test_state();
		$state['posts'][102]['status'] = 'draft';

		$this->assertSame( array( 100, 101 ), blueline_current_season_unclaimed_player_ids( 5 ) );
	}

	/**
	 * With no usable sp_season pool the query carries no tax_query: the broad, team-only pool.
	 */
	public function test_without_season_terms_the_query_has_no_tax_query(): void {
		blueline_current_season_unclaimed_player_ids( 5 );

		$queries = $GLOBALS['bl_core_test_claim_pool_queries'];

		$this->assertCount( 1, $queries );
		$this->assertArrayNotHasKey( 'tax_query', $queries[0] );
		$this->assertSame( 'sp_player', $queries[0]['post_type'] );
		$this->assertSame( 'ids', $queries[0]['fields'] );
	}

	/**
	 * The query's own-link clause carries the claimant's ID as a string.
	 */
	public function test_the_own_link_clause_names_the_claimant(): void {
		blueline_current_season_unclaimed_player_ids( 42 );

		$or_group = $GLOBALS['bl_core_test_claim_pool_queries'][0]['meta_query'][1];

		$this->assertSame( 'OR', $or_group['relation'] );
		$this->assertSame( '42', $or_group[2]['value'] );
	}
}
