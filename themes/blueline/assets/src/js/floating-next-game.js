/**
 * Blueline — floating next-game widget dismissal.
 *
 * Mirrors assets/src/js/announcement.js's own pattern exactly (localStorage
 * read/write wrapped in try/catch, degrading to "never dismissed" on any
 * failure; a server-rendered fingerprint compared client-side; `hidden` set
 * on dismiss rather than the element removed) but is kept a separate module
 * -- see inc/floating-next-game.php's own docblock for why this is a
 * distinct sitewide feature from the announcement banner, not a variant of
 * it -- with its own localStorage key and `data-*` attribute so the two
 * scripts never collide.
 *
 * The one deliberate difference from announcement.js: the fingerprint here
 * is the event's own `event_id` (`data-bl-next-game`), not a content hash.
 * A content hash would key on THIS week's rendered text, so dismissing this
 * week's game would also suppress next week's once the text happened to
 * collide; keying on the event id instead means a dismissal only ever
 * suppresses that one game, and the widget naturally reappears the moment
 * blueline_get_player_next_event() starts returning a different event.
 */

const STORAGE_KEY = 'blueline:next-game-dismissed';

/**
 * The event id this browser last dismissed, or null.
 *
 * @return {string|null} The stored event id.
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
 * widget still closes for this page view, it just comes back on the next
 * one.
 *
 * @param {string} eventId The dismissed event's own id.
 */
function rememberDismissed( eventId ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, eventId );
	} catch {
		// Intentionally ignored -- see this function's own docblock.
	}
}

const widget = document.querySelector( '[data-bl-next-game]' );

if ( widget ) {
	const eventId = widget.getAttribute( 'data-bl-next-game' );

	if ( eventId && readDismissed() === eventId ) {
		widget.hidden = true;
	}

	const dismiss = widget.querySelector( '[data-bl-next-game-dismiss]' );

	if ( dismiss ) {
		dismiss.addEventListener( 'click', function () {
			widget.hidden = true;

			if ( eventId ) {
				rememberDismissed( eventId );
			}
		} );
	}
}
