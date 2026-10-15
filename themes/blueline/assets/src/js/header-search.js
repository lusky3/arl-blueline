/**
 * Blueline header search: the desktop search button and the panel it opens
 * under the header bar (blueline_site_header(), inc/template-tags.php).
 *
 * The button is printed hidden, because without this script it could open
 * nothing; the mobile drawer carries its own always-visible form. This:
 *
 *   - reveals the button;
 *   - opens the panel on click and moves focus to its search field;
 *   - closes it on a second click, on Escape (focus back to the button), when
 *     focus or a click lands outside the button and the panel, and when the
 *     window narrows into the drawer layout;
 *   - keeps aria-expanded in step with the panel.
 *
 * Any opening animation is CSS, under prefers-reduced-motion: no-preference.
 */

const DESKTOP_QUERY = '(min-width: 960px)';

/**
 * Wire one button to the panel it controls.
 *
 * @param {Element} toggle The `.bl-search-toggle` button.
 * @param {Element} panel  The element its aria-controls names.
 * @param {Window}  win    Window, for the desktop media query.
 * @return {{open: function(): void, close: function(boolean): void, isOpen: function(): boolean}} Controller.
 */
function bindHeaderSearch( toggle, panel, win ) {
	const doc = toggle.ownerDocument;

	function isOpen() {
		return ! panel.hidden;
	}

	function close( restoreFocus ) {
		if ( ! isOpen() ) {
			return;
		}
		panel.hidden = true;
		toggle.setAttribute( 'aria-expanded', 'false' );
		if ( restoreFocus ) {
			toggle.focus();
		}
	}

	function open() {
		panel.hidden = false;
		toggle.setAttribute( 'aria-expanded', 'true' );
		const field = panel.querySelector(
			'input[type="search"], input[name="s"]'
		);
		if ( field ) {
			field.focus();
		}
	}

	function outside( target ) {
		return ! toggle.contains( target ) && ! panel.contains( target );
	}

	toggle.hidden = false;

	toggle.addEventListener( 'click', () => {
		if ( isOpen() ) {
			close( false );
		} else {
			open();
		}
	} );

	doc.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' !== event.key || ! isOpen() ) {
			return;
		}
		if ( ! outside( doc.activeElement ) ) {
			event.preventDefault();
			close( true );
		}
	} );

	doc.addEventListener( 'focusin', ( event ) => {
		if ( isOpen() && outside( event.target ) ) {
			close( false );
		}
	} );

	doc.addEventListener( 'click', ( event ) => {
		if ( isOpen() && outside( event.target ) ) {
			close( false );
		}
	} );

	if ( win && typeof win.matchMedia === 'function' ) {
		const desktop = win.matchMedia( DESKTOP_QUERY );
		const onChange = () => {
			if ( ! desktop.matches ) {
				close( false );
			}
		};
		if ( typeof desktop.addEventListener === 'function' ) {
			desktop.addEventListener( 'change', onChange );
		}
	}

	return { open, close, isOpen };
}

/**
 * Find the header's search button and panel and wire them.
 *
 * @param {Document} doc Document.
 * @param {Window}   win Window.
 * @return {Object|null} The controller, or null when the markup is absent.
 */
function initHeaderSearch( doc, win ) {
	const toggle = doc.querySelector( '.bl-search-toggle' );
	const panel = toggle
		? doc.getElementById( toggle.getAttribute( 'aria-controls' ) )
		: null;

	if ( ! toggle || ! panel ) {
		return null;
	}

	return bindHeaderSearch( toggle, panel, win );
}

if ( typeof document !== 'undefined' && typeof window !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', () =>
			initHeaderSearch( document, window )
		);
	} else {
		initHeaderSearch( document, window );
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { bindHeaderSearch, initHeaderSearch, DESKTOP_QUERY };
}
