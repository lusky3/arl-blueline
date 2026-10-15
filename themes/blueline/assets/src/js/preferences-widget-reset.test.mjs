import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { WIDGET_DISMISSED_KEY } = require( './preferences-widget-reset.js' );
const { DISMISSED_KEY } = require( './floating-next-game.js' );

test( 'WIDGET_DISMISSED_KEY matches the key floating-next-game.js actually uses', () => {
	// This is the one thing that must never silently drift: if
	// floating-next-game.js's own dismissed-state key ever changes, this
	// button's whole purpose breaks with no visible error anywhere. Compared
	// against floating-next-game.js's own exported DISMISSED_KEY, not a
	// second hardcoded literal, so an actual rename there fails this test.
	assert.equal( WIDGET_DISMISSED_KEY, DISMISSED_KEY );
} );

test( 'the confirmation is written into an always-present live region, cleared first', () => {
	const { announce } = require( './preferences-widget-reset.js' );
	const writes = [];
	const region = {
		set textContent( value ) {
			writes.push( value );
		},
	};
	const later = [];

	announce( region, 'It will show again.', ( fn ) => later.push( fn ) );

	// Cleared now and filled on the next tick: a region whose text is set to
	// the same words again is not re-announced, so the second click of a
	// visitor who missed the first still hears it.
	assert.deepEqual( writes, [ '' ] );
	later.forEach( ( fn ) => fn() );
	assert.deepEqual( writes, [ '', 'It will show again.' ] );
} );
