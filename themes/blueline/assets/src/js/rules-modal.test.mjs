import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { wrapTarget, createController } = require( './rules-modal.js' );

/**
 * Minimal fake element: attributes, focus tracking and containment only.
 *
 * @param {string} name  Label for assertions.
 * @param {Object} props Extra properties.
 * @return {Object} A fake element.
 */
function el( name, props = {} ) {
	const attributes = {};
	return {
		name,
		id: '',
		hidden: false,
		children: [],
		attributes,
		getAttribute: ( key ) => ( key in attributes ? attributes[ key ] : null ),
		setAttribute: ( key, value ) => {
			attributes[ key ] = String( value );
		},
		getClientRects: () => [ {} ],
		...props,
	};
}

function fixture() {
	const doc = { activeElement: null, body: el( 'body', { style: {} } ) };
	const focus = function () {
		doc.activeElement = this;
	};
	const heading = el( 'heading', { focus } );
	const checkbox = el( 'checkbox', { focus } );
	const cancel = el( 'cancel', { focus } );
	const panel = el( 'panel', {
		scrollTop: 863,
		querySelectorAll: () => [ checkbox, cancel ],
	} );
	const inside = [ panel, heading, checkbox, cancel ];
	const modal = el( 'modal', {
		querySelector: ( sel ) => ( 'h2' === sel ? heading : panel ),
		contains: ( node ) => inside.includes( node ),
	} );
	const opener = el( 'register-now', { focus } );
	doc.contains = ( node ) => node === opener || inside.includes( node );
	return { doc, modal, panel, heading, checkbox, cancel, opener };
}

test( 'wrapTarget: Tab on the last item wraps to the first', () => {
	assert.equal( wrapTarget( [ 'a', 'b', 'c' ], 'c', false ), 'a' );
} );

test( 'wrapTarget: Shift+Tab on the first item wraps to the last', () => {
	assert.equal( wrapTarget( [ 'a', 'b', 'c' ], 'a', true ), 'c' );
} );

test( 'wrapTarget: a middle item lets the browser move focus', () => {
	assert.equal( wrapTarget( [ 'a', 'b', 'c' ], 'b', false ), null );
	assert.equal( wrapTarget( [ 'a', 'b', 'c' ], 'b', true ), null );
} );

test( 'wrapTarget: focus outside the list is pulled back in', () => {
	assert.equal( wrapTarget( [ 'a', 'b' ], 'heading', false ), 'a' );
	assert.equal( wrapTarget( [ 'a', 'b' ], 'heading', true ), 'b' );
	assert.equal( wrapTarget( [], 'x', false ), null );
} );

test( 'createController: dialog semantics and a focusable heading', () => {
	const { doc, modal, heading } = fixture();
	createController( modal, doc );
	assert.equal( modal.getAttribute( 'role' ), 'dialog' );
	assert.equal( modal.getAttribute( 'aria-modal' ), 'true' );
	assert.equal( modal.getAttribute( 'aria-labelledby' ), heading.id );
	assert.equal( heading.getAttribute( 'tabindex' ), '-1' );
} );

test( 'opened: focus moves to the heading and the panel scrolls to the top', () => {
	const { doc, modal, panel, heading } = fixture();
	createController( modal, doc ).opened();
	assert.equal( doc.activeElement, heading );
	assert.equal( panel.scrollTop, 0 );
} );

test( 'closed: focus returns to the button that opened it', () => {
	const { doc, modal, opener, cancel } = fixture();
	const controller = createController( modal, doc );
	controller.remember( opener );
	doc.activeElement = cancel;
	controller.closed();
	assert.equal( doc.activeElement, opener );
} );

test( 'closed: focus the user already moved elsewhere is left alone', () => {
	const { doc, modal, opener } = fixture();
	const controller = createController( modal, doc );
	controller.remember( opener );
	const elsewhere = el( 'elsewhere' );
	doc.activeElement = elsewhere;
	controller.closed();
	assert.equal( doc.activeElement, elsewhere );
} );

test( 'remember: elements inside the dialog are never the opener', () => {
	const { doc, modal, cancel } = fixture();
	const controller = createController( modal, doc );
	controller.remember( cancel );
	doc.activeElement = doc.body;
	controller.closed();
	assert.equal( doc.activeElement, doc.body );
} );

test( 'keydown: Tab from the last control wraps inside the dialog', () => {
	const { doc, modal, checkbox, cancel } = fixture();
	const controller = createController( modal, doc );
	doc.activeElement = cancel;
	let prevented = false;
	controller.keydown( { key: 'Tab', shiftKey: false, preventDefault: () => ( prevented = true ) } );
	assert.equal( prevented, true );
	assert.equal( doc.activeElement, checkbox );
} );

test( 'keydown: Escape closes the dialog if it is still open', () => {
	const { doc, modal } = fixture();
	doc.body.style.overflow = 'hidden';
	createController( modal, doc ).keydown( { key: 'Escape' } );
	assert.equal( modal.hidden, true );
	assert.equal( doc.body.style.overflow, '' );
} );

test( 'keydown: ignored while the dialog is hidden', () => {
	const { doc, modal, cancel } = fixture();
	const controller = createController( modal, doc );
	modal.hidden = true;
	doc.activeElement = cancel;
	controller.keydown( { key: 'Tab', shiftKey: false, preventDefault: () => assert.fail( 'should not trap' ) } );
	assert.equal( doc.activeElement, cancel );
} );
