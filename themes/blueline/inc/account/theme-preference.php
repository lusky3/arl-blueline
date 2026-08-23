<?php
/**
 * Light/dark/system appearance preference, configurable from My Account.
 *
 * Design spec: docs/superpowers/specs/2026-08-22-blueline-theme-toggle-design.md.
 *
 * Storage is a single user meta value, one of BLUELINE_THEME_PREFERENCES,
 * defaulting to 'system' -- the absence of the meta key IS 'system', so
 * there is nothing to migrate for existing accounts. 'system' renders no
 * `data-theme` attribute at all and falls through to style.css's own
 * `@media (prefers-color-scheme: dark)` block; only an explicit 'light' or
 * 'dark' choice ever reaches the `<html>` tag.
 *
 * Guests never have a preference to read, so they always render as
 * 'system' -- the same content this theme served before this feature
 * existed. That also keeps this feature's own output cache-safe: a request
 * with no `wordpress_logged_in_*` cookie never varies by this filter, and
 * one that has that cookie is never cached in the first place (see
 * inc/settings/cache.php's own docblock on this site's srcache setup).
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * User meta key holding a user's stored appearance preference.
 *
 * @var string
 */
const BLUELINE_THEME_PREFERENCE_META_KEY = 'blueline_theme_preference';

/**
 * The only values blueline_theme_preference_html_attribute() and
 * style.css's own [data-theme] selectors have a treatment for. Anything
 * else a stray import or direct meta edit manages to store clamps to
 * 'system' -- the first entry -- everywhere this is read.
 */
const BLUELINE_THEME_PREFERENCES = array( 'system', 'light', 'dark' );

/**
 * Resolve a user's stored appearance preference, clamped to a known-good
 * value.
 *
 * @param int $user_id User ID.
 * @return string One of BLUELINE_THEME_PREFERENCES.
 */
function blueline_get_theme_preference( int $user_id ): string {
	$stored = (string) get_user_meta( $user_id, BLUELINE_THEME_PREFERENCE_META_KEY, true );

	return in_array( $stored, BLUELINE_THEME_PREFERENCES, true )
		? $stored
		: BLUELINE_THEME_PREFERENCES[0];
}

add_action( 'woocommerce_edit_account_form', 'blueline_render_theme_preference_field' );
/**
 * Render the appearance-preference <select> on the Account Details page.
 *
 * Plain WooCommerce form-row markup -- no new component CSS, this picks up
 * the existing `.woocommerce .form-row`/`.woocommerce select` styling
 * every other field on this form already uses (woocommerce.css).
 */
function blueline_render_theme_preference_field(): void {
	$current = blueline_get_theme_preference( get_current_user_id() );
	$options = array(
		'system' => __( 'Match my device', 'blueline' ),
		'light'  => __( 'Light', 'blueline' ),
		'dark'   => __( 'Dark', 'blueline' ),
	);
	?>
	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
		<label for="blueline_theme_preference"><?php esc_html_e( 'Appearance', 'blueline' ); ?></label>
		<select name="blueline_theme_preference" id="blueline_theme_preference" class="woocommerce-Input woocommerce-Input--select input-text">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>
	<?php
}

add_action( 'woocommerce_save_account_details', 'blueline_save_theme_preference', 12, 1 );
/**
 * Persist the submitted appearance preference.
 *
 * Runs after WooCommerce core's own `save_account_details_nonce` check
 * (WC_Form_Handler::save_account_details() verifies it before this action
 * ever fires), so no separate nonce check is needed here. The submitted
 * value is sanitized to exactly one of BLUELINE_THEME_PREFERENCES,
 * defaulting to 'system' for anything else -- the same "clamp to a
 * known-good value" pattern as blueline_announcement_severity().
 *
 * @param int $user_id The account being saved.
 */
function blueline_save_theme_preference( int $user_id ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see docblock.
	$submitted = isset( $_POST['blueline_theme_preference'] ) ? sanitize_text_field( wp_unslash( $_POST['blueline_theme_preference'] ) ) : '';

	$preference = in_array( $submitted, BLUELINE_THEME_PREFERENCES, true )
		? $submitted
		: BLUELINE_THEME_PREFERENCES[0];

	update_user_meta( $user_id, BLUELINE_THEME_PREFERENCE_META_KEY, $preference );
}

add_filter( 'language_attributes', 'blueline_theme_preference_html_attribute' );
/**
 * Append a `data-theme` attribute to <html> for a logged-in user with an
 * explicit (non-'system') appearance preference.
 *
 * Guests and 'system' both render nothing here, on purpose -- both mean
 * "follow prefers-color-scheme", which style.css's own media-query block
 * already handles with no attribute present.
 *
 * @param string $output The existing language_attributes() markup.
 * @return string
 */
function blueline_theme_preference_html_attribute( string $output ): string {
	if ( ! is_user_logged_in() ) {
		return $output;
	}

	$preference = blueline_get_theme_preference( get_current_user_id() );
	if ( 'system' === $preference ) {
		return $output;
	}

	return $output . sprintf( ' data-theme="%s"', esc_attr( $preference ) );
}
