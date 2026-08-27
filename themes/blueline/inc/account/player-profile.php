<?php
/**
 * The Player Profile tab: the claimed player's bio (photo, name, jersey
 * number) and six read-only registration-checkout fields (skill level,
 * position, jersey size, gender, emergency contact name/number), synced to
 * user meta by WooCommerce Checkout Field Editor Pro (confirmed live: these
 * are already complete, human-readable display strings -- no lookup table
 * needed, safe to render via esc_html()).
 *
 * Design spec: docs/superpowers/specs/2026-08-27-blueline-player-profile-
 * design.md.
 *
 * Read-only by design -- WCFE Pro already renders these six fields as
 * editable on Edit Account (confirmed live), so this page links there for
 * changes rather than building a second, duplicate editing mechanism.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * A stored field's display value, or a "not provided" fallback for an
 * empty one. get_user_meta() returns '' for an unset key, not null, so
 * this is the difference between a blank value rendering next to its
 * label and an honest "we don't have this" state.
 *
 * @param string $raw The raw stored value.
 * @return string The value to display.
 */
function blueline_player_profile_field_display( string $raw ): string {
	return '' !== $raw ? $raw : __( 'Not provided', 'blueline' );
}

/**
 * The bio section: photo (or a leaf-mark fallback, mirroring
 * blueline_account_render_my_team()'s own crest-fallback pattern in
 * dashboard.php, adapted from a team crest to a player photo), name, and
 * jersey number.
 *
 * @param int $player_id sp_player post ID.
 */
function blueline_render_player_profile_bio_section( int $player_id ): void {
	blueline_account_module_start( 'player-profile-bio', __( 'Player profile', 'blueline' ) );
	?>
	<div class="bl-player-profile__bio">
		<?php if ( has_post_thumbnail( $player_id ) ) : ?>
			<span class="bl-player-profile__photo"><?php echo get_the_post_thumbnail( $player_id, 'thumbnail' ); ?></span>
		<?php elseif ( function_exists( 'blueline_leaf_mark' ) ) : ?>
			<span class="bl-player-profile__photo bl-player-profile__photo--fallback">
				<?php blueline_leaf_mark( 'bl-player-profile__photo-mark' ); ?>
			</span>
		<?php endif; ?>
		<div class="bl-player-profile__identity">
			<p class="bl-player-profile__name"><?php echo esc_html( get_the_title( $player_id ) ); ?></p>
			<?php $number = blueline_player_jersey_number( $player_id ); ?>
			<?php if ( $number ) : ?>
				<p class="bl-player-profile__number">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: jersey number. */
							__( 'Jersey #%s', 'blueline' ),
							$number
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<?php
	blueline_account_module_end();
}

add_action( 'woocommerce_account_player-profile_endpoint', 'blueline_account_player_profile_endpoint' );
/**
 * Content for /account/player-profile/. Task 3 appends the
 * registration-details section after the bio section built here.
 */
function blueline_account_player_profile_endpoint(): void {
	blueline_account_render_claim_notice();

	$user_id   = get_current_user_id();
	$player_id = function_exists( 'blueline_get_linked_player_id' ) ? blueline_get_linked_player_id( $user_id ) : null;

	if ( ! $player_id ) {
		blueline_account_render_claim_card( $user_id, 'player-profile' );
		return;
	}

	blueline_render_player_profile_bio_section( $player_id );
}
