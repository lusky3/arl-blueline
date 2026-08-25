<?php
/**
 * The sitewide floating "next game" widget: a small, persistent,
 * position: fixed chip shown on every front-end page to a signed-in,
 * claimed visitor with a real upcoming game.
 *
 * Design spec: docs/superpowers/specs/2026-08-25-blueline-floating-next-
 * game-design.md. Second of three planned account-linked personalization
 * features built on blueline_current_user_player_id() (inc/account/
 * player-data.php, from the first, already-merged "highlight mine" work).
 *
 * Deliberately its own file rather than folded into inc/account/dashboard.php:
 * this is sitewide chrome consumed from footer.php on every template, not a
 * My Account dashboard module, even though it reads the same underlying data
 * (blueline_get_player_next_event()) and reuses that module's date/time and
 * vs/@ phrasing conventions. It is independently toggleable from the My
 * Account "My next game" card (account_next_game) -- see
 * blueline_section_definitions() (inc/settings/sections.php) -- so an admin
 * can run either, both, or neither.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Print the floating next-game widget, or nothing at all.
 *
 * Renders only for a logged-in, claimed visitor with a real upcoming game --
 * hidden entirely (no empty state) for logged-out, logged-in-unclaimed, and
 * claimed-with-no-upcoming-game visitors alike, and whenever the
 * `floating_next_game` section toggle is off. A persistent widget with
 * nothing concrete to show would be clutter, not information.
 *
 * The dismiss control is rendered unconditionally, including with
 * JavaScript unavailable, where clicking it does nothing -- same tradeoff
 * blueline_render_announcement() makes, and for the same reason: hiding it
 * until JS runs would mean the widget appearing late, after first paint, on
 * every page load for everyone else.
 *
 * `role="status"` rather than `role="alert"`: this is ambient information a
 * visitor can act on whenever they like, not an urgent interruption, so
 * assistive tech should not be interrupted by it on every page load.
 *
 * @return void
 */
function blueline_render_floating_next_game(): void {
	if ( ! blueline_section_enabled( 'floating_next_game' ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		return;
	}

	$player_id = blueline_current_user_player_id();

	if ( ! $player_id ) {
		return;
	}

	$event = blueline_get_player_next_event( $player_id );

	if ( ! $event ) {
		return;
	}

	$opponent_name = $event['opponent_team_id']
		? ( function_exists( 'blueline_sp_title' ) ? blueline_sp_title( $event['opponent_team_id'] ) : get_the_title( $event['opponent_team_id'] ) )
		: __( 'TBD', 'blueline' );

	$venue_url = ( $event['venue_term_id'] && taxonomy_exists( 'sp_venue' ) ) ? get_term_link( $event['venue_term_id'], 'sp_venue' ) : null;
	?>
	<div
		class="bl-floating-next-game"
		role="status"
		data-bl-next-game="<?php echo esc_attr( (string) $event['event_id'] ); ?>"
	>
		<button
			type="button"
			class="bl-floating-next-game__dismiss"
			data-bl-next-game-dismiss
			aria-label="<?php esc_attr_e( 'Dismiss next game reminder', 'blueline' ); ?>"
		><span aria-hidden="true">&times;</span></button>
		<p class="bl-floating-next-game__date">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: event date, 2: event time. */
					__( '%1$s at %2$s', 'blueline' ),
					get_the_date( 'D, M j', $event['event_id'] ),
					get_the_time( get_option( 'time_format' ), $event['event_id'] )
				)
			);
			?>
		</p>
		<a class="bl-floating-next-game__matchup" href="<?php echo esc_url( get_permalink( $event['event_id'] ) ); ?>">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: "vs" (home) or "@" (away), 2: opponent team name. */
					__( '%1$s %2$s', 'blueline' ),
					$event['is_home'] ? __( 'vs', 'blueline' ) : __( '@', 'blueline' ),
					$opponent_name
				)
			);
			?>
		</a>
		<?php if ( $event['venue'] ) : ?>
			<p class="bl-floating-next-game__venue">
				<?php if ( $venue_url && ! is_wp_error( $venue_url ) ) : ?>
					<a href="<?php echo esc_url( $venue_url ); ?>"><?php echo esc_html( $event['venue'] ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $event['venue'] ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}
