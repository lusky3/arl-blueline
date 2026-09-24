/**
 * Show a small "more content this way" cue at whichever edge of the
 * sidebar's own scroll box (.bl-sidebar, overflow-y: auto, layout.css)
 * currently has more content past it.
 *
 * Reported live: on a short page, the widget stack can run taller than its
 * box with nothing indicating it scrolls at all -- confirmed, a reader has
 * no reason to expect a plain content block does.
 *
 * Mirrors table-scroll.js's own contract closely, on purpose: this is the
 * same "only cue the side that actually has more, published as a data
 * attribute the stylesheet reads" shape, just for a vertical box instead of
 * a horizontal one -- see that file's own docblock for why an
 * unconditional cue (rather than one gated on real overflow) is a bug, not
 * a simplification: it reads as "there's more here" even once scrolled all
 * the way to that end.
 *
 * The fade itself is drawn by layout.css directly from the data
 * attributes ([data-can-scroll-up]::before / [data-can-scroll-down]::after
 * on .bl-sidebar) -- no markup needed for it. The chevron is real markup,
 * injected once here rather than drawn in CSS: it needs to be an actual
 * element for the bounce animation and currentColor theming table-scroll.js's
 * own pure-CSS approach doesn't need, and this file is the one place that
 * already knows which sidebars exist.
 */

const SIDEBAR_SELECTOR = '.bl-sidebar';

// Ignore sub-pixel rounding: a box exactly as tall as its content still
// reports a fractional scrollable remainder in some browsers' layout math,
// which would otherwise show a cue with nowhere left to scroll to.
const SLOP = 2;

const CHEVRON_SVG =
	'<svg class="bl-sidebar__scroll-cue-chevron" viewBox="0 0 12 8" aria-hidden="true" focusable="false"><path d="M1 1l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

/**
 * Publish whether $el has more content above and/or below what's currently
 * scrolled into view.
 *
 * @param {Element} el Scroll container.
 * @return {void}
 */
function update( el ) {
	const max = el.scrollHeight - el.clientHeight;

	if ( max <= SLOP ) {
		el.removeAttribute( 'data-can-scroll-up' );
		el.removeAttribute( 'data-can-scroll-down' );
		return;
	}

	el.toggleAttribute( 'data-can-scroll-up', el.scrollTop > SLOP );
	el.toggleAttribute( 'data-can-scroll-down', el.scrollTop < max - SLOP );
}

/**
 * Wire up one sidebar: inject its two chevrons, the scroll listener, the
 * resize observer (when available), and an immediate first measurement.
 *
 * @param {Element}             el             Sidebar element.
 * @param {ResizeObserver|null} resizeObserver Shared observer instance, or null when unsupported.
 * @return {void}
 */
function attach( el, resizeObserver ) {
	const up = document.createElement( 'span' );
	up.className = 'bl-sidebar__scroll-cue bl-sidebar__scroll-cue--up';
	up.setAttribute( 'aria-hidden', 'true' );
	up.innerHTML = CHEVRON_SVG;

	const down = document.createElement( 'span' );
	down.className = 'bl-sidebar__scroll-cue bl-sidebar__scroll-cue--down';
	down.setAttribute( 'aria-hidden', 'true' );
	down.innerHTML = CHEVRON_SVG;

	el.prepend( up );
	el.append( down );

	let queued = false;

	el.addEventListener(
		'scroll',
		() => {
			if ( queued ) {
				return;
			}

			queued = true;

			// One measurement per frame: scroll fires far more often than
			// the browser paints, and reading scrollTop/scrollHeight in the
			// handler forces a layout flush every time.
			window.requestAnimationFrame( () => {
				queued = false;
				update( el );
			} );
		},
		{ passive: true }
	);

	if ( resizeObserver ) {
		resizeObserver.observe( el );
	}

	update( el );
}

function initSidebarScrollCues() {
	const sidebars = document.querySelectorAll( SIDEBAR_SELECTOR );

	if ( ! sidebars.length ) {
		return;
	}

	const resizeObserver =
		'undefined' !== typeof window.ResizeObserver
			? new window.ResizeObserver( ( entries ) => {
					entries.forEach( ( entry ) => update( entry.target ) );
			  } )
			: null;

	sidebars.forEach( ( el ) => attach( el, resizeObserver ) );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initSidebarScrollCues );
	} else {
		initSidebarScrollCues();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { update, attach };
}
