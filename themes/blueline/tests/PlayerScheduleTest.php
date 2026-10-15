<?php
/**
 * Unit tests.
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
require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/account/dashboard.php';
require_once __DIR__ . '/../inc/account/endpoint-toggles.php';

/**
 * A player's games and teams on the account pages, and the single-game
 * calendar links: which teams a season's standings table lists, a player on
 * two current teams, postponed/cancelled and TBD games, and the redirect for
 * a switched-off account tab.
 *
 * Tests that need WP_Query, WP_Term or SP_League_Table run in a separate
 * process and load tests/fixtures/player-schedule-stubs.php there.
 */
final class PlayerScheduleTest extends TestCase {

	private const KINGS     = 12881;
	private const ICE_BEARS = 2570;
	private const DUCKS     = 7000;
	private const PLAYER    = 500;

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		// The shared get_posts() stub matches a meta value as one string, which
		// a multi-row sp_current_team cannot be; no teammates is enough here.
		$GLOBALS['bl_test_get_posts_model'] = static function ( array $args ) {
			return 'sp_player' === ( $args['post_type'] ?? '' ) ? array() : null;
		};
	}

	/**
	 * Do not leak the get_posts() model into other test classes.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_test_get_posts_model'] );
	}

	/**
	 * Load the scripted stubs (separate-process tests only).
	 */
	private function load_stubs(): void {
		require_once __DIR__ . '/fixtures/player-schedule-stubs.php';
	}

	/**
	 * Register a published team.
	 *
	 * @param int    $id    Team post id.
	 * @param string $title Team name.
	 */
	private function team( int $id, string $title ): void {
		$state                 = &blueline_test_state();
		$state['posts'][ $id ] = array(
			'status'    => 'publish',
			'permalink' => 'https://example.test/team/' . $id . '/',
			'type'      => 'sp_team',
			'title'     => $title,
		);
	}

	/**
	 * Seed a player rostered on Kings and Ice Bears (Kings stored first),
	 * behind SportsPress's usual leading '0' placeholder row.
	 */
	private function seed_two_team_player(): void {
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_player', 'sp_team', 'sp_event' );

		$this->team( self::KINGS, 'Kings' );
		$this->team( self::ICE_BEARS, 'Ice Bears' );
		$this->team( self::DUCKS, 'Ducks' );

		$state['post_meta'][ self::PLAYER ] = array(
			'sp_current_team' => new Blueline_Test_Meta_Rows( array( '0', (string) self::KINGS, (string) self::ICE_BEARS ) ),
		);
	}

	/**
	 * Seed an sp_event with its teams (home first) and status.
	 *
	 * @param int    $event_id Event post id.
	 * @param int[]  $teams    Team ids, home first.
	 * @param string $status   sp_status meta.
	 */
	private function event( int $event_id, array $teams, string $status = 'ok' ): void {
		$state                           = &blueline_test_state();
		$state['posts'][ $event_id ]     = array(
			'status'    => 'future',
			'permalink' => 'https://example.test/event/' . $event_id . '/',
			'type'      => 'sp_event',
			'title'     => 'Event ' . $event_id,
		);
		$state['post_meta'][ $event_id ] = array(
			'sp_team'   => new Blueline_Test_Meta_Rows( array_map( 'strval', $teams ) ),
			'sp_status' => $status,
		);
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
	 * Tables created programmatically (every W2026-27 table) store their teams
	 * only as `sp_team` rows, with an empty `sp_teams` override -- the exact
	 * shape of staging table 117444, '0' placeholder included.
	 */
	public function test_table_teams_come_from_sp_team_rows(): void {
		$state                      = &blueline_test_state();
		$state['post_meta'][117444] = array(
			'sp_team'  => new Blueline_Test_Meta_Rows( array( '0', '79', '12580', '10652', '79' ) ),
			'sp_teams' => '',
		);

		$this->assertSame( array( 79, 12580, 10652 ), blueline_sp_table_team_ids( 117444 ) );
	}

	/**
	 * A table with no `sp_team` rows still falls back to the override keys.
	 */
	public function test_table_teams_fall_back_to_the_override_keys(): void {
		$state                      = &blueline_test_state();
		$state['post_meta'][116154] = array(
			'sp_teams' => array(
				111510 => array( 'w' => 5 ),
				0      => array(),
			),
		);

		$this->assertSame( array( 111510 ), blueline_sp_table_team_ids( 116154 ) );
	}

	/**
	 * When both are present the rows win: they are what SportsPress computes from.
	 */
	public function test_table_team_rows_win_over_the_override_keys(): void {
		$state                      = &blueline_test_state();
		$state['post_meta'][117444] = array(
			'sp_team'  => new Blueline_Test_Meta_Rows( array( '10652' ) ),
			'sp_teams' => array( 999 => array() ),
		);

		$this->assertSame( array( 10652 ), blueline_sp_table_team_ids( 117444 ) );
	}

	/**
	 * End to end on the W2026-27 shape: the team's current-season table is
	 * found, so its division and its record come back.
	 */
	#[RunInSeparateProcess]
	public function test_division_and_record_resolve_from_a_table_with_only_sp_team_rows(): void {
		$this->load_stubs();

		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_event', 'sp_table' );
		blueline_test_register_term( 674, 'sp_season', 'W2026-27' );
		blueline_test_register_term( 55, 'sp_league', 'Division 5' );
		blueline_test_set_post_terms( 117444, 'sp_league', array( 55 ) );

		$state['post_meta'][117443] = array( 'sp_team' => new Blueline_Test_Meta_Rows( array( '0', '79' ) ) );
		$state['post_meta'][117444] = array(
			'sp_team'  => new Blueline_Test_Meta_Rows( array( '0', '79', '12580', '10652' ) ),
			'sp_teams' => '',
		);

		set_transient(
			BLUELINE_SEASON_STATE_TRANSIENT,
			array(
				'state'                => 'in_season',
				'is_playing'           => true,
				'is_registration_open' => false,
				'next_event_id'        => 117518,
			)
		);
		$GLOBALS['bl_test_object_term_slugs'] = array( 'sp_season' => array( 674 ) );
		$GLOBALS['bl_test_get_posts_model']   = static function ( array $args ) {
			return 'sp_table' === ( $args['post_type'] ?? '' ) ? array( 117443, 117444 ) : null;
		};
		$GLOBALS['bl_test_team_records']      = array(
			10652 => array(
				'w'   => 1,
				'l'   => 0,
				'tie' => 0,
				'pts' => 2,
			),
		);

		$this->assertSame( 117444, blueline_team_current_table_id( 10652 ) );
		$this->assertSame( 'Division 5', blueline_player_division_name( 10652 ) );
		$this->assertSame( 2, blueline_get_team_record( 10652 )['pts'] ?? null );
		$this->assertNull( blueline_team_current_table_id( 424242 ), 'a team no table lists has no table' );
	}

	/**
	 * The staging W2026-27 shape for Ice Bears: the regular-season table 117448
	 * (sp_season w2026-27) and, created a second later, the playoff table 117449
	 * (sp_season w2026-27-playoffs, a CHILD of w2026-27). Both list the team, in
	 * the same division. The get_posts() model honours the tax_query the way
	 * WP_Query does: child terms match unless include_children is false, and
	 * the newest table comes first.
	 *
	 * @param int $schedule_season_id The sp_season term the next game carries.
	 */
	private function seed_regular_and_playoff_tables( int $schedule_season_id ): void {
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_event', 'sp_table' );

		blueline_test_register_term( 674, 'sp_season', 'W2026-27' );
		blueline_test_register_term( 680, 'sp_season', 'W2026-27 Playoffs', 674 );
		$state['terms'][674]->slug = 'w2026-27';
		$state['terms'][680]->slug = 'w2026-27-playoffs';
		blueline_test_register_term( 53, 'sp_league', 'Division 3' );
		$state['terms'][53]->slug = 'division-3';

		blueline_test_set_post_terms( 117448, 'sp_season', array( 674 ) );
		blueline_test_set_post_terms( 117449, 'sp_season', array( 680 ) );
		blueline_test_set_post_terms( 117448, 'sp_league', array( 53 ) );
		blueline_test_set_post_terms( 117449, 'sp_league', array( 53 ) );

		$teams                      = new Blueline_Test_Meta_Rows( array( '0', (string) self::ICE_BEARS ) );
		$state['post_meta'][117448] = array( 'sp_team' => $teams );
		$state['post_meta'][117449] = array( 'sp_team' => $teams );

		set_transient(
			BLUELINE_SEASON_STATE_TRANSIENT,
			array(
				'state'                => 'in_season',
				'is_playing'           => true,
				'is_registration_open' => false,
				'next_event_id'        => 117518,
			)
		);
		$GLOBALS['bl_test_object_term_slugs'] = array( 'sp_season' => array( $schedule_season_id ) );

		$GLOBALS['bl_test_get_posts_model'] = static function ( array $args ) {
			if ( 'sp_table' !== ( $args['post_type'] ?? '' ) ) {
				return null;
			}

			$state  = &blueline_test_state();
			$clause = $args['tax_query'][0];
			$wanted = array( (int) $clause['terms'] );

			if ( $clause['include_children'] ?? true ) {
				foreach ( $state['terms'] as $term ) {
					if ( 'sp_season' === $term->taxonomy && in_array( (int) $term->parent, $wanted, true ) ) {
						$wanted[] = (int) $term->term_id;
					}
				}
			}

			$found = array();
			foreach ( array( 117449, 117448 ) as $table_id ) { // Newest first, WP_Query's default order.
				if ( array_intersect( $state['post_terms'][ $table_id ]['sp_season'], $wanted ) ) {
					$found[] = $table_id;
				}
			}

			return $found;
		};

		$GLOBALS['bl_test_team_records'] = array(
			self::ICE_BEARS => array(
				'w'   => 3,
				'l'   => 0,
				'tie' => 0,
				'pts' => 6,
			),
		);
	}

	/**
	 * The record is the regular season's: the newer playoff table in the
	 * season's child playoff term must not win (staging showed 0-0-0 for a
	 * 3-0-0 team).
	 */
	#[RunInSeparateProcess]
	public function test_current_table_is_the_regular_season_table_not_its_playoff_child(): void {
		$this->load_stubs();
		$this->seed_regular_and_playoff_tables( 674 );

		$this->assertSame( 117448, blueline_team_current_table_id( self::ICE_BEARS ) );
		$this->assertSame( 'Division 3', blueline_player_division_name( self::ICE_BEARS ) );
	}

	/**
	 * Once playoff games are the next ones scheduled, the schedule's season is
	 * the playoff term; the team still resolves to its regular-season table
	 * (division and season record) rather than to nothing.
	 */
	#[RunInSeparateProcess]
	public function test_during_playoffs_the_regular_season_table_still_resolves(): void {
		$this->load_stubs();
		$this->seed_regular_and_playoff_tables( 680 );

		$this->assertSame( 117448, blueline_team_current_table_id( self::ICE_BEARS ) );
		$this->assertSame( 6, blueline_get_team_record( self::ICE_BEARS )['pts'] ?? null );
	}

	/**
	 * A table tagged with the regular season but also with a playoff term is
	 * still a playoff table and is skipped.
	 */
	#[RunInSeparateProcess]
	public function test_a_table_carrying_any_playoff_term_is_skipped(): void {
		$this->load_stubs();
		$this->seed_regular_and_playoff_tables( 674 );
		blueline_test_set_post_terms( 117449, 'sp_season', array( 674, 680 ) );

		$this->assertSame( 117448, blueline_team_current_table_id( self::ICE_BEARS ) );
	}

	/**
	 * Both current teams are read, first-stored first, and a draft one is skipped.
	 */
	public function test_every_published_current_team_is_returned(): void {
		$this->seed_two_team_player();
		$state                = &blueline_test_state();
		$state['posts'][9001] = array( 'status' => 'draft' );
		$state['post_meta'][ self::PLAYER ]['sp_current_team'] = new Blueline_Test_Meta_Rows( array( '0', '9001', (string) self::KINGS, (string) self::ICE_BEARS ) );

		$this->assertSame( array( self::KINGS, self::ICE_BEARS ), blueline_player_published_team_ids( self::PLAYER ) );
		$this->assertSame( array( self::KINGS, self::ICE_BEARS ), array_column( blueline_get_player_teams( self::PLAYER ), 'team_id' ) );
		$this->assertSame( self::KINGS, blueline_get_player_team( self::PLAYER )['team_id'] );
	}

	/**
	 * The next game is looked for across BOTH teams, skipping postponed and
	 * cancelled games, and reports which team is playing. Ice Bears play at
	 * 6:45 and Kings at 9:45 the same night (staging, user D): the query must
	 * not be scoped to the first team only.
	 */
	#[RunInSeparateProcess]
	public function test_next_game_spans_every_current_team_and_names_the_team(): void {
		$this->load_stubs();
		$this->seed_two_team_player();
		$this->event( 117518, array( self::ICE_BEARS, self::DUCKS ) );
		$GLOBALS['bl_test_wp_query_results'] = array( array( 117518 ) );

		$event = blueline_get_player_next_event( self::PLAYER );

		$this->assertSame( 117518, $event['event_id'] );
		$this->assertSame( self::ICE_BEARS, $event['team_id'] );
		$this->assertTrue( $event['is_home'] );
		$this->assertSame( self::DUCKS, $event['opponent_team_id'] );
		$this->assertSame( 'Ice Bears vs Ducks', blueline_account_event_matchup( $event ) );

		$args = $GLOBALS['bl_test_wp_query_args'][0];
		$this->assertSame( 1, $args['posts_per_page'] );
		$this->assertSame( 'ASC', $args['order'] );
		$this->assertSame(
			array(
				'key'     => 'sp_team',
				'value'   => array( (string) self::KINGS, (string) self::ICE_BEARS ),
				'compare' => 'IN',
			),
			$args['meta_query'][0]
		);
		$this->assertSame(
			array(
				'relation' => 'OR',
				array(
					'key'     => 'sp_status',
					'value'   => array( 'postponed', 'cancelled' ),
					'compare' => 'NOT IN',
				),
				array(
					'key'     => 'sp_status',
					'compare' => 'NOT EXISTS',
				),
			),
			$args['meta_query'][1]
		);
	}

	/**
	 * Away game for the player's second team: "@", with the team named.
	 */
	#[RunInSeparateProcess]
	public function test_an_away_game_names_the_team_with_an_at_sign(): void {
		$this->load_stubs();
		$this->seed_two_team_player();
		$this->event( 117521, array( self::DUCKS, self::KINGS ) );
		$GLOBALS['bl_test_wp_query_results'] = array( array( 117521 ) );

		$event = blueline_get_player_next_event( self::PLAYER );

		$this->assertSame( self::KINGS, $event['team_id'] );
		$this->assertFalse( $event['is_home'] );
		$this->assertSame( 'Kings @ Ducks', blueline_account_event_matchup( $event ) );
	}

	/**
	 * A player with no published team has no next game, and no query is run.
	 */
	#[RunInSeparateProcess]
	public function test_no_team_means_no_next_game(): void {
		$this->load_stubs();
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_player', 'sp_team', 'sp_event' );

		$this->assertNull( blueline_get_player_next_event( self::PLAYER ) );
		$this->assertSame( array(), $GLOBALS['bl_test_wp_query_args'] );
	}

	/**
	 * My Schedule lists the games after the next one (at most five), keeps
	 * called-off games in with their label, and says "Time TBD" rather than
	 * a placeholder time.
	 */
	#[RunInSeparateProcess]
	public function test_my_schedule_lists_the_following_games_with_status_and_tbd(): void {
		$this->load_stubs();
		$this->seed_two_team_player();
		$this->event( 1, array( self::KINGS, self::DUCKS ) );
		$this->event( 2, array( self::DUCKS, self::ICE_BEARS ), 'postponed' );
		$this->event( 3, array( self::KINGS, self::DUCKS ), 'tbd' );
		foreach ( array( 4, 5, 6, 7 ) as $id ) {
			$this->event( $id, array( self::KINGS, self::DUCKS ) );
		}
		// The next-game lookup, then the schedule lookup (which keeps called-off games).
		$GLOBALS['bl_test_wp_query_results'] = array( array( 1 ), array( 1, 2, 3, 4, 5, 6 ) );

		$html = $this->render( static fn() => blueline_account_render_schedule( self::PLAYER ) );

		$schedule_args = $GLOBALS['bl_test_wp_query_args'][1];
		$this->assertSame( 6, $schedule_args['posts_per_page'] );
		$this->assertSame( array( 'relation', 0 ), array_keys( $schedule_args['meta_query'] ), 'the schedule keeps called-off games' );

		$this->assertStringNotContainsString( 'event/1/', $html, 'the next game is already on the card above' );
		$this->assertSame( 5, substr_count( $html, 'class="bl-account-schedule__item"' ) );
		$this->assertStringContainsString( 'event/6/', $html );
		$this->assertStringContainsString( 'Ice Bears @ Ducks', $html );
		$this->assertStringContainsString( '<span class="bl-account-schedule__status">Postponed</span>', $html );
		$this->assertStringContainsString( 'Aug 20 · Time TBD', $html );
	}

	/**
	 * Adding status to the fingerprint must not flag every unchanged game as
	 * "Updated": an on-time game hashes as it always did.
	 */
	public function test_fingerprint_is_unchanged_for_an_on_time_game(): void {
		$legacy = md5( implode( '|', array( '1700000000', '5', '115100', '1' ) ) );

		$this->assertSame( $legacy, blueline_event_schedule_fingerprint( 1700000000, 5, 115100, true ) );
		$this->assertSame( $legacy, blueline_event_schedule_fingerprint( 1700000000, 5, 115100, true, 'ok' ) );
	}

	/**
	 * A game whose status changes reads as changed.
	 */
	public function test_fingerprint_changes_when_the_status_changes(): void {
		$ok = blueline_event_schedule_fingerprint( 1700000000, 5, 115100, true, 'ok' );

		foreach ( array( 'tbd', 'postponed', 'cancelled' ) as $status ) {
			$this->assertNotSame( $ok, blueline_event_schedule_fingerprint( 1700000000, 5, 115100, true, $status ), $status );
		}
	}

	/**
	 * One My Team card per current team, each titled with its team and
	 * carrying that team's own season-calendar links.
	 */
	public function test_my_team_renders_one_card_per_team_with_its_own_calendar(): void {
		$this->seed_two_team_player();
		$state                 = &blueline_test_state();
		$state['post_types'][] = 'sp_calendar';
		foreach ( array(
			801 => self::KINGS,
			802 => self::ICE_BEARS,
		) as $calendar_id => $team_id ) {
			$state['posts'][ $calendar_id ]     = array(
				'status'    => 'publish',
				'type'      => 'sp_calendar',
				'permalink' => 'https://example.test/calendar/' . $calendar_id . '/',
			);
			$state['post_meta'][ $calendar_id ] = array( 'sp_team' => (string) $team_id );
		}

		$html = $this->render( static fn() => blueline_account_render_my_team( self::PLAYER, true ) );

		$this->assertSame( 2, substr_count( $html, 'class="bl-account-team"' ) );
		$this->assertStringContainsString( 'My team: Kings', $html );
		$this->assertStringContainsString( 'My team: Ice Bears', $html );
		$this->assertStringContainsString( 'webcal://example.test/calendar/801/?feed=sp-ical', $html );
		$this->assertStringContainsString( 'webcal://example.test/calendar/802/?feed=sp-ical', $html );
		$this->assertLessThan(
			strpos( $html, 'My team: Ice Bears' ),
			strpos( $html, 'calendar/801/' ),
			'Kings\' calendar sits in the Kings card'
		);
		$this->assertSame( 1, preg_match_all( '/id="bl-account-module-title--my-team"/', $html ), 'heading ids stay unique' );
	}

	/**
	 * A single-team player keeps the plain "My team" title.
	 */
	public function test_a_single_team_keeps_the_plain_title(): void {
		$this->seed_two_team_player();
		$state = &blueline_test_state();
		$state['post_meta'][ self::PLAYER ]['sp_current_team'] = new Blueline_Test_Meta_Rows( array( (string) self::KINGS ) );

		$html = $this->render( static fn() => blueline_account_render_my_team( self::PLAYER ) );

		$this->assertStringContainsString( '>My team<', $html );
		$this->assertStringNotContainsString( 'My team:', $html );
	}

	/**
	 * Single-game calendar links: the location is the arena-and-pad label plus
	 * the street address in both the Google link and the .ics, not the bare
	 * pad name ("Black") a maps app cannot resolve.
	 */
	#[RunInSeparateProcess]
	public function test_single_game_calendar_links_carry_the_venue_label_and_address(): void {
		$this->load_stubs();
		$this->event( 117513, array( self::KINGS, self::DUCKS ) );
		blueline_test_register_term( 13, 'sp_venue', 'Black' );
		$state              = &blueline_test_state();
		$state['terms'][13] = new WP_Term( $state['terms'][13] );
		blueline_test_set_post_terms( 117513, 'sp_venue', array( 13 ) );
		$GLOBALS['bl_test_options']['taxonomy_13'] = array( 'sp_address' => '1179 Northside Rd, Burlington, ON L7M, Canada' );

		$location = 'Mr. Lube and Tires Arena — Black, 1179 Northside Rd, Burlington, ON L7M, Canada';

		$this->assertSame( $location, blueline_sp_event_calendar_location( 117513 ) );

		$google = blueline_sp_event_calendar_url( 117513 );
		parse_str( (string) wp_parse_url( $google, PHP_URL_QUERY ), $query );
		$this->assertSame( $location, $query['location'] );
		$this->assertSame( '20261016T230000Z/20261017T003000Z', $query['dates'] );

		$ics = rawurldecode( substr( blueline_sp_event_ics_url( 117513 ), strlen( 'data:text/calendar;charset=utf-8,' ) ) );
		// The line is longer than 75 octets, so it is folded; unfold before comparing.
		$this->assertStringContainsString( "\r\nLOCATION:Mr. Lube and Tires Arena — Black\\, 1179 Northside Rd\\, Burlington\\, ON L7M\\, Canada\r\n", str_replace( "\r\n ", '', $ics ) );
	}

	/**
	 * The single-game .ics reads as plain text in a calendar app: the
	 * texturized title is decoded (no `&#8217;`), a long name with an accent is
	 * folded at 75 octets without cutting the letter, and the data URI says
	 * `charset=utf-8`.
	 */
	#[RunInSeparateProcess]
	public function test_the_single_game_ics_is_plain_text_folded_and_utf_8(): void {
		$this->load_stubs();
		$this->event( 4865, array( self::KINGS, self::DUCKS ) );
		$state                         = &blueline_test_state();
		$state['posts'][4865]['title'] = "Kings vs Ducks (Dan&#8217;s Welding Caf\u{e9} Z\u{fc}rich Hockey Club Memorial Tournament Final)";

		$uri = blueline_sp_event_ics_url( 4865 );

		$this->assertStringStartsWith( 'data:text/calendar;charset=utf-8,', $uri );

		$ics = rawurldecode( substr( $uri, strlen( 'data:text/calendar;charset=utf-8,' ) ) );

		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), 'line: ' . $line );
			$this->assertTrue( mb_check_encoding( $line, 'UTF-8' ) );
		}

		$unfolded = str_replace( "\r\n ", '', $ics );
		$this->assertStringContainsString( "SUMMARY:Kings vs Ducks (Dan\u{2019}s Welding Caf\u{e9} Z\u{fc}rich Hockey Club Memorial Tournament Final)\r\n", $unfolded );
		$this->assertStringNotContainsString( '&#', $unfolded );

		parse_str( (string) wp_parse_url( blueline_sp_event_calendar_url( 4865 ), PHP_URL_QUERY ), $query );
		$this->assertStringNotContainsString( '&#', $query['text'], 'the Google link gets the plain title too' );
	}

	/**
	 * A venue with no known address keeps just its label.
	 */
	#[RunInSeparateProcess]
	public function test_calendar_location_without_an_address_is_the_label_alone(): void {
		$this->load_stubs();
		blueline_test_register_term( 20, 'sp_venue', 'Central Arena' );
		$state              = &blueline_test_state();
		$state['terms'][20] = new WP_Term( $state['terms'][20] );
		blueline_test_set_post_terms( 900, 'sp_venue', array( 20 ) );

		$this->assertSame( 'Central Arena', blueline_sp_event_calendar_location( 900 ) );
		$this->assertSame( '', blueline_sp_event_calendar_location( 901 ), 'no venue, no location' );
	}

	/**
	 * A TBD-timed game offers neither calendar link: its stored time is a placeholder.
	 */
	#[RunInSeparateProcess]
	public function test_a_tbd_game_has_no_calendar_links(): void {
		$this->load_stubs();
		$this->event( 117600, array( self::KINGS, self::DUCKS ), 'tbd' );
		$this->event( 117601, array( self::KINGS, self::DUCKS ) );

		$this->assertTrue( blueline_sp_event_time_is_tbd( 117600 ) );
		$this->assertSame( '', blueline_sp_event_calendar_url( 117600 ) );
		$this->assertSame( '', blueline_sp_event_ics_url( 117600 ) );
		$this->assertNotSame( '', blueline_sp_event_calendar_url( 117601 ), 'an on-time game keeps its link' );
	}

	/**
	 * The account card says "Time TBD" instead of a firm time.
	 */
	public function test_the_account_card_says_time_tbd(): void {
		$this->assertSame(
			'Aug 20 · Time TBD',
			blueline_account_event_when(
				array(
					'event_id' => 1,
					'status'   => 'tbd',
				)
			)
		);
		$this->assertSame(
			'Aug 20 at 7:00 PM',
			blueline_account_event_when(
				array(
					'event_id' => 1,
					'status'   => 'ok',
				)
			)
		);
	}

	/**
	 * The Countdown widget's scope carries stock's status exclusion, so the
	 * theme's stale-pick re-check cannot land on a postponed game either.
	 */
	public function test_countdown_scope_excludes_called_off_games(): void {
		$scope = blueline_sp_countdown_scope_args( 12881, 0, 674, array( 'postponed', 'cancelled' ) );

		$this->assertSame(
			array(
				'key'   => 'sp_team',
				'value' => 12881,
			),
			$scope['meta_query'][0]
		);
		$this->assertSame( 'NOT IN', $scope['meta_query'][1][0]['compare'] );
		$this->assertSame( array( 'postponed', 'cancelled' ), $scope['meta_query'][1][0]['value'] );
		$this->assertSame( 'NOT EXISTS', $scope['meta_query'][1][1]['compare'] );
		$this->assertSame( 'sp_season', $scope['tax_query'][0]['taxonomy'] );
		$this->assertCount( 2, $scope['tax_query'], 'relation plus the season; no league was configured' );
	}

	/**
	 * With "show excluded" on (no excluded statuses) there is no status clause,
	 * and with no team either, no meta_query at all.
	 */
	public function test_countdown_scope_without_exclusions(): void {
		$this->assertSame( array(), blueline_sp_countdown_scope_args( null, null, null, array() ) );
	}

	/**
	 * A request for a switched-off league tab is detected; one for a tab that
	 * is on, or for any other endpoint, is not.
	 */
	public function test_a_disabled_endpoint_request_is_detected(): void {
		$this->assertNull( blueline_account_disabled_endpoint_in_request( array( 'my-team' => '' ) ) );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_my_team' => false ) );

		$this->assertSame( 'my-team', blueline_account_disabled_endpoint_in_request( array( 'my-team' => '' ) ) );
		$this->assertNull( blueline_account_disabled_endpoint_in_request( array( 'my-schedule' => '' ) ) );
		$this->assertNull( blueline_account_disabled_endpoint_in_request( array( 'pagename' => 'account' ) ) );
	}

	/**
	 * /account/my-schedule/ with "My next game" off goes to the dashboard
	 * instead of rendering an empty page.
	 */
	#[RunInSeparateProcess]
	public function test_a_disabled_endpoint_redirects_to_the_dashboard(): void {
		$this->load_stubs();
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_next_game' => false ) );
		$GLOBALS['bl_test_is_account_page'] = true;
		$GLOBALS['wp']                      = (object) array( 'query_vars' => array( 'my-schedule' => '' ) );

		try {
			blueline_redirect_disabled_account_endpoint();
			$this->fail( 'expected a redirect' );
		} catch ( Blueline_Test_Redirect_Exception $redirect ) {
			$this->assertSame( 'https://example.test/account/', $redirect->location );
		}
	}

	/**
	 * With the tab on, or off the account page, nothing happens.
	 */
	#[RunInSeparateProcess]
	public function test_an_enabled_endpoint_is_left_alone(): void {
		$this->load_stubs();
		$GLOBALS['bl_test_is_account_page'] = true;
		$GLOBALS['wp']                      = (object) array( 'query_vars' => array( 'my-schedule' => '' ) );

		blueline_redirect_disabled_account_endpoint();

		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_next_game' => false ) );
		$GLOBALS['bl_test_is_account_page'] = false;

		blueline_redirect_disabled_account_endpoint();

		$this->addToAssertionCount( 1 );
	}
}
