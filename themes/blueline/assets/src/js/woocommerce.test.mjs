import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { select2LabelledBy, labelSelect2Fields } = require( './woocommerce.js' );

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
