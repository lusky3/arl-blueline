/**
 * Fade the edges of a horizontally scrolling table only where content is
 * actually hidden.
 *
 * The fade started life as an unconditional mask on both edges, which meant it
 * dimmed the first and last 24px of every table whether or not there was
 * anything to scroll to -- so a table that fitted its column still had its
 * leading characters greyed out ("ᴛIME", "ᴀUGUST 14, 2026", "₁0:30 pm"), and a
 * table scrolled hard to the left still faded a left edge with nothing hidden
 * behind it. The cue was covering the information it was meant to help people
 * find.
 *
 * CSS alone cannot express "only if this element overflows, and only on the
 * side that has more": there is no overflow or scroll-position selector. So the
 * state is measured here and published as two data attributes, and sportspress
 * .css maps them to the mask. With no JS the attributes are absent and no mask
 * applies -- losing a hint, never hiding content.
 */

import { onReady } from './dom-ready.js';
import { rafThrottle } from './raf-throttle.js';

const CONTAINERS = [
	'.bl-table-scroll',
	'.sp-scrollable-table-wrapper',
	'table.bl-table-self-scroll',
].join( ',' );

// Ignore sub-pixel rounding: a container whose scrollWidth exceeds its
// clientWidth by a fraction is not actually scrollable, and layout maths
// routinely lands a pixel out.
const SLOP = 2;

/**
 * Publish whether $el has hidden content to its left and/or right.
 *
 * @param {Element} el Scroll container.
 * @return {void}
 */
function update( el ) {
	const max = el.scrollWidth - el.clientWidth;

	if ( max <= SLOP ) {
		el.removeAttribute( 'data-fade-start' );
		el.removeAttribute( 'data-fade-end' );
		return;
	}

	// scrollLeft is negative in RTL in some engines; the distance from each end
	// is what matters, so normalise before comparing.
	const from = Math.abs( el.scrollLeft );

	el.toggleAttribute( 'data-fade-start', from > SLOP );
	el.toggleAttribute( 'data-fade-end', from < max - SLOP );
}

function initTableScroll() {
	const containers = document.querySelectorAll( CONTAINERS );

	if ( ! containers.length ) {
		return;
	}

	const observer =
		'undefined' !== typeof window.ResizeObserver
			? new window.ResizeObserver( ( entries ) => {
					entries.forEach( ( entry ) => update( entry.target ) );
			  } )
			: null;

	containers.forEach( ( el ) => {
		// One measurement per frame: scroll fires far more often than the
		// browser paints, and reading scrollLeft/scrollWidth in the handler
		// forces a layout flush every time.
		el.addEventListener(
			'scroll',
			rafThrottle( () => update( el ) ),
			{
				passive: true,
			}
		);

		if ( observer ) {
			observer.observe( el );
		}

		update( el );
	} );

	// Late web fonts change column widths, which changes whether a table
	// overflows at all.
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( () => containers.forEach( update ) );
	}
}

onReady( initTableScroll );
