import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { findStoredInput } = require( './standings-tabs.js' );

test( 'findStoredInput returns null for a null stored value', () => {
	assert.equal(
		findStoredInput( null, [ { value: '1' }, { value: '2' } ] ),
		null
	);
} );

test( 'findStoredInput returns null for an empty stored value', () => {
	assert.equal( findStoredInput( '', [ { value: '1' } ] ), null );
} );

test( 'findStoredInput returns null when no input matches -- a different season\'s table ids', () => {
	assert.equal(
		findStoredInput( '999', [ { value: '1' }, { value: '2' } ] ),
		null
	);
} );

test( 'findStoredInput returns the matching input', () => {
	const second = { value: '2' };

	assert.equal(
		findStoredInput( '2', [ { value: '1' }, second, { value: '3' } ] ),
		second
	);
} );

test( 'findStoredInput matches by exact string equality, not loose equality', () => {
	// localStorage only ever stores strings; a stray numeric value must not
	// match via type coercion ('2' == 2 is true in JS, but this should not be).
	assert.equal( findStoredInput( '2', [ { value: 2 } ] ), null );
} );
