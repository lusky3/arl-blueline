/**
 * Shrink the fixed site header once the reader has scrolled past a threshold.
 *
 * All this does is toggle one class; every dimension lives in header.css, so
 * the two states cannot drift apart and a reduced-motion preference is handled
 * entirely in CSS rather than being re-decided here.
 *
 * Deliberately NOT a hide-on-scroll-down/show-on-scroll-up header: that
 * pattern reverses direction under the reader whenever they nudge the wheel,
 * and on a site whose main content is long schedule and standings tables it
 * fights the exact interaction people come here for. This only ever shrinks
 * and re-expands, and only at a fixed offset.
 */

import { onReady } from './dom-ready.js';
import { rafThrottle } from './raf-throttle.js';

const HEADER_SELECTOR = '.bl-header';
const STUCK_CLASS = 'is-stuck';

/*
 * Enter the shrunk state a little further down than we leave it. Without that
 * gap a reader parked exactly on the boundary -- or the 1-2px of drift a
 * trackpad produces at rest -- gets the header flipping between both states
 * repeatedly. Leaving is cheaper than entering, so the exit threshold is the
 * lower of the two.
 */
const ENTER_AT = 80;
const EXIT_AT = 40;

function initStickyHeader() {
	const header = document.querySelector( HEADER_SELECTOR );

	if ( ! header ) {
		return;
	}

	let stuck = false;

	/*
	 * Publish the header's un-shrunk height so .bl-header__spacer can reserve
	 * exactly that much. CSS cannot compute it: the sponsor slot is filled
	 * asynchronously by SportsPress (and may stay empty), the menu's line count
	 * depends on the viewport, and web fonts change the row height when they
	 * load. Only ever measured while NOT stuck -- measuring the shrunk header
	 * would shrink the reservation too and reintroduce the exact content jump
	 * the fixed-plus-spacer arrangement exists to prevent.
	 */
	const publishRestHeight = () => {
		if ( stuck ) {
			return;
		}

		const h = Math.round( header.getBoundingClientRect().height );

		if ( h > 0 ) {
			document.documentElement.style.setProperty(
				'--bl-header-rest-height',
				`${ h }px`
			);
		}
	};

	const apply = () => {
		const y = window.scrollY;

		// Hysteresis: only act on a crossing, and only of the relevant edge.
		if ( ! stuck && y > ENTER_AT ) {
			stuck = true;
			header.classList.add( STUCK_CLASS );
		} else if ( stuck && y < EXIT_AT ) {
			stuck = false;
			header.classList.remove( STUCK_CLASS );
		}
	};

	// Coalesce to one class check per frame. scroll fires far more often than
	// the browser paints, and reading scrollY in the handler itself would
	// force a layout flush on every one of those events.
	window.addEventListener( 'scroll', rafThrottle( apply ), {
		passive: true,
	} );

	publishRestHeight();

	/*
	 * Re-measure when the header's own box changes for any reason -- viewport
	 * resize, the menu re-wrapping, fonts arriving, or SportsPress dropping a
	 * sponsor logo in long after load. A ResizeObserver catches all of those;
	 * a resize listener alone would miss the async ones.
	 */
	if ( 'undefined' !== typeof window.ResizeObserver ) {
		new window.ResizeObserver( publishRestHeight ).observe( header );
	} else {
		window.addEventListener( 'resize', publishRestHeight );
	}

	// A reload part-way down the page restores the scroll position without
	// firing a scroll event, so the header would otherwise start tall while
	// the reader is already deep in the document.
	apply();
}

onReady( initStickyHeader );

export { ENTER_AT, EXIT_AT };
