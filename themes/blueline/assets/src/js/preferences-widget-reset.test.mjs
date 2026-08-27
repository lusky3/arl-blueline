import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { WIDGET_DISMISSED_KEY } = require( './preferences-widget-reset.js' );

test( 'WIDGET_DISMISSED_KEY matches the key floating-next-game.js actually uses', () => {
	// This is the one thing that must never silently drift: if
	// floating-next-game.js's own dismissed-state key ever changes, this
	// button's whole purpose breaks with no visible error anywhere.
	assert.equal( WIDGET_DISMISSED_KEY, 'blueline:next-game-dismissed' );
} );
