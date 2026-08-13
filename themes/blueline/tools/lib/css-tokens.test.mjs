import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeValue } from './css-tokens.mjs';

test( 'normalizeValue expands 3-digit hex to 6', () => {
	assert.equal( normalizeValue( '#fff' ), '#ffffff' );
} );

test( 'normalizeValue lowercases 6-digit hex', () => {
	assert.equal( normalizeValue( '#FFFFFF' ), '#ffffff' );
} );

test( 'normalizeValue collapses internal whitespace', () => {
	assert.equal( normalizeValue( '3px   solid   red' ), '3px solid red' );
} );

import { extractRootTokens } from './css-tokens.mjs';

test( 'extractRootTokens ignores :root mentioned inside a comment', () => {
	const source = `
/*
 * Note: :root custom properties don't cross the iframe boundary.
 */
:root {
	--bl-ink: #132343;
}`;
	const tokens = extractRootTokens( source );
	assert.equal( tokens.get( '--bl-ink' ), '#132343' );
	assert.equal( tokens.size, 1 );
} );

test( 'extractRootTokens handles multiple declarations on one line', () => {
	const source = ':root { --bl-space-1: 0.25rem;  --bl-space-2: 0.5rem; }';
	const tokens = extractRootTokens( source );
	assert.equal( tokens.get( '--bl-space-1' ), '0.25rem' );
	assert.equal( tokens.get( '--bl-space-2' ), '0.5rem' );
} );

test( 'extractRootTokens keeps commas inside clamp()', () => {
	const source = ':root { --bl-text-lg: clamp(1.125rem, 0.5vw + 1rem, 1.25rem); }';
	assert.equal(
		extractRootTokens( source ).get( '--bl-text-lg' ),
		'clamp(1.125rem, 0.5vw + 1rem, 1.25rem)'
	);
} );

test( 'extractRootTokens strips a trailing comment after the semicolon', () => {
	const source = ':root { --bl-ink: #132343; /* 14.94 on paper */ }';
	assert.equal( extractRootTokens( source ).get( '--bl-ink' ), '#132343' );
} );

test( 'extractRootTokens accepts a grouped selector', () => {
	const source = ':root, .editor-styles-wrapper { --bl-ink: #132343; }';
	assert.equal( extractRootTokens( source ).get( '--bl-ink' ), '#132343' );
} );

test( 'extractRootTokens throws when there is no :root rule', () => {
	assert.throws( () => extractRootTokens( 'body { color: red; }' ), /no :root/ );
} );

import { resolveToken } from './css-tokens.mjs';

test( 'resolveToken returns a literal unchanged but normalised', () => {
	const tokens = new Map( [ [ '--bl-white', '#FFFFFF' ] ] );
	assert.equal( resolveToken( tokens, '--bl-white' ), '#ffffff' );
} );

test( 'resolveToken follows a single var() alias', () => {
	const tokens = new Map( [
		[ '--bl-white', '#FFFFFF' ],
		[ '--bl-surface', 'var(--bl-white)' ],
	] );
	assert.equal( resolveToken( tokens, '--bl-surface' ), '#ffffff' );
} );

test( 'resolveToken follows a chain of aliases', () => {
	const tokens = new Map( [
		[ '--bl-white', '#FFFFFF' ],
		[ '--bl-surface', 'var(--bl-white)' ],
		[ '--bl-card', 'var(--bl-surface)' ],
	] );
	assert.equal( resolveToken( tokens, '--bl-card' ), '#ffffff' );
} );

test( 'resolveToken uses the var() fallback when the target is undefined', () => {
	const tokens = new Map( [ [ '--bl-x', 'var(--bl-missing, #123456)' ] ] );
	assert.equal( resolveToken( tokens, '--bl-x' ), '#123456' );
} );

test( 'resolveToken throws on a reference cycle', () => {
	const tokens = new Map( [
		[ '--bl-a', 'var(--bl-b)' ],
		[ '--bl-b', 'var(--bl-a)' ],
	] );
	assert.throws( () => resolveToken( tokens, '--bl-a' ), /cycle/ );
} );

test( 'resolveToken throws on an unknown token', () => {
	assert.throws( () => resolveToken( new Map(), '--bl-nope' ), /not defined/ );
} );

test( 'resolveToken leaves a composite shorthand alone after resolving its parts', () => {
	const tokens = new Map( [
		[ '--bl-accent-text', '#3F6E9D' ],
		[ '--bl-focus', '3px solid var(--bl-accent-text)' ],
	] );
	assert.equal( resolveToken( tokens, '--bl-focus' ), '3px solid #3f6e9d' );
} );

test( 'resolveToken does not mistake a diamond dependency for a cycle', () => {
	const tokens = new Map( [
		[ '--bl-d', '#123456' ],
		[ '--bl-b', 'var(--bl-d)' ],
		[ '--bl-c', 'var(--bl-d)' ],
		[ '--bl-a', 'linear-gradient(var(--bl-b), var(--bl-c))' ],
	] );
	assert.equal(
		resolveToken( tokens, '--bl-a' ),
		'linear-gradient(#123456, #123456)'
	);
} );
