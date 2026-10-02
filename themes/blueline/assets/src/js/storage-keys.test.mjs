import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire( import.meta.url );
const floating = require( './floating-next-game.js' );
const account = require( './account-next-game.js' );
const themeToggle = require( './footer-theme-toggle.js' );

// localStorage keys are shared contracts between modules (and one PHP inline
// script); these assertions stop them drifting apart silently.

test( 'account-next-game.js and floating-next-game.js share one "seen" key', () => {
	assert.equal( account.SEEN_KEY, floating.SEEN_KEY );
} );

test( 'the seen and dismissed keys stay distinct', () => {
	assert.notEqual( floating.SEEN_KEY, floating.DISMISSED_KEY );
} );

test( "the theme toggle's key matches the PHP pre-paint bootstrap literal", () => {
	const php = readFileSync(
		new URL( '../../../inc/account/theme-preference.php', import.meta.url ),
		'utf8'
	);
	assert.ok(
		php.includes( `localStorage.getItem( '${ themeToggle.STORAGE_KEY }' )` ),
		`theme-preference.php no longer reads '${ themeToggle.STORAGE_KEY }'`
	);
} );
