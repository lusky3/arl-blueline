<?php
/**
 * The homepage's data rules: which registration products the hero may sell,
 * the waitlist-only hero, the in-season week and games-this-week copy, and
 * keeping postponed/cancelled games off the next-game surfaces.
 *
 * Tests that need WP_Query or wc_get_product() run in a separate process and
 * load tests/fixtures/season-state-signal-stubs.php there, so those stubs
 * never leak into the shared suite.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/commerce.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/homepage-modules.php';
require_once __DIR__ . '/../inc/template-tags.php';

/**
 * Unit tests.
 */
final class HomepageSeasonRulesTest extends TestCase {

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		unset( $GLOBALS['bl_test_get_posts_model'] );
	}

	/**
	 * Load the scripted stubs (separate-process tests only).
	 */
	private function load_stubs(): void {
		require_once __DIR__ . '/fixtures/season-state-signal-stubs.php';
	}

	/**
	 * Seed a registration term with one season child term ("Fall 2026").
	 */
	private function seed_registration_season(): void {
		blueline_test_register_term( 91, 'product_cat', 'Registration' );
		blueline_test_register_term( 300, 'product_cat', 'Fall 2026', 91 );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'registration_term' => 91 ) );
	}

	/**
	 * Tag a product "Featured", the tag the hero sells from.
	 *
	 * @param int $product_id Product ID.
	 */
	private function feature( int $product_id ): void {
		blueline_test_register_term( 400, 'product_tag', 'Featured' );
		blueline_test_set_post_terms( $product_id, 'product_tag', array( 400 ) );
	}

	/**
	 * The week counts 7-day spans from the season's first game day, in
	 * site-local dates: day one through day seven are week 1, day eight
	 * starts week 2. Before the first game there is no week at all.
	 */
	public function test_week_number_counts_from_the_first_game_day(): void {
		$first = '2026-10-02 21:45:00';

		$this->assertSame( 1, blueline_homepage_season_week_number( $first, '2026-10-02 09:00:00' ) );
		$this->assertSame( 1, blueline_homepage_season_week_number( $first, '2026-10-08 23:59:00' ) );
		$this->assertSame( 2, blueline_homepage_season_week_number( $first, '2026-10-09 00:01:00' ) );
		$this->assertSame( 2, blueline_homepage_season_week_number( $first, '2026-10-13 12:00:00' ) );
		$this->assertSame( 16, blueline_homepage_season_week_number( $first, '2027-01-15 12:00:00' ) );
		$this->assertSame( 0, blueline_homepage_season_week_number( $first, '2026-10-01 12:00:00' ) );
		$this->assertSame( 0, blueline_homepage_season_week_number( '', '2026-10-13 12:00:00' ) );
	}

	/**
	 * A bye week: no games in the next seven days, so the headline names the
	 * next game's date instead of "0 games this week", and the eyebrow never
	 * prints a game count as a week number.
	 */
	public function test_in_season_bye_week_shows_the_next_game_instead_of_zero(): void {
		$content = blueline_homepage_hero_in_season_content( array( 'next_event_id' => 42 ) );

		$this->assertSame( 'Next game: <span class="bl-hero__highlight">Aug 20</span>', $content['headline_html'] );
		$this->assertStringNotContainsString( '0 games', $content['headline_html'] );
		$this->assertStringNotContainsString( 'Week', $content['eyebrow'] );
	}

	/**
	 * The waitlist hero says "Waitlist open", carries no price anywhere, and
	 * points at the register page.
	 */
	public function test_waitlist_hero_says_waitlist_open_with_no_price(): void {
		$content = blueline_homepage_hero_waitlist_content( array(), 'Winter 2026-27' );

		$this->assertSame( 'Winter 2026-27 · Waitlist open', $content['eyebrow'] );
		$this->assertSame( 'Join the waitlist', $content['cta_label'] );
		$this->assertSame( blueline_resolve_link( 'page_register' ), $content['cta_url'] );
		$this->assertSame( array(), $content['subcopy_lines'] );
		$this->assertStringNotContainsString( '$', implode( ' ', array( $content['eyebrow'], $content['headline_html'], $content['cta_label'] ) ) );
		$this->assertSame( 'Waitlist open', blueline_homepage_hero_waitlist_content( array(), '' )['eyebrow'] );
	}

	/**
	 * Like the registration hero, the waitlist hero still answers "when is
	 * my next game" while the season is being played.
	 */
	public function test_waitlist_hero_keeps_the_next_game_line_while_playing(): void {
		$content = blueline_homepage_hero_waitlist_content(
			array(
				'is_playing'    => true,
				'next_event_id' => 42,
			),
			'Winter 2026-27'
		);

		$this->assertSame( array( 'Next game: Aug 20' ), $content['subcopy_lines'] );
	}

	/**
	 * The Next games module asks only for games that are going ahead.
	 */
	public function test_next_games_query_excludes_postponed_and_cancelled_games(): void {
		$now  = strtotime( '2026-10-13T16:00:00+00:00' );
		$args = blueline_homepage_next_games_query_args( $now );

		$this->assertSame( blueline_season_state_live_event_meta_query(), $args['meta_query'] );
		$this->assertSame( array( 'postponed', 'cancelled' ), $args['meta_query'][1]['value'] );
		$this->assertSame( '2026-10-13 12:00:00', $args['date_query'][0]['after'] );
		$this->assertSame( 4, $args['posts_per_page'] );
	}

	/**
	 * Games this week: evaluated at the injected moment (site-local bounds,
	 * seven days wide) and never counting postponed or cancelled games.
	 */
	#[RunInSeparateProcess]
	public function test_games_this_week_uses_the_injected_moment_and_skips_games_not_going_ahead(): void {
		$this->load_stubs();
		$state                 = &blueline_test_state();
		$state['post_types'][] = 'sp_event';

		$GLOBALS['bl_test_wp_query_results'] = array( array( 501, 502, 503 ) );

		$this->assertSame( 3, blueline_homepage_games_this_week( strtotime( '2026-10-13T16:00:00+00:00' ) ) );

		$args = $GLOBALS['bl_test_wp_query_args'][0];
		$this->assertSame( '2026-10-13 12:00:00', $args['date_query'][0]['after'] );
		$this->assertSame( '2026-10-20 12:00:00', $args['date_query'][0]['before'] );
		$this->assertSame( blueline_season_state_live_event_meta_query(), $args['meta_query'] );
	}

	/**
	 * In season with games this week: the eyebrow carries the real week of
	 * the season (from the season's first game), not the game count.
	 */
	#[RunInSeparateProcess]
	public function test_in_season_eyebrow_carries_the_real_week_number(): void {
		$this->load_stubs();
		$state                 = &blueline_test_state();
		$state['post_types'][] = 'sp_event';
		$state['taxonomies'][] = 'sp_season';

		$GLOBALS['bl_test_wp_query_results']  = array( array( 501, 502, 503, 504, 505, 506, 507, 508, 509, 510, 511, 512, 513, 514, 515, 516 ) );
		$GLOBALS['bl_test_object_term_ids']   = array( 'sp_season' => array( 55 ) );
		$GLOBALS['bl_test_object_term_slugs'] = array( 'sp_season' => array( 'W2026-27' ) );
		$GLOBALS['bl_test_post_dates']        = array( 900 => '2026-10-02 21:45:00' );
		$GLOBALS['bl_test_get_posts_seen']    = array();
		$GLOBALS['bl_test_get_posts_model']   = static function ( array $args ) {
			$GLOBALS['bl_test_get_posts_seen'][] = $args;
			return 'sp_event' === ( $args['post_type'] ?? '' ) ? array( 900 ) : null;
		};

		$content = blueline_homepage_hero_in_season_content( array( 'next_event_id' => 501 ), strtotime( '2026-10-13T16:00:00+00:00' ) );

		$this->assertSame( 'W2026-27 · Week 2', $content['eyebrow'] );
		$this->assertStringContainsString( '<span class="bl-hero__highlight">16</span> games this week.', $content['headline_html'] );
		$this->assertSame( 55, $GLOBALS['bl_test_get_posts_seen'][0]['tax_query'][0]['terms'] );
		$this->assertSame( 'ASC', $GLOBALS['bl_test_get_posts_seen'][0]['order'] );
	}

	/**
	 * A Featured product the league has hidden from the catalogue is never
	 * offered on the hero (or the header CTA, which reads the same list),
	 * however purchasable and in stock it is; a visible one still is.
	 */
	#[RunInSeparateProcess]
	public function test_a_hidden_featured_product_is_never_offered(): void {
		$this->load_stubs();
		$this->seed_registration_season();
		$this->feature( 21 );
		blueline_test_set_post_terms( 22, 'product_tag', array( 400 ) );
		$GLOBALS['bl_test_wp_query_results'] = array( array( 21, 22 ) );
		$GLOBALS['bl_test_wc_products']      = array(
			21 => array( true, true, 'hidden', '555' ),
			22 => array( true, true, 'visible', '170' ),
		);

		$offers = blueline_homepage_registration_offers();

		$this->assertSame( array( 22 ), array_map( static fn( $offer ) => $offer['product']->get_id(), $offers ) );
	}

	/**
	 * Every paid product is hidden and only a $0 waitlist is on sale, with no
	 * games being played (between seasons). The hero says "Waitlist open" with
	 * no price (even if the state was forced to registration_open).
	 */
	#[RunInSeparateProcess]
	public function test_waitlist_only_between_seasons_shows_the_waitlist_hero(): void {
		$this->seed_waitlist_only_season();

		$content = blueline_homepage_hero_content(
			'registration_open',
			array(
				'next_event_id' => 42,
				'is_playing'    => false,
			)
		);

		$this->assertSame( 'waitlist', $content['variant'] );
		$this->assertSame( 'preseason', $content['state'] );
		$this->assertSame( 'Fall 2026 · Waitlist open', $content['eyebrow'] );
		$this->assertSame( 'Join the waitlist', $content['cta_label'] );
		$this->assertStringNotContainsString( '$', implode( ' ', $content['subcopy_lines'] ) . $content['cta_label'] );
	}

	/**
	 * The live case: a season is running and only $0 waitlists are on sale.
	 * The hero stays the in-season hero (it is for the people playing); the
	 * waitlist is reached from the header, not by replacing their games.
	 */
	#[RunInSeparateProcess]
	public function test_waitlist_only_while_playing_keeps_the_in_season_hero(): void {
		$this->seed_waitlist_only_season();

		$content = blueline_homepage_hero_content(
			'registration_open',
			array(
				'next_event_id' => 42,
				'is_playing'    => true,
			)
		);

		$this->assertArrayNotHasKey( 'variant', $content );
		$this->assertSame( 'in_season', $content['state'] );
	}

	/**
	 * Shared set-up: paid products hidden, one visible $0 waitlist.
	 */
	private function seed_waitlist_only_season(): void {
		$this->load_stubs();
		$this->seed_registration_season();
		$this->feature( 21 );
		blueline_test_set_post_terms( 23, 'product_cat', array( 300 ) );
		$season_products                     = array( 21, 22, 23 );
		$GLOBALS['bl_test_wp_query_results'] = array( $season_products, $season_products );
		$GLOBALS['bl_test_wc_products']      = array(
			21 => array( true, true, 'hidden', '555' ),
			22 => array( true, true, 'hidden', '170' ),
			23 => array( true, true, 'visible', '0' ),
		);
	}

	/**
	 * With nothing at all on offer, the hero is the ordinary season hero:
	 * no waitlist variant.
	 */
	#[RunInSeparateProcess]
	public function test_no_offer_at_all_keeps_the_season_hero(): void {
		$this->load_stubs();
		$this->seed_registration_season();
		$GLOBALS['bl_test_wp_query_results'] = array( array( 23 ) );
		$GLOBALS['bl_test_wc_products']      = array( 23 => array( true, false, 'visible', '0' ) );

		$content = blueline_homepage_hero_content( 'offseason', array() );

		$this->assertArrayNotHasKey( 'variant', $content );
		$this->assertSame( 'offseason', $content['state'] );
	}
}
