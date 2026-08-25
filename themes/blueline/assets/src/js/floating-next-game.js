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
 * it -- with its own localStorage keys and `data-*` attribute so the two
 * scripts never collide.
 *
 * TWO separate keys, not one -- a real bug found live 2026-08-25: an
 * earlier version of this feature used ONE shared `event_id:fingerprint`
 * value for both "the visitor explicitly dismissed this widget" AND "the
 * visitor viewed the My Account next-game card" (assets/src/js/
 * account-next-game.js writes on every view of that card, by design, so
 * the "Updated" flag there doesn't keep nagging once you've looked). Since
 * both surfaces describe the SAME underlying game, sharing one key meant
 * merely visiting My Account silently marked the FLOATING WIDGET as
 * dismissed too -- it would flash on the very next page (server always
 * renders it) and then immediately hide itself, on every single page
 * after that, with no dismiss click ever happening. Splitting the concern
 * into two keys fixes it:
 *
 * - `blueline:next-game-dismissed` -- written ONLY by this widget's own
 *   dismiss button. Read ONLY to decide whether to hide the widget.
 *   Visiting My Account never touches this key.
 * - `blueline:next-game-seen` -- written by dismissing this widget AND by
 *   viewing the My Account card (account-next-game.js). Read ONLY to
 *   decide whether to reveal the "Updated" indicator -- never affects
 *   whether the widget itself is shown or hidden.
 *
 * The stored/compared value under each key is still `event_id:fingerprint`
 * (`data-bl-next-game`), not a content hash of the rendered text. Design
 * spec: docs/superpowers/specs/2026-08-25-blueline-schedule-change-notice-
 * design.md. `fingerprint` (a hash of the event's own date/time, venue,
 * opponent, and home/away flag -- blueline_event_schedule_fingerprint() in
 * inc/account/player-data.php) is what lets a dismissal of THIS version of
 * a game not suppress a later, changed version of the SAME game.
 */

const DISMISSED_KEY = 'blueline:next-game-dismissed';
const SEEN_KEY = 'blueline:next-game-seen';

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
 * The `event_id:fingerprint` pair stored under the given key, or null.
 *
 * @param {string} key The localStorage key to read.
 * @return {string|null} The stored pair.
 */
function readState( key ) {
	try {
		return window.localStorage.getItem( key );
	} catch {
		// Storage disabled or unavailable -- treat as never stored.
		return null;
	}
}

/**
 * Store an `event_id:fingerprint` pair under the given key. A failure to
 * store is not worth surfacing: the widget still behaves correctly for
 * this page view, it just cannot remember it for the next one.
 *
 * @param {string} key   The localStorage key to write.
 * @param {string} state The `event_id:fingerprint` pair to store.
 */
function writeState( key, state ) {
	try {
		window.localStorage.setItem( key, state );
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
		const dismissed = parseNextGameState( readState( DISMISSED_KEY ) );

		if (
			current &&
			dismissed &&
			dismissed.eventId === current.eventId &&
			dismissed.fingerprint === current.fingerprint
		) {
			widget.hidden = true;
		} else {
			const seen = parseNextGameState( readState( SEEN_KEY ) );

			if (
				current &&
				seen &&
				seen.eventId === current.eventId &&
				seen.fingerprint !== current.fingerprint
			) {
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
					// Dismissing also counts as having seen it -- otherwise
					// the "Updated" badge would immediately reappear on the
					// next page, right after the visitor just dismissed
					// this exact state.
					writeState( DISMISSED_KEY, currentRaw );
					writeState( SEEN_KEY, currentRaw );
				}
			} );
		}
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { parseNextGameState };
}
