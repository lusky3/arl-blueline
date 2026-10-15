<?php
/**
 * Stand-ins for what the single-event hero (inc/sportspress/heroes.php) reads from SportsPress, so the hero
 * itself can be rendered.
 *
 * Defining sp_get_status() makes the hero (and other code that branches on function_exists( 'sp_get_status' ))
 * treat SportsPress as active, so this is loaded ONLY inside #[RunInSeparateProcess] tests.
 *
 * @package blueline
 */

/**
 * What sp_get_status() answers for each event: 'results' once a score is recorded, else 'scheduled'.
 *
 * @var array<int, string>
 */
$GLOBALS['bl_test_sp_event_status'] = array();

if ( ! function_exists( 'sp_get_status' ) ) {
	/**
	 * SportsPress's own result status for an event.
	 *
	 * @param int $event_id sp_event post ID.
	 * @return string
	 */
	function sp_get_status( $event_id ) {
		return $GLOBALS['bl_test_sp_event_status'][ (int) $event_id ] ?? 'scheduled';
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Core's sanitize_file_name(), reduced to what a game title needs: runs of anything outside
	 * letters, digits, dot, underscore and dash become one dash.
	 *
	 * @param string $filename File name.
	 * @return string
	 */
	function sanitize_file_name( $filename ) {
		return trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $filename ), '-' );
	}
}
