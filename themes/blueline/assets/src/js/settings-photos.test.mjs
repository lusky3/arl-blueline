import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { blPhotoNeighbour } = require( './settings-photos.js' );

test( 'blPhotoNeighbour: after a removal focus goes to the next photo, else the previous, else none', () => {
	const items = [ 'first', 'middle', 'last' ];

	assert.equal( blPhotoNeighbour( items, 'first' ), 'middle' );
	assert.equal( blPhotoNeighbour( items, 'middle' ), 'last' );
	assert.equal( blPhotoNeighbour( items, 'last' ), 'middle' );
	assert.equal( blPhotoNeighbour( [ 'only' ], 'only' ), null );
} );
