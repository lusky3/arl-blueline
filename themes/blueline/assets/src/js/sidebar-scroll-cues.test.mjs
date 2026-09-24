import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { update } = require( './sidebar-scroll-cues.js' );

/**
 * Minimal fake scroll-container element: just enough surface for update()
 * to operate on -- no real DOM, matching table-scroll.test.mjs's own
 * fakeElement() for the identical reason (this project's house rule
 * against a from-memory DOM/WP_Query stub). scrollHeight/clientHeight/
 * scrollTop are the only geometry update() reads; attributes are tracked
 * in a plain object so assertions can read them back directly.
 *
 * @param {object} geometry Partial overrides for scrollHeight/clientHeight/scrollTop.
 * @return {object} A fake element.
 */
function fakeElement( geometry = {} ) {
	const attributes = {};

	return {
		scrollHeight: 0,
		clientHeight: 0,
		scrollTop: 0,
		...geometry,
		attributes,
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
	};
}

test( 'update: a box with no overflow carries neither scroll-cue attribute', () => {
	const el = fakeElement( { scrollHeight: 300, clientHeight: 300 } );

	update( el );

	assert.equal( 'data-can-scroll-up' in el.attributes, false );
	assert.equal( 'data-can-scroll-down' in el.attributes, false );
} );

test( 'update: scrolled fully to the top cues only downward', () => {
	const el = fakeElement( { scrollHeight: 800, clientHeight: 300, scrollTop: 0 } );

	update( el );

	assert.equal( 'data-can-scroll-up' in el.attributes, false );
	assert.equal( 'data-can-scroll-down' in el.attributes, true );
} );

test( 'update: scrolled fully to the bottom cues only upward', () => {
	const el = fakeElement( { scrollHeight: 800, clientHeight: 300, scrollTop: 500 } );

	update( el );

	assert.equal( 'data-can-scroll-up' in el.attributes, true );
	assert.equal( 'data-can-scroll-down' in el.attributes, false );
} );

test( 'update: scrolled to the middle cues both directions', () => {
	const el = fakeElement( { scrollHeight: 800, clientHeight: 300, scrollTop: 250 } );

	update( el );

	assert.equal( 'data-can-scroll-up' in el.attributes, true );
	assert.equal( 'data-can-scroll-down' in el.attributes, true );
} );

test( 'update: a fractional remainder within SLOP is treated as no overflow', () => {
	const el = fakeElement( { scrollHeight: 301, clientHeight: 300 } );

	update( el );

	assert.equal( 'data-can-scroll-up' in el.attributes, false );
	assert.equal( 'data-can-scroll-down' in el.attributes, false );
} );

test( 'update: a previously-set attribute is cleared once scrolled back within SLOP of that end', () => {
	const el = fakeElement( { scrollHeight: 800, clientHeight: 300, scrollTop: 500 } );

	update( el );
	assert.equal( 'data-can-scroll-down' in el.attributes, false );

	el.scrollTop = 0;
	update( el );
	assert.equal( 'data-can-scroll-up' in el.attributes, false );
	assert.equal( 'data-can-scroll-down' in el.attributes, true );
} );
