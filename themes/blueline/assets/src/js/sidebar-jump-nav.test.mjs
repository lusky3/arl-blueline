import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { pickActiveSectionId } = require( './sidebar-jump-nav.js' );

test( 'pickActiveSectionId returns null for an empty section list', () => {
	assert.equal( pickActiveSectionId( [], 500 ), null );
} );

test( 'pickActiveSectionId returns the first section before any heading is reached', () => {
	const sections = [
		{ id: 'first', offsetTop: 400 },
		{ id: 'second', offsetTop: 900 },
	];

	assert.equal( pickActiveSectionId( sections, 0 ), 'first' );
} );

test( 'pickActiveSectionId returns the last section the scroll position has reached', () => {
	const sections = [
		{ id: 'first', offsetTop: 0 },
		{ id: 'second', offsetTop: 400 },
		{ id: 'third', offsetTop: 900 },
	];

	assert.equal( pickActiveSectionId( sections, 500 ), 'second' );
} );

test( 'pickActiveSectionId returns the final section once scrolled past it', () => {
	const sections = [
		{ id: 'first', offsetTop: 0 },
		{ id: 'second', offsetTop: 400 },
		{ id: 'third', offsetTop: 900 },
	];

	assert.equal( pickActiveSectionId( sections, 1200 ), 'third' );
} );

test( 'pickActiveSectionId treats an exact offset match as reached', () => {
	const sections = [
		{ id: 'first', offsetTop: 0 },
		{ id: 'second', offsetTop: 400 },
	];

	assert.equal( pickActiveSectionId( sections, 400 ), 'second' );
} );
