<?php
/**
 * Unit tests for the theme's side of "the current SportsPress season": every theme call site
 * against the shared fixtures blueline-core's suite also runs, using the theme's own copy of the
 * resolver (the plugin is not loaded here), and that copy's identity with the plugin's file.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/current-season.php';
require_once __DIR__ . '/../inc/homepage-modules.php';
require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/account/player-data.php';
require_once dirname( __DIR__, 3 ) . '/plugins/blueline-core/tests/fixtures/current-season-scenarios.php';

/**
 * Covers inc/current-season.php, inc/current-season-core.php and the theme surfaces that read them.
 */
final class CurrentSeasonTest extends TestCase {

	/**
	 * Fresh state and an sp_event-aware get_posts() model.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_object_terms']    = array();
		$GLOBALS['bl_test_get_posts_model'] = blueline_test_current_season_get_posts_model();
	}

	/**
	 * Do not leak the get_posts() model or the term store into other test classes.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_test_get_posts_model'], $GLOBALS['bl_test_object_terms'] );
	}

	/**
	 * Seed a scenario, and Season State's cached answer for its next game (the theme hands that to
	 * the resolver instead of letting it query).
	 *
	 * @param array $scenario One of blueline_test_current_season_scenarios().
	 */
	private function seed( array $scenario ): void {
		blueline_test_current_season_seed( $scenario );
		$next_event_id = blueline_test_current_season_next_live_event_id();

		set_transient(
			BLUELINE_SEASON_STATE_TRANSIENT,
			array(
				'state'                => 'in_season',
				'product_id'           => null,
				'next_event_id'        => $next_event_id ? $next_event_id : null,
				'is_registration_open' => false,
				'is_playing'           => true,
				'is_waitlist_open'     => false,
			)
		);
	}

	/**
	 * The shared scenarios, keyed by name.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function scenarios(): array {
		$cases = array();
		foreach ( blueline_test_current_season_scenarios() as $name => $scenario ) {
			$cases[ $name ] = array( $scenario );
		}

		return $cases;
	}

	/**
	 * Every theme call site gives the shared answer, scenario by scenario: the same answers the
	 * plugin's suite asserts for the claim pool and the SEO season name.
	 *
	 * @param array $scenario One of blueline_test_current_season_scenarios().
	 */
	#[DataProvider( 'scenarios' )]
	public function test_every_theme_call_site_agrees( array $scenario ): void {
		$this->seed( $scenario );

		$current = $scenario['current'] > 0 ? $scenario['current'] : null;
		$phase   = $scenario['phase'] > 0 ? $scenario['phase'] : null;

		$this->assertSame( $scenario['current'], blueline_current_season_id(), 'theme wrapper' );
		$this->assertSame( $current, blueline_current_sp_season_term_id(), 'account, rosters, team table' );
		$this->assertSame( $scenario['current'], blueline_team_events_current_season_id( 0 ), 'team page games' );
		$this->assertSame( $phase, blueline_homepage_active_event_season_term_id(), 'homepage standings' );
		$this->assertSame( $scenario['phase'], blueline_current_season_phase_term_id(), 'phase wrapper' );
		$this->assertSame( $scenario['term_ids'], blueline_core_current_season_term_ids(), 'season plus playoffs' );
	}

	/**
	 * The roster stats cache is keyed by the shared current season, so flipping the setting moves
	 * every roster to the new season's figures.
	 */
	public function test_the_roster_stats_cache_key_follows_the_current_season(): void {
		$this->seed( blueline_test_current_season_scenarios()['board has not flipped yet: the older setting wins'] );
		$this->assertSame( 666, blueline_current_sp_season_term_id() );

		blueline_roster_season_stats( 42, array( 7 ) );

		$this->assertNotFalse( get_transient( blueline_sp_cache_key( 'roster_stats', array( 42, 666 ) ) ) );
	}

	/**
	 * The theme's copy of the resolver is the plugin's file, byte for byte.
	 */
	public function test_the_theme_copy_is_identical_to_the_plugin_file(): void {
		$source = dirname( __DIR__, 3 ) . '/plugins/blueline-core/includes/season/season.php';
		if ( ! is_file( $source ) ) {
			$this->markTestSkipped( 'blueline-core source is not next to the theme in this checkout.' );
		}

		$this->assertSame(
			hash_file( 'sha256', $source ),
			hash_file( 'sha256', dirname( __DIR__ ) . '/inc/current-season-core.php' ),
			'Copy plugins/blueline-core/includes/season/season.php over themes/blueline/inc/current-season-core.php.'
		);
	}

	/**
	 * The theme hands Season State's next game to the resolver, and leaves another answer alone.
	 */
	public function test_the_theme_supplies_season_states_next_game(): void {
		$this->seed( blueline_test_current_season_scenarios()['setting unset: the next game decides, as before'] );

		$this->assertSame( 10, has_filter( 'blueline_core_current_season_next_event_id', 'blueline_current_season_next_event_id' ) );
		$this->assertSame( 117518, apply_filters( 'blueline_core_current_season_next_event_id', null ) );
		$this->assertSame( 5, blueline_current_season_next_event_id( 5 ) );
	}

	/**
	 * Season State's cached next game decides, not a fresh query: the resolver must not disagree
	 * with the hero's "Next game" line within the cache's lifetime.
	 */
	public function test_season_states_next_game_wins_over_a_query(): void {
		$this->seed( blueline_test_current_season_scenarios()['setting unset: the next game decides, as before'] );
		blueline_test_set_post_terms( 117600, 'sp_season', array( 680 ) );
		$GLOBALS['bl_test_object_terms']['sp_season'][117600] = array( 680 );

		$data                  = get_transient( BLUELINE_SEASON_STATE_TRANSIENT );
		$data['next_event_id'] = 117600;
		set_transient( BLUELINE_SEASON_STATE_TRANSIENT, $data );

		$this->assertSame( 680, blueline_homepage_active_event_season_term_id() );
	}
}
