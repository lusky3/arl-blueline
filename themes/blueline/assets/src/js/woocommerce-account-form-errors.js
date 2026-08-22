/**
 * Blueline WooCommerce account form errors: associate an unassociated
 * `.woocommerce-error` list with the field it actually describes.
 *
 * A11y finding: submitting /account's login form empty re-renders the page
 * with a focused `role="alert"` banner ("Error: Username is required.") --
 * but the `username` field itself gets no `aria-invalid`, no
 * `aria-describedby` pointing back at that message, and no visible border
 * change. The checkout's own fields already get exactly that treatment
 * (`.woocommerce .woocommerce-invalid .input-text`, woocommerce.css) because
 * WooCommerce's own checkout.js toggles a `woocommerce-invalid` class on a
 * field's `.form-row` wrapper -- but that script only ever runs on
 * checkout. WooCommerce's account-area forms (login/register/lost
 * password, myaccount/form-login.php and friends) are hand-rolled markup
 * in WooCommerce core itself, not built through the woocommerce_form_field()
 * API a woocommerce_form_field_args filter could adjust, and this theme
 * does not override that template at all (see the woocommerce/ directory:
 * only checkout templates are theme-owned here, and Task 9's own file-level
 * comment in woocommerce.css is explicit that ported markup stays a port,
 * not a redesign) -- so there is no template-level or filter-level hook to
 * add the association server-side.
 *
 * This is therefore a best-effort, client-side association instead:
 * WooCommerce's own error text is the only signal available on page load
 * for WHICH field failed (the error list carries no reference to any
 * field id anywhere in the markup), so each error message is matched to a
 * field by looking for that field's own `name` attribute inside the
 * error's own text -- "username" is a substring of "Username is
 * required.", "password" of "Password is required.", and so on. A
 * WooCommerce update that changes its exact English wording, or a
 * non-English site, degrades this back to today's baseline (the error is
 * still visible and still `role="alert"`) rather than mismatching a field,
 * since a field is only ever touched when its own name is actually found
 * in the error's text.
 *
 * Reuses WooCommerce's OWN `woocommerce-invalid` convention for the visible
 * border (rather than inventing a second, parallel one) precisely so the
 * existing checkout-only CSS rule above now also covers this case, with no
 * new selector needed.
 *
 * Vanilla ES2017+, no dependencies.
 */
( function () {
	'use strict';

	// Scoped to the account-area forms this finding is actually about --
	// WooCommerce's checkout already gets its own, JS-driven
	// `woocommerce-invalid` handling (checkout.js), and this must not
	// double up with or fight that on a page this script also loads on.
	const form = document.querySelector(
		'form.woocommerce-form-login, form.woocommerce-form-register, form.woocommerce-ResetPassword'
	);

	if ( ! form ) {
		return;
	}

	const errorList = document.querySelector( '.woocommerce-error' );

	if ( ! errorList ) {
		return;
	}

	const errorItems = Array.prototype.slice.call(
		errorList.querySelectorAll( 'li' )
	);
	const fields = Array.prototype.slice.call(
		form.querySelectorAll( 'input, select, textarea' )
	);

	if ( ! errorItems.length || ! fields.length ) {
		return;
	}

	errorItems.forEach( function ( item, index ) {
		const errorText = ( item.textContent || '' ).toLowerCase();

		fields.forEach( function ( field ) {
			// A name shorter than this is too generic/likely to false-
			// match unrelated words in the error sentence (a nonce field's
			// name, a checkbox named "rememberme" partially overlapping
			// "remember" in unrelated text, etc.) to search for safely.
			const name = ( field.name || '' )
				.toLowerCase()
				.replace( /[_-]+/g, ' ' )
				.trim();

			if ( name.length < 4 || -1 === errorText.indexOf( name ) ) {
				return;
			}

			if ( ! item.id ) {
				item.id = 'bl-wc-account-form-error-' + index;
			}

			field.setAttribute( 'aria-invalid', 'true' );

			// Preserve any aria-describedby the field already carries
			// (WooCommerce itself sets none on these forms today, but a
			// future core change, or a plugin, might) rather than
			// clobbering it.
			const existingDescribedBy =
				field.getAttribute( 'aria-describedby' );
			field.setAttribute(
				'aria-describedby',
				existingDescribedBy
					? existingDescribedBy + ' ' + item.id
					: item.id
			);

			const row = field.closest( '.form-row' );
			if ( row ) {
				row.classList.add( 'woocommerce-invalid' );
			}
		} );
	} );
} )();
