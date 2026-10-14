import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { buttonLabel, readHidden, writeHidden, STORAGE_KEY } = require(
	'./occasion-effects-toggle.js'
);

function memoryStorage() {
	const data = new Map();
	return {
		data,
		getItem: ( key ) => ( data.has( key ) ? data.get( key ) : null ),
		setItem: ( key, value ) => data.set( key, value ),
		removeItem: ( key ) => data.delete( key ),
	};
}

const broken = {
	getItem() {
		throw new Error( 'denied' );
	},
	setItem() {
		throw new Error( 'denied' );
	},
	removeItem() {
		throw new Error( 'denied' );
	},
};

test( 'the label names the action the next click performs', () => {
	assert.equal( buttonLabel( false ), 'Hide seasonal effects' );
	assert.equal( buttonLabel( true ), 'Show seasonal effects' );
} );

test( 'the choice round-trips through storage', () => {
	const storage = memoryStorage();
	assert.equal( readHidden( storage ), false );

	writeHidden( storage, true );
	assert.equal( storage.getItem( STORAGE_KEY ), '1' );
	assert.equal( readHidden( storage ), true );

	writeHidden( storage, false );
	assert.equal( readHidden( storage ), false );
	assert.equal( storage.data.has( STORAGE_KEY ), false );
} );

test( 'unavailable storage means "not hidden" and never throws', () => {
	assert.equal( readHidden( broken ), false );
	assert.doesNotThrow( () => writeHidden( broken, true ) );
	assert.doesNotThrow( () => writeHidden( broken, false ) );
} );
