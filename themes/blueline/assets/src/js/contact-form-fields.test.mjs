import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { autocompleteFor, enhanceContactForms } = require( './contact-form-fields.js' );

/**
 * Fake form control.
 *
 * @param {Object} attrs Attributes (name, type, placeholder, ...).
 * @param {Object} extra Extra properties (id, closest).
 * @return {Object} A fake input.
 */
function input( attrs, extra = {} ) {
	const attributes = { ...attrs };
	return {
		attributes,
		closest: () => null,
		getAttribute: ( key ) => ( key in attributes ? attributes[ key ] : null ),
		setAttribute: ( key, value ) => {
			attributes[ key ] = String( value );
		},
		hasAttribute: ( key ) => key in attributes,
		...extra,
	};
}

/**
 * Fake label wrapping $controls, with $text as its own text.
 *
 * @param {string}   text     Label text.
 * @param {Object[]} controls Wrapped controls.
 * @return {Object} A fake label.
 */
function label( text, controls ) {
	return {
		childNodes: [ { nodeType: 3, textContent: text }, { nodeType: 1, textContent: 'ignored' } ],
		querySelectorAll: () => controls,
	};
}

/**
 * Fake document returning $fields and no external labels.
 *
 * @param {Object[]} fields Fields on the page.
 * @return {Object} A fake document.
 */
function doc( fields ) {
	return {
		querySelectorAll: () => fields,
		querySelector: () => null,
	};
}

test( 'autocompleteFor maps the stock Contact Form 7 field names', () => {
	assert.equal( autocompleteFor( 'first-name', 'text' ), 'given-name' );
	assert.equal( autocompleteFor( 'last-name', 'text' ), 'family-name' );
	assert.equal( autocompleteFor( 'your-first-name', 'text' ), 'given-name' );
	assert.equal( autocompleteFor( 'your-name', 'text' ), 'name' );
	assert.equal( autocompleteFor( 'your-email', 'text' ), 'email' );
	assert.equal( autocompleteFor( 'your-phone', 'text' ), 'tel' );
} );

test( 'autocompleteFor trusts the input type and ignores unrelated names', () => {
	assert.equal( autocompleteFor( 'anything', 'email' ), 'email' );
	assert.equal( autocompleteFor( 'anything', 'tel' ), 'tel' );
	assert.equal( autocompleteFor( 'your-subject', 'text' ), null );
	assert.equal( autocompleteFor( 'hotel-room', 'text' ), null );
	assert.equal( autocompleteFor( 'your-message', 'text' ), null );
} );

test( 'enhanceContactForms adds autocomplete and the matching keyboard hint', () => {
	const email = input( { name: 'your-email', type: 'text' } );
	const phone = input( { name: 'your-phone', type: 'text' } );
	const typed = input( { name: 'x', type: 'email' } );

	enhanceContactForms( doc( [ email, phone, typed ] ) );

	assert.equal( email.getAttribute( 'autocomplete' ), 'email' );
	assert.equal( email.getAttribute( 'inputmode' ), 'email' );
	assert.equal( phone.getAttribute( 'autocomplete' ), 'tel' );
	assert.equal( phone.getAttribute( 'inputmode' ), 'tel' );
	assert.equal( typed.getAttribute( 'autocomplete' ), 'email' );
	assert.equal( typed.hasAttribute( 'inputmode' ), false );
} );

test( 'enhanceContactForms keeps an autocomplete the form already set', () => {
	const field = input( { name: 'your-email', type: 'email', autocomplete: 'off' } );

	enhanceContactForms( doc( [ field ] ) );

	assert.equal( field.getAttribute( 'autocomplete' ), 'off' );
} );

test( 'enhanceContactForms names each part of a compound "Name" label', () => {
	const first = input( { name: 'first-name', type: 'text', placeholder: 'First' } );
	const last = input( { name: 'last-name', type: 'text', placeholder: 'Last' } );
	const wrapping = label( 'Name', [ first, last ] );
	first.closest = () => wrapping;
	last.closest = () => wrapping;

	enhanceContactForms( doc( [ first, last ] ) );

	assert.equal( first.getAttribute( 'aria-label' ), 'First name' );
	assert.equal( last.getAttribute( 'aria-label' ), 'Last name' );
	assert.equal( last.getAttribute( 'autocomplete' ), 'family-name' );
} );

test( 'enhanceContactForms leaves a single labelled field alone', () => {
	const field = input( { name: 'your-email', type: 'email', placeholder: 'you@example.com' } );
	field.closest = () => label( 'Email', [ field ] );

	enhanceContactForms( doc( [ field ] ) );

	assert.equal( field.hasAttribute( 'aria-label' ), false );
} );

test( 'enhanceContactForms names a placeholder-only field with no label at all', () => {
	const field = input( { name: 'your-subject', type: 'text', placeholder: 'Subject' } );

	enhanceContactForms( doc( [ field ] ) );

	assert.equal( field.getAttribute( 'aria-label' ), 'Subject' );
} );

test( 'enhanceContactForms does not override an existing accessible name', () => {
	const field = input( { name: 'last-name', type: 'text', placeholder: 'Last', 'aria-label': 'Surname' } );

	enhanceContactForms( doc( [ field ] ) );

	assert.equal( field.getAttribute( 'aria-label' ), 'Surname' );
} );
