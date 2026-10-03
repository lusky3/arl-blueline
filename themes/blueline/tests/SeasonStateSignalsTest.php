<?php
/**
 * Tests for the two signal collectors blueline_season_state_data() delegates
 * to: blueline_season_state_product_signal() and
 * blueline_season_state_event_signals().
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
require_once __DIR__ . '/../inc/settings/commerce.php';
require_once __DIR__ . '/../inc/season-state.php';

/**
 * Unit tests.
 */
final class SeasonStateSignalsTest extends TestCase {

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Load the scripted stubs (separate-process tests only).
	 */
	private function load_stubs(): void {
		require_once __DIR__ . '/fixtures/season-state-signal-stubs.php';
	}

	/**
	 * Register sp_event so blueline_season_state_data() reads event signals.
	 */
	private function register_sp_event(): void {
		$state                 = &blueline_test_state();
		$state['post_types'][] = 'sp_event';
	}

	/**
	 * Seed a configured registration term with one season child term, so
	 * blueline_registration_season_product_ids() reaches its WP_Query.
	 */
	private function seed_registration_season(): void {
		blueline_test_register_term( 91, 'product_cat', 'Registration' );
		blueline_test_register_term( 300, 'product_cat', 'Fall 2026', 91 );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'registration_term' => 91 ) );
	}

	/**
	 * Without WooCommerce there is no product signal.
	 */
	public function test_product_signal_without_woocommerce_is_false(): void {
		$this->assertSame( array( false, null ), blueline_season_state_product_signal() );
	}

	/**
	 * The first purchasable AND in-stock candidate wins; earlier misses are skipped.
	 */
	#[RunInSeparateProcess]
	public function test_product_signal_returns_first_purchasable_in_stock_product(): void {
		$this->load_stubs();
		$this->seed_registration_season();
		$GLOBALS['bl_test_wp_query_results'] = array( array( 11, 12, 13, 14 ) );
		$GLOBALS['bl_test_wc_products']      = array(
			11 => array( true, false ),
			12 => array( false, true ),
			13 => array( true, true ),
			14 => array( true, true ),
		);

		$this->assertSame( array( true, 13 ), blueline_season_state_product_signal() );
		$this->assertSame( 300, $GLOBALS['bl_test_wp_query_args'][0]['tax_query'][0]['terms'] );
	}

	/**
	 * No buyable candidate means no signal and no product ID.
	 */
	#[RunInSeparateProcess]
	public function test_product_signal_is_false_when_nothing_is_buyable(): void {
		$this->load_stubs();
		$this->seed_registration_season();
		$GLOBALS['bl_test_wp_query_results'] = array( array( 11, 12 ) );
		$GLOBALS['bl_test_wc_products']      = array( 11 => array( true, false ) );

		$this->assertSame( array( false, null ), blueline_season_state_product_signal() );
	}

	/**
	 * Upcoming + recent counts, days to the next event, playoffs and next ID.
	 */
	#[RunInSeparateProcess]
	public function test_event_signals_from_upcoming_and_recent_events(): void {
		$this->load_stubs();
		$GLOBALS['bl_test_wp_query_results']  = array( array( 501, 502 ), array( 601, 602, 603 ) );
		$GLOBALS['bl_test_post_dates']        = array( 501 => '2026-08-20 19:00:00' );
		$GLOBALS['bl_test_object_taxonomies'] = array( 'sp_season', 'sp_league' );
		$GLOBALS['bl_test_object_term_slugs'] = array( 'sp_league' => array( 'division-a', 'fall-playoffs' ) );

		$now_mysql = '2026-08-15 12:00:00';
		$signals   = blueline_season_state_event_signals( $now_mysql, (int) strtotime( $now_mysql ) );

		$this->assertSame(
			array(
				'upcoming_events'    => 2,
				'days_to_next_event' => 6,
				'has_playoff_events' => true,
				'recent_events'      => 3,
				'next_event_id'      => 501,
			),
			$signals
		);

		list( $upcoming_args, $recent_args ) = $GLOBALS['bl_test_wp_query_args'];
		$this->assertSame( $now_mysql, $upcoming_args['date_query'][0]['after'] );
		$this->assertSame( array( 'publish', 'future' ), $upcoming_args['post_status'] );
		$this->assertSame( '2026-07-16 12:00:00', $recent_args['date_query'][0]['after'] );
		$this->assertSame( $now_mysql, $recent_args['date_query'][0]['before'] );
	}

	/**
	 * No upcoming events: no next ID, no day count, no playoff scan.
	 */
	#[RunInSeparateProcess]
	public function test_event_signals_with_no_upcoming_events(): void {
		$this->load_stubs();
		$GLOBALS['bl_test_wp_query_results']  = array( array(), array( 601 ) );
		$GLOBALS['bl_test_object_taxonomies'] = array( 'sp_league' );
		$GLOBALS['bl_test_object_term_slugs'] = array( 'sp_league' => array( 'playoffs' ) );

		$this->assertSame(
			array(
				'upcoming_events'    => 0,
				'days_to_next_event' => null,
				'has_playoff_events' => false,
				'recent_events'      => 1,
				'next_event_id'      => null,
			),
			blueline_season_state_event_signals( '2026-08-15 12:00:00', (int) strtotime( '2026-08-15 12:00:00' ) )
		);
	}

	/**
	 * Only league/division taxonomies count, and a WP_Error is skipped.
	 */
	#[RunInSeparateProcess]
	public function test_playoff_scan_ignores_other_taxonomies_and_errors(): void {
		$this->load_stubs();
		$GLOBALS['bl_test_object_taxonomies'] = array( 'sp_season', 'sp_league', 'sp_division' );
		$GLOBALS['bl_test_object_term_slugs'] = array(
			'sp_season' => array( 'playoffs-2026' ),
			'sp_league' => new WP_Error( 'boom', 'Boom.' ),
		);

		$this->assertFalse( blueline_season_state_has_playoff_events( array( 501 ) ) );

		$GLOBALS['bl_test_object_term_slugs']['sp_division'] = array( 'spring-playoff-round' );
		$this->assertTrue( blueline_season_state_has_playoff_events( array( 501 ) ) );
	}

	/**
	 * The data layer composes both collectors into the decided state.
	 */
	#[RunInSeparateProcess]
	public function test_data_composes_both_signals(): void {
		$this->load_stubs();
		$this->register_sp_event();
		$GLOBALS['bl_test_wp_query_results'] = array( array( 501 ), array( 601 ) );
		$GLOBALS['bl_test_post_dates']       = array( 501 => '2026-08-17 12:00:00' );

		$this->assertSame(
			array(
				'state'                => 'in_season',
				'product_id'           => null,
				'next_event_id'        => 501,
				'is_registration_open' => false,
				'is_playing'           => true,
			),
			blueline_season_state_data( strtotime( '2026-08-15T16:00:00+00:00' ) )
		);
	}
}
