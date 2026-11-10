<?php
/**
 * My Season for a player on two current teams (inc/account/player-data.php,
 * inc/account/dashboard.php).
 *
 * The module used to read the stat line of the first current team only, so a
 * skater on one team and a spare goalie on another saw half their season.
 * Like the My Team cards, each current team gets its own block, read from the
 * SportsPress league that team plays in.
 *
 * Every test here needs SP_Player, WP_Query and the season-state signals, so
 * it runs in a separate process with tests/fixtures/player-schedule-stubs.php.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/current-season.php';
require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/account/dashboard.php';
require_once __DIR__ . '/../inc/account/endpoint-toggles.php';

/**
 * See this file's own docblock.
 */
final class PlayerSeasonStatsTest extends TestCase {

	private const KINGS     = 12881;
	private const ICE_BEARS = 2570;
	private const PLAYER    = 500;
	private const SEASON    = 666;

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Stand-ins, then a player on Kings (league 6) and Ice Bears (league 7).
	 *
	 * SP_Player::data( $league ) answers per league, as SportsPress does: a
	 * different stat line for each, and a combined one for league 0.
	 *
	 * @param array<int, array<int, int>> $leagues The player's `sp_leagues` map, league => season => team.
	 */
	private function arrange( array $leagues ): void {
		require_once __DIR__ . '/fixtures/player-schedule-stubs.php';

		// Defined here, not in the shared fixture: theme code branches on class_exists( 'SP_Player' ).
		// Like SportsPress (class-sp-player.php), data() drops every season the
		// player's sp_leagues map has no team for in that league (missing or -1).
		eval( 'class SP_Player { public function __construct( public $id = 0 ) {} public function data( $league_id = 0 ) { $map = get_post_meta( $this->id, "sp_leagues", true ); $teams = is_array( $map ) && is_array( $map[ (int) $league_id ] ?? null ) ? $map[ (int) $league_id ] : array(); $rows = $GLOBALS["bl_test_sp_player_data"][ (int) $league_id ] ?? array(); foreach ( array_keys( $rows ) as $season ) { if ( -1 === (int) ( $teams[ $season ] ?? -1 ) ) { unset( $rows[ $season ] ); } } return $rows; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- separate-process stand-in for SportsPress's SP_Player.

		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_player', 'sp_team', 'sp_event' );
		$state['taxonomies'] = array( 'sp_season' );
		blueline_test_register_term( self::SEASON, 'sp_season', 'W2026-27' ); // The next game's season must be a real term.
		$state['post_meta'][ self::PLAYER ] = array(
			'sp_current_team' => new Blueline_Test_Meta_Rows( array( '0', (string) self::KINGS, (string) self::ICE_BEARS ) ),
			'sp_leagues'      => $leagues,
		);

		foreach ( array(
			self::KINGS     => 'Kings',
			self::ICE_BEARS => 'Ice Bears',
		) as $id => $title ) {
			$state['posts'][ $id ] = array(
				'status'    => 'publish',
				'permalink' => 'https://example.test/team/' . $id . '/',
				'type'      => 'sp_team',
				'title'     => $title,
			);
		}

		$GLOBALS['bl_test_object_term_ids'] = array( 'sp_season' => array( self::SEASON ) );
		$GLOBALS['bl_test_sp_player_data']  = array(
			0 => array(
				self::SEASON => array(
					'gp'  => 19,
					'g'   => 9,
					'a'   => 8,
					'pim' => 4,
				),
			),
			6 => array(
				self::SEASON => array(
					'gp'  => 12,
					'g'   => 7,
					'a'   => 5,
					'pim' => 2,
				),
			),
			7 => array(
				self::SEASON => array(
					'gp'  => 7,
					'g'   => 2,
					'a'   => 3,
					'pim' => 2,
				),
			),
		);

		// The season is read off the next game; a cached answer saves scripting the queries behind it.
		set_transient(
			BLUELINE_SEASON_STATE_TRANSIENT,
			array(
				'state'                => 'in_season',
				'product_id'           => null,
				'next_event_id'        => 900,
				'is_registration_open' => false,
				'is_playing'           => true,
				'is_waitlist_open'     => false,
			),
			900
		);
	}

	/**
	 * This season's division tables: Kings in league 6, Ice Bears in league 7.
	 */
	private function arrange_tables(): void {
		$state                 = &blueline_test_state();
		$state['post_types'][] = 'sp_table';
		blueline_test_register_term( 6, 'sp_league', 'Division 1' );
		blueline_test_register_term( 7, 'sp_league', 'Division 3' );

		foreach ( array(
			906 => array( self::KINGS, 6 ),
			907 => array( self::ICE_BEARS, 7 ),
		) as $table_id => [ $team_id, $league_id ] ) {
			$state['post_meta'][ $table_id ]  = array( 'sp_team' => new Blueline_Test_Meta_Rows( array( (string) $team_id ) ) );
			$state['post_terms'][ $table_id ] = array( 'sp_league' => array( $league_id ) );
		}

		$GLOBALS['bl_test_get_posts_model'] = static fn( array $args ) => 'sp_table' === ( $args['post_type'] ?? '' ) ? array( 906, 907 ) : null;
	}

	/**
	 * SPT-01: nobody saves a player in wp-admin when they are rostered, so
	 * `sp_leagues` has no entry for the new season, and SportsPress drops that
	 * season from data(). The stat line must still come back.
	 */
	#[RunInSeparateProcess]
	public function test_a_season_missing_from_sp_leagues_still_reads_the_season(): void {
		$this->arrange(
			array(
				6 => array( 600 => self::KINGS ),
				0 => array( 600 => 1 ),
			)
		);
		$state = &blueline_test_state();
		$state['post_meta'][ self::PLAYER ]['sp_current_team'] = new Blueline_Test_Meta_Rows( array( '0', (string) self::KINGS ) );

		$this->assertSame( 19, blueline_get_player_season_stats( self::PLAYER )['gp'], 'the all-leagues line, not zeros' );
	}

	/**
	 * SPT-01: an entry SportsPress does hold for the season (-1, "did not
	 * play this league") is left alone.
	 */
	#[RunInSeparateProcess]
	public function test_an_explicit_no_team_entry_is_respected(): void {
		$this->arrange( array( 0 => array( self::SEASON => -1 ) ) );

		$this->assertSame( 0, blueline_get_player_season_stats( self::PLAYER, null, 0 )['gp'] );
	}

	/**
	 * SPT-01: without an sp_leagues entry, the league is the one the team's
	 * own current-season table is in, so each team still gets its own line.
	 */
	#[RunInSeparateProcess]
	public function test_without_an_sp_leagues_entry_the_team_s_table_league_is_read(): void {
		$this->arrange( array() );
		$this->arrange_tables();

		$this->assertSame( 6, blueline_player_stats_league_id( self::PLAYER, self::SEASON, self::KINGS ) );
		$this->assertSame( 7, blueline_player_stats_league_id( self::PLAYER, self::SEASON, self::ICE_BEARS ) );
		$this->assertSame( 12, blueline_get_player_season_stats( self::PLAYER, self::KINGS )['gp'] );
		$this->assertSame( 7, blueline_get_player_season_stats( self::PLAYER, self::ICE_BEARS )['gp'] );

		$blocks = blueline_player_season_stat_blocks( self::PLAYER );
		$this->assertCount( 2, $blocks );
		$this->assertSame( 12, $blocks[0]['stats']['gp'] );
		$this->assertSame( 7, $blocks[1]['stats']['gp'] );
	}

	/**
	 * The stored meta is not changed: the season is only lent to data().
	 */
	#[RunInSeparateProcess]
	public function test_the_sp_leagues_meta_is_not_modified(): void {
		$this->arrange( array( 6 => array( 600 => self::KINGS ) ) );

		blueline_get_player_season_stats( self::PLAYER );

		$this->assertSame( array( 6 => array( 600 => self::KINGS ) ), get_post_meta( self::PLAYER, 'sp_leagues', true ) );
		$this->assertEmpty( array_filter( $GLOBALS['bl_test_hooks']['get_post_metadata'] ?? array() ), 'the lending filter is removed again' );
	}

	/**
	 * SPT-03: a two-team player's roster line on each team is that team's.
	 */
	#[RunInSeparateProcess]
	public function test_a_roster_reads_the_line_for_its_own_team(): void {
		$this->arrange( array() );
		$this->arrange_tables();

		$this->assertSame( 12, blueline_roster_season_stats( self::KINGS, array( self::PLAYER ) )[ self::PLAYER ]['gp'] );
		$this->assertSame( 7, blueline_roster_season_stats( self::ICE_BEARS, array( self::PLAYER ) )[ self::PLAYER ]['gp'] );
	}

	/**
	 * Capture a renderer's echoed output.
	 *
	 * @param callable $renderer Zero-arg callable that echoes markup.
	 * @return string
	 */
	private function render( callable $renderer ): string {
		ob_start();
		$renderer();
		return (string) ob_get_clean();
	}

	/**
	 * The league looked up depends on which team is asked about.
	 */
	#[RunInSeparateProcess]
	public function test_the_stats_league_follows_the_team_asked_about(): void {
		$this->arrange(
			array(
				6 => array( self::SEASON => self::KINGS ),
				7 => array( self::SEASON => self::ICE_BEARS ),
			)
		);

		$this->assertSame( 6, blueline_player_stats_league_id( self::PLAYER, self::SEASON, self::KINGS ) );
		$this->assertSame( 7, blueline_player_stats_league_id( self::PLAYER, self::SEASON, self::ICE_BEARS ) );
		$this->assertSame( 6, blueline_player_stats_league_id( self::PLAYER, self::SEASON ), 'no team asked for: the first one, as before' );
	}

	/**
	 * A team's stat line is the one from its own league, memoised per team.
	 */
	#[RunInSeparateProcess]
	public function test_season_stats_are_read_for_the_team_asked_about(): void {
		$this->arrange(
			array(
				6 => array( self::SEASON => self::KINGS ),
				7 => array( self::SEASON => self::ICE_BEARS ),
			)
		);

		$this->assertSame( 12, blueline_get_player_season_stats( self::PLAYER, self::KINGS )['gp'] );
		$this->assertSame( 7, blueline_get_player_season_stats( self::PLAYER, self::ICE_BEARS )['gp'] );
		$this->assertSame( 12, blueline_get_player_season_stats( self::PLAYER )['gp'], 'no team asked for: the first one, as before' );
	}

	/**
	 * Two teams in two leagues: one block each, in the My Team order.
	 */
	#[RunInSeparateProcess]
	public function test_each_team_in_its_own_league_gets_its_own_block(): void {
		$this->arrange(
			array(
				6 => array( self::SEASON => self::KINGS ),
				7 => array( self::SEASON => self::ICE_BEARS ),
			)
		);

		$blocks = blueline_player_season_stat_blocks( self::PLAYER );

		$this->assertCount( 2, $blocks );
		$this->assertSame( self::KINGS, $blocks[0]['team']['team_id'] );
		$this->assertSame( 12, $blocks[0]['stats']['gp'] );
		$this->assertSame( self::ICE_BEARS, $blocks[1]['team']['team_id'] );
		$this->assertSame( 7, $blocks[1]['stats']['gp'] );
	}

	/**
	 * When the leagues cannot tell the teams apart (SportsPress keeps one
	 * team per league and season), two blocks would print the same combined
	 * line twice under different team names. One honest combined block
	 * instead.
	 */
	#[RunInSeparateProcess]
	public function test_teams_the_leagues_cannot_tell_apart_share_one_combined_block(): void {
		$this->arrange( array( 6 => array( self::SEASON => self::KINGS ) ) );

		$blocks = blueline_player_season_stat_blocks( self::PLAYER );

		$this->assertCount( 1, $blocks );
		$this->assertNull( $blocks[0]['team'] );
		$this->assertSame( 19, $blocks[0]['stats']['gp'], 'the all-leagues line' );
	}

	/**
	 * A player on one team keeps the single "My season" block.
	 */
	#[RunInSeparateProcess]
	public function test_a_player_on_one_team_gets_one_block(): void {
		$this->arrange( array( 6 => array( self::SEASON => self::KINGS ) ) );
		$state = &blueline_test_state();
		$state['post_meta'][ self::PLAYER ]['sp_current_team'] = new Blueline_Test_Meta_Rows( array( '0', (string) self::KINGS ) );

		$blocks = blueline_player_season_stat_blocks( self::PLAYER );

		$this->assertCount( 1, $blocks );
		$this->assertSame( self::KINGS, $blocks[0]['team']['team_id'] );
		$this->assertSame( 12, $blocks[0]['stats']['gp'] );
	}

	/**
	 * A player with no team still gets the (zero-filled) block the module
	 * has always printed.
	 */
	#[RunInSeparateProcess]
	public function test_a_player_with_no_team_still_gets_a_block(): void {
		$this->arrange( array() );
		$state = &blueline_test_state();
		$state['post_meta'][ self::PLAYER ]['sp_current_team'] = new Blueline_Test_Meta_Rows( array( '0' ) );

		$blocks = blueline_player_season_stat_blocks( self::PLAYER );

		$this->assertCount( 1, $blocks );
		$this->assertNull( $blocks[0]['team'] );
	}

	/**
	 * The module prints a titled block per team, each with its own numbers.
	 */
	#[RunInSeparateProcess]
	public function test_the_module_prints_one_block_per_team(): void {
		$this->arrange(
			array(
				6 => array( self::SEASON => self::KINGS ),
				7 => array( self::SEASON => self::ICE_BEARS ),
			)
		);

		$html = $this->render( static fn() => blueline_account_render_season_stats( self::PLAYER ) );

		$this->assertStringContainsString( 'My season: Kings', $html );
		$this->assertStringContainsString( 'My season: Ice Bears', $html );
		$this->assertSame( 2, substr_count( $html, 'bl-account-stats"' ) );
		$this->assertStringContainsString( 'bl-account-module--season-stats"', $html );
		$this->assertStringContainsString( 'bl-account-module--season-stats-' . self::ICE_BEARS . '"', $html );
		$this->assertMatchesRegularExpression( '#<dd>12</dd>.*<dd>7</dd>#s', $html );
		$this->assertMatchesRegularExpression( '#<dd>7</dd>.*<dd>2</dd>.*<dd>3</dd>#s', $html );
	}

	/**
	 * One team: the title stays plain "My season".
	 */
	#[RunInSeparateProcess]
	public function test_the_module_for_one_team_keeps_its_plain_title(): void {
		$this->arrange( array( 6 => array( self::SEASON => self::KINGS ) ) );
		$state = &blueline_test_state();
		$state['post_meta'][ self::PLAYER ]['sp_current_team'] = new Blueline_Test_Meta_Rows( array( '0', (string) self::KINGS ) );

		$html = $this->render( static fn() => blueline_account_render_season_stats( self::PLAYER ) );

		$this->assertStringContainsString( '>My season<', $html );
		$this->assertStringNotContainsString( 'My season:', $html );
		$this->assertSame( 1, substr_count( $html, 'bl-account-stats"' ) );
	}
}
