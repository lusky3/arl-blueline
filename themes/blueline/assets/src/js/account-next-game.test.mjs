import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { parseNextGameState } = require( './account-next-game.js' );

test( 'parseNextGameState returns null for a null raw value', () => {
	assert.equal( parseNextGameState( null ), null );
} );

test( 'parseNextGameState returns null for an empty string', () => {
	assert.equal( parseNextGameState( '' ), null );
} );

test( 'parseNextGameState returns null for a value with no colon', () => {
	assert.equal( parseNextGameState( '123' ), null );
} );

test( 'parseNextGameState parses a well-formed pair', () => {
	assert.deepEqual( parseNextGameState( '123:abc123' ), {
		eventId: '123',
		fingerprint: 'abc123',
	} );
} );

/**
 * Minimal fake card element: just enough surface (an attribute map and one
 * child queried by selector) to exercise the "reveal the Updated note"
 * decision in isolation -- no real DOM, per this project's own house rule
 * (see table-scroll.test.mjs's fakeElement() docblock for the identical
 * trade-off elsewhere in this file set). Reimplements the module's own
 * top-level card-lookup decision here since that wiring itself requires
 * `document` and is exercised live per this codebase's established
 * precedent (see this file's own module docblock).
 *
 * @param {string|null} stored  The stored pair from a prior visit.
 * @param {string}      current The card's current `event_id:fingerprint` value.
 * @return {{updatedRevealed: boolean, written: string|null}} The resulting state.
 */
function resolveAccountCardState( stored, current ) {
	const currentState = parseNextGameState( current );
	const storedState = parseNextGameState( stored );

	let updatedRevealed = false;
	let written = null;

	if ( currentState ) {
		if (
			storedState &&
			storedState.eventId === currentState.eventId &&
			storedState.fingerprint !== currentState.fingerprint
		) {
			updatedRevealed = true;
		}

		written = current;
	}

	return { updatedRevealed, written };
}

test( 'same event id, different fingerprint: "Updated" note revealed, current pair written', () => {
	const result = resolveAccountCardState( '123:abc', '123:xyz' );

	assert.deepEqual( result, { updatedRevealed: true, written: '123:xyz' } );
} );

test( 'same event id, same fingerprint: no "Updated" note, current pair still written', () => {
	const result = resolveAccountCardState( '123:abc', '123:abc' );

	assert.deepEqual( result, { updatedRevealed: false, written: '123:abc' } );
} );

test( 'different event id: no "Updated" note, current pair still written', () => {
	const result = resolveAccountCardState( '456:abc', '123:xyz' );

	assert.deepEqual( result, { updatedRevealed: false, written: '123:xyz' } );
} );

test( 'nothing stored yet: no "Updated" note, current pair still written (establishes the baseline)', () => {
	const result = resolveAccountCardState( null, '123:xyz' );

	assert.deepEqual( result, { updatedRevealed: false, written: '123:xyz' } );
} );
