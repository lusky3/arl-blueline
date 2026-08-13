import { test } from 'node:test';
import assert from 'node:assert/strict';
import { contrastRatio, evaluateRule } from './contrast.mjs';

const round = ( n ) => Number( n.toFixed( 2 ) );

test( 'contrastRatio matches known WCAG values', () => {
	assert.equal( round( contrastRatio( '#000000', '#ffffff' ) ), 21 );
	assert.equal( round( contrastRatio( '#ffffff', '#ffffff' ) ), 1 );
	assert.equal( round( contrastRatio( '#132343', '#f7fbfc' ) ), 14.94 );
	assert.equal( round( contrastRatio( '#3f6e9d', '#f7fbfc' ) ), 5.13 );
} );

test( 'contrastRatio is symmetric', () => {
	assert.equal(
		contrastRatio( '#132343', '#f7fbfc' ),
		contrastRatio( '#f7fbfc', '#132343' )
	);
} );

test( 'evaluateRule passes a satisfied min rule', () => {
	const tokens = new Map( [
		[ '--bl-ink', '#132343' ],
		[ '--bl-paper', '#F7FBFC' ],
	] );
	const result = evaluateRule(
		{ id: 'x', description: 'ink on paper', fg: '--bl-ink', bg: '--bl-paper', min: 4.5 },
		tokens
	);
	assert.equal( result.ok, true );
	assert.equal( round( result.ratio ), 14.94 );
} );

test( 'evaluateRule fails an unsatisfied min rule', () => {
	const tokens = new Map( [
		[ '--bl-ice', '#74C0E1' ],
		[ '--bl-paper', '#F7FBFC' ],
	] );
	const result = evaluateRule(
		{ id: 'y', description: 'ice on paper', fg: '--bl-ice', bg: '--bl-paper', min: 4.5 },
		tokens
	);
	assert.equal( result.ok, false );
} );

test( 'evaluateRule supports a max bound for fill-only tokens', () => {
	const tokens = new Map( [
		[ '--bl-ice', '#74C0E1' ],
		[ '--bl-paper', '#F7FBFC' ],
	] );
	const result = evaluateRule(
		{ id: 'z', description: 'ice unusable as text', fg: '--bl-ice', bg: '--bl-paper', max: 3.0 },
		tokens
	);
	assert.equal( result.ok, true );
} );
