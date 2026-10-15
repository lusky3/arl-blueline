import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { blPhotoNeighbour, blPhotoBindFrame } = require( './settings-photos.js' );

test( 'blPhotoNeighbour: after a removal focus goes to the next photo, else the previous, else none', () => {
	const items = [ 'first', 'middle', 'last' ];

	assert.equal( blPhotoNeighbour( items, 'first' ), 'middle' );
	assert.equal( blPhotoNeighbour( items, 'middle' ), 'last' );
	assert.equal( blPhotoNeighbour( items, 'last' ), 'middle' );
	assert.equal( blPhotoNeighbour( [ 'only' ], 'only' ), null );
} );

/**
 * Fake wp.media frame: records handlers, fires them on demand.
 *
 * @param {Object[]} chosen Attachment attributes the selection holds.
 * @return {Object} Frame.
 */
function fakeFrame( chosen ) {
	const handlers = {};

	return {
		on: ( event, handler ) => {
			( handlers[ event ] = handlers[ event ] || [] ).push( handler );
		},
		fire: ( event ) => ( handlers[ event ] || [] ).forEach( ( h ) => h() ),
		state: () => ( {
			get: () => chosen.map( ( attrs ) => ( { toJSON: () => attrs } ) ),
		} ),
	};
}

/**
 * A fake added row whose alignment select records focus.
 *
 * @param {number[]} log Where focus calls are recorded.
 * @param {number}   id  Attachment id.
 * @return {Object} Row.
 */
function fakeRow( log, id ) {
	const select = { focus: () => log.push( id ) };

	return {
		querySelector: ( selector ) =>
			'[data-bl-photos-align]' === selector ? select : null,
	};
}

test( 'blPhotoBindFrame: after adding photographs focus moves to the first new row, though WordPress closes the frame before it fires select', () => {
	const focused = [];
	const deferred = [];
	const frame = fakeFrame( [ { id: 4 }, { id: 5 } ] );

	blPhotoBindFrame(
		frame,
		( attachment ) => fakeRow( focused, attachment.id ),
		( fn ) => deferred.push( fn )
	);

	// wp.media.view.Toolbar.Select: controller.close(), then trigger( 'select' ).
	frame.fire( 'close' );
	frame.fire( 'select' );

	// Deferred past the focus the closing modal hands back to its opener.
	assert.deepEqual( focused, [] );
	deferred.forEach( ( fn ) => fn() );
	assert.deepEqual( focused, [ 4 ] );
} );

test( 'blPhotoBindFrame: only duplicates chosen (no new row) leaves focus alone', () => {
	const deferred = [];
	const frame = fakeFrame( [ { id: 4 } ] );

	blPhotoBindFrame( frame, () => undefined, ( fn ) => deferred.push( fn ) );
	frame.fire( 'select' );

	assert.equal( deferred.length, 0 );
} );
