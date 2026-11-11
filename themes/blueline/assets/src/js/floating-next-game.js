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
 * TWO separate keys, not one: a single shared `event_id:fingerprint`
 * value for both "the visitor explicitly dismissed this widget" AND "the
 * visitor viewed the My Account next-game card" (assets/src/js/
 * account-next-game.js writes on every view of that card, by design, so
 * the "Updated" flag there doesn't keep nagging once you've looked). Since
 * both surfaces describe the SAME underlying game, sharing one key would mean
 * merely visiting My Account silently marks the FLOATING WIDGET as
 * dismissed too -- it would flash on the very next page (server always
 * renders it) and then immediately hide itself, on every single page
 * after that, with no dismiss click ever happening. Two keys avoid that:
 *
 * - `blueline:next-game-dismissed` -- written ONLY by this widget's own
 *   dismiss button. Read ONLY to decide whether to hide the widget.
 *   Visiting My Account never touches this key.
 * - `blueline:next-game-seen` -- written by dismissing this widget AND by
 *   viewing the My Account card (account-next-game.js). Read ONLY to
 *   decide whether to reveal the "Updated" indicator -- never affects
 *   whether the widget itself is shown or hidden.
 *
 * Both keys are suffixed with the signed-in member's `data-bl-member` scope
 * (next-game-keys.js), so on a shared browser one member's state never
 * applies to another (FE-01).
 *
 * The stored/compared value under each key is still `event_id:fingerprint`
 * (`data-bl-next-game`), not a content hash of the rendered text. `fingerprint` (a hash of the event's own date/time, venue,
 * opponent, and home/away flag -- blueline_event_schedule_fingerprint() in
 * inc/account/player-data.php) is what lets a dismissal of THIS version of
 * a game not suppress a later, changed version of the SAME game.
 */

// The keys come from next-game-keys.js, which account-next-game.js and
// preferences-widget-reset.js (the "Show next game widget again" button)
// also require, so the three cannot drift apart.
const { moveFocusOut } = require( './dismiss-focus.js' );
const {
	DISMISSED_KEY,
	SEEN_KEY,
	memberKey,
	forgetUnscopedState,
} = require( './next-game-keys.js' );

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
 * @param {string|null} key The localStorage key to read (null: no member scope).
 * @return {string|null} The stored pair.
 */
function readState( key ) {
	if ( ! key ) {
		return null;
	}

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
 * @param {string|null} key   The localStorage key to write (null: no member scope).
 * @param {string}      state The `event_id:fingerprint` pair to store.
 */
function writeState( key, state ) {
	if ( ! key ) {
		return;
	}

	try {
		window.localStorage.setItem( key, state );
	} catch {
		// Intentionally ignored -- see this function's own docblock.
	}
}

/*
 * Guarded on `document` (every front-end entry file otherwise runs its
 * block unconditionally, since it only ever loads in a
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
		const member = widget.getAttribute( 'data-bl-member' );
		const dismissedKey = memberKey( DISMISSED_KEY, member );
		const seenKey = memberKey( SEEN_KEY, member );

		try {
			forgetUnscopedState( window.localStorage );
		} catch {
			// Storage unavailable -- nothing to clear.
		}

		const dismissed = parseNextGameState( readState( dismissedKey ) );

		if (
			current &&
			dismissed &&
			dismissed.eventId === current.eventId &&
			dismissed.fingerprint === current.fingerprint
		) {
			widget.hidden = true;
		} else {
			const seen = parseNextGameState( readState( seenKey ) );

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

		// Where focus was just before it entered the widget (the widget is
		// last in the DOM, so that is normally the footer control tabbed
		// out of): the least surprising place to hand it back to.
		let lastOutside = null;

		document.addEventListener( 'focusin', function ( event ) {
			if ( ! widget.contains( event.target ) ) {
				lastOutside = event.target;
			}
		} );

		if ( dismiss ) {
			dismiss.addEventListener( 'click', function () {
				// Before `hidden`: a hidden element cannot take focus, and
				// hiding the focused button would drop it to <body>.
				moveFocusOut(
					widget,
					[ lastOutside, document.getElementById( 'main' ) ],
					dismiss.ownerDocument.activeElement
				);

				widget.hidden = true;

				if ( currentRaw ) {
					// Dismissing also counts as having seen it -- otherwise
					// the "Updated" badge would immediately reappear on the
					// next page, right after the visitor just dismissed
					// this exact state.
					writeState( dismissedKey, currentRaw );
					writeState( seenKey, currentRaw );
				}
			} );
		}
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { parseNextGameState, DISMISSED_KEY, SEEN_KEY };
}
