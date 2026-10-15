import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { loaderIsEmpty } = require( './sponsors.js' );

test( 'a loader with content is never empty', () => {
	assert.equal( loaderIsEmpty( true, 1, 2 ), false );
	assert.equal( loaderIsEmpty( true, 0, 2 ), false );
} );

test( 'an empty loader is not called empty while another sponsor request is still out', () => {
	// The header and footer slots each send an identical request, and the
	// footer's can answer first: the header's loader is still waiting, not
	// empty, and collapsing it then shifted the whole page by 64px.
	assert.equal( loaderIsEmpty( false, 1, 2 ), false );
} );

test( 'an empty loader is empty once every sponsor request has answered', () => {
	assert.equal( loaderIsEmpty( false, 2, 2 ), true );
	assert.equal( loaderIsEmpty( false, 3, 2 ), true );
	assert.equal( loaderIsEmpty( false, 1, 1 ), true );
} );
