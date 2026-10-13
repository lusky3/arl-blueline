import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { pickFocusTarget, moveFocusOut } = require( './dismiss-focus.js' );

function fakeElement( { connected = true, children = [] } = {} ) {
	return {
		isConnected: connected,
		focused: null,
		focus( options ) {
			this.focused = options || true;
		},
		contains( other ) {
			return other === this || children.includes( other );
		},
	};
}

test( 'pickFocusTarget skips null, disconnected and non-focusable candidates', () => {
	const gone = fakeElement( { connected: false } );
	const inert = { isConnected: true };
	const good = fakeElement();

	assert.equal(
		pickFocusTarget( [ null, undefined, gone, inert, good ] ),
		good
	);
} );

test( 'pickFocusTarget returns null when nothing is usable', () => {
	assert.equal(
		pickFocusTarget( [ null, fakeElement( { connected: false } ) ] ),
		null
	);
	assert.equal( pickFocusTarget( [] ), null );
} );

test( 'moveFocusOut moves focus to the first usable candidate without scrolling', () => {
	const button = fakeElement();
	const container = fakeElement( { children: [ button ] } );
	const previous = fakeElement();
	const main = fakeElement();

	assert.equal( moveFocusOut( container, [ previous, main ], button ), true );
	assert.deepEqual( previous.focused, { preventScroll: true } );
	assert.equal( main.focused, null );
} );

test( 'moveFocusOut falls back to the next candidate when the first is gone', () => {
	const button = fakeElement();
	const container = fakeElement( { children: [ button ] } );
	const previous = fakeElement( { connected: false } );
	const main = fakeElement();

	assert.equal( moveFocusOut( container, [ previous, main ], button ), true );
	assert.deepEqual( main.focused, { preventScroll: true } );
} );

test( 'moveFocusOut leaves focus alone when it is not inside the container', () => {
	const container = fakeElement();
	const elsewhere = fakeElement();
	const main = fakeElement();

	assert.equal( moveFocusOut( container, [ main ], elsewhere ), false );
	assert.equal( moveFocusOut( container, [ main ], null ), false );
	assert.equal( main.focused, null );
} );

test( 'moveFocusOut reports false when there is nowhere to go', () => {
	const button = fakeElement();
	const container = fakeElement( { children: [ button ] } );

	assert.equal( moveFocusOut( container, [ null ], button ), false );
} );
