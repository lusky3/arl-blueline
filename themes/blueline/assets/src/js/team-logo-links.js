/**
 * SportsPress prints team logos as links (`.team-logo > a`) around a media-library
 * image whose alt text is usually empty, or around nothing at all for a team with
 * no logo. Both give a link with no accessible name (axe `link-name`). A link
 * that wraps an image is named after the team (the wrapper's `title`); a
 * completely empty one is hidden from assistive tech and the tab order.
 */

const LINK_SELECTOR = '.team-logo a';

/**
 * Whether an element has a usable accessible name already.
 *
 * @param {Element} link Anchor element.
 * @return {boolean} True if it is named.
 */
function hasName( link ) {
	if ( ( link.getAttribute( 'aria-label' ) || '' ).trim() ) {
		return true;
	}

	if ( ( link.textContent || '' ).trim() ) {
		return true;
	}

	const img = link.querySelector( 'img' );

	return !! ( img && ( img.getAttribute( 'alt' ) || '' ).trim() );
}

/**
 * Name or hide every unnamed team-logo link under root.
 *
 * @param {Element|null} root Container (or document).
 * @return {number} How many links were changed.
 */
function fixTeamLogoLinks( root ) {
	if ( ! root || typeof root.querySelectorAll !== 'function' ) {
		return 0;
	}

	let changed = 0;

	root.querySelectorAll( LINK_SELECTOR ).forEach( ( link ) => {
		if ( hasName( link ) ) {
			return;
		}

		const wrapper = link.closest( '.team-logo' );
		const title = wrapper
			? ( wrapper.getAttribute( 'title' ) || '' ).trim()
			: '';

		if ( link.querySelector( 'img' ) && title ) {
			link.setAttribute( 'aria-label', title );
			changed++;
		} else if ( ! link.querySelector( 'img' ) ) {
			link.setAttribute( 'aria-hidden', 'true' );
			link.setAttribute( 'tabindex', '-1' );
			changed++;
		}
	} );

	return changed;
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', () =>
			fixTeamLogoLinks( document )
		);
	} else {
		fixTeamLogoLinks( document );
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { fixTeamLogoLinks, hasName, LINK_SELECTOR };
}
