import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const {
	isClipped,
	resolveTap,
	clampLeft,
} = require( './team-name-tooltip.js' );

test( 'isClipped is true when the text is wider than its box', () => {
	assert.equal( isClipped( { scrollWidth: 109, clientWidth: 80 } ), true );
} );

test( 'isClipped is false when the text fits exactly', () => {
	assert.equal( isClipped( { scrollWidth: 80, clientWidth: 80 } ), false );
} );

test( 'isClipped ignores a one-pixel sub-pixel remainder', () => {
	assert.equal( isClipped( { scrollWidth: 81, clientWidth: 80 } ), false );
	assert.equal( isClipped( { scrollWidth: 82, clientWidth: 80 } ), true );
} );

test( 'a first touch tap on a clamped name reveals it instead of navigating', () => {
	assert.equal(
		resolveTap( { pointerType: 'touch', armed: false } ),
		'reveal'
	);
} );

test( 'a pen tap is treated like a touch tap', () => {
	assert.equal(
		resolveTap( { pointerType: 'pen', armed: false } ),
		'reveal'
	);
} );

test( 'a second touch tap on the same name follows the link', () => {
	assert.equal(
		resolveTap( { pointerType: 'touch', armed: true } ),
		'navigate'
	);
} );

test( 'a mouse click always navigates, since hover already shows the title', () => {
	assert.equal(
		resolveTap( { pointerType: 'mouse', armed: false } ),
		'navigate'
	);
	assert.equal(
		resolveTap( { pointerType: 'mouse', armed: true } ),
		'navigate'
	);
} );

test( 'a keyboard-synthesised click (no pointer type) always navigates', () => {
	assert.equal( resolveTap( { pointerType: '', armed: false } ), 'navigate' );
} );

test( 'clampLeft keeps a bubble that already fits where it wanted to be', () => {
	assert.equal( clampLeft( 40, 120, 400 ), 40 );
} );

test( 'clampLeft pulls a bubble back from the right edge', () => {
	// 380 + 120 would overflow a 400px viewport; the largest allowed left edge
	// leaves the 8px margin: 400 - 120 - 8.
	assert.equal( clampLeft( 380, 120, 400 ), 272 );
} );

test( 'clampLeft never goes left of the margin', () => {
	assert.equal( clampLeft( -30, 120, 400 ), 8 );
} );

test( 'clampLeft falls back to the margin when the bubble is wider than the viewport', () => {
	assert.equal( clampLeft( 50, 500, 400 ), 8 );
} );
