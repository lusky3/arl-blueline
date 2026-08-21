import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { blOccasionContrastRatio, blOccasionIsHex } = require( './settings-occasions.js' );

test( 'blOccasionContrastRatio: black on white is the maximum ratio', () => {
	assert.ok( blOccasionContrastRatio( '#000000', '#ffffff' ) > 20 );
} );

test( 'blOccasionContrastRatio: a colour against itself is exactly 1', () => {
	assert.equal( blOccasionContrastRatio( '#132343', '#132343' ), 1 );
} );

test( 'blOccasionContrastRatio: order of arguments does not matter', () => {
	const a = blOccasionContrastRatio( '#132343', '#ffffff' );
	const b = blOccasionContrastRatio( '#ffffff', '#132343' );
	assert.equal( a, b );
} );

test( 'blOccasionIsHex: accepts a well-formed 6-digit hex colour', () => {
	assert.equal( blOccasionIsHex( '#132343' ), true );
	assert.equal( blOccasionIsHex( '#ABCDEF' ), true );
} );

test( 'blOccasionIsHex: rejects anything else', () => {
	assert.equal( blOccasionIsHex( '132343' ), false );
	assert.equal( blOccasionIsHex( '#12334' ), false );
	assert.equal( blOccasionIsHex( 'not-a-colour' ), false );
	assert.equal( blOccasionIsHex( '' ), false );
} );

/*
 * PHP<->JS parity fixture (design spec §5.1's fourth ruling): these
 * exact hex pairs and their expected ratios were computed once,
 * directly, with this exact formula, and confirmed identical (to 6
 * decimal places) against inc/team-colors.php's
 * blueline_contrast_ratio() by running both side by side -- see
 * tests/OccasionsContrastParityTest.php, which pins the SAME table on
 * the PHP side. Neither file imports the other; if
 * blOccasionContrastRatio() or blueline_contrast_ratio() ever drifts
 * from this formula, whichever one moved fails its OWN half of this
 * pinned table, which is the whole point: a silent, one-sided drift is
 * exactly what the ruling is guarding against.
 */
const PARITY_FIXTURE = [
	[ '#132343', '#ffffff', 15.565337 ],
	[ '#132343', '#000000', 1.349152 ],
	[ '#132343', '#132343', 1.0 ],
	[ '#132343', '#274a63', 1.664712 ],
	[ '#132343', '#f7fbfc', 14.942248 ],
	[ '#132343', '#c8102e', 2.645692 ],
	[ '#132343', '#ffd700', 11.097474 ],
];

test( 'blOccasionContrastRatio matches the PHP<->JS parity fixture', () => {
	for ( const [ a, b, expected ] of PARITY_FIXTURE ) {
		const actual = blOccasionContrastRatio( a, b );
		assert.ok(
			Math.abs( actual - expected ) < 0.0005,
			`${ a } vs ${ b }: expected ${ expected }, got ${ actual }`
		);
	}
} );
