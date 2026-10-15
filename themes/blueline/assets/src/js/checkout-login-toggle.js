/**
 * Checkout's "Returning player? Click here to login" toggle as a real
 * disclosure.
 *
 * WooCommerce's checkout.js slides the login form open and shut when
 * `a.showlogin` is clicked, but the link carries no state, so a screen reader
 * hears "link" and nothing about whether the form is now showing. The coupon
 * toggle beside it already has role="button" and aria-expanded; this gives the
 * login toggle the same: role="button" (woocommerce/checkout/form-login.php
 * prints it with its initial aria-expanded), aria-controls pointing at the form
 * (which WooCommerce renders without an id, so one is added), aria-expanded
 * kept in step with each click, and Space to activate it like a button.
 *
 * WooCommerce decides open or shut from the form's current visibility and
 * animates towards the other state, so flipping aria-expanded on each click
 * tracks it, including a second click mid-animation.
 */

const FORM_ID = 'bl-checkout-login-form';

/**
 * Whether the login form is showing (WooCommerce hides it with an inline
 * display:none).
 *
 * @param {Element} form Login form.
 * @return {boolean} Shown.
 */
function isShown( form ) {
	return ! form.hidden && 'none' !== form.style.display;
}

/**
 * Wire one toggle to its form.
 *
 * @param {Element} toggle `a.showlogin`.
 * @param {Element} form   The checkout login form.
 * @return {void}
 */
function wireLoginToggle( toggle, form ) {
	if ( ! form.id ) {
		form.id = FORM_ID;
	}

	toggle.setAttribute( 'role', 'button' );
	toggle.setAttribute( 'aria-controls', form.id );
	toggle.setAttribute( 'aria-expanded', isShown( form ) ? 'true' : 'false' );

	toggle.addEventListener( 'click', () => {
		toggle.setAttribute(
			'aria-expanded',
			'true' === toggle.getAttribute( 'aria-expanded' ) ? 'false' : 'true'
		);
	} );

	toggle.addEventListener( 'keydown', ( event ) => {
		if ( ' ' === event.key ) {
			event.preventDefault();
			toggle.click();
		}
	} );
}

function initCheckoutLoginToggle() {
	const toggle = document.querySelector( 'a.showlogin' );
	const form = document.querySelector( 'form.woocommerce-form-login.login' );

	if ( toggle && form ) {
		wireLoginToggle( toggle, form );
	}
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener(
			'DOMContentLoaded',
			initCheckoutLoginToggle
		);
	} else {
		initCheckoutLoginToggle();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { FORM_ID, isShown, wireLoginToggle };
}
