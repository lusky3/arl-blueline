import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const {
	MAX_BYTES,
	fitWithin,
	prepareFile,
	handleSelection,
	formatMegabytes,
} = require( './player-photo-upload.js' );

const MB = 1024 * 1024;

/**
 * A fake File.
 *
 * @param {number} size Bytes.
 * @param {string} type MIME type.
 * @param {string} name File name.
 * @return {Object} A fake file.
 */
function file( size, type = 'image/jpeg', name = 'IMG_0001.JPG' ) {
	return { size, type, name };
}

/**
 * Deps whose encoder returns blobs of the sizes listed, one per attempt.
 *
 * @param {number[]} sizes   Blob sizes, in attempt order.
 * @param {Object}   decoded What decode() resolves to.
 * @return {Object} Deps plus a log of encode calls.
 */
function deps( sizes, decoded = { width: 4000, height: 3000 } ) {
	const calls = [];
	return {
		calls,
		decode: async () => decoded,
		encode: async ( image, width, height, quality ) => {
			calls.push( { width, height, quality } );
			const size = sizes[ calls.length - 1 ];
			return undefined === size ? null : { size };
		},
		makeFile: ( blob, name ) => ( { size: blob.size, type: 'image/jpeg', name } ),
	};
}

test( 'MAX_BYTES is the 2 MB cap the server enforces', () => {
	assert.equal( MAX_BYTES, 2 * MB );
} );

test( 'fitWithin scales the long edge down and keeps the aspect ratio', () => {
	assert.deepEqual( fitWithin( 4000, 3000, 1600 ), { width: 1600, height: 1200 } );
	assert.deepEqual( fitWithin( 3000, 4000, 1600 ), { width: 1200, height: 1600 } );
} );

test( 'fitWithin never scales a small image up', () => {
	assert.deepEqual( fitWithin( 800, 600, 1600 ), { width: 800, height: 600 } );
} );

test( 'formatMegabytes rounds to one decimal', () => {
	assert.equal( formatMegabytes( 2.91 * MB ), '2.9 MB' );
	assert.equal( formatMegabytes( 19 * MB ), '19.0 MB' );
} );

test( 'prepareFile re-encodes a big photo as a JPEG under the cap, at about 1600px first', async () => {
	const d = deps( [ 3 * MB, MB ] );
	const result = await prepareFile( file( 6 * MB ), d );

	assert.equal( result.error, undefined );
	assert.equal( result.file.name, 'IMG_0001.jpg' );
	assert.equal( result.file.size, MB );
	assert.deepEqual( [ d.calls[ 0 ].width, d.calls[ 0 ].height ], [ 1600, 1200 ] );
	assert.ok( d.calls[ 1 ].quality < d.calls[ 0 ].quality, 'the second try lowers quality' );
} );

test( 'prepareFile shrinks the dimensions further when quality alone is not enough', async () => {
	const d = deps( [ 4 * MB, 3 * MB, MB ] );
	const result = await prepareFile( file( 12 * MB ), d );

	assert.equal( result.file.size, MB );
	assert.ok( d.calls[ 2 ].width < d.calls[ 0 ].width );
} );

test( 'prepareFile gives up with cannot-shrink when nothing fits', async () => {
	const d = deps( new Array( 12 ).fill( 5 * MB ) );

	assert.deepEqual( await prepareFile( file( 9 * MB ), d ), { error: 'cannot-shrink' } );
} );

test( 'prepareFile reports unreadable when the browser cannot decode the file', async () => {
	const d = deps( [] );
	d.decode = async () => {
		throw new Error( 'nope' );
	};

	assert.deepEqual( await prepareFile( file( 9 * MB, 'image/heic' ), d ), { error: 'unreadable' } );
} );

test( 'prepareFile will not flatten an animated GIF', async () => {
	assert.deepEqual( await prepareFile( file( 9 * MB, 'image/gif', 'a.gif' ), deps( [ MB ] ) ), {
		error: 'too-large',
	} );
} );

/**
 * A recording UI.
 *
 * @return {Object} UI stub plus its event log.
 */
function ui() {
	const log = [];
	return {
		log,
		setStatus: ( text, isError ) => log.push( [ 'status', text, Boolean( isError ) ] ),
		setBusy: ( busy ) => log.push( [ 'busy', busy ] ),
		replaceFile: ( f ) => log.push( [ 'replace', f.name ] ),
		submit: () => log.push( [ 'submit' ] ),
	};
}

test( 'handleSelection: a photo within the cap says it is uploading, then submits', async () => {
	const u = ui();

	assert.equal( await handleSelection( file( MB ), u, deps( [] ) ), 'submitted' );
	assert.deepEqual( u.log, [
		[ 'busy', true ],
		[ 'status', 'Uploading photo…', false ],
		[ 'submit' ],
	] );
} );

test( 'handleSelection: an over-cap photo is resized, replaced, then uploaded', async () => {
	const u = ui();

	assert.equal( await handleSelection( file( 6 * MB ), u, deps( [ MB ] ) ), 'submitted' );
	assert.deepEqual( u.log.map( ( entry ) => entry[ 0 ] ), [ 'busy', 'status', 'replace', 'status', 'submit' ] );
	assert.equal( u.log[ 1 ][ 1 ], 'Resizing photo…' );
	assert.equal( u.log[ 3 ][ 1 ], 'Uploading photo…' );
} );

test( 'handleSelection: a photo that cannot be shrunk shows the too-large message inline and never submits', async () => {
	const u = ui();

	assert.equal( await handleSelection( file( 9 * MB, 'image/gif', 'a.gif' ), u, deps( [] ) ), 'rejected' );

	const error = u.log.find( ( entry ) => 'status' === entry[ 0 ] && true === entry[ 2 ] );
	assert.ok( error, 'an error status is shown' );
	assert.match( error[ 1 ], /too large/i );
	assert.match( error[ 1 ], /9\.0 MB/ );
	assert.match( error[ 1 ], /2 MB/ );
	assert.equal( u.log.some( ( entry ) => 'submit' === entry[ 0 ] ), false );
	assert.deepEqual( u.log[ u.log.length - 1 ], [ 'busy', false ] );
} );

test( 'handleSelection: an unreadable file shows a readable message and never submits', async () => {
	const u = ui();
	const d = deps( [] );
	d.decode = async () => {
		throw new Error( 'nope' );
	};

	assert.equal( await handleSelection( file( 9 * MB, 'image/heic' ), u, d ), 'rejected' );
	assert.equal( u.log.some( ( entry ) => 'submit' === entry[ 0 ] ), false );
	assert.ok( u.log.some( ( entry ) => 'status' === entry[ 0 ] && true === entry[ 2 ] ) );
} );
