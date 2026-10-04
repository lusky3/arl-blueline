/**
 * Accessible names for SportsPress logo/photo links (C-04, A-13, C-27).
 *
 * 1. An image-only link (player-list Team column, team gallery, sponsor
 *    logos, `.team-logo` links) is hidden when a same-URL text link sits in
 *    the same item; otherwise, if it has no alt, it is labelled from the
 *    link/wrapper/image `title`. A `.team-logo` link with no image and no
 *    text is hidden.
 * 2. A crest or player photo printed next to the visible name (same link or
 *    cell) is decorative, so its alt is emptied ("Red Wings Red Wings").
 */

const LINK_SELECTOR = '.team-logo a';
const IMAGE_LINK_SELECTOR = 'a[href]';
const LOGO_IMG_SELECTOR =
	'.team-logo img, .player-photo img, .staff-photo img, .sp-data-table img';
const NAME_CONTAINERS = 'a[href], button, td, th, li, dd, figcaption';
const ITEM_CONTAINERS = '.gallery-item, li, tr, figure, article';

/**
 * Trimmed attribute value ('' when missing).
 *
 * @param {Element|null} el   Element.
 * @param {string}       name Attribute.
 * @return {string} Value.
 */
function attr( el, name ) {
	return el ? ( el.getAttribute( name ) || '' ).trim() : '';
}

/**
 * Whether a link already has a usable accessible name.
 *
 * @param {Element} link Anchor element.
 * @return {boolean} True if it is named.
 */
function hasName( link ) {
	if ( attr( link, 'aria-label' ) || attr( link, 'aria-labelledby' ) ) {
		return true;
	}

	if ( ( link.textContent || '' ).trim() ) {
		return true;
	}

	return !! attr( link.querySelector( 'img' ), 'alt' );
}

/**
 * The best name for an image-only link, from its own markup.
 *
 * @param {Element} link Anchor element.
 * @return {string} Name, or ''.
 */
function nameFor( link ) {
	const wrapper = link.closest( '.team-logo' );

	return (
		attr( link, 'title' ) ||
		attr( wrapper, 'title' ) ||
		attr( link.querySelector( 'img' ), 'title' )
	);
}

/**
 * Whether a named link to the same URL sits in the link's own item.
 *
 * @param {Element} link Anchor element.
 * @return {boolean} True if the image link duplicates a text link.
 */
function hasNamedTwin( link ) {
	const item = link.closest( ITEM_CONTAINERS );
	const href = attr( link, 'href' );

	if ( ! item || ! href ) {
		return false;
	}

	return Array.from( item.querySelectorAll( IMAGE_LINK_SELECTOR ) ).some(
		( other ) =>
			other !== link &&
			attr( other, 'href' ) === href &&
			( other.textContent || '' ).trim()
	);
}

/**
 * Hide a link from assistive tech and the tab order.
 *
 * @param {Element} link Anchor element.
 */
function hide( link ) {
	link.setAttribute( 'aria-hidden', 'true' );
	link.setAttribute( 'tabindex', '-1' );
}

/**
 * Name or hide every unnamed image link under root.
 *
 * @param {Element|null} root Container (or document).
 * @return {number} How many links were changed.
 */
function fixTeamLogoLinks( root ) {
	if ( ! root || typeof root.querySelectorAll !== 'function' ) {
		return 0;
	}

	let changed = 0;

	root.querySelectorAll( IMAGE_LINK_SELECTOR ).forEach( ( link ) => {
		if (
			'true' === attr( link, 'aria-hidden' ) ||
			( link.textContent || '' ).trim()
		) {
			return;
		}

		const hasImg = !! link.querySelector( 'img' );

		if ( ! hasImg ) {
			if ( ! hasName( link ) && link.closest( '.team-logo' ) ) {
				hide( link );
				changed++;
			}
			return;
		}

		// A logo repeating the text link beside it is one tab stop too many.
		if ( hasNamedTwin( link ) ) {
			hide( link );
			changed++;
			return;
		}

		const name = hasName( link ) ? '' : nameFor( link );

		if ( name ) {
			link.setAttribute( 'aria-label', name );
			changed++;
		}
	} );

	return changed;
}

/**
 * Empty the alt of crests/photos printed beside their own visible name.
 *
 * @param {Element|null} root Container (or document).
 * @return {number} How many images were changed.
 */
function fixRedundantAlts( root ) {
	if ( ! root || typeof root.querySelectorAll !== 'function' ) {
		return 0;
	}

	let changed = 0;

	root.querySelectorAll( LOGO_IMG_SELECTOR ).forEach( ( img ) => {
		const container = img.closest( NAME_CONTAINERS );

		if (
			! attr( img, 'alt' ) ||
			! container ||
			! ( container.textContent || '' ).trim()
		) {
			return;
		}

		img.setAttribute( 'alt', '' );
		changed++;
	} );

	return changed;
}

/**
 * Run both passes.
 *
 * @param {Element|null} root Container (or document).
 */
function run( root ) {
	fixRedundantAlts( root );
	fixTeamLogoLinks( root );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', () => run( document ) );
	} else {
		run( document );
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = {
		fixTeamLogoLinks,
		fixRedundantAlts,
		hasName,
		run,
		LINK_SELECTOR,
		LOGO_IMG_SELECTOR,
	};
}
