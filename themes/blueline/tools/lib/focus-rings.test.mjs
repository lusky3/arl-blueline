import { test } from 'node:test';
import assert from 'node:assert/strict';
import { findFocusRingProblems } from './focus-rings.mjs';

test( 'a component box-shadow on focus must keep the halo', () => {
	assert.equal( findFocusRingProblems( '.b:focus-visible { box-shadow: 0 0 0 1px red; }' ).length, 1 );
	assert.deepEqual( findFocusRingProblems( '.b:focus-visible { box-shadow: var(--bl-focus-ring), 0 0 0 1px red; }' ), [] );
} );

test( 'an outline-only ring on a proxy element is flagged', () => {
	const css = '.i:focus-visible ~ .label { outline: 3px solid red; outline-offset: 2px; }';
	assert.equal( findFocusRingProblems( css ).length, 1 );
	assert.deepEqual( findFocusRingProblems( css.replace( '}', 'box-shadow: var(--bl-focus-ring); }' ) ), [] );
} );

test( 'an outline on the focused element itself inherits the base halo', () => {
	assert.deepEqual( findFocusRingProblems( 'input:focus-visible { outline: 3px solid red; }' ), [] );
} );

test( 'outline: none needs a replacement or an allowlist entry', () => {
	const css = '.notice:focus { outline: none; box-shadow: none; }';
	assert.equal( findFocusRingProblems( css ).length, 1 );
	assert.deepEqual( findFocusRingProblems( css, [ '.notice' ] ), [] );
} );

test( 'pseudo-element paint and comments are ignored', () => {
	assert.deepEqual( findFocusRingProblems( '/* .x:focus { outline: none } */ .b:focus-visible::before { box-shadow: inset 0 0 0 9px red; }' ), [] );
} );

test( 'a resting box-shadow on a control needs its own ringed focus rule', () => {
	const css = '.w a.button { box-shadow: 0 0 0 1px red; }';
	assert.equal( findFocusRingProblems( css ).length, 1 );
	assert.deepEqual( findFocusRingProblems( css + '.w a.button:focus-visible { box-shadow: var(--bl-focus-ring); }' ), [] );
	assert.deepEqual( findFocusRingProblems( '.card { box-shadow: 0 1px 2px red; }' ), [] );
} );

test( 'rules inside @media are checked', () => {
	assert.equal( findFocusRingProblems( '@media (min-width: 1px) { .b:focus-visible { box-shadow: 0 0 1px red; } }' ).length, 1 );
} );
