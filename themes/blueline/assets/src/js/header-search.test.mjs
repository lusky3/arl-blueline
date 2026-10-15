import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { initHeaderSearch } = require( './header-search.js' );

/**
 * A minimal event target.
 *
 * @return {object} Object with addEventListener/dispatch.
 */
function emitter() {
	const handlers = {};
	return {
		addEventListener( type, fn ) {
			( handlers[ type ] = handlers[ type ] || [] ).push( fn );
		},
		dispatch( type, event ) {
			( handlers[ type ] || [] ).forEach( ( fn ) => fn( event ) );
		},
	};
}

/**
 * A fake document holding the search button, its panel (with a search
 * field) and one unrelated link.
 *
 * @return {object} The fake document and its parts.
 */
function fakePage() {
	const doc = emitter();
	const attrs = {
		'aria-expanded': 'false',
		'aria-controls': 'bl-header-search',
	};
	const field = {
		focus() {
			doc.activeElement = field;
		},
	};
	const toggle = {
		...emitter(),
		hidden: true,
		ownerDocument: doc,
		getAttribute: ( name ) => attrs[ name ],
		setAttribute: ( name, value ) => {
			attrs[ name ] = value;
		},
		contains: ( node ) => node === toggle,
		focus() {
			doc.activeElement = toggle;
		},
	};
	const panel = {
		hidden: true,
		contains: ( node ) => node === panel || node === field,
		querySelector: () => field,
	};
	const elsewhere = {};

	doc.activeElement = null;
	doc.querySelector = ( selector ) =>
		'.bl-search-toggle' === selector ? toggle : null;
	doc.getElementById = ( id ) => ( 'bl-header-search' === id ? panel : null );

	return { doc, toggle, panel, field, elsewhere, attrs };
}

/**
 * A fake window whose desktop media query can be flipped.
 *
 * @return {object} Fake window with a `query` handle.
 */
function fakeWindow() {
	const query = { ...emitter(), matches: true };
	return {
		query,
		matchMedia: () => query,
	};
}

test( 'the button is revealed once the script runs', () => {
	const page = fakePage();
	initHeaderSearch( page.doc, fakeWindow() );

	assert.equal( page.toggle.hidden, false );
} );

test( 'clicking opens the panel, sets aria-expanded and focuses the field', () => {
	const page = fakePage();
	initHeaderSearch( page.doc, fakeWindow() );

	page.toggle.dispatch( 'click', { target: page.toggle } );

	assert.equal( page.panel.hidden, false );
	assert.equal( page.attrs[ 'aria-expanded' ], 'true' );
	assert.equal( page.doc.activeElement, page.field );
} );

test( 'a second click closes it again', () => {
	const page = fakePage();
	initHeaderSearch( page.doc, fakeWindow() );

	page.toggle.dispatch( 'click', { target: page.toggle } );
	page.toggle.dispatch( 'click', { target: page.toggle } );

	assert.equal( page.panel.hidden, true );
	assert.equal( page.attrs[ 'aria-expanded' ], 'false' );
} );

test( 'Escape from the field closes the panel and returns focus to the button', () => {
	const page = fakePage();
	initHeaderSearch( page.doc, fakeWindow() );
	page.toggle.dispatch( 'click', { target: page.toggle } );

	let prevented = false;
	page.doc.dispatch( 'keydown', {
		key: 'Escape',
		preventDefault() {
			prevented = true;
		},
	} );

	assert.equal( page.panel.hidden, true );
	assert.equal( page.doc.activeElement, page.toggle );
	assert.equal( prevented, true );
} );

test( 'focus or a click moving elsewhere closes it without stealing focus', () => {
	const page = fakePage();
	initHeaderSearch( page.doc, fakeWindow() );

	page.toggle.dispatch( 'click', { target: page.toggle } );
	page.doc.dispatch( 'focusin', { target: page.elsewhere } );
	assert.equal( page.panel.hidden, true );
	assert.equal( page.doc.activeElement, page.field );

	page.toggle.dispatch( 'click', { target: page.toggle } );
	page.doc.dispatch( 'click', { target: page.elsewhere } );
	assert.equal( page.panel.hidden, true );
} );

test( 'clicks inside the panel keep it open', () => {
	const page = fakePage();
	initHeaderSearch( page.doc, fakeWindow() );

	page.toggle.dispatch( 'click', { target: page.toggle } );
	page.doc.dispatch( 'click', { target: page.field } );
	page.doc.dispatch( 'focusin', { target: page.field } );

	assert.equal( page.panel.hidden, false );
} );

test( 'narrowing into the drawer layout closes it', () => {
	const page = fakePage();
	const win = fakeWindow();
	initHeaderSearch( page.doc, win );

	page.toggle.dispatch( 'click', { target: page.toggle } );
	win.query.matches = false;
	win.query.dispatch( 'change', {} );

	assert.equal( page.panel.hidden, true );
} );

test( 'no markup, no controller', () => {
	const doc = { querySelector: () => null, getElementById: () => null };

	assert.equal( initHeaderSearch( doc, fakeWindow() ), null );
} );
