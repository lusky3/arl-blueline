/**
 * Blueline — sitewide footer light/dark/system toggle.
 *
 * Mirrors inc/account/theme-preference.php's own "system beats nothing,
 * explicit choice beats system" three-value semantics, but drives it from
 * a click on `[data-bl-theme-toggle-option]` rather than a <select> submit,
 * and applies the change to `<html data-theme="...">` immediately -- no
 * page reload -- for both visitor types this file's own `data-bl-theme-
 * toggle-mode` attribute distinguishes:
 *
 * - `mode="account"` (a logged-in visitor): persisted server-side via the
 *   `blueline_save_theme_preference` AJAX action
 *   (blueline_ajax_save_theme_preference(), same file), using the nonce and
 *   admin-ajax.php URL blueline_render_theme_toggle() already put on the
 *   container as data attributes -- no separate script localization needed.
 * - `mode="guest"`: persisted client-side only, in this browser's own
 *   localStorage, under STORAGE_KEY below. Never reaches the server at
 *   all -- this site's anonymous-visitor page cache means the server could
 *   never learn a guest's choice safely in the first place (see
 *   inc/account/theme-preference.php's own docblock).
 *
 * STORAGE_KEY MUST match the literal string
 * blueline_render_guest_theme_bootstrap_script() prints into header.php's
 * own blocking inline script -- that script re-reads this same key, before
 * first paint, on every page load so a guest's stored theme applies with no
 * flash of the wrong one. Kept in sync by convention (cross-referenced in
 * both files' docblocks), the same trade-off assets/src/js/announcement.js
 * and assets/src/js/floating-next-game.js already make for their own
 * dismissal keys, not by a shared build-time constant.
 */

const KNOWN_PREFERENCES = [ 'system', 'light', 'dark' ];
const STORAGE_KEY = 'blueline:theme-preference';

/**
 * Clamp a requested preference value to a known-good one -- the same
 * "anything unrecognised is 'system'" rule
 * blueline_persist_theme_preference() (inc/account/theme-preference.php)
 * applies server-side, reproduced here so a malformed or tampered
 * `data-bl-theme-toggle-option` value can never be written to localStorage
 * or sent to the AJAX handler. Pure decision, split out purely so it's
 * testable without a real DOM -- see floating-next-game.js's
 * parseNextGameState() for the same trade-off elsewhere in this file set.
 *
 * @param {string|null} value The requested value.
 * @return {string} One of KNOWN_PREFERENCES.
 */
function clampPreference( value ) {
	return KNOWN_PREFERENCES.includes( value ) ? value : 'system';
}

/**
 * Decide what a click on a given toggle option should actually do: which
 * value to persist (clamped), and which persistence mechanism the current
 * visitor's mode calls for. Pure decision, no DOM or network access, so
 * it's testable in isolation -- see clampPreference()'s own docblock for
 * the same trade-off.
 *
 * @param {string}      mode           `data-bl-theme-toggle-mode`'s value -- 'account' for a logged-in visitor, anything else (expected: 'guest') otherwise.
 * @param {string|null} requestedValue The clicked option's own `data-bl-theme-toggle-option` value.
 * @return {{value: string, persist: 'ajax'|'localStorage'}} The clamped value and where to persist it.
 */
function resolveToggleAction( mode, requestedValue ) {
	return {
		value: clampPreference( requestedValue ),
		persist: 'account' === mode ? 'ajax' : 'localStorage',
	};
}

/**
 * This guest's stored preference, clamped -- 'system' when nothing is
 * stored, storage is unavailable, or the stored value is unrecognised.
 *
 * @return {string} One of KNOWN_PREFERENCES.
 */
function readGuestPreference() {
	try {
		return clampPreference( window.localStorage.getItem( STORAGE_KEY ) );
	} catch {
		// Storage disabled or unavailable -- treat as the default.
		return 'system';
	}
}

/**
 * Remember a guest's preference. A failure to store is not worth
 * surfacing: the theme still applies for this page view, it just won't be
 * remembered on the next one -- same tradeoff as every other localStorage
 * write in this theme (see assets/src/js/announcement.js).
 *
 * @param {string} value One of KNOWN_PREFERENCES.
 */
function writeGuestPreference( value ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, value );
	} catch {
		// Intentionally ignored -- see this function's own docblock.
	}
}

/**
 * Apply a preference to `<html>`, the same attribute
 * blueline_theme_preference_html_attribute() and this file's own guest
 * bootstrap script render server-side: no attribute at all for 'system'
 * (style.css's prefers-color-scheme block takes over), `data-theme="light"`
 * or `data-theme="dark"` otherwise.
 *
 * @param {string} value One of KNOWN_PREFERENCES.
 */
function applyTheme( value ) {
	if ( 'system' === value ) {
		document.documentElement.removeAttribute( 'data-theme' );
	} else {
		document.documentElement.setAttribute( 'data-theme', value );
	}
}

/**
 * Mark exactly one of the given buttons as the pressed (active) one.
 *
 * @param {Element[]} buttons The `[data-bl-theme-toggle-option]` buttons to update.
 * @param {string}    value   The now-active value.
 */
function setPressedState( buttons, value ) {
	buttons.forEach( ( button ) => {
		const isActive =
			button.getAttribute( 'data-bl-theme-toggle-option' ) === value;
		button.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
	} );
}

/**
 * Mark the pressed value across EVERY `[data-bl-theme-toggle]` instance on
 * the page, not just the one that was clicked. A logged-in visitor can see
 * two instances on the same page at once (the footer's own, plus a second
 * one on /account/preferences/) -- confirmed live: without this, changing
 * the theme on one instance left the other showing its old pressed state
 * until the next page load, even though `<html data-theme>` and the saved
 * preference were both already correct. Only called from a click's own
 * success path (localStorage write or AJAX success), never from init --
 * each instance's OWN initial pressed state on page load already comes
 * from the same source of truth (server-rendered user meta for 'account'
 * mode, this browser's localStorage for 'guest' mode), so instances are
 * already consistent with each other before any click ever happens.
 *
 * @param {string} value The now-active value.
 */
function setPressedStateEverywhere( value ) {
	setPressedState(
		Array.from(
			document.querySelectorAll( '[data-bl-theme-toggle-option]' )
		),
		value
	);
}

/**
 * POST a logged-in visitor's chosen value to
 * blueline_ajax_save_theme_preference(), applying the visible change only
 * once that call actually succeeds -- not optimistically before it --
 * matching the design's own "on success, update data-theme" wording. A
 * failed request leaves the toggle showing its previous state; that
 * failure is not surfaced beyond that, the same network-failure tradeoff
 * assets/src/js/sponsors.js's own AJAX-driven behaviour already accepts
 * silently elsewhere in this theme.
 *
 * @param {Element}  container The `[data-bl-theme-toggle]` element carrying the nonce/AJAX-URL data attributes.
 * @param {string}   value     The clamped value to persist.
 * @param {Function} onSuccess Called with no arguments once the server confirms the save.
 */
function saveViaAjax( container, value, onSuccess ) {
	const nonce = container.getAttribute( 'data-bl-theme-toggle-nonce' );
	const ajaxUrl = container.getAttribute( 'data-bl-theme-toggle-ajax-url' );

	if ( ! nonce || ! ajaxUrl ) {
		return;
	}

	const body = new URLSearchParams();
	body.set( 'action', 'blueline_save_theme_preference' );
	body.set( 'nonce', nonce );
	body.set( 'preference', value );

	window
		.fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
		.then( ( response ) => ( response.ok ? response.json() : null ) )
		.then( ( json ) => {
			if ( json && json.success ) {
				onSuccess();
			}
		} )
		.catch( () => {
			// Intentionally ignored -- see this function's own docblock.
		} );
}

/**
 * Wire up ONE `[data-bl-theme-toggle]` instance. Split out from
 * initFooterThemeToggle() below so that function can initialize every
 * matching instance on the page, not just the first -- this page's footer
 * toggle and, on /account/preferences/, a second instance both carry the
 * same data attribute, and both need independently working click handlers.
 *
 * @param {Element} container One `[data-bl-theme-toggle]` element.
 */
function initThemeToggleInstance( container ) {
	const mode = container.getAttribute( 'data-bl-theme-toggle-mode' );
	const buttons = Array.from(
		container.querySelectorAll( '[data-bl-theme-toggle-option]' )
	);

	if ( 'account' !== mode ) {
		// Guest: the server always renders 'system' as pressed (it cannot
		// safely render anything else -- see blueline_render_theme_toggle()'s
		// own docblock), so sync the visible pressed state to this
		// browser's real, client-side-only preference. data-theme itself
		// is already correct on <html> before this script even runs, via
		// header.php's blocking bootstrap script.
		setPressedState( buttons, readGuestPreference() );
	}

	buttons.forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const requested = button.getAttribute(
				'data-bl-theme-toggle-option'
			);
			const { value, persist } = resolveToggleAction( mode, requested );

			if ( 'localStorage' === persist ) {
				writeGuestPreference( value );
				applyTheme( value );
				setPressedStateEverywhere( value );
				return;
			}

			saveViaAjax( container, value, () => {
				applyTheme( value );
				setPressedStateEverywhere( value );
			} );
		} );
	} );
}

function initFooterThemeToggle() {
	document
		.querySelectorAll( '[data-bl-theme-toggle]' )
		.forEach( initThemeToggleInstance );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initFooterThemeToggle );
	} else {
		initFooterThemeToggle();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { clampPreference, resolveToggleAction };
}
