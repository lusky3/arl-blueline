<?php
/**
 * Unit tests for the season module: the one definition of "the current SportsPress season", and
 * every plugin call site that reads it.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/season/season.php';
require_once __DIR__ . '/../includes/player-link/player-link.php';
require_once __DIR__ . '/../includes/seo-meta/seo-meta.php';
require_once __DIR__ . '/fixtures/current-season-scenarios.php';

/**
 * Covers includes/season/season.php, the claim pool's season and the SEO season name.
 */
final class CurrentSeasonTest extends TestCase {

	/**
	 * The get_posts() model saved in setUp() and restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_model;

	/**
	 * Fresh state, and an sp_event-aware get_posts() model in front of the suite's default one.
	 */
	protected function setUp(): void {
		parent::setUp();
		blueline_test_reset();
		blueline_test_reset_state();

		$this->previous_model               = $GLOBALS['bl_test_get_posts_model'] ?? null;
		$GLOBALS['bl_test_get_posts_model'] = blueline_test_current_season_get_posts_model( $this->previous_model );
	}

	/**
	 * Restore the shared get_posts() model.
	 */
	protected function tearDown(): void {
		$GLOBALS['bl_test_get_posts_model'] = $this->previous_model;
		parent::tearDown();
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
	 * Every plugin call site gives the shared answer, scenario by scenario.
	 *
	 * @param array $scenario One of blueline_test_current_season_scenarios().
	 */
	#[DataProvider( 'scenarios' )]
	public function test_every_plugin_call_site_agrees( array $scenario ): void {
		blueline_test_current_season_seed( $scenario );

		$this->assertSame( $scenario['current'], blueline_core_current_season_id(), 'current season' );
		$this->assertSame( $scenario['term_ids'], blueline_core_current_season_term_ids(), 'season plus playoffs' );
		$this->assertSame( $scenario['phase'], blueline_core_current_season_phase_term_id(), 'phase term' );

		$term = blueline_core_current_season_term();
		$this->assertSame( $scenario['current'], $term ? (int) $term->term_id : 0, 'term object' );

		$this->assertSame( $scenario['term_ids'], blueline_claim_pool_active_season_term_ids(), 'claim pool' );
		$this->assertSame( $term ? $term->name : '', blueline_core_seo_current_season_name(), 'SEO season name' );
	}

	/**
	 * The plugin file and the theme's copy are the same bytes, so the theme's fallback can never
	 * answer differently from the plugin.
	 */
	public function test_the_theme_carries_an_identical_copy(): void {
		$copy = dirname( __DIR__, 3 ) . '/themes/blueline/inc/current-season-core.php';

		$this->assertFileExists( $copy );
		$this->assertSame(
			hash_file( 'sha256', dirname( __DIR__ ) . '/includes/season/season.php' ),
			hash_file( 'sha256', $copy ),
			'Copy plugins/blueline-core/includes/season/season.php over themes/blueline/inc/current-season-core.php.'
		);
	}

	/**
	 * When the setting is usable no event query runs at all.
	 */
	public function test_a_usable_setting_needs_no_event_query(): void {
		blueline_test_current_season_seed( blueline_test_current_season_scenarios()['staging today: the setting and the next game agree'] );

		$queries                            = 0;
		$model                              = $GLOBALS['bl_test_get_posts_model'];
		$GLOBALS['bl_test_get_posts_model'] = static function ( array $args ) use ( $model, &$queries ) {
			if ( 'sp_event' === ( $args['post_type'] ?? '' ) ) {
				++$queries;
			}

			return $model( $args );
		};

		$this->assertSame( 674, blueline_core_current_season_id() );
		$this->assertSame( 0, $queries );
	}

	/**
	 * A next game handed in through the filter is used as is; the resolver does not query for one.
	 */
	public function test_a_supplied_next_game_skips_the_query(): void {
		blueline_test_current_season_seed( blueline_test_current_season_scenarios()['setting unset: the next game decides, as before'] );
		blueline_test_register_term( 700, 'sp_season', 'Elsewhere' );
		blueline_test_set_post_terms( 4242, 'sp_season', array( 700 ) );

		add_filter(
			'blueline_core_current_season_next_event_id',
			static function () {
				return 4242;
			}
		);

		$this->assertSame( 700, blueline_core_current_season_id() );
	}

	/**
	 * A supplied 0 means "nothing scheduled": the last game played decides.
	 */
	public function test_a_supplied_zero_falls_back_to_the_last_game_played(): void {
		blueline_test_current_season_seed( blueline_test_current_season_scenarios()['setting unset: the next game decides, as before'] );

		add_filter(
			'blueline_core_current_season_next_event_id',
			static function () {
				return 0;
			}
		);

		$this->assertSame( 674, blueline_core_current_season_id() );
		$this->assertSame( array( 674 ), blueline_core_current_season_active_event_term_ids() );
	}

	/**
	 * The answer is memoised for the request, and changing the setting clears the memo at once.
	 */
	public function test_changing_the_setting_clears_the_memo(): void {
		blueline_test_current_season_seed( blueline_test_current_season_scenarios()['staging today: the setting and the next game agree'] );
		$this->assertSame( 674, blueline_core_current_season_id() );

		$GLOBALS['bl_test_options']['sportspress_season'] = '666';
		$this->assertSame( 674, blueline_core_current_season_id(), 'memoised until something says it changed' );

		update_option( 'sportspress_season', '667' );
		$this->assertSame( 666, blueline_core_current_season_id(), 'the new setting, its Playoffs term read as the season' );
	}

	/**
	 * A cache-generation bump also starts the memo afresh.
	 */
	public function test_a_cache_generation_bump_clears_the_memo(): void {
		blueline_test_current_season_seed( blueline_test_current_season_scenarios()['staging today: the setting and the next game agree'] );
		$this->assertSame( 674, blueline_core_current_season_id() );

		$GLOBALS['bl_test_options']['sportspress_season']    = '666';
		$GLOBALS['bl_test_options']['blueline_sp_cache_gen'] = 7;

		$this->assertSame( 666, blueline_core_current_season_id() );
	}

	/**
	 * A season term edit clears the memo; an edit in another taxonomy does not.
	 */
	public function test_a_season_term_change_clears_the_memo(): void {
		blueline_test_current_season_seed( blueline_test_current_season_scenarios()['staging today: the setting and the next game agree'] );
		$this->assertSame( 674, blueline_core_current_season_id() );

		$GLOBALS['bl_test_options']['sportspress_season'] = '666';
		do_action( 'edited_term', 1, 1, 'sp_league' );
		$this->assertSame( 674, blueline_core_current_season_id() );

		do_action( 'edited_term', 674, 674, 'sp_season' );
		$this->assertSame( 666, blueline_core_current_season_id() );
	}

	/**
	 * Both filters reach the answer.
	 */
	public function test_the_filters_can_override_the_answer(): void {
		blueline_test_current_season_seed( blueline_test_current_season_scenarios()['staging today: the setting and the next game agree'] );

		add_filter(
			'blueline_core_current_season_term_ids',
			static function ( array $term_ids ) {
				return array_merge( $term_ids, array( 690 ) );
			}
		);
		$this->assertSame( array( 674, 680, 690 ), blueline_core_current_season_term_ids() );

		add_filter(
			'blueline_core_current_season_id',
			static function () {
				return 666;
			}
		);
		$this->assertSame( 666, blueline_core_current_season_id() );
	}

	/**
	 * Without SportsPress there is no current season, whatever the setting says.
	 */
	public function test_without_sportspress_there_is_none(): void {
		$GLOBALS['bl_test_options']['sportspress_season'] = '674';

		$this->assertSame( 0, blueline_core_current_season_id() );
		$this->assertSame( array(), blueline_core_current_season_term_ids() );
		$this->assertSame( 0, blueline_core_current_season_phase_term_id() );
		$this->assertNull( blueline_core_current_season_term() );
	}

	/**
	 * The pure decision: a usable setting wins without the fallback being worked out.
	 */
	public function test_the_decision_only_derives_when_the_setting_is_unusable(): void {
		$calls   = 0;
		$derived = static function () use ( &$calls ) {
			++$calls;
			return 674;
		};

		$this->assertSame( 666, blueline_core_decide_current_season_id( 666, $derived ) );
		$this->assertSame( 0, $calls );
		$this->assertSame( 674, blueline_core_decide_current_season_id( 0, $derived ) );
		$this->assertSame( 1, $calls );
		$this->assertSame( 0, blueline_core_decide_current_season_id( 0, static fn () => -3 ) );
	}
}
