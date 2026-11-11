import { test, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );

/*
 * FE-01: two members on one browser. Runs the real top-level wiring of
 * floating-next-game.js, account-next-game.js and preferences-widget-reset.js
 * against a fake document and ONE shared localStorage, the way a family
 * laptop would.
 */

const MEMBER_A = 'aaaa1111bbbb2222';
const MEMBER_B = 'cccc3333dddd4444';

// Each page load installs its own fake globals and leaves them in place, so a
// later click still runs against them; they are removed after every test.
afterEach( () => {
	delete globalThis.document;
	delete globalThis.window;
} );

function fakeStorage() {
	const items = new Map();
	return {
		items,
		getItem: ( key ) => ( items.has( key ) ? items.get( key ) : null ),
		setItem: ( key, value ) => items.set( key, String( value ) ),
		removeItem: ( key ) => items.delete( key ),
	};
}

function fakeElement( attributes, children = {} ) {
	const listeners = {};
	return {
		hidden: false,
		isConnected: true,
		getAttribute: ( name ) =>
			Object.prototype.hasOwnProperty.call( attributes, name )
				? attributes[ name ]
				: null,
		querySelector: ( selector ) => children[ selector ] || null,
		addEventListener: ( type, fn ) => {
			listeners[ type ] = fn;
		},
		contains: () => false,
		click() {
			listeners.click();
		},
	};
}

/**
 * Load `file` fresh against a page whose `selector` matches `element`.
 *
 * @param {string} file     Module path.
 * @param {string} selector The root selector the module queries.
 * @param {Object} element  Fake element for that selector.
 * @param {Object} storage  The shared fake localStorage.
 */
function loadPage( file, selector, element, storage ) {
	globalThis.window = {
		localStorage: storage,
		setTimeout: ( fn ) => fn(),
	};
	globalThis.document = {
		querySelector: ( s ) => ( s === selector ? element : null ),
		addEventListener: () => {},
		getElementById: () => null,
	};
	delete require.cache[ require.resolve( file ) ];
	require( file );
}

function widgetPage( member, state, storage ) {
	const updated = fakeElement( {} );
	updated.hidden = true;
	const dismiss = fakeElement( {} );
	const widget = fakeElement(
		{ 'data-bl-next-game': state, 'data-bl-member': member },
		{
			'[data-bl-next-game-updated]': updated,
			'[data-bl-next-game-dismiss]': dismiss,
		}
	);
	dismiss.ownerDocument = { activeElement: null };
	loadPage( './floating-next-game.js', '[data-bl-next-game]', widget, storage );
	return { widget, updated, dismiss };
}

function accountPage( member, state, storage ) {
	const updated = fakeElement( {} );
	updated.hidden = true;
	const card = fakeElement(
		{ 'data-bl-next-game-account': state, 'data-bl-member': member },
		{ '[data-bl-next-game-account-updated]': updated }
	);
	loadPage(
		'./account-next-game.js',
		'[data-bl-next-game-account]',
		card,
		storage
	);
	return { card, updated };
}

test( "member A's dismissal does not hide member B's widget for the same game", () => {
	const storage = fakeStorage();

	widgetPage( MEMBER_A, '700:abc', storage ).dismiss.click();
	assert.equal(
		widgetPage( MEMBER_A, '700:abc', storage ).widget.hidden,
		true,
		'A still sees it dismissed'
	);

	assert.equal( widgetPage( MEMBER_B, '700:abc', storage ).widget.hidden, false );
} );

test( "member A's My Account view does not give member B an \"Updated\" flag", () => {
	const storage = fakeStorage();

	accountPage( MEMBER_A, '700:old', storage );

	assert.equal( widgetPage( MEMBER_B, '700:new', storage ).updated.hidden, true );
	assert.equal( accountPage( MEMBER_B, '700:new', storage ).updated.hidden, true );
	// A, who did see the old version, still gets the flag.
	assert.equal( widgetPage( MEMBER_A, '700:new', storage ).updated.hidden, false );
} );

test( 'PR #25 split kept: viewing My Account never hides the widget', () => {
	const storage = fakeStorage();

	accountPage( MEMBER_A, '700:abc', storage );

	assert.equal( widgetPage( MEMBER_A, '700:abc', storage ).widget.hidden, false );
} );

test( 'old browser-wide state is ignored and cleared', () => {
	const storage = fakeStorage();
	storage.setItem( 'blueline:next-game-dismissed', '700:abc' );
	storage.setItem( 'blueline:next-game-seen', '700:abc' );

	assert.equal( widgetPage( MEMBER_B, '700:abc', storage ).widget.hidden, false );
	assert.equal( storage.getItem( 'blueline:next-game-dismissed' ), null );
	assert.equal( storage.getItem( 'blueline:next-game-seen' ), null );
} );

test( "Preferences reset clears only the signed-in member's dismissal", () => {
	const storage = fakeStorage();
	widgetPage( MEMBER_A, '700:abc', storage ).dismiss.click();
	widgetPage( MEMBER_B, '700:abc', storage ).dismiss.click();

	const confirmation = fakeElement( { 'data-bl-message': 'Done' } );
	const button = fakeElement( { 'data-bl-member': MEMBER_B } );
	globalThis.window = { localStorage: storage, setTimeout: ( fn ) => fn() };
	globalThis.document = {
		querySelector: ( s ) =>
			( {
				'[data-bl-widget-reset]': button,
				'[data-bl-widget-reset-confirmation]': confirmation,
			} )[ s ] || null,
	};
	delete require.cache[ require.resolve( './preferences-widget-reset.js' ) ];
	require( './preferences-widget-reset.js' );
	button.click();

	assert.equal( confirmation.textContent, 'Done' );
	assert.equal( widgetPage( MEMBER_B, '700:abc', storage ).widget.hidden, false );
	assert.equal( widgetPage( MEMBER_A, '700:abc', storage ).widget.hidden, true );
} );

test( 'without a member scope nothing is read or written', () => {
	const storage = fakeStorage();

	widgetPage( '', '700:abc', storage ).dismiss.click();
	accountPage( '', '700:abc', storage );

	assert.equal( storage.items.size, 0 );
} );
