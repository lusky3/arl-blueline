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
 *
 * Live-review finding: a schedule table's Arena column clipped at the
 * viewport edge with only a faint native scrollbar as a cue -- the fade
 * mask above never appeared for it. Root cause: `.sp-scrollable-table-wrapper`
 * is not server-rendered by this theme at all -- it is created at RUNTIME by
 * SportsPress's own bundled jQuery script, which wraps an existing
 * `<table class="sp-scrollable-table">` in it on that script's own DOM-ready
 * handler. This file's own DOMContentLoaded handler is a SEPARATE listener
 * whose firing order relative to that one is not something this theme
 * controls (it depends on script enqueue order and jQuery's own ready
 * timing). A one-time `querySelectorAll( CONTAINERS )` snapshot, taken here
 * before that wrap() call has run, would find zero `.sp-scrollable-table-
 * wrapper` elements and -- with no later re-scan -- would never attach an
 * update()/observer to the one that eventually appears. Native scrolling
 * would still work (the wrapper's own CSS `overflow-x: auto` needs no JS),
 * but the fade cue this file exists to provide would silently never show,
 * for that table, ever: exactly the reported symptom. The MutationObserver
 * below catches any such container the moment it actually appears in the
 * DOM, instead of assuming the initial snapshot already saw everything.
 */

const CONTAINERS = [
	'.bl-table-scroll',
	'.sp-scrollable-table-wrapper',
	'table.bl-table-self-scroll',
	// QA A-02: legacy classic-editor tables scroll themselves (sportspress.css).
	'.entry-content > table:not([class*="sp-"])',
].join( ',' );

// Marks the attributes this file added, so only those are ever removed again.
const FOCUS_FLAG = 'data-bl-scroll-focus';

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

	setFocusable( el, max > SLOP );

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

/**
 * Accessible name for a scroll container: the table's caption, else the
 * SportsPress caption heading above it, else a generic label.
 *
 * @param {Element} el Scroll container.
 * @return {string} Label text.
 */
function scrollLabel( el ) {
	const table =
		'TABLE' === el.tagName
			? el
			: el.querySelector && el.querySelector( 'table' );
	const caption = table && table.caption ? table.caption.textContent : '';
	const template = el.closest && el.closest( '.sp-template' );
	const heading =
		template && template.querySelector( '.sp-table-caption, .sp-heading' );
	const text = ( caption || ( heading && heading.textContent ) || '' )
		.replace( /\s+/g, ' ' )
		.trim();

	return uniqueLabel( el, text || 'Scrollable table' );
}

/**
 * Two regions sharing one name are indistinguishable in a screen reader's
 * landmark list (axe landmark-unique), and SportsPress can render two
 * identically captioned tables (a team page's two "Upcoming Games"). The
 * first keeps its plain name; later ones get a numeric suffix.
 *
 * @param {Element} el   Scroll container being named.
 * @param {string}  text Proposed label.
 * @return {string} Label unique among the labels this script generated.
 */
function uniqueLabel( el, text ) {
	if ( ! el.ownerDocument ) {
		return text;
	}

	const taken = Array.from(
		el.ownerDocument.querySelectorAll( `[${ FOCUS_FLAG }="label"]` )
	).filter( ( other ) => other !== el );
	const labels = new Set(
		taken.map( ( other ) => other.getAttribute( 'aria-label' ) )
	);

	let label = text;
	for ( let n = 2; labels.has( label ); n++ ) {
		label = `${ text } (${ n })`;
	}

	return label;
}

/**
 * QA C-03/B-08 (WCAG 2.1.1): a container that actually overflows must be
 * reachable by keyboard so arrow keys can scroll it; one that fits must not
 * add an empty tab stop. A <table> keeps its own role.
 *
 * @param {Element} el         Scroll container.
 * @param {boolean} scrollable Whether it currently overflows.
 * @return {void}
 */
function setFocusable( el, scrollable ) {
	const added = el.hasAttribute( FOCUS_FLAG );

	if ( scrollable && ! added ) {
		if ( el.hasAttribute( 'tabindex' ) ) {
			return; // Someone else owns this element's focus behaviour.
		}

		el.setAttribute( FOCUS_FLAG, '' );
		el.setAttribute( 'tabindex', '0' );

		if ( 'TABLE' !== el.tagName ) {
			el.setAttribute( 'role', 'region' );
		}

		if (
			! el.hasAttribute( 'aria-label' ) &&
			! el.hasAttribute( 'aria-labelledby' )
		) {
			el.setAttribute( FOCUS_FLAG, 'label' );
			el.setAttribute( 'aria-label', scrollLabel( el ) );
		}
	} else if ( ! scrollable && added ) {
		if ( 'label' === el.getAttribute( FOCUS_FLAG ) ) {
			el.removeAttribute( 'aria-label' );
		}

		if ( 'TABLE' !== el.tagName ) {
			el.removeAttribute( 'role' );
		}

		el.removeAttribute( 'tabindex' );
		el.removeAttribute( FOCUS_FLAG );
	}
}

/**
 * Wire up one scroll container: the scroll listener, the resize observer
 * (when available), and an immediate first measurement. Safe to call more
 * than once for the same element -- $seen makes every call after the first
 * a no-op, so a container the initial scan already found and a later
 * MutationObserver hit for the same element never double-attach listeners.
 *
 * @param {Element}             el             Scroll container.
 * @param {ResizeObserver|null} resizeObserver Shared observer instance, or null when unsupported.
 * @param {WeakSet}             seen           Elements already wired up.
 * @return {void}
 */
function attach( el, resizeObserver, seen ) {
	if ( seen.has( el ) ) {
		return;
	}

	seen.add( el );

	let queued = false;

	el.addEventListener(
		'scroll',
		() => {
			if ( queued ) {
				return;
			}

			queued = true;

			// One measurement per frame: scroll fires far more often than
			// the browser paints, and reading scrollLeft/scrollWidth in the
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

/**
 * Attach every scroll container within $root (inclusive of $root itself) --
 * used both for the initial document-wide scan and for each node a later
 * DOM mutation adds.
 *
 * @param {Node}                root           Node to scan.
 * @param {ResizeObserver|null} resizeObserver Shared observer instance, or null when unsupported.
 * @param {WeakSet}             seen           Elements already wired up.
 * @return {void}
 */
function attachWithin( root, resizeObserver, seen ) {
	if ( ! root || 1 !== root.nodeType ) {
		return;
	}

	if ( root.matches( CONTAINERS ) ) {
		attach( root, resizeObserver, seen );
	}

	root.querySelectorAll( CONTAINERS ).forEach( ( el ) =>
		attach( el, resizeObserver, seen )
	);
}

function initTableScroll() {
	const seen = new WeakSet();

	const resizeObserver =
		'undefined' !== typeof window.ResizeObserver
			? new window.ResizeObserver( ( entries ) => {
					entries.forEach( ( entry ) => update( entry.target ) );
				} )
			: null;

	attachWithin( document.body, resizeObserver, seen );

	// See this file's own top-of-file docblock: a container SportsPress's own
	// script creates after this handler has already run must still be found.
	if ( 'undefined' !== typeof window.MutationObserver ) {
		new window.MutationObserver( ( mutations ) => {
			mutations.forEach( ( mutation ) => {
				mutation.addedNodes.forEach( ( node ) =>
					attachWithin( node, resizeObserver, seen )
				);
			} );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	// Late web fonts change column widths, which changes whether a table
	// overflows at all.
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( () =>
			document.querySelectorAll( CONTAINERS ).forEach( update )
		);
	}
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initTableScroll );
	} else {
		initTableScroll();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { update, attach, attachWithin, scrollLabel };
}
