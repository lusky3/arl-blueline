/**
 * Blueline — registration rules modal focus management (D-04/D-05).
 *
 * #arl-rules-modal's markup and open/close script come from a Code Snippets
 * entry in the database, not this repo. Its own script focuses the checkbox
 * (scrolling a phone-height panel to the bottom), never traps Tab and drops
 * focus on <body> when it closes. This adds the dialog behaviour around it
 * without touching its flow: focus the heading at the top on open, keep Tab
 * inside the panel, close on Escape, and hand focus back to the button that
 * opened it.
 */

const MODAL_ID = 'arl-rules-modal';
const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Where Tab should wrap to, or null to let the browser move focus normally.
 *
 * @param {Array}   items     Focusable elements inside the dialog, in order.
 * @param {*}       current   The currently focused element.
 * @param {boolean} backwards Shift+Tab.
 * @return {*} Element to focus, or null.
 */
function wrapTarget( items, current, backwards ) {
	if ( ! items.length ) {
		return null;
	}
	const index = items.indexOf( current );
	const last = items.length - 1;

	if ( -1 === index ) {
		return backwards ? items[ last ] : items[ 0 ];
	}
	if ( ! backwards && index === last ) {
		return items[ 0 ];
	}
	if ( backwards && 0 === index ) {
		return items[ last ];
	}
	return null;
}

/**
 * Focus behaviour for one modal element.
 *
 * @param {Object} modal The #arl-rules-modal element.
 * @param {Object} doc   Its document.
 * @return {Object} { remember, opened, closed, keydown } handlers.
 */
function createController( modal, doc ) {
	const panel = modal.querySelector( '.arl-rules-panel' ) || modal;
	const heading = modal.querySelector( 'h2' );
	let opener = null;

	if ( ! modal.getAttribute( 'role' ) ) {
		modal.setAttribute( 'role', 'dialog' );
	}
	modal.setAttribute( 'aria-modal', 'true' );
	if ( heading ) {
		if ( ! heading.id ) {
			heading.id = MODAL_ID + '-heading';
		}
		if ( ! modal.getAttribute( 'aria-labelledby' ) ) {
			modal.setAttribute( 'aria-labelledby', heading.id );
		}
		heading.setAttribute( 'tabindex', '-1' );
	}

	const focusables = () =>
		Array.from( panel.querySelectorAll( FOCUSABLE ) ).filter(
			( el ) => ! el.hidden && el.getClientRects().length > 0
		);

	return {
		remember( el ) {
			if ( el && ! modal.contains( el ) ) {
				opener = el;
			}
		},
		opened() {
			const target = heading || panel;
			if ( 'function' === typeof target.focus ) {
				target.focus( { preventScroll: true } );
			}
			panel.scrollTop = 0;
		},
		closed() {
			const active = doc.activeElement;
			const lost =
				! active || active === doc.body || modal.contains( active );
			if ( opener && lost && doc.contains( opener ) ) {
				opener.focus();
			}
			opener = null;
		},
		keydown( event ) {
			if ( modal.hidden ) {
				return;
			}
			if ( 'Escape' === event.key ) {
				// The snippet's own handler normally closes it first.
				modal.hidden = true;
				doc.body.style.overflow = '';
				return;
			}
			if ( 'Tab' !== event.key ) {
				return;
			}
			const target = wrapTarget(
				focusables(),
				doc.activeElement,
				event.shiftKey
			);
			if ( target ) {
				event.preventDefault();
				target.focus();
			}
		},
	};
}

function initRulesModal() {
	const modal = document.getElementById( MODAL_ID );
	if ( ! modal || 'undefined' === typeof window.MutationObserver ) {
		return;
	}
	const controller = createController( modal, document );

	// The snippet opens on a cart form's submit; remember what triggered it.
	document.addEventListener(
		'submit',
		( event ) => {
			const form = event.target;
			if ( form && form.classList && form.classList.contains( 'cart' ) ) {
				controller.remember(
					event.submitter ||
						form.querySelector( '[type="submit"]' ) ||
						form.ownerDocument.activeElement
				);
			}
		},
		true
	);
	document.addEventListener( 'focusin', ( event ) => {
		if ( modal.hidden ) {
			controller.remember( event.target );
		}
	} );
	document.addEventListener( 'keydown', controller.keydown );

	new window.MutationObserver( () => {
		if ( modal.hidden ) {
			controller.closed();
		} else {
			controller.opened();
		}
	} ).observe( modal, { attributes: true, attributeFilter: [ 'hidden' ] } );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initRulesModal );
	} else {
		initRulesModal();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { wrapTarget, createController };
}
