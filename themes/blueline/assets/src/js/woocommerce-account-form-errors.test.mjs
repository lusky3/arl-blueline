import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { associateErrors, findForm, labelKey } = require( './woocommerce-account-form-errors.js' );

/**
 * Fake element with attributes and a class list.
 *
 * @param {Object} props Extra properties.
 * @return {Object} A fake element.
 */
function el( props = {} ) {
	const attributes = { ...( props.attrs || {} ) };
	const classes = new Set();
	return {
		attributes,
		classes,
		id: '',
		getAttribute: ( key ) => ( key in attributes ? attributes[ key ] : null ),
		setAttribute( key, value ) {
			attributes[ key ] = String( value );
		},
		classList: { add: ( name ) => classes.add( name ) },
		closest: () => null,
		...props,
	};
}

/**
 * Fake form-field with an optional visible label.
 *
 * @param {string} name  Field name.
 * @param {string} label Visible label text ('' for none).
 * @return {Object} { field, label }.
 */
function field( name, label = '' ) {
	const row = el();
	const input = el( { id: name, name, closest: () => row } );
	const labelEl = label ? { htmlFor: name, textContent: label } : null;
	return { input, labelEl, row };
}

/**
 * Fake form over the given fields.
 *
 * @param {Object[]} fields Entries from field().
 * @return {Object} A fake form.
 */
function form( fields ) {
	return {
		querySelectorAll: ( selector ) =>
			selector.startsWith( 'label' )
				? fields.map( ( f ) => f.labelEl ).filter( Boolean )
				: fields.map( ( f ) => f.input ),
	};
}

/**
 * Fake error item.
 *
 * @param {string} text   Message text.
 * @param {string} dataId Optional data-id.
 * @return {Object} A fake <li>.
 */
function item( text, dataId = '' ) {
	return el( { textContent: text, attrs: dataId ? { 'data-id': dataId } : {} } );
}

test( 'labelKey strips the required marker, the optional hint and punctuation', () => {
	assert.equal( labelKey( 'First name *' ), 'first name' );
	assert.equal( labelKey( 'Current password (leave blank to leave unchanged)' ), 'current password' );
	assert.equal( labelKey( 'Email address: *' ), 'email address' );
} );

test( 'an error naming a field by its label is tied to that field', () => {
	const first = field( 'account_first_name', 'First name *' );
	const last = field( 'account_last_name', 'Last name *' );
	const error = item( 'First name is a required field.' );

	associateErrors( form( [ first, last ] ), [ error ] );

	assert.equal( first.input.getAttribute( 'aria-invalid' ), 'true' );
	assert.equal( first.input.getAttribute( 'aria-describedby' ), error.id );
	assert.ok( first.row.classes.has( 'woocommerce-invalid' ) );
	assert.equal( last.input.getAttribute( 'aria-invalid' ), null );
} );

test( 'the longest label match wins, so a field labelled just "Name" is not blamed for "Company name"', () => {
	const company = field( 'billing_company', 'Company name' );
	const name = field( 'billing_name', 'name' );
	const error = item( 'Company name is a required field.' );

	associateErrors( form( [ company, name ] ), [ error ] );

	assert.equal( company.input.getAttribute( 'aria-invalid' ), 'true' );
	assert.equal( name.input.getAttribute( 'aria-invalid' ), null );
} );

test( 'a data-id on the error is an exact match and beats text matching', () => {
	const street = field( 'billing_address_1', 'Street address *' );
	const city = field( 'billing_city', 'Town / City *' );
	const error = item( 'Something went wrong with the town / city.', 'billing_address_1' );

	associateErrors( form( [ street, city ] ), [ error ] );

	assert.equal( street.input.getAttribute( 'aria-invalid' ), 'true' );
	assert.equal( city.input.getAttribute( 'aria-invalid' ), null );
} );

test( 'the login form still matches on the field name', () => {
	const username = field( 'username' );
	const password = field( 'password' );

	associateErrors( form( [ username, password ] ), [ item( 'Error: Username is required.' ) ] );

	assert.equal( username.input.getAttribute( 'aria-invalid' ), 'true' );
	assert.equal( password.input.getAttribute( 'aria-invalid' ), null );
} );

test( 'an existing aria-describedby is kept and the error id is not added twice', () => {
	const email = field( 'account_email', 'Email address *' );
	email.input.setAttribute( 'aria-describedby', 'hint' );
	const error = item( 'Email address is a required field.' );

	associateErrors( form( [ email ] ), [ error ] );
	associateErrors( form( [ email ] ), [ error ] );

	assert.equal( email.input.getAttribute( 'aria-describedby' ), `hint ${ error.id }` );
} );

test( 'an error that names no field changes nothing', () => {
	const email = field( 'account_email', 'Email address *' );

	associateErrors( form( [ email ] ), [ item( 'Please fill out all password fields.' ) ] );

	assert.equal( email.input.getAttribute( 'aria-invalid' ), null );
} );

test( 'findForm covers the login/register/reset forms, Edit Account and the address form', () => {
	const seen = [];
	const login = el();
	const found = findForm( {
		querySelector: ( selector ) => {
			seen.push( selector );
			return selector.includes( 'woocommerce-EditAccountForm' ) ? login : null;
		},
	} );

	assert.equal( found, login );
	assert.ok( seen[ 0 ].includes( 'woocommerce-form-login' ) );

	const address = el( { closest: ( selector ) => ( 'form' === selector ? login : null ) } );
	const viaAddress = findForm( {
		querySelector: ( selector ) =>
			'.woocommerce-address-fields' === selector ? address : null,
	} );

	assert.equal( viaAddress, login );
} );
