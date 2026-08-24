/**
 * Repaint SportsPress' Leaflet venue map after its container settles.
 *
 * Leaflet lays tiles out for the container size it sees at init and does not
 * notice later changes. SportsPress initialises the map inside a table cell
 * whose width is not final at that moment -- the surrounding table, its
 * scroll wrapper and the web fonts all resolve afterwards -- so the map ends up
 * with tiles for a narrower box than it actually occupies, leaving a blank
 * strip down one side that reads as a half-loaded map.
 *
 * Measured on an event page: the container's right edge was at 359px while the
 * furthest tile reached only 290px, with three tiles rendered. After a resize
 * the same map had six tiles reaching past the container, as it should.
 *
 * Leaflet's own `trackResize` (on by default) already re-measures on window
 * resize, so the fix is to tell it the layout changed rather than to reach
 * inside for the map instance -- which SportsPress does not expose anywhere.
 */

import { onReady } from './dom-ready.js';

const CONTAINER = '.leaflet-container';

/**
 * Ask Leaflet to re-measure. Dispatching the event it already listens for is
 * deliberate: the alternative is grabbing a private map handle that neither
 * Leaflet nor SportsPress publishes, which would break on any update to either.
 *
 * @return {void}
 */
function nudgeLeaflet() {
	window.dispatchEvent( new Event( 'resize' ) );
}

function initVenueMap() {
	const containers = document.querySelectorAll( CONTAINER );

	if ( ! containers.length ) {
		return;
	}

	// One nudge once everything that affects width has settled: late
	// stylesheets, the scroll wrapper, and web fonts.
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( nudgeLeaflet );
	}

	window.requestAnimationFrame( nudgeLeaflet );

	if ( 'undefined' === typeof window.ResizeObserver ) {
		return;
	}

	/*
	 * And again whenever a container's own box changes afterwards -- opening
	 * the tab a map sits in, or the sidebar reflowing, both resize it long
	 * after load. Guarded against the observer reacting to the relayout its own
	 * nudge causes, which would otherwise loop.
	 */
	let settling = false;

	const observer = new window.ResizeObserver( () => {
		if ( settling ) {
			return;
		}

		settling = true;
		nudgeLeaflet();

		window.setTimeout( () => {
			settling = false;
		}, 250 );
	} );

	containers.forEach( ( el ) => observer.observe( el ) );
}

onReady( initVenueMap );

// Maps inside a tab panel only get a real size when the tab is first shown,
// which happens after DOMContentLoaded and after load.
window.addEventListener( 'load', initVenueMap );
