/**
 * Blueline My Account: move focus to the post-claim result notice on load.
 *
 * The notice (`.bl-account-notice`, inc/account/dashboard.php) is
 * server-rendered on a normal page load after a redirect from the claim
 * handler (admin_post_blueline_claim_player, Task 11) -- it is not a live
 * region injected after the fact, so `role="alert"` alone is not reliably
 * announced by every screen reader. Moving focus to it on load (it carries
 * `tabindex="-1"` specifically so it is a valid, non-tab-order programmatic
 * focus target) is the standard "you just navigated here, read this"
 * pattern, and works regardless of which of the linked/invalid/
 * already_linked/user_already_linked/forbidden outcomes produced it.
 *
 * Progressive enhancement: without this script the notice is still fully
 * visible, still carries `role="alert"`, and its text still communicates
 * the result to sighted and no-JS users -- this only closes the gap for
 * screen-reader users on a page that loaded normally rather than via a
 * live-region update.
 *
 * Vanilla ES2017+, no dependencies.
 */
( function () {
	'use strict';

	const notice = document.querySelector( '.bl-account-notice' );

	if ( ! notice ) {
		return;
	}

	notice.focus();
} )();
