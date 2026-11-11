/**
 * Blueline — "Show next game widget again" button on the Preferences page.
 *
 * The floating next-game widget (assets/src/js/floating-next-game.js) hides
 * itself once a visitor dismisses it, by comparing the current game's
 * event_id:fingerprint pair against WIDGET_DISMISSED_KEY in this browser's
 * own localStorage -- see that file's own docblock. This button just clears
 * the signed-in member's own copy of that key (scoped by the button's
 * `data-bl-member`, next-game-keys.js), never another member's on a shared
 * browser (FE-01); it does not need to touch `blueline:next-game-seen` (the key
 * for the separate "Updated since you last checked" indicator),
 * since that key never affects visibility, only whether the indicator
 * shows.
 *
 * WIDGET_DISMISSED_KEY is floating-next-game.js's own DISMISSED_KEY, both
 * taken from next-game-keys.js; this file's own test asserts they match.
 */

const {
	DISMISSED_KEY: WIDGET_DISMISSED_KEY,
	memberKey,
} = require( './next-game-keys.js' );

/**
 * Clear the member's dismissed-widget key. A failure to clear is not worth
 * surfacing beyond the button simply not working this once -- the same
 * degrade-silently convention every localStorage access in this theme
 * follows (see assets/src/js/announcement.js).
 *
 * @param {string|null} member The button's `data-bl-member` scope.
 * @return {boolean} Whether the clear actually succeeded.
 */
function resetDismissedWidget( member ) {
	const key = memberKey( WIDGET_DISMISSED_KEY, member );

	if ( ! key ) {
		return false;
	}

	try {
		window.localStorage.removeItem( key );
		return true;
	} catch {
		return false;
	}
}

/**
 * Put $message in a polite live region so it is announced.
 *
 * The region is rendered empty and stays in the page: text that appears
 * together with its container (the old `hidden` paragraph) is ignored by
 * many screen readers. It is cleared first and filled on the next tick, so
 * pressing the button again re-announces the same words.
 *
 * @param {Element}                          region   role="status" element.
 * @param {string}                           message  What to say.
 * @param {function(function(): void): void} schedule Runs a callback later (window.setTimeout in the page).
 * @return {void}
 */
function announce( region, message, schedule ) {
	region.textContent = '';
	schedule( () => {
		region.textContent = message;
	} );
}

if ( typeof document !== 'undefined' ) {
	const button = document.querySelector( '[data-bl-widget-reset]' );

	if ( button ) {
		button.addEventListener( 'click', () => {
			const confirmation = document.querySelector(
				'[data-bl-widget-reset-confirmation]'
			);

			if (
				confirmation &&
				resetDismissedWidget( button.getAttribute( 'data-bl-member' ) )
			) {
				announce(
					confirmation,
					confirmation.getAttribute( 'data-bl-message' ) || '',
					( fn ) => window.setTimeout( fn, 100 )
				);
			}
		} );
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { WIDGET_DISMISSED_KEY, resetDismissedWidget, announce };
}
