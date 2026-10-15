import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { FORM_ID, isShown, wireLoginToggle } = require(
	'./checkout-login-toggle.js'
);

// Fake elements (house rule: no jsdom) with real listener dispatch.
function el( attrs = {}, style = {} ) {
	const listeners = {};
	const self = {
		id: attrs.id || '',
		hidden: false,
		style: { ...style },
		attrs: { ...attrs },
		getAttribute: ( name ) => ( name in self.attrs ? self.attrs[ name ] : null ),
		setAttribute: ( name, value ) => {
			self.attrs[ name ] = String( value );
		},
		addEventListener: ( type, fn ) => {
			( listeners[ type ] = listeners[ type ] || [] ).push( fn );
		},
		dispatch: ( type, event = {} ) => ( listeners[ type ] || [] ).forEach( ( fn ) => fn( event ) ),
		click: () => self.dispatch( 'click' ),
	};
	return self;
}

test( 'a hidden form starts collapsed, and the toggle points at it by a fresh id', () => {
	const toggle = el();
	const form = el( {}, { display: 'none' } );

	wireLoginToggle( toggle, form );

	assert.equal( form.id, FORM_ID );
	assert.equal( toggle.getAttribute( 'role' ), 'button' );
	assert.equal( toggle.getAttribute( 'aria-controls' ), FORM_ID );
	assert.equal( toggle.getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'a form shown after a failed login starts expanded and keeps its own id', () => {
	const toggle = el();
	const form = el( { id: 'existing' } );

	wireLoginToggle( toggle, form );

	assert.equal( toggle.getAttribute( 'aria-controls' ), 'existing' );
	assert.equal( toggle.getAttribute( 'aria-expanded' ), 'true' );
} );

test( 'each click flips the announced state, as WooCommerce flips the form', () => {
	const toggle = el();
	wireLoginToggle( toggle, el( {}, { display: 'none' } ) );

	toggle.click();
	assert.equal( toggle.getAttribute( 'aria-expanded' ), 'true' );
	toggle.click();
	assert.equal( toggle.getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'Space works like a button press and does not scroll', () => {
	const toggle = el();
	wireLoginToggle( toggle, el( {}, { display: 'none' } ) );

	let prevented = false;
	toggle.dispatch( 'keydown', { key: ' ', preventDefault: () => ( prevented = true ) } );
	toggle.dispatch( 'keydown', { key: 'a', preventDefault: () => assert.fail( 'only Space' ) } );

	assert.equal( prevented, true );
	assert.equal( toggle.getAttribute( 'aria-expanded' ), 'true' );
} );

test( 'shown means neither hidden nor display:none', () => {
	assert.equal( isShown( el() ), true );
	assert.equal( isShown( el( {}, { display: 'none' } ) ), false );
	const hidden = el();
	hidden.hidden = true;
	assert.equal( isShown( hidden ), false );
} );
