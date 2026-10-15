<?php
/**
 * One answer for every failed login and every lost-password request, so neither form tells a
 * visitor whether an account exists.
 *
 * Covers both the WooCommerce account forms and core's wp-login.php. Error codes are kept and
 * only the wording changes, so lockout and captcha plugins that act on codes (or on
 * wp_login_failed) keep working, and their own errors are shown unchanged.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * The login failure text for an unknown username, an unknown email address or a wrong password.
 *
 * @return string
 */
function blueline_core_generic_login_error(): string {
	return __( 'The username or password is incorrect.', 'blueline-core' );
}

/**
 * The lost-password answer, whether or not the account exists.
 *
 * @return string
 */
function blueline_core_generic_lost_password_message(): string {
	return __( "If that account exists, we've emailed a link to reset the password.", 'blueline-core' );
}

add_filter( 'authenticate', 'blueline_core_generic_login_errors', PHP_INT_MAX, 3 );
/**
 * Reword core's account-revealing login failures after every authenticator has run.
 *
 * Core answers an unknown account without ever hashing the password, so it also answers faster
 * than for a wrong password. An unknown account therefore pays for one password hash here, which
 * keeps the two from being told apart by timing.
 *
 * @param WP_User|WP_Error|null $user     The result so far.
 * @param string                $username Submitted username or email (unused).
 * @param string                $password Submitted password.
 * @return WP_User|WP_Error|null
 */
function blueline_core_generic_login_errors( $user, $username, $password ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- core passes three arguments.
	if ( ! is_wp_error( $user ) ) {
		return $user;
	}

	$code = (string) $user->get_error_code();

	if ( ! in_array( $code, array( 'invalid_username', 'invalid_email', 'incorrect_password' ), true ) ) {
		return $user;
	}

	if ( 'incorrect_password' !== $code && '' !== (string) $password ) {
		wp_hash_password( (string) $password );
	}

	// Core's authenticators return a single code with no data, so a fresh error loses nothing.
	return new WP_Error( $code, blueline_core_generic_login_error() );
}

add_action( 'lostpassword_post', 'blueline_core_wc_lost_password_unknown_account', PHP_INT_MAX, 2 );
/**
 * WooCommerce's lost-password form: for an unknown account, send the same redirect WooCommerce
 * sends after emailing a real reset link, instead of letting it print "Invalid username or email".
 *
 * Runs last on lostpassword_post, the final hook WooCommerce fires before that decision; an error
 * another plugin added (a failed captcha) is left for WooCommerce to show, exactly as it would be
 * for a real account.
 *
 * @param WP_Error      $errors    Errors so far.
 * @param WP_User|false $user_data The account, or false.
 * @return void
 */
function blueline_core_wc_lost_password_unknown_account( $errors, $user_data ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the form's nonce before calling retrieve_password(); only the field's presence is read.
	if ( ! isset( $_POST['wc_reset_password'] ) || $user_data || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
		return;
	}

	if ( is_wp_error( $errors ) && $errors->has_errors() ) {
		return;
	}

	wp_safe_redirect( add_query_arg( 'reset-link-sent', 'true', wc_get_account_endpoint_url( 'lost-password' ) ) );
	exit;
}

add_filter( 'lostpassword_errors', 'blueline_core_lost_password_unknown_account', PHP_INT_MAX, 2 );
/**
 * Core's wp-login.php lost-password form: for an unknown account, send the same redirect core
 * sends after emailing a real reset link, instead of "There is no account with that username or
 * email address".
 *
 * The lostpassword_errors filter is the last hook before core decides; core only ever adds `invalid_email`
 * for an unknown account by then (`invalidcombo` comes after). Any other error (an empty field, a
 * failed captcha) is returned unchanged.
 *
 * @param WP_Error      $errors    Errors so far.
 * @param WP_User|false $user_data The account, or false.
 * @return WP_Error
 */
function blueline_core_lost_password_unknown_account( $errors, $user_data ) {
	if ( $user_data || ! is_wp_error( $errors ) || 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
		return $errors;
	}

	if ( array_diff( $errors->get_error_codes(), array( 'invalid_email' ) ) ) {
		return $errors;
	}

	// Same destination, built the same way, as wp-login.php's own success branch.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- core's own form has no nonce; wp_safe_redirect() validates the target.
	$redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? wp_sanitize_redirect( wp_unslash( $_REQUEST['redirect_to'] ) ) : 'wp-login.php?checkemail=confirm';

	wp_safe_redirect( $redirect_to );
	exit;
}

add_filter( 'gettext_woocommerce', 'blueline_core_wc_lost_password_notice_text', 10, 3 );
/**
 * The notice on WooCommerce's lost-password confirmation page.
 *
 * @param string $translation Translated text.
 * @param string $text        Original text.
 * @param string $domain      Text domain (always woocommerce here).
 * @return string
 */
function blueline_core_wc_lost_password_notice_text( $translation, $text, $domain ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the domain-specific hook passes it.
	return 'Password reset email has been sent.' === $text ? blueline_core_generic_lost_password_message() : $translation;
}

add_filter( 'woocommerce_lost_password_confirmation_message', 'blueline_core_wc_lost_password_confirmation_message' );
/**
 * The paragraph under that notice. WooCommerce's says the email went to "the address on file for
 * your account", which assumes there is one.
 *
 * @param string $message WooCommerce's paragraph (replaced).
 * @return string
 */
function blueline_core_wc_lost_password_confirmation_message( $message ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- replaced outright.
	return __( 'It can take a few minutes to arrive, so check your spam folder too. Please wait at least 10 minutes before asking for another link.', 'blueline-core' );
}

add_filter( 'wp_login_errors', 'blueline_core_login_screen_reset_message', 10, 2 );
/**
 * Core's "Check your email for the confirmation link" screen, shown after a lost-password request,
 * uses the same sentence. Other login-screen messages are left alone.
 *
 * @param WP_Error $errors      Login-screen messages.
 * @param string   $redirect_to Redirect destination (unused).
 * @return WP_Error
 */
function blueline_core_login_screen_reset_message( $errors, $redirect_to ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- core passes two arguments.
	if ( ! is_wp_error( $errors ) || ! in_array( 'confirm', $errors->get_error_codes(), true ) ) {
		return $errors;
	}

	$data = $errors->get_error_data( 'confirm' );
	$errors->remove( 'confirm' );
	$errors->add( 'confirm', blueline_core_generic_lost_password_message(), $data ?? 'message' );

	return $errors;
}
