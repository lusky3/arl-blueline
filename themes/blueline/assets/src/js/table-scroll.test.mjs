import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { update, attach, attachWithin, scrollLabel } = require( './table-scroll.js' );

/**
 * Minimal fake scroll-container element: just enough surface for update()/
 * attach()/attachWithin() to operate on -- no real DOM, per this project's
 * own house rule against a from-memory DOM/WP_Query stub (see
 * tests/SeasonStateOverrideTest.php's docblock on the identical trade-off
 * for PHP). scrollWidth/clientWidth/scrollLeft are the only geometry
 * update() reads; attributes are tracked in a plain object so assertions
 * can read them back directly rather than re-implementing getAttribute().
 *
 * @param {object} geometry Partial overrides for scrollWidth/clientWidth/scrollLeft.
 * @return {object} A fake element.
 */
function fakeElement( geometry = {} ) {
	const attributes = {};

	return {
		scrollWidth: 0,
		clientWidth: 0,
		scrollLeft: 0,
		...geometry,
		attributes,
		nodeType: 1,
		tagName: 'DIV',
		removeAttribute( name ) {
			delete attributes[ name ];
		},
		setAttribute( name, value ) {
			attributes[ name ] = String( value );
		},
		getAttribute( name ) {
			return name in attributes ? attributes[ name ] : null;
		},
		hasAttribute( name ) {
			return name in attributes;
		},
		closest() {
			return null;
		},
		querySelector() {
			return null;
		},
		toggleAttribute( name, force ) {
			if ( force ) {
				attributes[ name ] = '';
			} else {
				delete attributes[ name ];
			}
		},
		addEventListener() {
			// Not exercised: no test fires a synthetic 'scroll' event.
		},
		matches() {
			return false;
		},
		querySelectorAll() {
			return [];
		},
	};
}

test( 'update: a container with no overflow carries neither fade attribute', () => {
	const el = fakeElement( { scrollWidth: 300, clientWidth: 300 } );

	update( el );

	assert.equal( 'data-fade-start' in el.attributes, false );
	assert.equal( 'data-fade-end' in el.attributes, false );
} );

test( 'update: scrolled fully to the start fades only the end', () => {
	const el = fakeElement( { scrollWidth: 800, clientWidth: 300, scrollLeft: 0 } );

	update( el );

	assert.equal( 'data-fade-start' in el.attributes, false );
	assert.equal( 'data-fade-end' in el.attributes, true );
} );

test( 'update: scrolled fully to the end fades only the start', () => {
	const el = fakeElement( { scrollWidth: 800, clientWidth: 300, scrollLeft: 500 } );

	update( el );

	assert.equal( 'data-fade-start' in el.attributes, true );
	assert.equal( 'data-fade-end' in el.attributes, false );
} );

test( 'update: scrolled to the middle fades both edges', () => {
	const el = fakeElement( { scrollWidth: 800, clientWidth: 300, scrollLeft: 250 } );

	update( el );

	assert.equal( 'data-fade-start' in el.attributes, true );
	assert.equal( 'data-fade-end' in el.attributes, true );
} );

test( 'attach: wires up an element and measures it immediately', () => {
	const el = fakeElement( { scrollWidth: 800, clientWidth: 300, scrollLeft: 0 } );
	const seen = new WeakSet();

	attach( el, null, seen );

	assert.equal( seen.has( el ), true );
	assert.equal( 'data-fade-end' in el.attributes, true, 'attach() must call update() at least once' );
} );

test( 'attach: the same element is never wired up twice', () => {
	const el = fakeElement( { scrollWidth: 800, clientWidth: 300 } );
	const seen = new WeakSet();
	let listenerCount = 0;
	el.addEventListener = () => {
		listenerCount++;
	};

	attach( el, null, seen );
	attach( el, null, seen );

	assert.equal( listenerCount, 1, 'a second attach() call for an already-seen element must be a no-op' );
} );

/*
 * THE EXACT BUG (live-review finding): SportsPress's own jQuery script
 * creates .sp-scrollable-table-wrapper at ITS OWN DOM-ready time, which can
 * run after this file's initial querySelectorAll() snapshot. attachWithin()
 * is what the MutationObserver callback calls for every node a later DOM
 * mutation adds -- this proves it actually finds and wires up a container
 * nested inside the added node, not just the added node itself.
 */
test( 'attachWithin: finds and wires up a scroll container nested inside a newly-added node', () => {
	const inner = fakeElement( { scrollWidth: 800, clientWidth: 300 } );
	inner.matches = ( selector ) => selector.includes( 'sp-scrollable-table-wrapper' );

	const addedNode = {
		nodeType: 1,
		matches: () => false,
		querySelectorAll: () => [ inner ],
	};

	const seen = new WeakSet();

	attachWithin( addedNode, null, seen );

	assert.equal( seen.has( inner ), true );
} );

test( 'attachWithin: a text node (nodeType !== 1) is ignored rather than throwing', () => {
	const textNode = { nodeType: 3 };
	const seen = new WeakSet();

	assert.doesNotThrow( () => attachWithin( textNode, null, seen ) );
} );

test( 'attachWithin: the root node itself is wired up when it matches the container selector', () => {
	const root = fakeElement( { scrollWidth: 800, clientWidth: 300 } );
	root.matches = () => true;

	const seen = new WeakSet();

	attachWithin( root, null, seen );

	assert.equal( seen.has( root ), true );
} );

test( 'update: an overflowing wrapper becomes a labelled, focusable region', () => {
	const el = fakeElement( { scrollWidth: 800, clientWidth: 300 } );
	el.closest = () => ( {
		querySelector: () => ( { textContent: '  Upcoming\n Games ' } ),
	} );

	update( el );

	assert.equal( el.attributes.tabindex, '0' );
	assert.equal( el.attributes.role, 'region' );
	assert.equal( el.attributes[ 'aria-label' ], 'Upcoming Games' );
} );

test( 'update: a wrapper that stops overflowing loses the tab stop again', () => {
	const el = fakeElement( { scrollWidth: 800, clientWidth: 300 } );

	update( el );
	el.clientWidth = 800;
	update( el );

	assert.equal( 'tabindex' in el.attributes, false );
	assert.equal( 'role' in el.attributes, false );
	assert.equal( 'aria-label' in el.attributes, false );
	assert.equal( 'data-bl-scroll-focus' in el.attributes, false );
} );

test( 'update: a wrapper that fits never gets a tab stop', () => {
	const el = fakeElement( { scrollWidth: 300, clientWidth: 300 } );

	update( el );

	assert.equal( 'tabindex' in el.attributes, false );
} );

test( 'update: a self-scrolling table keeps its table role', () => {
	const el = fakeElement( { scrollWidth: 800, clientWidth: 300 } );
	el.tagName = 'TABLE';
	el.caption = { textContent: 'Refs' };

	update( el );

	assert.equal( el.attributes.tabindex, '0' );
	assert.equal( 'role' in el.attributes, false );
	assert.equal( el.attributes[ 'aria-label' ], 'Refs' );
} );

test( 'update: an author tabindex and aria-label are never touched', () => {
	const owned = fakeElement( { scrollWidth: 800, clientWidth: 300 } );
	owned.attributes.tabindex = '-1';
	update( owned );
	assert.equal( owned.attributes.tabindex, '-1' );
	assert.equal( 'role' in owned.attributes, false );

	const named = fakeElement( { scrollWidth: 800, clientWidth: 300 } );
	named.attributes[ 'aria-label' ] = 'Box score';
	update( named );
	named.clientWidth = 800;
	update( named );
	assert.equal( named.attributes[ 'aria-label' ], 'Box score' );
} );

test( 'scrollLabel: falls back to a generic name', () => {
	assert.equal( scrollLabel( fakeElement() ), 'Scrollable table' );
} );

test( 'scrollLabel: a repeated caption gets a numeric suffix', () => {
	const first = fakeElement();
	first.setAttribute( 'data-bl-scroll-focus', 'label' );
	first.setAttribute( 'aria-label', 'Upcoming Games' );
	const second = fakeElement();
	second.setAttribute( 'data-bl-scroll-focus', 'label' );
	second.setAttribute( 'aria-label', 'Upcoming Games (2)' );
	const third = fakeElement();
	third.querySelector = () => ( {
		caption: { textContent: 'Upcoming Games' },
	} );
	const labelled = [ first, second ];
	third.ownerDocument = { querySelectorAll: () => labelled };

	assert.equal( scrollLabel( third ), 'Upcoming Games (3)' );
	assert.equal( scrollLabel( first ), 'Scrollable table' );
} );
