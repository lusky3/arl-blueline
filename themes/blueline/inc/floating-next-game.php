<?php
/**
 * The sitewide floating "next game" widget: a small, persistent,
 * position: fixed chip shown on every front-end page to a signed-in,
 * claimed visitor with a real upcoming game.
 *
 * Design spec: docs/superpowers/specs/2026-08-25-blueline-floating-next-
 * game-design.md. Second of three planned account-linked personalization
 * features built on blueline_current_user_player_id() (now in the
 * blueline-core plugin; without it the widget never renders).
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
 * A labelled `role="region"`, not a live region: it is present at load, so
 * `role="status"` would never be announced anyway, and this is ambient
 * information a visitor can act on whenever they like, not an urgent
 * interruption. The label ("Your next game") is also printed, visibly, on the
 * desktop card -- the date, opponent and venue alone never said whose game it
 * was.
 *
 * Never printed on the account, cart or checkout pages: there it sat over the
 * very fields being filled in (and the My Account dashboard already shows the
 * same game in its own card). On phones (<= 600px) the same markup is
 * restyled as a compact bottom bar (floating-next-game.css); the full and
 * short date are both printed and CSS shows one, so assistive tech only ever
 * reads one of them.
 *
 * `data-bl-next-game` carries `event_id:fingerprint`, not a bare event id --
 * see the schedule-change-notice design spec
 * (docs/superpowers/specs/2026-08-25-blueline-schedule-change-notice-design.md).
 * assets/src/js/floating-next-game.js parses that pair to tell "this is a
 * new next game" (different event id) apart from "this is the SAME game and
 * its details changed since it was dismissed" (same event id, different
 * fingerprint), and reveals `[data-bl-next-game-updated]` -- rendered here,
 * hidden, same "always render server-side, JS decides what shows"
 * convention as the rest of this widget -- only for the latter.
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

	// The WooCommerce conditional tags only exist while it is active.
	if (
		( function_exists( 'is_account_page' ) && is_account_page() )
		|| ( function_exists( 'is_cart' ) && is_cart() )
		|| ( function_exists( 'is_checkout' ) && is_checkout() )
	) {
		return;
	}

	$player_id = function_exists( 'blueline_current_user_player_id' ) ? blueline_current_user_player_id() : null;

	if ( ! $player_id ) {
		return;
	}

	$event = blueline_get_player_next_event( $player_id );

	if ( ! $event ) {
		return;
	}

	$venue_url = ( $event['venue_term_id'] && taxonomy_exists( 'sp_venue' ) ) ? get_term_link( $event['venue_term_id'], 'sp_venue' ) : null;
	?>
	<div
		class="bl-floating-next-game"
		role="region"
		aria-labelledby="bl-next-game-label"
		data-bl-next-game="<?php echo esc_attr( $event['event_id'] . ':' . $event['fingerprint'] ); ?>"
	>
		<p class="bl-floating-next-game__kicker" id="bl-next-game-label"><?php esc_html_e( 'Your next game', 'blueline' ); ?></p>
		<span class="bl-floating-next-game__updated" data-bl-next-game-updated hidden>
			<?php esc_html_e( 'Updated', 'blueline' ); ?>
		</span>
		<p class="bl-floating-next-game__date">
			<span class="bl-floating-next-game__date-full"><?php echo esc_html( blueline_account_event_when( $event ) ); ?></span>
			<span class="bl-floating-next-game__date-short"><?php echo esc_html( blueline_account_event_when_short( $event ) ); ?></span>
		</p>
		<a class="bl-floating-next-game__matchup" href="<?php echo esc_url( get_permalink( $event['event_id'] ) ); ?>">
			<?php echo esc_html( blueline_account_event_matchup( $event ) ); ?>
		</a>
		<?php if ( $event['venue'] ) : ?>
			<p class="bl-floating-next-game__venue">
				<?php if ( $venue_url && ! is_wp_error( $venue_url ) ) : ?>
					<a href="<?php echo esc_url( $venue_url ); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Venue:', 'blueline' ); ?> </span><?php echo esc_html( $event['venue'] ); ?></a>
				<?php else : ?>
					<span class="screen-reader-text"><?php esc_html_e( 'Venue:', 'blueline' ); ?> </span><?php echo esc_html( $event['venue'] ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php
		/*
		 * Last in the DOM so reading and tab order follow the content it
		 * dismisses; floating-next-game.css pins it to the card's corner (or
		 * the bar's end) wherever it sits in the source.
		 */
		?>
		<button
			type="button"
			class="bl-floating-next-game__dismiss"
			data-bl-next-game-dismiss
			aria-label="<?php esc_attr_e( 'Dismiss next game reminder', 'blueline' ); ?>"
		><span aria-hidden="true">&times;</span></button>
	</div>
	<?php
}
