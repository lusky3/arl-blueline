/**
 * Remembers which homepage standings tab a visitor last picked, and
 * pre-selects it on their next visit -- progressive enhancement over the
 * pure-CSS radio-tab mechanism in inc/homepage-modules.php's
 * blueline_homepage_standings_tabs(), which works fully without this file.
 *
 * A stored value is just an sp_table post id (the radio's own `value`).
 * When a new season's tables replace last season's, the stored id simply
 * matches nothing on the page, so findStoredInput() returns null and the
 * server's own default (the first division) stays checked -- no season
 * bookkeeping needed here, the id space does it for free.
 */

const STORAGE_KEY = 'bl_standings_division';

/**
 * Given the last-picked division's stored value and the tab inputs
 * currently on the page, return the input whose value matches -- or null if
 * none do (a different season's tables, first visit, storage cleared).
 * Pure decision, split out purely so it's testable without a real DOM --
 * see table-scroll.test.mjs's fakeElement() for the same trade-off
 * elsewhere in this file set.
 *
 * @param {string|null}            stored Last-picked value, or null.
 * @param {Array<{value: string}>} inputs Tab inputs (real or fake elements).
 * @return {{value: string}|null} The matching input, or null.
 */
function findStoredInput( stored, inputs ) {
	if ( ! stored ) {
		return null;
	}

	for ( const input of inputs ) {
		if ( input.value === stored ) {
			return input;
		}
	}

	return null;
}

/**
 * @return {string|null} The stored division's sp_table post id, or null.
 */
function readStoredDivision() {
	try {
		return window.localStorage.getItem( STORAGE_KEY );
	} catch {
		return null; // Private browsing / storage disabled -- fall back silently.
	}
}

/**
 * @param {string} value
 * @return {void}
 */
function storeDivision( value ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, value );
	} catch {
		// Private browsing / storage disabled -- the tab strip still works,
		// it just won't remember next time.
	}
}

function initStandingsTabs() {
	const container = document.querySelector( '.bl-standings-tabs' );

	if ( ! container ) {
		return;
	}

	const inputs = Array.from(
		container.querySelectorAll( '.bl-standings-tabs__input' )
	);

	if ( ! inputs.length ) {
		return;
	}

	const match = findStoredInput( readStoredDivision(), inputs );

	if ( match ) {
		match.checked = true;
	}

	inputs.forEach( ( input ) => {
		input.addEventListener( 'change', () => {
			if ( input.checked ) {
				storeDivision( input.value );
			}
		} );
	} );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initStandingsTabs );
	} else {
		initStandingsTabs();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { findStoredInput };
}
