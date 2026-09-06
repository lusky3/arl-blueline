import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { blBrandColorLuminance, blBrandColorContrastRatio, blBrandColorIsHex } =
	require( './settings-brand-colors.js' );

test( 'blBrandColorContrastRatio: matches style.css\'s documented ink-on-paper ratio', () => {
	const ratio = blBrandColorContrastRatio( '#132343', '#F7FBFC' );
	assert.ok( Math.abs( ratio - 14.94 ) < 0.1, `expected ~14.94, got ${ ratio }` );
} );

test( 'blBrandColorContrastRatio: matches style.css\'s documented ice-on-paper ratio (below AA)', () => {
	const ratio = blBrandColorContrastRatio( '#74C0E1', '#F7FBFC' );
	assert.ok( Math.abs( ratio - 1.94 ) < 0.1, `expected ~1.94, got ${ ratio }` );
} );

test( 'blBrandColorContrastRatio: matches style.css\'s documented accent-text-on-paper ratio', () => {
	const ratio = blBrandColorContrastRatio( '#3F6E9D', '#F7FBFC' );
	assert.ok( Math.abs( ratio - 5.13 ) < 0.1, `expected ~5.13, got ${ ratio }` );
} );

test( 'blBrandColorContrastRatio: is symmetric', () => {
	const a = blBrandColorContrastRatio( '#132343', '#F7FBFC' );
	const b = blBrandColorContrastRatio( '#F7FBFC', '#132343' );
	assert.ok( Math.abs( a - b ) < 0.00001, `expected ${ a } to equal ${ b }` );
} );

test( 'blBrandColorIsHex: accepts a well-formed 6-digit hex with a leading #', () => {
	assert.equal( blBrandColorIsHex( '#3F6E9D' ), true );
} );

test( 'blBrandColorIsHex: rejects anything else', () => {
	assert.equal( blBrandColorIsHex( '3F6E9D' ), false );
	assert.equal( blBrandColorIsHex( '#3F6' ), false );
	assert.equal( blBrandColorIsHex( 'not-a-color' ), false );
	assert.equal( blBrandColorIsHex( '' ), false );
} );

test( 'blBrandColorLuminance: black is 0 and white is 1', () => {
	assert.equal( blBrandColorLuminance( '#000000' ), 0 );
	assert.equal( blBrandColorLuminance( '#FFFFFF' ), 1 );
} );
