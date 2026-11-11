import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const {
	DISMISSED_KEY,
	SEEN_KEY,
	memberKey,
	forgetUnscopedState,
} = require( './next-game-keys.js' );

// FE-01: localStorage belongs to the browser, so every next-game key is
// scoped to the signed-in member (the server-rendered `data-bl-member` hash).

test( 'two members on one browser get different keys', () => {
	assert.notEqual(
		memberKey( DISMISSED_KEY, 'aaaa1111bbbb2222' ),
		memberKey( DISMISSED_KEY, 'cccc3333dddd4444' )
	);
} );

test( 'the scoped key keeps the base key as its prefix', () => {
	assert.equal(
		memberKey( SEEN_KEY, 'aaaa1111bbbb2222' ),
		'blueline:next-game-seen:aaaa1111bbbb2222'
	);
} );

test( 'no usable member scope means no key at all, never the shared one', () => {
	assert.equal( memberKey( DISMISSED_KEY, null ), null );
	assert.equal( memberKey( DISMISSED_KEY, '' ), null );
	assert.equal( memberKey( DISMISSED_KEY, 'not a hash' ), null );
} );

test( 'the old browser-wide keys are removed', () => {
	const removed = [];
	forgetUnscopedState( {
		removeItem( key ) {
			removed.push( key );
		},
	} );
	assert.deepEqual( removed.sort(), [ DISMISSED_KEY, SEEN_KEY ].sort() );
} );

test( 'a storage that throws is ignored', () => {
	assert.doesNotThrow( () =>
		forgetUnscopedState( {
			removeItem() {
				throw new Error( 'denied' );
			},
		} )
	);
} );
