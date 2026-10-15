import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const {
	isClipped,
	resolveTap,
	resolveScroll,
	clampLeft,
} = require( './team-name-tooltip.js' );

/**
 * A name box: `w` is [scrollWidth, clientWidth], `h` [scrollHeight, clientHeight].
 *
 * @param {number[]} w Widths.
 * @param {number[]} h Heights; defaults to a box that fits vertically.
 * @return {Object} Box metrics.
 */
const box = ( w, h = [ 20, 20 ] ) => ( {
	scrollWidth: w[ 0 ],
	clientWidth: w[ 1 ],
	scrollHeight: h[ 0 ],
	clientHeight: h[ 1 ],
} );

test( 'isClipped is true when the text is wider than its box', () => {
	assert.equal( isClipped( box( [ 109, 80 ] ) ), true );
} );

test( 'isClipped is false when the text fits exactly', () => {
	assert.equal( isClipped( box( [ 80, 80 ] ) ), false );
} );

test( 'isClipped ignores a one-pixel sub-pixel remainder', () => {
	assert.equal( isClipped( box( [ 81, 80 ] ) ), false );
	assert.equal( isClipped( box( [ 82, 80 ] ) ), true );
} );

test( 'isClipped is true when a wrapped name runs past its line clamp', () => {
	// Two 20px lines shown, a third hidden.
	assert.equal( isClipped( box( [ 80, 80 ], [ 60, 40 ] ) ), true );
} );

test( 'isClipped is false for a name that wraps within its line clamp', () => {
	assert.equal( isClipped( box( [ 80, 80 ], [ 40, 40 ] ) ), false );
	assert.equal( isClipped( box( [ 80, 80 ], [ 41, 40 ] ) ), false );
} );

test( 'a scroll with no bubble showing does nothing', () => {
	assert.equal( resolveScroll( { showing: false, focused: false } ), 'none' );
	assert.equal( resolveScroll( { showing: false, focused: true } ), 'none' );
} );

test( 'a scroll while the bubble belongs to the focused link moves it with the link', () => {
	// Focusing a link in the scrolling table scrolls it into view, which must not
	// hide the bubble the focus had just shown.
	assert.equal(
		resolveScroll( { showing: true, focused: true } ),
		'reposition'
	);
} );

test( 'a scroll away from a tapped (unfocused) name hides its bubble', () => {
	assert.equal( resolveScroll( { showing: true, focused: false } ), 'hide' );
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
