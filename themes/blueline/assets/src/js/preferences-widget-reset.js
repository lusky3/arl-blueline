/**
 * Blueline — "Show next game widget again" button on the Preferences page.
 *
 * The floating next-game widget (assets/src/js/floating-next-game.js) hides
 * itself once a visitor dismisses it, by comparing the current game's
 * event_id:fingerprint pair against WIDGET_DISMISSED_KEY in this browser's
 * own localStorage -- see that file's own docblock. This button just clears
 * that one key; it does not need to touch `blueline:next-game-seen` (PR
 * #24's key for the separate "Updated since you last checked" indicator),
 * since that key never affects visibility, only whether the indicator
 * shows.
 *
 * WIDGET_DISMISSED_KEY MUST match floating-next-game.js's own DISMISSED_KEY
 * exactly -- kept in sync by convention (cross-referenced in both files'
 * docblocks) and this file's own test, the same trade-off this theme's
 * other localStorage-key-sharing files already make (see footer-theme-
 * toggle.js's own docblock on the same pattern for its STORAGE_KEY).
 */

const WIDGET_DISMISSED_KEY = 'blueline:next-game-dismissed';

/**
 * Clear the dismissed-widget key. A failure to clear is not worth
 * surfacing beyond the button simply not working this once -- the same
 * degrade-silently convention every localStorage access in this theme
 * follows (see assets/src/js/announcement.js).
 *
 * @return {boolean} Whether the clear actually succeeded.
 */
function resetDismissedWidget() {
	try {
		window.localStorage.removeItem( WIDGET_DISMISSED_KEY );
		return true;
	} catch {
		return false;
	}
}

if ( typeof document !== 'undefined' ) {
	const button = document.querySelector( '[data-bl-widget-reset]' );

	if ( button ) {
		button.addEventListener( 'click', () => {
			const succeeded = resetDismissedWidget();

			if ( succeeded ) {
				const confirmation = document.querySelector(
					'[data-bl-widget-reset-confirmation]'
				);

				if ( confirmation ) {
					confirmation.hidden = false;
				}
			}
		} );
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { WIDGET_DISMISSED_KEY, resetDismissedWidget };
}
