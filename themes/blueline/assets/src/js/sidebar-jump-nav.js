/**
 * Scroll-spy for the "on this page" jump-nav (inc/page-sidebar-navigation.php):
 * highlights whichever section the reader has scrolled past, so the rail
 * always shows where they are, not just where they last clicked. Purely a
 * progressive enhancement -- the plain link list underneath works fully
 * without this file.
 */

const ACTIVE_CLASS = 'is-active';

/**
 * Pure: given each section's own vertical offset (top of its heading, in
 * the same coordinate space as scrollPosition) and how far the reader has
 * scrolled, return the id of the section currently "current" -- the last
 * heading the scroll position has reached, or the first section if none
 * has been reached yet (top of page). Split out from the real DOM
 * measurement so this decision is testable without a real layout -- see
 * standings-tabs.js's findStoredInput() for the same trade-off elsewhere in
 * this file set.
 *
 * @param {Array<{id: string, offsetTop: number}>} sections       Must be non-empty to return a result.
 * @param {number}                                 scrollPosition
 * @return {string|null} The active section's id, or null if sections is empty.
 */
function pickActiveSectionId( sections, scrollPosition ) {
	if ( ! sections.length ) {
		return null;
	}

	let active = sections[ 0 ];

	for ( const section of sections ) {
		if ( section.offsetTop <= scrollPosition ) {
			active = section;
		}
	}

	return active.id;
}

function initSidebarJumpNav() {
	const nav = document.querySelector( '.bl-jump-nav' );

	if ( ! nav ) {
		return;
	}

	const links = Array.from( nav.querySelectorAll( '.bl-jump-nav__list a' ) );

	if ( ! links.length ) {
		return;
	}

	const linksById = new Map();
	const sections = [];

	links.forEach( ( link ) => {
		const id = link.getAttribute( 'href' ).slice( 1 );
		const heading = document.getElementById( id );

		if ( ! heading ) {
			return; // Stale/mismatched anchor -- leave this link never-active rather than erroring.
		}

		linksById.set( id, link );
		sections.push( { id, offsetTop: heading.offsetTop } );
	} );

	if ( ! sections.length ) {
		return;
	}

	let ticking = false;

	function updateActiveLink() {
		ticking = false;

		/*
		 * A generous fixed buffer (rather than reading --bl-header-rest-height
		 * back out of computed styles) keeps this roughly in step with
		 * scroll-padding-top's own header-clearance math (base.css) without
		 * this file needing to duplicate a CSS custom property read -- no
		 * other file in this set does that, and being a section early counts
		 * as "reached" for scroll-spy purposes anyway.
		 */
		const activeId = pickActiveSectionId( sections, window.scrollY + 120 );
		const activeLink = activeId ? linksById.get( activeId ) : null;

		links.forEach( ( link ) => {
			link.classList.toggle( ACTIVE_CLASS, link === activeLink );
		} );
	}

	function onScroll() {
		if ( ticking ) {
			return;
		}

		ticking = true;
		window.requestAnimationFrame( updateActiveLink );
	}

	updateActiveLink();
	window.addEventListener( 'scroll', onScroll, { passive: true } );
	window.addEventListener( 'resize', onScroll );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initSidebarJumpNav );
	} else {
		initSidebarJumpNav();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { pickActiveSectionId };
}
