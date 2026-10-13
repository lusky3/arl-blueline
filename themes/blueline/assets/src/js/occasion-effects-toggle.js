/**
 * Blueline — "Hide seasonal effects" control for the occasion effects layer.
 *
 * The falling snow, poppies and fireworks (inc/occasions.php, occasions.css)
 * run for as long as the page is open and sit beside the content being read,
 * so the visitor needs a way to stop them on the page itself -- an operating
 * system reduced-motion setting is honoured too (occasions.css), but is not
 * something every visitor has found or set.
 *
 * The control is added from script, in the footer's bottom bar, only when the
 * effects layer is actually on the page: an occasion is active on a handful
 * of days a year, and a permanent control for something that is not there
 * would be noise. Hiding the layer is a class on <html> (occasions.css), and
 * the choice is remembered per browser. Storage can throw (disabled, private
 * mode), so every access is wrapped; without it the choice simply lasts for
 * the page view.
 */

const STORAGE_KEY = 'blueline:occasion-effects-hidden';
const HIDDEN_CLASS = 'bl-occasion-effects-hidden';

/**
 * The button's text for the current state.
 *
 * @param {boolean} hidden Whether the effects are currently hidden.
 * @return {string} The label.
 */
function buttonLabel( hidden ) {
	return hidden ? 'Show seasonal effects' : 'Hide seasonal effects';
}

/**
 * Whether this browser has chosen to hide the effects.
 *
 * @param {{getItem: Function}} storage A localStorage-like object.
 * @return {boolean} True when hidden.
 */
function readHidden( storage ) {
	try {
		return '1' === storage.getItem( STORAGE_KEY );
	} catch {
		return false;
	}
}

/**
 * Remember the choice. A failure to store is not worth surfacing.
 *
 * @param {{setItem: Function, removeItem: Function}} storage A localStorage-like object.
 * @param {boolean}                                   hidden  Whether the effects are hidden.
 */
function writeHidden( storage, hidden ) {
	try {
		if ( hidden ) {
			storage.setItem( STORAGE_KEY, '1' );
		} else {
			storage.removeItem( STORAGE_KEY );
		}
	} catch {
		// Intentionally ignored -- see this function's own docblock.
	}
}

/**
 * `window.localStorage`, or null when even touching it throws.
 *
 * @return {Storage|null} The storage object, if usable.
 */
function getStorage() {
	try {
		return window.localStorage;
	} catch {
		return null;
	}
}

if ( typeof document !== 'undefined' ) {
	const effects = document.querySelector( '.bl-occasion-effects' );
	const bar = document.querySelector( '.bl-footer__bottom-inner' );

	if ( effects && bar ) {
		const root = document.documentElement;
		const storage = getStorage();
		let hidden = storage ? readHidden( storage ) : false;

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'bl-occasion-toggle';

		const apply = () => {
			root.classList.toggle( HIDDEN_CLASS, hidden );
			button.textContent = buttonLabel( hidden );
		};

		button.addEventListener( 'click', () => {
			hidden = ! hidden;
			apply();

			if ( storage ) {
				writeHidden( storage, hidden );
			}
		} );

		apply();
		bar.appendChild( button );
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { buttonLabel, readHidden, writeHidden, STORAGE_KEY };
}
