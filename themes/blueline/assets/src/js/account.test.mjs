import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { RESULT_NOTICE_SELECTOR, findResultNotice, focusResultNotice } = require( './account.js' );

/**
 * Fake notice: `inToggle` says whether it sits inside a login/coupon toggle.
 *
 * @param {string}  name     Label for failure messages.
 * @param {boolean} inToggle Whether an ancestor is a toggle prompt.
 * @return {Object} A fake element.
 */
function notice( name, inToggle = false ) {
	const attributes = {};
	return {
		name,
		focused: false,
		attributes,
		closest: ( selector ) =>
			inToggle && selector.includes( 'woocommerce-form-login-toggle' ) ? {} : null,
		hasAttribute: ( key ) => key in attributes,
		setAttribute: ( key, value ) => {
			attributes[ key ] = String( value );
		},
		focus() {
			this.focused = true;
		},
	};
}

/**
 * Fake document whose query returns the given notices in order.
 *
 * @param {Object[]} notices Notices, in DOM order.
 * @return {Object} A fake document.
 */
function doc( notices ) {
	return { querySelectorAll: () => notices };
}

test( 'the selector covers result notices and leaves the bare .woocommerce-info out', () => {
	assert.ok( RESULT_NOTICE_SELECTOR.includes( '.bl-account-notice' ) );
	assert.ok( RESULT_NOTICE_SELECTOR.includes( '.woocommerce-error' ) );
	assert.ok( RESULT_NOTICE_SELECTOR.includes( '.woocommerce-message' ) );
	assert.ok( RESULT_NOTICE_SELECTOR.includes( '.woocommerce-notices-wrapper > .woocommerce-info' ) );
	assert.ok( ! /(^|,)\s*\.woocommerce-info\s*(,|$)/.test( RESULT_NOTICE_SELECTOR ) );
} );

test( 'findResultNotice skips the login and coupon toggle prompts', () => {
	const toggle = notice( 'coupon prompt', true );
	const result = notice( 'error' );

	assert.equal( findResultNotice( doc( [ toggle, result ] ) ), result );
} );

test( 'findResultNotice returns null when only toggle prompts are present', () => {
	assert.equal( findResultNotice( doc( [ notice( 'login prompt', true ) ] ) ), null );
} );

test( 'findResultNotice returns null when there are no notices', () => {
	assert.equal( findResultNotice( doc( [] ) ), null );
} );

test( 'focusResultNotice makes the notice programmatically focusable and focuses it', () => {
	const result = notice( 'message' );

	assert.equal( focusResultNotice( doc( [ result ] ) ), true );
	assert.equal( result.attributes.tabindex, '-1' );
	assert.equal( result.focused, true );
} );

test( 'focusResultNotice keeps an existing tabindex', () => {
	const result = notice( 'message' );
	result.setAttribute( 'tabindex', '0' );

	focusResultNotice( doc( [ result ] ) );

	assert.equal( result.attributes.tabindex, '0' );
} );

test( 'focusResultNotice does nothing for a page with only prompts', () => {
	const prompt = notice( 'coupon prompt', true );

	assert.equal( focusResultNotice( doc( [ prompt ] ) ), false );
	assert.equal( prompt.focused, false );
	assert.equal( 'tabindex' in prompt.attributes, false );
} );
