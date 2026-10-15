/**
 * Warn screen-reader users before a link opens a new tab.
 *
 * Sponsor logos, menu items set to open in a new tab, the Page Links To
 * plugin's "#new_tab" links and links in page content all carry
 * `target="_blank"` with nothing that says so; a screen-reader user only finds
 * out when the Back button stops working. Each such link gets a visually
 * hidden "(opens in a new tab)" after its own text, or the same words appended
 * to its aria-label, which would otherwise override that text.
 *
 * Theme-rendered links (the primary menu, sponsor slots) already print the
 * hint server-side with the same class, so they work without JavaScript; this
 * covers everything else, including the sponsor slots SportsPress loads over
 * AJAX after the page, and links Page Links To retargets at runtime.
 */

const HINT_CLASS = 'bl-new-tab-hint';
const HINT_TEXT = '(opens in a new tab)';

// Already says so in its own words, in a language the hint would only repeat.
const SAYS_SO = /new (tab|window)/i;

/**
 * Add the hint to one link, if it opens a new tab and does not say so yet.
 *
 * @param {Element}  link <a>.
 * @param {Document} doc  Document that creates the hint.
 * @return {boolean} Whether the hint was added.
 */
function hintLink( link, doc ) {
	if ( '_blank' !== ( link.getAttribute( 'target' ) || '' ).toLowerCase() ) {
		return false;
	}

	if ( link.querySelector( `.${ HINT_CLASS }` ) ) {
		return false;
	}

	const label = link.getAttribute( 'aria-label' );

	if ( null !== label ) {
		if ( SAYS_SO.test( label ) ) {
			return false;
		}

		link.setAttribute( 'aria-label', `${ label.trim() } ${ HINT_TEXT }` );
		return true;
	}

	if ( SAYS_SO.test( link.textContent || '' ) ) {
		return false;
	}

	const hint = doc.createElement( 'span' );
	hint.className = `screen-reader-text ${ HINT_CLASS }`;
	hint.textContent = ` ${ HINT_TEXT }`;
	link.appendChild( hint );

	return true;
}

/**
 * Hint every new-tab link inside $root (and $root itself, if it is one).
 *
 * @param {Element|Document} root Where to look.
 * @param {Document}         doc  Document.
 * @return {void}
 */
function hintLinksWithin( root, doc ) {
	if ( root.matches && root.matches( 'a[target]' ) ) {
		hintLink( root, doc );
	}

	if ( root.querySelectorAll ) {
		root.querySelectorAll( 'a[target]' ).forEach( ( link ) =>
			hintLink( link, doc )
		);
	}
}

function initNewTabLinks() {
	hintLinksWithin( document, document );

	// Page Links To sets its targets on load.
	window.addEventListener( 'load', () =>
		hintLinksWithin( document, document )
	);

	if ( 'undefined' !== typeof window.MutationObserver ) {
		new window.MutationObserver( ( mutations ) => {
			mutations.forEach( ( mutation ) =>
				mutation.addedNodes.forEach( ( added ) => {
					if ( 1 === added.nodeType ) {
						hintLinksWithin( added, document );
					}
				} )
			);
		} ).observe( document.body, { childList: true, subtree: true } );
	}
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initNewTabLinks );
	} else {
		initNewTabLinks();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { HINT_CLASS, HINT_TEXT, hintLink, hintLinksWithin };
}
