import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { focusLeftFlyout, hasOpenedBefore, rememberOpened, OPENED_KEY } =
	require( './team-flyout.js' );

const inside = {};
const outside = {};
const flyout = { contains: ( node ) => node === inside };

test( 'focus moving to a control outside the flyout closes it', () => {
	assert.equal( focusLeftFlyout( flyout, outside, false ), true );
	// Even with the pointer parked over it: keyboard focus left.
	assert.equal( focusLeftFlyout( flyout, outside, true ), true );
} );

test( 'focus moving between the flyout\'s own controls keeps it open', () => {
	assert.equal( focusLeftFlyout( flyout, inside, false ), false );
} );

test( 'focus going nowhere closes it only when the pointer is not over it', () => {
	// Window blur / a click on empty page.
	assert.equal( focusLeftFlyout( flyout, null, false ), true );
	// Pressing on the panel's scrollbar or padding must not dismiss it.
	assert.equal( focusLeftFlyout( flyout, null, true ), false );
} );

test( 'hasOpenedBefore reads the stored flag', () => {
	assert.equal( hasOpenedBefore( { getItem: () => '1' } ), true );
	assert.equal( hasOpenedBefore( { getItem: () => null } ), false );
	assert.equal( hasOpenedBefore( { getItem: () => '0' } ), false );
} );

test( 'hasOpenedBefore treats unavailable storage as never opened', () => {
	assert.equal(
		hasOpenedBefore( {
			getItem: () => {
				throw new Error( 'denied' );
			},
		} ),
		false
	);
} );

test( 'rememberOpened writes the flag and swallows storage failures', () => {
	const written = {};
	rememberOpened( { setItem: ( k, v ) => ( written[ k ] = v ) } );
	assert.deepEqual( written, { [ OPENED_KEY ]: '1' } );

	assert.doesNotThrow( () =>
		rememberOpened( {
			setItem: () => {
				throw new Error( 'quota' );
			},
		} )
	);
} );
