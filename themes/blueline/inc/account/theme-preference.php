<?php
/**
 * Light/dark/system appearance preference: configurable from the Preferences
 * tab (/account/preferences/) for a logged-in player, AND from a sitewide
 * footer toggle for every visitor, logged in or not. Both share the exact
 * same toggle markup and click handling -- blueline_render_theme_toggle()
 * below, and assets/src/js/footer-theme-toggle.js on the client.
 *
 * Design specs: docs/superpowers/specs/2026-08-22-blueline-theme-toggle-
 * design.md (the original Account Details field, since removed in favour of
 * the Preferences tab) and docs/superpowers/specs/2026-08-26-blueline-
 * footer-theme-toggle-design.md (the footer toggle this file's second half
 * adds).
 *
 * Storage is a single user meta value, one of BLUELINE_THEME_PREFERENCES,
 * defaulting to 'system' -- the absence of the meta key IS 'system', so
 * there is nothing to migrate for existing accounts. 'system' renders no
 * `data-theme` attribute at all and falls through to style.css's own
 * `@media (prefers-color-scheme: dark)` block; only an explicit 'light' or
 * 'dark' choice ever reaches the `<html>` tag.
 *
 * A guest has no account to store a preference against, so this file's
 * server-rendered pieces (the account field, the language_attributes
 * filter) still always treat a guest as 'system' -- the same content this
 * theme served before either feature existed. That is exactly what keeps
 * this feature's own SERVER-RENDERED output cache-safe: a request with no
 * `wordpress_logged_in_*` cookie never varies by anything in this file, and
 * one that has that cookie is never cached in the first place (see
 * inc/settings/cache.php's own docblock on this site's srcache setup). A
 * guest's OWN preference still exists -- it just lives entirely in that
 * guest's own browser (localStorage), applied entirely client-side by
 * assets/src/js/footer-theme-toggle.js and, before first paint, by
 * blueline_render_guest_theme_bootstrap_script()'s inline script below.
 * The server never learns it and never renders it.
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

/**
 * Clamp a submitted appearance-preference value to a known-good one and
 * persist it. Shared by both persistence paths this feature has: the
 * WooCommerce Account Details save handler below, and the sitewide footer
 * toggle's AJAX handler (blueline_ajax_save_theme_preference()) further
 * down this file. Extracted so the clamping rule -- "anything not in
 * BLUELINE_THEME_PREFERENCES is 'system'" -- has exactly one
 * implementation shared by both, rather than two that could drift.
 *
 * @param int    $user_id   The user whose preference this is.
 * @param string $submitted The raw submitted value, already
 *                           sanitize_text_field()'d by the caller.
 * @return string The value actually persisted -- one of BLUELINE_THEME_PREFERENCES.
 */
function blueline_persist_theme_preference( int $user_id, string $submitted ): string {
	$preference = in_array( $submitted, BLUELINE_THEME_PREFERENCES, true )
		? $submitted
		: BLUELINE_THEME_PREFERENCES[0];

	update_user_meta( $user_id, BLUELINE_THEME_PREFERENCE_META_KEY, $preference );

	return $preference;
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

add_action( 'wp_ajax_blueline_save_theme_preference', 'blueline_ajax_save_theme_preference' );
/**
 * AJAX persistence path for the sitewide footer toggle
 * (blueline_render_theme_toggle()), reached only for a logged-in visitor.
 * A guest has no account to save to and never calls this at all --
 * assets/src/js/footer-theme-toggle.js branches on the toggle's own
 * `data-bl-theme-toggle-mode` and writes to localStorage instead for a
 * guest; see that file's own docblock.
 *
 * Registered only as `wp_ajax_...`, never `wp_ajax_nopriv_...`, so
 * admin-ajax.php itself already refuses a logged-out request before this
 * function is ever called in production. The is_user_logged_in() check
 * below is defense in depth -- and it is what a unit test actually
 * exercises for "a logged-out request is rejected", since a test calls
 * this function directly, bypassing admin-ajax.php's own routing
 * entirely.
 *
 * Capability-free beyond being logged in: this is a personal, per-user
 * preference with no wider effect, the same trust level My Account's own
 * Account Details form already has for this exact value.
 *
 * @return void
 */
function blueline_ajax_save_theme_preference(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'You must be logged in to save an appearance preference.', 'blueline' ) ), 401 );
	}

	check_ajax_referer( 'blueline_save_theme_preference', 'nonce' );

	$submitted = isset( $_POST['preference'] ) ? sanitize_text_field( wp_unslash( $_POST['preference'] ) ) : '';

	$preference = blueline_persist_theme_preference( get_current_user_id(), $submitted );

	wp_send_json_success( array( 'preference' => $preference ) );
}

/**
 * Render the light/dark/system toggle -- visible to every visitor, logged
 * in or not (design: docs/superpowers/specs/2026-08-26-blueline-footer-
 * theme-toggle-design.md). Called twice per page view for a logged-in
 * visitor on /account/preferences/ (inc/account/preferences.php) -- once
 * there, once by the sitewide footer -- and exactly once everywhere else.
 * Three buttons rather than a <select>: clicking one
 * applies the theme instantly (assets/src/js/footer-theme-toggle.js) and,
 * for a logged-in visitor, fires the AJAX save
 * (blueline_ajax_save_theme_preference()) with no page reload and no
 * separate "Save changes" step.
 *
 * The initial pressed state and the nonce/AJAX-URL data attributes are
 * computed ONLY for a logged-in visitor. This markup is part of every
 * page's own HTML, including the anonymous, page-cached response
 * (inc/settings/cache.php's srcache), so nothing that could vary between
 * two different anonymous visitors may ever be rendered here -- exactly
 * the same constraint blueline_theme_preference_html_attribute() above
 * already observes. A guest's own preference lives only in that guest's
 * own browser (localStorage), never on the server; this function renders
 * the same "system" pressed / no nonce markup for every guest alike, and
 * assets/src/js/footer-theme-toggle.js's own `mode: 'guest'` branch (read
 * from `data-bl-theme-toggle-mode` below) applies that guest's real,
 * client-side-only preference on top of it after the fact.
 *
 * @return void
 */
function blueline_render_theme_toggle(): void {
	$logged_in = is_user_logged_in();
	$current   = $logged_in ? blueline_get_theme_preference( get_current_user_id() ) : BLUELINE_THEME_PREFERENCES[0];
	$label_id  = wp_unique_id( 'bl-theme-toggle-label-' );

	$options = array(
		'system' => __( 'System', 'blueline' ),
		'light'  => __( 'Light', 'blueline' ),
		'dark'   => __( 'Dark', 'blueline' ),
	);
	?>
	<div
		class="bl-theme-toggle"
		data-bl-theme-toggle
		data-bl-theme-toggle-mode="<?php echo esc_attr( $logged_in ? 'account' : 'guest' ); ?>"
		<?php if ( $logged_in ) : ?>
			data-bl-theme-toggle-nonce="<?php echo esc_attr( wp_create_nonce( 'blueline_save_theme_preference' ) ); ?>"
			data-bl-theme-toggle-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		<?php endif; ?>
	>
		<span class="bl-theme-toggle__label" id="<?php echo esc_attr( $label_id ); ?>"><?php esc_html_e( 'Appearance', 'blueline' ); ?></span>
		<div class="bl-theme-toggle__group" role="group" aria-labelledby="<?php echo esc_attr( $label_id ); ?>">
			<?php foreach ( $options as $value => $label ) : ?>
				<button
					type="button"
					class="bl-theme-toggle__option"
					data-bl-theme-toggle-option="<?php echo esc_attr( $value ); ?>"
					aria-pressed="<?php echo esc_attr( $current === $value ? 'true' : 'false' ); ?>"
				><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * Print a small, BLOCKING inline script in <head> -- before any stylesheet
 * loads -- that applies a guest's own stored theme preference to <html>
 * before first paint. Only for a guest (is_user_logged_in() === false): a
 * logged-in visitor's data-theme is already rendered server-side by
 * blueline_theme_preference_html_attribute() above, on a page that is
 * never cached in the first place, and re-deciding it here from
 * localStorage would risk a stale browser value overriding the account's
 * own current preference the moment it disagreed with it.
 *
 * Cache-safe by construction, same reasoning as
 * blueline_theme_preference_html_attribute()'s own docblock: this prints
 * IDENTICAL markup for every anonymous visitor (the literal string below,
 * nothing computed per-request), so the srcache-cached anonymous response
 * is the same for everyone regardless of which theme any one guest has
 * actually chosen -- the choice itself never leaves that guest's own
 * browser, and never reaches this function at all.
 *
 * The localStorage key is a plain literal here, not a shared PHP
 * constant -- it must match assets/src/js/footer-theme-toggle.js's own
 * STORAGE_KEY exactly, cross-referenced in both files' docblocks rather
 * than kept in sync by a build-time constant, the same "kept in sync by
 * convention, not by machinery" precedent assets/src/js/announcement.js
 * and assets/src/js/floating-next-game.js already set for their own
 * dismissal keys.
 *
 * Called from header.php, before wp_head() -- see that call site's own
 * comment on why the position matters: this must run before any enqueued
 * stylesheet prints, or a dark-preferring guest sees a flash of the light
 * theme on every single page load.
 *
 * @return void
 */
function blueline_render_guest_theme_bootstrap_script(): void {
	if ( is_user_logged_in() ) {
		return;
	}
	?>
	<script>
	( function () {
		try {
			var stored = window.localStorage.getItem( 'blueline:theme-preference' );
			if ( 'light' === stored || 'dark' === stored ) {
				document.documentElement.setAttribute( 'data-theme', stored );
			}
		} catch ( e ) {
			// Storage disabled or unavailable -- fall through to
			// style.css's own prefers-color-scheme block, the same
			// degrade-silently convention every localStorage access in
			// this theme follows (see assets/src/js/announcement.js).
		}
	} )();
	</script>
	<?php
}
