import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { update, attach, attachWithin } = require( './table-scroll.js' );

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
		removeAttribute( name ) {
			delete attributes[ name ];
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
