<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Live-site review: a team's public "Results" card showed games years old
 * (e.g. 2017) directly beneath "Upcoming Games," because SportsPress' own
 * team-events.php ('blocks' format, this site's configured
 * sportspress_team_events_format) calls
 * sp_get_template( 'event-fixtures-results.php', array( 'team' => $id ) )
 * with no season argument at all, so SP_Calendar falls back to every event
 * the team has ever played.
 *
 * Covers blueline_resolve_team_events_season() -- the pure decision behind
 * the `sp_team_events_season` filter callback
 * (blueline_team_events_current_season_id()) -- kept deliberately free of
 * blueline_current_sp_season_term_id()'s own get_posts()/transient-backed
 * resolution path (inc/account/player-data.php), which this file does not
 * require and does not need to.
 */
final class TeamEventsSeasonTest extends TestCase {

	/**
	 * With no season chosen yet (SportsPress' own filter default, or any
	 * earlier-priority callback landing on 0), the resolved current season
	 * wins.
	 */
	public function test_the_resolved_current_season_wins_over_the_plugin_default(): void {
		$this->assertSame( 91, blueline_resolve_team_events_season( 0, 91 ) );
	}

	/**
	 * With no resolvable current season (e.g. the site has no sp_event at
	 * all yet), the plugin's own default of 0 -- "no season filter" -- is
	 * kept rather than fabricating one.
	 */
	public function test_falls_back_to_the_plugin_default_when_nothing_resolves(): void {
		$this->assertSame( 0, blueline_resolve_team_events_season( 0, null ) );
	}

	/**
	 * A season chosen by something upstream (an earlier-priority filter,
	 * or a future caller passing one explicitly) must never be overridden
	 * by this theme's own guess, even when a current season also resolves.
	 */
	public function test_never_overrides_a_season_already_chosen_upstream(): void {
		$this->assertSame( 42, blueline_resolve_team_events_season( 42, 91 ) );
	}

	/**
	 * Confirms blueline_team_events_current_season_id() is actually hooked
	 * onto the `sp_team_events_season` filter, not merely defined and
	 * never wired -- checked directly against the fake hook store rather
	 * than via apply_filters(), which would pass identically whether or
	 * not any callback were registered at all (the plugin default, 0, is
	 * also apply_filters()'s own no-op answer).
	 */
	public function test_the_resolver_is_hooked_onto_the_sp_team_events_season_filter(): void {
		$registered = false;

		foreach ( $GLOBALS['bl_test_hooks']['sp_team_events_season'] ?? array() as $bucket ) {
			foreach ( $bucket as $hook ) {
				if ( 'blueline_team_events_current_season_id' === $hook['cb'] ) {
					$registered = true;
				}
			}
		}

		$this->assertTrue(
			$registered,
			'blueline_team_events_current_season_id() must be add_filter()-ed onto sp_team_events_season'
		);
	}
}
