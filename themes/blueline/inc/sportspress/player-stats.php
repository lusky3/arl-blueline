<?php
/**
 * One empty state for a player with no stats at all.
 *
 * SportsPress's player-statistics.php prints one table per league the player
 * is in, then a "Career Total" table, each through player-statistics-league.php
 * (this theme's override). A player with nothing recorded got a leaf block per
 * table -- "Division 2 not recorded yet." over "Career Total not recorded
 * yet." -- which read as an error. Empty tables inside one player-statistics.php
 * render are now held back: if a real table follows, they print just before it
 * (a player with stats in one division and none in another still sees which is
 * which); if none does, one empty state stands in for the whole section.
 *
 * Loaded by inc/sportspress.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The collector behind the held-back empty tables. Each event returns the
 * captions whose own "not recorded yet" line should print now, and whether
 * the one whole-section empty state should.
 *
 * - 'open'  : a player-statistics.php render starts.
 * - 'empty' : a table has nothing to show ($caption names it).
 * - 'table' : a table with stats is about to print.
 * - 'close' : the render ends.
 *
 * Outside an open render (the league template used on its own) an empty
 * table prints its own line straight away, as it always did.
 *
 * @param string $event   One of the events above.
 * @param string $caption The empty table's caption, for 'empty'.
 * @return array{print: string[], none: bool}
 */
function blueline_sp_player_stats_collect( string $event, string $caption = '' ): array {
	static $open    = false;
	static $tables  = 0;
	static $pending = array();

	$result = array(
		'print' => array(),
		'none'  => false,
	);

	switch ( $event ) {
		case 'open':
			$open    = true;
			$tables  = 0;
			$pending = array();
			break;

		case 'empty':
			if ( ! $open || $tables > 0 ) {
				$result['print'] = array( $caption );
			} else {
				$pending[] = $caption;
			}
			break;

		case 'table':
			if ( $open ) {
				$result['print'] = $pending;
				$pending         = array();
				++$tables;
			}
			break;

		case 'close':
			$result['none'] = $open && 0 === $tables && array() !== $pending;
			$open           = false;
			$tables         = 0;
			$pending        = array();
			break;
	}

	return $result;
}

/**
 * The leaf block for one table with nothing recorded.
 *
 * @param string $caption Table caption, e.g. "Career Total" or a league name.
 * @return string HTML.
 */
function blueline_sp_player_stats_empty_html( string $caption ): string {
	return blueline_sp_player_stats_leaf_html(
		sprintf(
			/* translators: %s: table caption, e.g. "Career Total" or a league name. */
			__( '%s not recorded yet.', 'blueline' ),
			wp_strip_all_tags( $caption )
		)
	);
}

/**
 * The leaf block standing in for the whole stats section of a player with
 * nothing recorded anywhere.
 *
 * @return string HTML.
 */
function blueline_sp_player_stats_none_html(): string {
	return blueline_sp_player_stats_leaf_html( __( 'No stats yet. They appear here after the first scored game.', 'blueline' ) );
}

/**
 * The leaf mark plus one line (DESIGN.md's empty state).
 *
 * @param string $text Plain text.
 * @return string HTML.
 */
function blueline_sp_player_stats_leaf_html( string $text ): string {
	ob_start();
	blueline_leaf_mark( 'bl-sp-empty__mark' );
	$mark = (string) ob_get_clean();

	return '<div class="bl-sp-empty">' . $mark . '<p class="bl-sp-empty__text">' . esc_html( $text ) . '</p></div>';
}

/**
 * Print the empty lines a collector event released.
 *
 * @param array{print: string[], none: bool} $released blueline_sp_player_stats_collect() result.
 * @return void
 */
function blueline_sp_player_stats_print( array $released ): void {
	foreach ( $released['print'] as $caption ) {
		echo blueline_sp_player_stats_empty_html( $caption ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by blueline_sp_player_stats_leaf_html().
	}

	if ( $released['none'] ) {
		echo blueline_sp_player_stats_none_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by blueline_sp_player_stats_leaf_html().
	}
}

add_action( 'sportspress_before_template', 'blueline_sp_player_stats_open' );
/**
 * Open the collector as player-statistics.php starts.
 *
 * @param string $template_name Template file name.
 * @return void
 */
function blueline_sp_player_stats_open( $template_name ): void {
	if ( 'player-statistics.php' === $template_name ) {
		blueline_sp_player_stats_collect( 'open' );
	}
}

add_action( 'sportspress_after_template', 'blueline_sp_player_stats_close' );
/**
 * Close the collector as player-statistics.php ends, printing the one
 * whole-section empty state when no table had anything to show.
 *
 * @param string $template_name Template file name.
 * @return void
 */
function blueline_sp_player_stats_close( $template_name ): void {
	if ( 'player-statistics.php' === $template_name ) {
		blueline_sp_player_stats_print( blueline_sp_player_stats_collect( 'close' ) );
	}
}
