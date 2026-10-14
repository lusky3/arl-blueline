/**
 * Blueline: move focus to a server-rendered result notice on load.
 *
 * `.bl-account-notice` (inc/account/dashboard.php) is the post-claim
 * result notice from a redirect after admin_post_blueline_claim_player
 * (Task 11); WooCommerce's own `.woocommerce-message`/`.woocommerce-error`
 * and the `.woocommerce-info` inside the notices wrapper cover every other
 * server-rendered outcome this same
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
 * A bare `.woocommerce-info` is NOT a result: checkout prints its "Returning
 * player? Click here to login" and "Got a credit code?" prompts in that same
 * class, and focusing one of those drops a keyboard or screen-reader user
 * into the middle of the page instead of at its start. Only the notices
 * wrapper's own info messages count, and the two toggle prompts are excluded
 * outright in case a plugin ever nests them there.
 *
 * Vanilla ES2017+, no dependencies.
 */
const RESULT_NOTICE_SELECTOR = [
	'.bl-account-notice',
	'.woocommerce-error',
	'.woocommerce-message',
	'.woocommerce-notices-wrapper > .woocommerce-info',
].join( ', ' );

const PROMPT_SELECTOR =
	'.woocommerce-form-login-toggle, .woocommerce-form-coupon-toggle';

/**
 * The first result notice on the page, ignoring checkout's login/coupon prompts.
 *
 * @param {Document} doc Document to search.
 * @return {Element|null} The notice, or null.
 */
function findResultNotice( doc ) {
	const candidates = Array.from(
		doc.querySelectorAll( RESULT_NOTICE_SELECTOR )
	);

	return (
		candidates.find(
			( candidate ) => ! candidate.closest( PROMPT_SELECTOR )
		) || null
	);
}

/**
 * Move focus to the page's result notice, if it has one.
 *
 * @param {Document} doc Document to search.
 * @return {boolean} Whether a notice was focused.
 */
function focusResultNotice( doc ) {
	const notice = findResultNotice( doc );

	if ( ! notice ) {
		return false;
	}

	if ( ! notice.hasAttribute( 'tabindex' ) ) {
		notice.setAttribute( 'tabindex', '-1' );
	}

	notice.focus();

	return true;
}

if ( typeof document !== 'undefined' ) {
	focusResultNotice( document );
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = {
		RESULT_NOTICE_SELECTOR,
		findResultNotice,
		focusResultNotice,
	};
}
