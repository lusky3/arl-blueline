import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeValue } from './css-tokens.mjs';

test( 'normalizeValue expands 3-digit hex to 6', () => {
	assert.equal( normalizeValue( '#fff' ), '#ffffff' );
} );

test( 'normalizeValue lowercases 6-digit hex', () => {
	assert.equal( normalizeValue( '#FFFFFF' ), '#ffffff' );
} );

test( 'normalizeValue collapses internal whitespace', () => {
	assert.equal( normalizeValue( '3px   solid   red' ), '3px solid red' );
} );
