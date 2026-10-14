import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const {
	select2LabelledBy,
	labelSelect2Fields,
	SELECT2_FORMS_SELECTOR,
	initSelect2Labels,
	levelAccountSectionHeadings,
	markStarredFieldsRequired,
	fixAddressAutocomplete,
} = require( './woocommerce.js' );

/**
 * Minimal fake element with an attribute map.
 *
 * @param {Object} props Extra properties.
 * @return {Object} A fake element.
 */
function el( props = {} ) {
	const attributes = { ...( props.attrs || {} ) };
	return {
		attributes,
		getAttribute: ( key ) => ( key in attributes ? attributes[ key ] : null ),
		setAttribute: ( key, value ) => {
			attributes[ key ] = String( value );
		},
		hasAttribute: ( key ) => key in attributes,
		removeAttribute: ( key ) => {
			delete attributes[ key ];
		},
		...props,
	};
}

/**
 * One checkout .form-row with a label and a select2 selection.
 *
 * @param {boolean} multiple Tag-style multi-select (no role on the wrapper).
 * @return {Object} { root, label, selection, search }.
 */
function row( multiple ) {
	const label = el( { id: '', htmlFor: 'arl_division' } );
	const search = el( { attrs: { role: 'textbox' } } );
	const rendered = el( { attrs: { role: 'textbox' } } );
	const selection = el( {
		attrs: multiple
			? { 'aria-expanded': 'false', 'aria-haspopup': 'true' }
			: { role: 'combobox', 'aria-labelledby': 'select2-arl_division-container' },
		matches: ( sel ) => ! multiple && '[role="combobox"]' === sel,
		querySelector: ( sel ) => {
			if ( multiple ) {
				return search;
			}
			return '[role="textbox"]' === sel ? rendered : null;
		},
		closest: () => ( { querySelector: () => label } ),
	} );
	const root = { querySelectorAll: () => [ selection ] };
	return { root, label, selection, search, rendered };
}

test( 'select2LabelledBy: the label id goes first, existing ids are kept', () => {
	assert.equal( select2LabelledBy( 'select2-x-container', 'x-label' ), 'x-label select2-x-container' );
	assert.equal( select2LabelledBy( null, 'x-label' ), 'x-label' );
} );

test( 'select2LabelledBy: running twice does not duplicate the label', () => {
	assert.equal( select2LabelledBy( 'x-label select2-x-container', 'x-label' ), 'x-label select2-x-container' );
} );

test( 'labelSelect2Fields: a single-select combobox is named by its field label', () => {
	const { root, label, selection, rendered } = row( false );
	labelSelect2Fields( root );
	assert.equal( label.id, 'arl_division-label' );
	assert.equal( selection.getAttribute( 'aria-labelledby' ), 'arl_division-label select2-arl_division-container' );
	assert.equal( rendered.getAttribute( 'aria-labelledby' ), 'arl_division-label' );
} );

test( 'labelSelect2Fields: a multi-select names its search field and drops role-less ARIA', () => {
	const { root, selection, search } = row( true );
	labelSelect2Fields( root );
	assert.equal( search.getAttribute( 'aria-labelledby' ), 'arl_division-label' );
	assert.equal( selection.hasAttribute( 'aria-expanded' ), false );
	assert.equal( selection.hasAttribute( 'aria-haspopup' ), false );
} );

test( 'SELECT2_FORMS_SELECTOR covers the checkout form and the account-area forms', () => {
	assert.ok( SELECT2_FORMS_SELECTOR.includes( 'form.checkout' ) );
	assert.ok( SELECT2_FORMS_SELECTOR.includes( '.woocommerce-MyAccount-content form' ) );
} );

test( 'initSelect2Labels watches every matching form, and does nothing without MutationObserver', () => {
	const observed = [];
	class FakeObserver {
		observe( target ) {
			observed.push( target );
		}
	}
	const forms = [
		{ querySelectorAll: () => [] },
		{ querySelectorAll: () => [] },
	];
	const doc = {
		querySelectorAll: ( selector ) => ( selector === SELECT2_FORMS_SELECTOR ? forms : [] ),
	};

	initSelect2Labels( doc, { MutationObserver: FakeObserver, setTimeout: () => 0 } );
	assert.deepEqual( observed, forms );

	observed.length = 0;
	initSelect2Labels( doc, {} );
	assert.deepEqual( observed, [] );
} );

test( 'initSelect2Labels names the comboboxes of an account form on the first pass', () => {
	const { root, label, selection } = row( false );
	const form = { querySelectorAll: root.querySelectorAll };
	const doc = { querySelectorAll: () => [ form ] };

	initSelect2Labels( doc, {
		MutationObserver: class {
			observe() {}
		},
		setTimeout: () => 0,
	} );

	assert.equal( label.id, 'arl_division-label' );
	assert.ok( selection.getAttribute( 'aria-labelledby' ).startsWith( 'arl_division-label' ) );
} );

test( 'levelAccountSectionHeadings gives plugin section titles a real heading level', () => {
	const title = el();
	levelAccountSectionHeadings( { querySelectorAll: () => [ title ] } );

	assert.equal( title.getAttribute( 'role' ), 'heading' );
	assert.equal( title.getAttribute( 'aria-level' ), '2' );
} );

test( 'levelAccountSectionHeadings leaves a heading that already declares a level alone', () => {
	const title = el( { attrs: { 'aria-level': '3' } } );
	levelAccountSectionHeadings( { querySelectorAll: () => [ title ] } );

	assert.equal( title.getAttribute( 'aria-level' ), '3' );
	assert.equal( title.hasAttribute( 'role' ), false );
} );

/**
 * One account .form-row: a label that may carry a required marker, and a field.
 *
 * @param {boolean} starred Whether the label has the required asterisk.
 * @return {Object} { rowEl, field }.
 */
function accountRow( starred ) {
	const field = el();
	const rowEl = {
		querySelector: ( selector ) => {
			if ( selector.includes( 'abbr.required' ) ) {
				return starred ? el() : null;
			}
			return field;
		},
	};
	return { rowEl, field };
}

test( 'markStarredFieldsRequired marks starred fields required for the browser and assistive tech', () => {
	const { rowEl, field } = accountRow( true );
	markStarredFieldsRequired( { querySelectorAll: () => [ rowEl ] } );

	assert.ok( field.hasAttribute( 'required' ) );
	assert.equal( field.getAttribute( 'aria-required' ), 'true' );
} );

test( 'markStarredFieldsRequired leaves optional fields alone', () => {
	const { rowEl, field } = accountRow( false );
	markStarredFieldsRequired( { querySelectorAll: () => [ rowEl ] } );

	assert.equal( field.hasAttribute( 'required' ), false );
} );

test( 'markStarredFieldsRequired skips rows with no plain field (select2-backed selects)', () => {
	const rowEl = {
		querySelector: ( selector ) => ( selector.includes( 'abbr.required' ) ? el() : null ),
	};

	assert.doesNotThrow( () => markStarredFieldsRequired( { querySelectorAll: () => [ rowEl ] } ) );
} );

test( 'fixAddressAutocomplete replaces autocomplete="off" on the street address lines', () => {
	const line1 = el( { id: 'billing_address_1', attrs: { autocomplete: 'off' } } );
	const line2 = el( { id: 'shipping_address_2', attrs: { autocomplete: 'off' } } );
	const other = el( { id: 'billing_company', attrs: { autocomplete: 'off' } } );

	fixAddressAutocomplete( { querySelectorAll: () => [ line1, line2, other ] } );

	assert.equal( line1.getAttribute( 'autocomplete' ), 'address-line1' );
	assert.equal( line2.getAttribute( 'autocomplete' ), 'address-line2' );
	assert.equal( other.getAttribute( 'autocomplete' ), 'off' );
} );
