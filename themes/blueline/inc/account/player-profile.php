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
 * The six registration-checkout fields this tab reads, meta key => label.
 * All six are confirmed live (staging, 2026-08-27) to already sync to user
 * meta via WooCommerce Checkout Field Editor Pro as complete, human-readable
 * display strings (e.g. "4 - Beginner – Intermediate") -- no lookup table
 * needed, safe via esc_html(). Extracted as its own function (rather than
 * inlined in the render function below) so the exact field list/label
 * mapping has one place to change, and one test asserting it hasn't
 * silently drifted.
 *
 * @return array<string,string> meta_key => label.
 */
function blueline_player_profile_registration_fields(): array {
	return array(
		'arl_division'          => __( 'Skill level', 'blueline' ),
		'arl_position'          => __( 'Position', 'blueline' ),
		'arl_jerseysize'        => __( 'Jersey size', 'blueline' ),
		'arl_gender'            => __( 'Gender', 'blueline' ),
		'arl_emergency_contact' => __( 'Emergency contact', 'blueline' ),
		'arl_emergency_number'  => __( 'Emergency contact number', 'blueline' ),
	);
}

/**
 * The registration-details section: the six fields above, read-only, with
 * a link to Edit Account for changes -- WCFE Pro already renders them as
 * editable there (confirmed live), so this does not duplicate that.
 *
 * @param int $user_id WordPress user ID.
 */
function blueline_render_player_profile_registration_section( int $user_id ): void {
	blueline_account_module_start( 'player-profile-registration', __( 'Registration details', 'blueline' ) );
	?>
	<dl class="bl-player-profile__fields">
		<?php foreach ( blueline_player_profile_registration_fields() as $meta_key => $label ) : ?>
			<div class="bl-player-profile__field">
				<dt><?php echo esc_html( $label ); ?></dt>
				<dd><?php echo esc_html( blueline_player_profile_field_display( (string) get_user_meta( $user_id, $meta_key, true ) ) ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
	<p class="bl-player-profile__edit-link">
		<a class="bl-account-module__link" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>">
			<?php esc_html_e( 'Edit these details', 'blueline' ); ?> <span aria-hidden="true">&rarr;</span>
		</a>
	</p>
	<?php
	blueline_account_module_end();
}

/**
 * The bio section: photo (or a leaf-mark fallback, mirroring
 * blueline_account_render_my_team()'s own crest-fallback pattern in
 * dashboard.php, adapted from a team crest to a player photo), a small
 * pencil-icon control to change it, name, and jersey number.
 *
 * The pencil replaces sportspress-player-tools' own /account/profile-picture
 * page (see blueline_redirect_profile_picture_endpoint(), blueline-core's
 * player-photo module, for
 * why that page had to go, not just gain a link here): that plugin looks a
 * player up by WordPress post_author, but this site links a player to a
 * user via the sp_user meta key instead (blueline_get_linked_player_id(),
 * blueline-core's player-link module) -- the two never agree for a real player, so
 * the plugin's own upload form silently rendered nothing for every real
 * user. This control reads the SAME already-correct $player_id this page
 * already resolves, so it has no way to inherit that bug.
 *
 * @param int  $player_id      sp_player post ID.
 * @param bool $can_edit_photo Whether to render the photo-change control
 *                             (verified owners only, see
 *                             blueline_user_is_verified_player_owner()).
 */
function blueline_render_player_profile_bio_section( int $player_id, bool $can_edit_photo = false ): void {
	blueline_account_module_start( 'player-profile-bio', __( 'Bio', 'blueline' ) );
	?>
	<div class="bl-player-profile__bio">
		<span class="bl-player-profile__photo-wrap">
			<?php if ( has_post_thumbnail( $player_id ) ) : ?>
				<span class="bl-player-profile__photo"><?php echo get_the_post_thumbnail( $player_id, 'thumbnail' ); ?></span>
			<?php else : ?>
				<span class="bl-player-profile__photo bl-player-profile__photo--fallback">
					<?php blueline_leaf_mark( 'bl-player-profile__photo-mark' ); ?>
				</span>
			<?php endif; ?>
			<?php if ( $can_edit_photo ) : ?>
			<form class="bl-player-profile__photo-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'blueline_upload_player_photo' ); ?>
				<input type="hidden" name="action" value="blueline_upload_player_photo">
				<input type="file" id="bl-player-photo-input" name="player_photo" accept="image/*" class="bl-player-profile__photo-input">
				<label class="bl-player-profile__photo-edit" for="bl-player-photo-input">
					<span class="screen-reader-text"><?php esc_html_e( 'Change photo', 'blueline' ); ?></span>
					<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M13.5 2.5l4 4L7 17H3v-4L13.5 2.5Z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
				</label>
				<noscript>
					<button type="submit" class="bl-btn bl-btn--secondary bl-player-profile__photo-submit"><?php esc_html_e( 'Upload', 'blueline' ); ?></button>
				</noscript>
			</form>
			<?php endif; ?>
		</span>
		<div class="bl-player-profile__identity">
			<p class="bl-player-profile__name"><?php echo esc_html( get_the_title( $player_id ) ); ?></p>
			<?php $number = blueline_player_jersey_number( $player_id ); ?>
			<?php if ( null !== $number ) : ?>
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
 * Content for /account/player-profile/: the bio section, then the
 * registration-details section.
 */
function blueline_account_player_profile_endpoint(): void {
	blueline_account_render_claim_notice();
	blueline_account_render_photo_notice();

	$user_id   = get_current_user_id();
	$player_id = function_exists( 'blueline_get_linked_player_id' ) ? blueline_get_linked_player_id( $user_id ) : null;

	if ( ! $player_id ) {
		blueline_account_render_claim_card( $user_id, 'player-profile' );
		return;
	}

	$verified = function_exists( 'blueline_user_is_verified_player_owner' ) && blueline_user_is_verified_player_owner( $user_id, $player_id );

	blueline_render_player_profile_bio_section( $player_id, $verified );

	if ( $verified ) {
		blueline_render_player_profile_registration_section( $user_id );
		return;
	}

	blueline_render_player_profile_unverified_section();
}

/**
 * Shown in place of the registration details for a name-claimed link: the
 * account can view the player's public profile but not edit it or see
 * personal details until the league confirms the record is theirs.
 */
function blueline_render_player_profile_unverified_section(): void {
	blueline_account_module_start( 'player-profile-unverified', __( 'Profile access', 'blueline' ) );

	$contact = blueline_contact_url();

	blueline_account_module_empty_state_html(
		sprintf(
			wp_kses(
				/* translators: 1: opening <a> tag to the Contact Us page, 2: closing </a> tag. */
				__( 'This player profile is view-only for your account. Photo changes and your registration details unlock once the league confirms this player record is yours. %1$sContact the league%2$s to confirm it.', 'blueline' ),
				array( 'a' => array( 'href' => array() ) )
			),
			'<a href="' . esc_url( $contact ) . '">',
			'</a>'
		)
	);

	blueline_account_module_end();
}

/**
 * The photo-upload outcome notice, read back from
 * blueline_redirect_after_photo_upload()'s own query var (the upload
 * handler lives in blueline-core's player-photo module). Same rendering
 * contract as blueline_account_render_claim_notice() (inc/account/
 * dashboard.php) -- same markup, same reason it is a query-string status
 * rather than wc_add_notice() (see that redirect function's own docblock)
 * -- deliberately not a shared helper: the two carry different status
 * vocabularies for different features, and a shared "generic notice"
 * abstraction over two call sites each currently invokes it once would be
 * speculative.
 *
 * @return void
 */
function blueline_account_render_photo_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag from our own post-upload redirect, not a state-changing request.
	if ( empty( $_GET['blueline_photo'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
	$status = sanitize_key( wp_unslash( $_GET['blueline_photo'] ) );

	$messages = array(
		'updated'   => array( 'success', __( 'Photo updated.', 'blueline' ) ),
		'too_large' => array( 'error', __( 'That photo is too large. The limit is 2MB.', 'blueline' ) ),
		'invalid'   => array( 'error', __( 'Use a JPG, PNG, GIF, or WebP image.', 'blueline' ) ),
		'unlinked'  => array( 'error', __( 'Link your player before changing your photo.', 'blueline' ) ),
		'not_owner' => array( 'error', __( 'Only the confirmed owner of this player profile can change its photo.', 'blueline' ) ),
		'error'     => array( 'error', __( 'The photo could not be saved. Please try again.', 'blueline' ) ),
	);

	if ( ! isset( $messages[ $status ] ) ) {
		return;
	}

	list( $type, $text ) = $messages[ $status ];
	?>
	<section class="bl-account-notice bl-account-notice--<?php echo esc_attr( $type ); ?>" role="alert" tabindex="-1">
		<?php echo esc_html( $text ); ?>
	</section>
	<?php
}
