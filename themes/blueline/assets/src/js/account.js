/**
 * Blueline: move focus to a server-rendered result notice on load.
 *
 * `.bl-account-notice` (inc/account/dashboard.php) is the post-claim
 * result notice from a redirect after admin_post_blueline_claim_player
 * (Task 11); WooCommerce's own `.woocommerce-message`/`.woocommerce-error`/
 * `.woocommerce-info` cover every other server-rendered outcome this same
 * "normal page load, not a live region" problem applies to -- an Account
 * Details save (including the light/dark/system appearance field,
 * inc/account/theme-preference.php), a cart coupon message, a checkout
 * validation error. None of them are live-region updates injected after
 * the fact, so `role="alert"` alone is not reliably announced by every
 * screen reader on load; moving focus to whichever one is present is the
 * standard "you just navigated here, read this" pattern.
 *
 * `.bl-account-notice` already carries `tabindex="-1"` server-side, so it
 * is already a valid, non-tab-order focus target. WooCommerce core's own
 * notices never do, so it is added here, right before focusing -- the
 * same reason `.bl-account-notice` was given the attribute in the first
 * place, just applied to a class this theme doesn't render itself.
 *
 * Progressive enhancement: without this script every one of these
 * notices is still fully visible, still carries `role="alert"`, and its
 * text still communicates the result to sighted and no-JS users -- this
 * only closes the gap for screen-reader users on a page that loaded
 * normally rather than via a live-region update.
 *
 * Vanilla ES2017+, no dependencies.
 */
( function () {
	'use strict';

	const notice = document.querySelector(
		'.bl-account-notice, .woocommerce-message, .woocommerce-error, .woocommerce-info'
	);

	if ( ! notice ) {
		return;
	}

	if ( ! notice.hasAttribute( 'tabindex' ) ) {
		notice.setAttribute( 'tabindex', '-1' );
	}

	notice.focus();
} )();
