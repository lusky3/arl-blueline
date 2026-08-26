import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { clampPreference, resolveToggleAction } = require( './footer-theme-toggle.js' );

test( 'clampPreference passes each known value through unchanged', () => {
	assert.equal( clampPreference( 'system' ), 'system' );
	assert.equal( clampPreference( 'light' ), 'light' );
	assert.equal( clampPreference( 'dark' ), 'dark' );
} );

test( 'clampPreference clamps an unrecognised value to system', () => {
	assert.equal( clampPreference( 'solarized' ), 'system' );
} );

test( 'clampPreference clamps null to system', () => {
	assert.equal( clampPreference( null ), 'system' );
} );

test( 'clampPreference clamps an empty string to system', () => {
	assert.equal( clampPreference( '' ), 'system' );
} );

test( 'resolveToggleAction persists via ajax for a logged-in ("account") visitor', () => {
	assert.deepEqual( resolveToggleAction( 'account', 'dark' ), {
		value: 'dark',
		persist: 'ajax',
	} );
} );

test( 'resolveToggleAction persists via localStorage for a guest', () => {
	assert.deepEqual( resolveToggleAction( 'guest', 'dark' ), {
		value: 'dark',
		persist: 'localStorage',
	} );
} );

test( 'resolveToggleAction clamps an unrecognised value before deciding persistence', () => {
	assert.deepEqual( resolveToggleAction( 'account', 'nonsense' ), {
		value: 'system',
		persist: 'ajax',
	} );
	assert.deepEqual( resolveToggleAction( 'guest', 'nonsense' ), {
		value: 'system',
		persist: 'localStorage',
	} );
} );

test( 'resolveToggleAction treats any mode other than "account" as the localStorage path', () => {
	// Defensive: blueline_render_theme_toggle() only ever renders exactly
	// "account" or "guest" for data-bl-theme-toggle-mode, but a malformed
	// or missing mode must never be mistaken for the AJAX path --
	// localStorage is the safe default here, since it cannot send a
	// request to admin-ajax.php with no valid nonce.
	assert.deepEqual( resolveToggleAction( '', 'dark' ), {
		value: 'dark',
		persist: 'localStorage',
	} );
	assert.deepEqual( resolveToggleAction( null, 'dark' ), {
		value: 'dark',
		persist: 'localStorage',
	} );
} );
