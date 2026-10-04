<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * C-08: the event hero read only the clock and the results, so a cancelled
 * game said "Result pending" and a postponed one "Final 2-4". SportsPress's
 * own `sp_status` flag now wins, and old games show their year.
 */
final class EventHeroStatusTest extends TestCase {

	/**
	 * Postponed/cancelled override every clock-based state.
	 */
	public function test_postponed_and_cancelled_override_the_clock_state(): void {
		foreach ( array( 'final', 'pending', 'preview' ) as $state ) {
			$this->assertSame( 'cancelled', blueline_sp_event_display_status( $state, 'cancelled' ) );
			$this->assertSame( 'postponed', blueline_sp_event_display_status( $state, 'postponed' ) );
		}
	}

	/**
	 * An on-time, TBD or missing status leaves the clock-based state alone.
	 */
	public function test_other_statuses_keep_the_clock_state(): void {
		foreach ( array( 'ok', 'tbd', '', 'Cancelled ' ) as $sp_status ) {
			$this->assertSame( 'final', blueline_sp_event_display_status( 'final', $sp_status ) );
			$this->assertSame( 'pending', blueline_sp_event_display_status( 'pending', $sp_status ) );
		}
	}

	/**
	 * Every status has its own label; an unknown key falls back to "Preview".
	 */
	public function test_status_labels(): void {
		$this->assertSame( 'Postponed', blueline_sp_event_status_label( 'postponed' ) );
		$this->assertSame( 'Cancelled', blueline_sp_event_status_label( 'cancelled' ) );
		$this->assertSame( 'Final', blueline_sp_event_status_label( 'final' ) );
		$this->assertSame( 'Result pending', blueline_sp_event_status_label( 'pending' ) );
		$this->assertSame( 'Preview', blueline_sp_event_status_label( 'nope' ) );
	}

	/**
	 * The hero date carries the year only when it is not this year.
	 */
	public function test_hero_date_format_adds_the_year_for_other_years(): void {
		$this->assertSame( 'D, M j', blueline_sp_event_hero_date_format( 2026, 2026 ) );
		$this->assertSame( 'D, M j, Y', blueline_sp_event_hero_date_format( 2020, 2026 ) );
		$this->assertSame( 'D, M j, Y', blueline_sp_event_hero_date_format( 2027, 2026 ) );
	}

	/**
	 * The hero prints the matchup as the page's h1, with the status from sp_status (C-08, C-12).
	 */
	public function test_hero_source_wires_status_and_h1(): void {
		$src = (string) file_get_contents( __DIR__ . '/../inc/sportspress/heroes.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( "get_post_meta( \$event_id, 'sp_status', true )", $src );
		$this->assertStringContainsString( '<h1 class="sp-scoreboard__matchup">', $src );
		$this->assertStringNotContainsString( '<div class="sp-scoreboard__matchup">', $src );
		$this->assertStringContainsString( "( 'preview' === \$status ) ? blueline_sp_event_calendar_url", $src );
	}

	/**
	 * A recorded score shows for played, postponed and cancelled games, never for a game still to come.
	 */
	public function test_scores_show_for_played_postponed_and_cancelled_only(): void {
		foreach ( array( 'final', 'postponed', 'cancelled' ) as $status ) {
			$this->assertTrue( blueline_sp_event_status_shows_score( $status ), $status );
		}
		foreach ( array( 'pending', 'preview', '' ) as $status ) {
			$this->assertFalse( blueline_sp_event_status_shows_score( $status ), $status );
		}
	}

	/**
	 * On a postponed game's own page the Details time shows the time, not the status the hero already says.
	 */
	public function test_details_time_replaces_the_status_word_on_the_games_own_page(): void {
		blueline_test_reset_state();
		$state                               = &blueline_test_state();
		$state['queried_post_type']          = 'sp_event';
		$state['queried_object_id']          = 50;
		$state['post_meta'][50]['sp_status'] = 'postponed';
		$state['post_meta'][51]['sp_status'] = 'postponed';
		$state['post_meta'][52]['sp_status'] = 'ok';

		$this->assertSame( '7:00 PM', blueline_sp_event_details_time( 'Postponed', 50 ) );
		// Another event's row (e.g. a schedule list) keeps SportsPress's wording.
		$this->assertSame( 'Postponed', blueline_sp_event_details_time( 'Postponed', 51 ) );
		// An on-time game is untouched.
		$state['queried_object_id'] = 52;
		$this->assertSame( '5:00 pm', blueline_sp_event_details_time( '5:00 pm', 52 ) );
	}

	/**
	 * Off a single event page the filter is inert.
	 */
	public function test_details_time_is_inert_off_a_single_event_page(): void {
		blueline_test_reset_state();
		$state                               = &blueline_test_state();
		$state['queried_post_type']          = 'page';
		$state['queried_object_id']          = 50;
		$state['post_meta'][50]['sp_status'] = 'postponed';

		$this->assertSame( 'Postponed', blueline_sp_event_details_time( 'Postponed', 50 ) );
	}

	/**
	 * A hero team links to its own page only when it exists and is public.
	 */
	public function test_hero_team_links_only_to_public_teams(): void {
		blueline_test_reset_state();
		$state                           = &blueline_test_state();
		$state['posts'][70]['status']    = 'publish';
		$state['posts'][70]['permalink'] = 'https://example.test/team/blueliners/';
		$state['posts'][71]['status']    = 'draft';
		$state['posts'][71]['permalink'] = 'https://example.test/?p=71';

		$this->assertSame( 'https://example.test/team/blueliners/', blueline_sp_event_team_url( 70 ) );
		$this->assertSame( '', blueline_sp_event_team_url( 71 ), 'A draft team has no public page.' );
		$this->assertSame( '', blueline_sp_event_team_url( 0 ), 'A TBD slot has nothing to link.' );
		$this->assertSame( '', blueline_sp_event_team_url( 999 ), 'An unknown post has nothing to link.' );
	}
}
