import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// The three settings scripts are enqueued as classic <script> tags, so in
// wp-admin they all run in one shared global scope. Load all three, in
// order, into ONE vm context to prove they can share it: a top-level
// `const ROOT` in two of them is a SyntaxError for the second, and a top-level
// `function init()` silently overwrites the first.
const FILES = [
	'settings-photos.js',
	'settings-brand-colors.js',
	'settings-occasions.js',
];

// Names that must not be script-global (they are generic enough to collide with
// another wp-admin script).
const FORMER_GLOBALS = [
	'ROOT',
	'init',
	'renumber',
	'addRow',
	'initPhotoField',
	'hexForRow',
	'updateReadout',
	'updateAllRows',
	'updateRow',
	'currentHexForToken',
	'DEFAULT_OCCASIONS_DATA',
];

function load( files ) {
	// Just enough DOM for each script's top-level init() to find nothing to do.
	const context = vm.createContext( {
		document: {
			readyState: 'complete',
			querySelectorAll: () => [],
			addEventListener() {},
		},
		window: {},
	} );

	for ( const file of files ) {
		const source = readFileSync(
			new URL( `./${ file }`, import.meta.url ),
			'utf8'
		);
		vm.runInContext( source, context, { filename: file } );
	}

	return context;
}

test( 'the settings scripts load together in one global scope', () => {
	assert.doesNotThrow( () => load( FILES ) );
} );

test( 'the settings scripts load in any order', () => {
	assert.doesNotThrow( () => load( [ ...FILES ].reverse() ) );
} );

test( 'loading a script twice (e.g. a page that enqueues it again) does not throw', () => {
	assert.doesNotThrow( () => load( [ ...FILES, ...FILES ] ) );
} );

test( 'no generic helper name leaks into the shared global scope', () => {
	const context = load( FILES );

	for ( const name of FORMER_GLOBALS ) {
		assert.equal(
			vm.runInContext( `typeof ${ name }`, context ),
			'undefined',
			`${ name } must not be a global`
		);
	}
} );

test( 'the exports the other tests rely on still exist outside a browser', async () => {
	const { createRequire } = await import( 'node:module' );
	const require = createRequire( import.meta.url );

	assert.equal(
		typeof require( './settings-brand-colors.js' ).blBrandColorContrastRatio,
		'function'
	);
	assert.equal(
		typeof require( './settings-occasions.js' ).blOccasionContrastRatio,
		'function'
	);
} );
