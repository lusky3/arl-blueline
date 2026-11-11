/**
 * Blueline — the localStorage keys the next-game scripts share, scoped to
 * the signed-in member.
 *
 * floating-next-game.js, account-next-game.js and preferences-widget-reset.js
 * all require this file, so the keys cannot drift apart. The two base keys
 * stay separate on purpose (PR #25; see floating-next-game.js's docblock):
 * `...-dismissed` decides only whether the floating widget hides,
 * `...-seen` decides only whether an "Updated" flag shows.
 *
 * localStorage belongs to the browser, not to the member, so each key is
 * suffixed with the member's scope: the `data-bl-member` value the server
 * renders (a truncated salted hash of the user id, inc/next-game-member-
 * scope.php). One member's dismissal or "seen" state on a shared device
 * then never applies to the next member who signs in there (FE-01). With no
 * usable scope the scripts store nothing rather than fall back to a shared
 * key.
 */

const DISMISSED_KEY = 'blueline:next-game-dismissed';
const SEEN_KEY = 'blueline:next-game-seen';

/**
 * The member-scoped form of a base key, or null without a usable scope.
 *
 * @param {string}      base   DISMISSED_KEY or SEEN_KEY.
 * @param {string|null} member The element's `data-bl-member` value.
 * @return {string|null} The key to use, or null to store nothing.
 */
function memberKey( base, member ) {
	if ( ! member || ! /^[0-9a-f]+$/.test( member ) ) {
		return null;
	}

	return base + ':' + member;
}

/**
 * Remove the browser-wide keys written before the keys were scoped, so a
 * previous member's game and dismissal do not linger on the device.
 *
 * @param {Storage} storage window.localStorage.
 */
function forgetUnscopedState( storage ) {
	try {
		storage.removeItem( DISMISSED_KEY );
		storage.removeItem( SEEN_KEY );
	} catch {
		// Storage disabled or unavailable -- nothing to clear.
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = {
		DISMISSED_KEY,
		SEEN_KEY,
		memberKey,
		forgetUnscopedState,
	};
}
