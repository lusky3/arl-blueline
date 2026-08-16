/**
 * Blueline — announcement banner dismissal.
 *
 * The banner renders server-side on every page (inc/announcement.php) and is
 * hidden here if this browser has already dismissed THIS announcement. The
 * key is the eight-character fingerprint PHP puts on the element in
 * `data-bl-announce`, hashed there so this file needs no hashing of its own:
 * editing the announcement changes the fingerprint, so a new one is not
 * already-dismissed for anyone who dismissed the previous one.
 *
 * Dismissal is per-browser, not per-user — localStorage, no server round
 * trip, so it works for logged-out readers and behind a full-page cache. The
 * cost is a visible flash: the banner is in the HTML, so an already-dismissed
 * one can paint before this runs and then disappear. Hiding it up front and
 * revealing it from script would trade that for the banner appearing late on
 * every page load for everyone who has not dismissed it, which is the more
 * common case.
 *
 * Every localStorage access is wrapped, because it can throw rather than
 * return -- storage disabled, or a quota condition. This file does its work
 * at import time (like every other module in index.js), so an unhandled
 * throw would abort the whole bundle's evaluation, not just this feature.
 */

const STORAGE_KEY = 'blueline:announcement-dismissed';

/**
 * The fingerprint this browser last dismissed, or null.
 *
 * @return {string|null} The stored fingerprint.
 */
function readDismissed() {
	try {
		return window.localStorage.getItem( STORAGE_KEY );
	} catch {
		// Storage disabled or unavailable -- treat as never dismissed.
		return null;
	}
}

/**
 * Remember a dismissal. A failure to store is not worth surfacing: the
 * banner still closes for this page view, it just comes back on the next
 * one.
 *
 * @param {string} fingerprint The announcement's own fingerprint.
 */
function rememberDismissed( fingerprint ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, fingerprint );
	} catch {
		// Intentionally ignored -- see this function's own docblock.
	}
}

const banner = document.querySelector( '[data-bl-announce]' );

if ( banner ) {
	const fingerprint = banner.getAttribute( 'data-bl-announce' );

	if ( fingerprint && readDismissed() === fingerprint ) {
		banner.hidden = true;
	}

	const dismiss = banner.querySelector( '[data-bl-announce-dismiss]' );

	if ( dismiss ) {
		dismiss.addEventListener( 'click', function () {
			banner.hidden = true;

			if ( fingerprint ) {
				rememberDismissed( fingerprint );
			}
		} );
	}
}
