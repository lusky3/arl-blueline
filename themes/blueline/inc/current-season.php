<?php
/**
 * The theme's door to "the current SportsPress season".
 *
 * The rule lives in the blueline-core plugin (includes/season/season.php): SportsPress's
 * own Current Season setting, falling back to the season of the game the schedule is on. The
 * theme calls it through the two wrappers below. When the plugin is missing or older than the
 * version that ships it, they load inc/current-season-core.php, a byte-for-byte copy of the
 * plugin's file (tests/CurrentSeasonTest.php fails while the two differ), so every theme surface
 * keeps one answer either way.
 *
 * Not the season registration is open for: that is the next season, read from the registration
 * products by Season State (inc/season-state.php).
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Make sure the shared resolver is defined: the plugin's copy when the plugin has loaded it,
 * otherwise the theme's own.
 *
 * Loaded on first use rather than with the rest of inc/: the plugin loads its modules on
 * `after_setup_theme`, after this theme's files, so loading the copy up front would always shadow
 * the plugin's, even a newer one.
 *
 * @return void
 */
function blueline_current_season_load(): void {
	if ( ! function_exists( 'blueline_core_current_season_id' ) ) {
		require_once __DIR__ . '/current-season-core.php';
	}
}

/**
 * The current SportsPress season's sp_season term ID (a regular-season term), or 0.
 *
 * @return int
 */
function blueline_current_season_id(): int {
	blueline_current_season_load();

	return blueline_core_current_season_id();
}

/**
 * The term for the phase of the current season being played: one of its Playoffs terms while its
 * playoff games are the ones on the schedule, otherwise the season itself. 0 when there is none.
 *
 * @return int
 */
function blueline_current_season_phase_term_id(): int {
	blueline_current_season_load();

	return blueline_core_current_season_phase_term_id();
}

add_filter( 'blueline_core_current_season_next_event_id', 'blueline_current_season_next_event_id' );
/**
 * Hand the resolver Season State's next game, which is already worked out (postponed and
 * cancelled games skipped) and cached, so it does not query for it again.
 *
 * @param int|null $event_id Another callback's answer, or null.
 * @return int|null
 */
function blueline_current_season_next_event_id( $event_id ) {
	if ( null !== $event_id || ! post_type_exists( 'sp_event' ) ) {
		return $event_id;
	}

	$state_data = blueline_season_state_data();

	return ! empty( $state_data['next_event_id'] ) ? (int) $state_data['next_event_id'] : 0;
}
