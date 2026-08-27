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
 * blueline_link_player_to_user() (inc/account/player-link.php) explicitly
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
 * The linked-team section: read-only summary, or an unclaimed/rosterless
 * message with a contact-the-league link.
 */
function blueline_render_preferences_team_section(): void {
	blueline_account_module_start( 'preferences-team', __( 'Linked team', 'blueline' ) );

	$player_id = function_exists( 'blueline_current_user_player_id' ) ? blueline_current_user_player_id() : null;
	$team      = $player_id ? blueline_get_player_team( $player_id ) : null;
	$summary   = blueline_preferences_team_summary( $team );

	if ( null === $summary ) {
		$contact = function_exists( 'blueline_contact_url' ) ? blueline_contact_url() : home_url( '/' );
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
	?>
	<p class="bl-preferences-widget__intro">
		<?php esc_html_e( 'If you’ve dismissed the floating next-game widget, you can bring it back here.', 'blueline' ); ?>
	</p>
	<button type="button" class="bl-btn bl-btn--secondary" data-bl-widget-reset>
		<span class="bl-skew"><span><?php esc_html_e( 'Show next game widget again', 'blueline' ); ?></span></span>
	</button>
	<p class="bl-preferences-widget__confirmation" data-bl-widget-reset-confirmation hidden>
		<?php esc_html_e( 'Done — it will show again on your next page view.', 'blueline' ); ?>
	</p>
	<?php
	blueline_account_module_end();
}
