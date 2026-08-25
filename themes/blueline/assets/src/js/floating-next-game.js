/**
 * Blueline — floating next-game widget dismissal, plus the "Updated"
 * schedule-change indicator.
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
 * The one deliberate difference from announcement.js: the stored/compared
 * value here is `event_id:fingerprint` (`data-bl-next-game`), not a content
 * hash of the rendered text. Design spec: docs/superpowers/specs/2026-08-25-
 * blueline-schedule-change-notice-design.md. `event_id` alone (this file's
 * original shape) meant a dismissal only ever suppressed that one game and
 * the widget naturally reappeared once blueline_get_player_next_event()
 * started returning a different event -- that property is unchanged.
 * `fingerprint` (a hash of the event's own date/time, venue, opponent, and
 * home/away flag -- blueline_event_schedule_fingerprint() in inc/account/
 * player-data.php) adds a second property on top: a dismissal of THIS
 * version of the game must not suppress a later, changed version of the
 * SAME game. Three outcomes when comparing the stored pair against the
 * current one:
 *
 * - Equal event id, equal fingerprint: unchanged since it was dismissed --
 *   hidden, same as before this feature existed.
 * - Equal event id, different fingerprint: the same game, but its details
 *   changed since this browser last saw it -- shown, with the "Updated"
 *   indicator revealed, even if the old version was already dismissed.
 * - Different event id (or nothing stored yet): a genuinely different next
 *   game, not a change to one already seen -- shown normally, no indicator.
 */

const STORAGE_KEY = 'blueline:next-game-dismissed';

/**
 * Parse a stored or server-rendered `event_id:fingerprint` pair into its two
 * parts, or null for an empty or malformed string (including a bare legacy
 * event id from before this feature existed -- a missing fingerprint half
 * cannot be compared, so it is treated the same as nothing stored at all).
 * Pure decision, split out purely so it's testable without a real DOM or
 * localStorage -- see standings-tabs.js's findStoredInput() for the same
 * trade-off elsewhere in this file set.
 *
 * @param {string|null} raw The raw `event_id:fingerprint` string, or null.
 * @return {{eventId: string, fingerprint: string}|null} The parsed pair, or null.
 */
function parseNextGameState( raw ) {
	if ( ! raw ) {
		return null;
	}

	const separator = raw.indexOf( ':' );

	if ( separator === -1 ) {
		return null;
	}

	const eventId = raw.slice( 0, separator );
	const fingerprint = raw.slice( separator + 1 );

	if ( ! eventId || ! fingerprint ) {
		return null;
	}

	return { eventId, fingerprint };
}

/**
 * The `event_id:fingerprint` pair this browser last dismissed, or null.
 *
 * @return {string|null} The stored pair.
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
 * @param {string} state The dismissed `event_id:fingerprint` pair.
 */
function rememberDismissed( state ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, state );
	} catch {
		// Intentionally ignored -- see this function's own docblock.
	}
}

/*
 * Guarded on `document` (this file previously ran this block unconditionally
 * -- every front-end entry file does, since it only ever loads in a
 * browser) so this module can also be `require()`d by
 * floating-next-game.test.mjs under plain Node, with no DOM present, purely
 * to reach parseNextGameState() -- same trade-off standings-tabs.js and
 * table-scroll.js already make for the same reason.
 */
if ( typeof document !== 'undefined' ) {
	const widget = document.querySelector( '[data-bl-next-game]' );

	if ( widget ) {
		const currentRaw = widget.getAttribute( 'data-bl-next-game' );
		const current = parseNextGameState( currentRaw );
		const stored = parseNextGameState( readDismissed() );

		if ( current && stored && stored.eventId === current.eventId ) {
			if ( stored.fingerprint === current.fingerprint ) {
				widget.hidden = true;
			} else {
				const updated = widget.querySelector(
					'[data-bl-next-game-updated]'
				);

				if ( updated ) {
					updated.hidden = false;
				}
			}
		}

		const dismiss = widget.querySelector( '[data-bl-next-game-dismiss]' );

		if ( dismiss ) {
			dismiss.addEventListener( 'click', function () {
				widget.hidden = true;

				if ( currentRaw ) {
					rememberDismissed( currentRaw );
				}
			} );
		}
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { parseNextGameState };
}
