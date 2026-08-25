/**
 * Blueline — My Account "next game" card: the "Updated since you last
 * checked" read, and the write-on-view that clears it for the floating
 * widget too.
 *
 * Design spec: docs/superpowers/specs/2026-08-25-blueline-schedule-change-
 * notice-design.md. This card (blueline_account_render_next_game(), inc/
 * account/dashboard.php) is never dismissible -- it's permanent account
 * content, not ambient chrome -- so there is no dismiss control here, only
 * a read (does the stored pair for this event differ from the current
 * one?) and a write (record the current pair as seen).
 *
 * Deliberately its own small file rather than added to assets/src/js/
 * account.js: this reads and writes the exact same `blueline:next-game-
 * dismissed` localStorage key and `event_id:fingerprint` pair shape
 * assets/src/js/floating-next-game.js already owns, so both surfaces share
 * one acknowledgment state -- viewing this card counts as having seen a
 * change, and the floating widget elsewhere then stops flagging it. The
 * parsing logic below is intentionally a second, small copy of
 * floating-next-game.js's own parseNextGameState() rather than an import
 * from it: this codebase's established precedent (compare this file's own
 * readStored()/writeStored() with announcement.js's and floating-next-
 * game.js's nearly identical localStorage try/catch wrappers) is to
 * duplicate a small per-feature helper rather than share it through a new
 * cross-file dependency.
 */

const STORAGE_KEY = 'blueline:next-game-dismissed';

/**
 * Parse a stored or server-rendered `event_id:fingerprint` pair into its two
 * parts, or null for an empty or malformed string. Pure decision, split out
 * purely so it's testable without a real DOM or localStorage -- mirrors
 * floating-next-game.js's own parseNextGameState() exactly; see this file's
 * own module docblock for why it is a second copy rather than a shared
 * import.
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
 * The `event_id:fingerprint` pair this browser last saw (from either this
 * card or the floating widget -- they share one key), or null.
 *
 * @return {string|null} The stored pair.
 */
function readStored() {
	try {
		return window.localStorage.getItem( STORAGE_KEY );
	} catch {
		// Storage disabled or unavailable -- treat as never seen.
		return null;
	}
}

/**
 * Record the current pair as seen. A failure to store is not worth
 * surfacing: the card still renders correctly for this page view either
 * way, it just cannot suppress a future "Updated" flag it would otherwise
 * have cleared.
 *
 * @param {string} state The current `event_id:fingerprint` pair.
 */
function writeStored( state ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, state );
	} catch {
		// Intentionally ignored -- see this function's own docblock.
	}
}

if ( typeof document !== 'undefined' ) {
	const card = document.querySelector( '[data-bl-next-game-account]' );

	if ( card ) {
		const currentRaw = card.getAttribute( 'data-bl-next-game-account' );
		const current = parseNextGameState( currentRaw );

		if ( current ) {
			// Read BEFORE writing -- the comparison needs what this
			// browser saw before this page view, not what it is about to
			// become.
			const stored = parseNextGameState( readStored() );

			if (
				stored &&
				stored.eventId === current.eventId &&
				stored.fingerprint !== current.fingerprint
			) {
				const updated = card.querySelector(
					'[data-bl-next-game-account-updated]'
				);

				if ( updated ) {
					updated.hidden = false;
				}
			}

			// Viewing this card counts as having seen the current state --
			// write it so the floating widget elsewhere does not keep
			// flagging an already-acknowledged change.
			writeStored( currentRaw );
		}
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { parseNextGameState };
}
