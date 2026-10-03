<?php
/**
 * The Preferences page: everything that affects a signed-in player's site
 * experience but isn't account/billing administration -- linked team
 * (read-only), appearance, and next-game widget visibility.
 *
 * Design spec: docs/superpowers/specs/2026-08-27-blueline-account-
 * preferences-design.md.
 *
 * No self-service unlink/re-claim exists in this codebase --
 * blueline_link_player_to_user() (blueline-core's player-link module) explicitly
 * rejects re-linking an already-linked account -- so the linked-team
 * section here is read-only, with a contact-the-league link for anyone who
 * needs to change it, the same pattern the claim card already uses for its
 * own "no candidates found" state.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * A human-readable "Team · Division · #Number" summary of
 * blueline_get_player_team()'s row, omitting any segment that's empty --
 * the same idiom blueline_format_candidate_detail() (inc/account/
 * dashboard.php) uses for the claim card's own disambiguating line.
 *
 * @param array{team_id:int, name:string, logo_id:?int, division:string, number:?string}|null $team blueline_get_player_team()'s result, or null.
 * @return string|null Null when $team itself is null.
 */
function blueline_preferences_team_summary( ?array $team ): ?string {
	if ( null === $team ) {
		return null;
	}

	$number = $team['number'] ?? '';

	$parts = array_filter(
		array(
			$team['name'],
			$team['division'] ?? '',
			'' !== $number ? sprintf(
				/* translators: %s: jersey number. */
				__( '#%s', 'blueline' ),
				$number
			) : '',
		),
		static fn( $part ) => '' !== $part
	);

	return implode( ' · ', $parts );
}

/**
 * The "Show next game widget again" confirmation text -- pure decision, so
 * it's testable without a real WordPress/player lookup. The button always
 * clears the dismissed-widget flag either way (see
 * blueline_account_preferences_endpoint()'s own docblock for why that's
 * still useful with no upcoming game), but the two possible outcomes need
 * different copy: promising "it will show again on your next page view"
 * when there is no upcoming game at all is a lie -- nothing will actually
 * appear, since blueline_render_floating_next_game() renders nothing
 * without one, which live testing found reads as "the button doesn't
 * work" with no explanation why.
 *
 * @param bool $has_upcoming_event Whether the linked player currently has an upcoming event.
 * @return string The confirmation message to render.
 */
function blueline_preferences_widget_confirmation_text( bool $has_upcoming_event ): string {
	return $has_upcoming_event
		? __( 'Done — it will show again on your next page view.', 'blueline' )
		: __( 'Done — you don’t have an upcoming game right now, so it’ll show as soon as one’s scheduled.', 'blueline' );
}

/**
 * The linked-team section: read-only summary, or an unclaimed/rosterless
 * message with a contact-the-league link.
 */
function blueline_render_preferences_team_section(): void {
	blueline_account_module_start( 'preferences-team', __( 'Linked team', 'blueline' ) );

	$player_id = function_exists( 'blueline_current_user_player_id' ) ? blueline_current_user_player_id() : null;
	$team      = $player_id ? blueline_get_player_team( $player_id ) : null;
	$summary   = blueline_preferences_team_summary( $team );

	if ( null === $summary ) {
		$contact = blueline_contact_url();
		$message = sprintf(
			wp_kses(
				/* translators: 1: opening <a> tag to the Contact Us page, 2: closing </a> tag. */
				__( 'No team linked yet. %1$sContact the league%2$s if you need this set or changed.', 'blueline' ),
				array( 'a' => array( 'href' => array() ) )
			),
			'<a href="' . esc_url( $contact ) . '">',
			'</a>'
		);
		blueline_account_module_empty_state_html( $message );
	} else {
		?>
		<p class="bl-preferences-team__summary"><?php echo esc_html( $summary ); ?></p>
		<?php
	}

	blueline_account_module_end();
}

add_action( 'woocommerce_account_preferences_endpoint', 'blueline_account_preferences_endpoint' );
/**
 * Content for /account/preferences/: linked team, appearance, and
 * next-game-widget visibility, in that order.
 */
function blueline_account_preferences_endpoint(): void {
	blueline_render_preferences_team_section();

	blueline_account_module_start( 'preferences-appearance', __( 'Appearance', 'blueline' ) );
	blueline_render_theme_toggle();
	blueline_account_module_end();

	blueline_account_module_start( 'preferences-widget', __( 'Next game widget', 'blueline' ) );

	// The button always clears the dismissed-widget flag regardless of
	// whether an upcoming game exists right now -- clearing it is still
	// useful pre-emptively, for whenever one gets scheduled. Only the
	// confirmation copy needs to know which case this is; see
	// blueline_preferences_widget_confirmation_text()'s own docblock.
	$preferences_widget_player_id = function_exists( 'blueline_current_user_player_id' ) ? blueline_current_user_player_id() : null;
	$preferences_widget_has_event = $preferences_widget_player_id && blueline_get_player_next_event( $preferences_widget_player_id );
	?>
	<p class="bl-preferences-widget__intro">
		<?php esc_html_e( 'If you’ve dismissed the floating next-game widget, you can bring it back here.', 'blueline' ); ?>
	</p>
	<button type="button" class="bl-btn bl-btn--secondary" data-bl-widget-reset>
		<span class="bl-skew"><span><?php esc_html_e( 'Show next game widget again', 'blueline' ); ?></span></span>
	</button>
	<p class="bl-preferences-widget__confirmation" data-bl-widget-reset-confirmation hidden role="status">
		<?php echo esc_html( blueline_preferences_widget_confirmation_text( $preferences_widget_has_event ) ); ?>
	</p>
	<?php
	blueline_account_module_end();
}
