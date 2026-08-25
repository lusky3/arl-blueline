import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { parseNextGameState } = require( './floating-next-game.js' );

test( 'parseNextGameState returns null for a null raw value', () => {
	assert.equal( parseNextGameState( null ), null );
} );

test( 'parseNextGameState returns null for an empty string', () => {
	assert.equal( parseNextGameState( '' ), null );
} );

test( 'parseNextGameState returns null for a bare legacy event id with no colon', () => {
	// The stored format before this feature existed -- must not be
	// mistaken for a valid pair, since it carries no fingerprint half.
	assert.equal( parseNextGameState( '123' ), null );
} );

test( 'parseNextGameState returns null when the event id half is missing', () => {
	assert.equal( parseNextGameState( ':abc123' ), null );
} );

test( 'parseNextGameState returns null when the fingerprint half is missing', () => {
	assert.equal( parseNextGameState( '123:' ), null );
} );

test( 'parseNextGameState parses a well-formed pair', () => {
	assert.deepEqual( parseNextGameState( '123:abc123' ), {
		eventId: '123',
		fingerprint: 'abc123',
	} );
} );

test( 'parseNextGameState splits on the FIRST colon only', () => {
	// An md5 fingerprint never contains a colon, but this guards the
	// parsing itself against ever assuming otherwise.
	assert.deepEqual( parseNextGameState( '123:abc:def' ), {
		eventId: '123',
		fingerprint: 'abc:def',
	} );
} );

/**
 * Minimal fake widget element: just enough surface for the comparison logic
 * to run against -- no real DOM, per this project's own house rule (see
 * table-scroll.test.mjs's fakeElement() docblock for the identical
 * trade-off elsewhere in this file set). Exercises the same two-key
 * comparison the module performs on `[data-bl-next-game]` at import time,
 * reimplemented here in isolation since that top-level wiring itself
 * requires `document` and is exercised live per this codebase's established
 * precedent (see this file's own module docblock).
 *
 * Two SEPARATE stored values, not one -- see this file's own module
 * docblock for the real bug (found live 2026-08-25) this split fixes:
 * `dismissed` controls ONLY `hidden`; `seen` controls ONLY
 * `updatedRevealed`. A caller that only ever wrote one of them (e.g.
 * account-next-game.js writes `seen` but never `dismissed`) must never be
 * able to hide the widget.
 *
 * @param {string|null} dismissed The stored dismissed pair, or null.
 * @param {string|null} seen      The stored seen pair, or null.
 * @param {string}      current   The current `data-bl-next-game` value.
 * @return {{hidden: boolean, updatedRevealed: boolean}} The resulting UI state.
 */
function resolveWidgetState( dismissed, seen, current ) {
	const currentState = parseNextGameState( current );
	const dismissedState = parseNextGameState( dismissed );
	const seenState = parseNextGameState( seen );

	let hidden = false;
	let updatedRevealed = false;

	if (
		currentState &&
		dismissedState &&
		dismissedState.eventId === currentState.eventId &&
		dismissedState.fingerprint === currentState.fingerprint
	) {
		hidden = true;
	} else if (
		currentState &&
		seenState &&
		seenState.eventId === currentState.eventId &&
		seenState.fingerprint !== currentState.fingerprint
	) {
		updatedRevealed = true;
	}

	return { hidden, updatedRevealed };
}

test( 'dismissed pair equal to current pair: hidden, no "Updated" indicator', () => {
	const result = resolveWidgetState( '123:abc', '123:abc', '123:abc' );

	assert.deepEqual( result, { hidden: true, updatedRevealed: false } );
} );

test( 'dismissed same event id, different fingerprint: shown, "Updated" indicator revealed', () => {
	// Dismissing writes both keys together, so `seen` also differs here --
	// the game changed since the OLD version was dismissed.
	const result = resolveWidgetState( '123:abc', '123:abc', '123:xyz' );

	assert.deepEqual( result, { hidden: false, updatedRevealed: true } );
} );

test( 'different event id: shown normally, no "Updated" indicator', () => {
	const result = resolveWidgetState( '123:abc', '123:abc', '456:xyz' );

	assert.deepEqual( result, { hidden: false, updatedRevealed: false } );
} );

test( 'nothing stored yet: shown normally, no "Updated" indicator', () => {
	const result = resolveWidgetState( null, null, '456:xyz' );

	assert.deepEqual( result, { hidden: false, updatedRevealed: false } );
} );

test( 'REGRESSION (found live 2026-08-25): "seen" written with no "dismissed" must never hide the widget', () => {
	// This is exactly what visiting the My Account next-game card does --
	// account-next-game.js writes ONLY the seen key. Before the two-key
	// split, this silently hid the floating widget on every other page.
	const result = resolveWidgetState( null, '123:abc', '123:abc' );

	assert.deepEqual( result, { hidden: false, updatedRevealed: false } );
} );

test( '"seen" differs with no "dismissed": shown, "Updated" indicator revealed, still not hidden', () => {
	const result = resolveWidgetState( null, '123:abc', '123:xyz' );

	assert.deepEqual( result, { hidden: false, updatedRevealed: true } );
} );
