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
 * checkout. WooCommerce's account-area forms (login/register/lost password,
 * Edit Account, Addresses) are rendered by WooCommerce core and Checkout
 * Field Editor Pro, not through any template or filter this theme owns for
 * errors, so there is no server-side hook to add the association.
 *
 * This is therefore a best-effort, client-side association instead:
 * WooCommerce's own error text is the only signal available on page load
 * for WHICH field failed, so each error message is matched to a field by,
 * in order:
 *
 *   1. a `data-id` on the error item equal to the field's id or name
 *      (WooCommerce adds it when the error was raised for a specific field);
 *   2. otherwise the field's own `name` attribute, or its visible label text,
 *      found inside the error's text -- "First name is a required field."
 *      matches the "First name" label. When several fields match, only the
 *      one with the longest match is used, so "Company name" is not confused
 *      with a field labelled just "Name".
 *
 * A WooCommerce update that changes its exact English wording, or a
 * non-English site, degrades this back to the baseline (the error is still
 * visible and still `role="alert"`) rather than mismatching a field, since a
 * field is only ever touched when something about it is actually found in the
 * error's text.
 *
 * Reuses WooCommerce's OWN `woocommerce-invalid` convention for the visible
 * border (rather than inventing a second, parallel one) precisely so the
 * existing checkout-only CSS rule above now also covers this case, with no
 * new selector needed.
 *
 * Vanilla ES2017+, no dependencies.
 */

// Scoped to the account-area forms -- WooCommerce's checkout already gets its
// own, JS-driven `woocommerce-invalid` handling (checkout.js), and this must
// not double up with or fight that on a page this script also loads on.
const FORM_SELECTOR = [
	'form.woocommerce-form-login',
	'form.woocommerce-form-register',
	'form.woocommerce-ResetPassword',
	'form.woocommerce-EditAccountForm',
].join( ', ' );

// A name or label shorter than this is too generic/likely to false-match
// unrelated words in the error sentence (a nonce field's name, a checkbox
// named "rememberme" partially overlapping "remember" in unrelated text,
// etc.) to search for safely.
const MIN_KEY_LENGTH = 4;

/**
 * The form this page's error list belongs to, if it is an account form.
 *
 * @param {Document} doc Document to search.
 * @return {Element|null} The form, or null.
 */
function findForm( doc ) {
	const form = doc.querySelector( FORM_SELECTOR );
	if ( form ) {
		return form;
	}

	// The address form is a bare <form> around `.woocommerce-address-fields`.
	const address = doc.querySelector( '.woocommerce-address-fields' );
	return ( address && address.closest( 'form' ) ) || null;
}

/**
 * A label's text reduced to what an error message would repeat: lowercase,
 * with the required marker, any "(optional)" style hint and punctuation gone.
 *
 * @param {string} text Label text.
 * @return {string} Normalised text.
 */
function labelKey( text ) {
	return ( text || '' )
		.replace( /\([^)]*\)/g, ' ' )
		.replace( /[*:]/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim()
		.toLowerCase();
}

/**
 * Every search key a field can be matched by: its name and its label text.
 *
 * @param {Element}               field  The form control.
 * @param {Object<string,string>} labels Normalised label text, by control id.
 * @return {string[]} Keys long enough to match on.
 */
function fieldKeys( field, labels ) {
	const name = ( field.name || '' )
		.toLowerCase()
		.replace( /[_-]+/g, ' ' )
		.trim();

	return [ name, labels[ field.id ] || '' ].filter(
		( key ) => key.length >= MIN_KEY_LENGTH
	);
}

/**
 * Mark one field as the subject of an error item.
 *
 * @param {Element} field The form control.
 * @param {Element} item  The error's <li>.
 */
function markInvalid( field, item ) {
	field.setAttribute( 'aria-invalid', 'true' );

	// Preserve any aria-describedby the field already carries rather than
	// clobbering it, and never list the same message twice.
	const existing = ( field.getAttribute( 'aria-describedby' ) || '' )
		.split( /\s+/ )
		.filter( Boolean );
	if ( ! existing.includes( item.id ) ) {
		existing.push( item.id );
	}
	field.setAttribute( 'aria-describedby', existing.join( ' ' ) );

	const row = field.closest( '.form-row' );
	if ( row ) {
		row.classList.add( 'woocommerce-invalid' );
	}
}

/**
 * Tie each error message to the field(s) it names.
 *
 * @param {Element}   form  The account form.
 * @param {Element[]} items The error list's <li> elements.
 */
function associateErrors( form, items ) {
	const fields = Array.from(
		form.querySelectorAll( 'input, select, textarea' )
	);
	const labels = {};
	form.querySelectorAll( 'label[for]' ).forEach( ( label ) => {
		labels[ label.htmlFor ] = labelKey( label.textContent );
	} );

	items.forEach( ( item, index ) => {
		const errorText = ( item.textContent || '' ).toLowerCase();
		const dataId = item.getAttribute( 'data-id' );

		let matches = dataId
			? fields.filter(
					( field ) => field.id === dataId || field.name === dataId
				)
			: [];

		if ( ! matches.length ) {
			const scored = fields
				.map( ( field ) => ( {
					field,
					length: Math.max(
						0,
						...fieldKeys( field, labels )
							.filter( ( key ) => errorText.includes( key ) )
							.map( ( key ) => key.length )
					),
				} ) )
				.filter( ( entry ) => entry.length > 0 );
			const best = Math.max(
				0,
				...scored.map( ( entry ) => entry.length )
			);
			matches = scored
				.filter( ( entry ) => entry.length === best )
				.map( ( entry ) => entry.field );
		}

		if ( ! matches.length ) {
			return;
		}

		if ( ! item.id ) {
			item.id = 'bl-wc-account-form-error-' + index;
		}

		matches.forEach( ( field ) => markInvalid( field, item ) );
	} );
}

function initAccountFormErrors( doc ) {
	const form = findForm( doc );
	const errorList = form && doc.querySelector( '.woocommerce-error' );

	if ( ! errorList ) {
		return;
	}

	associateErrors( form, Array.from( errorList.querySelectorAll( 'li' ) ) );
}

if ( typeof document !== 'undefined' ) {
	initAccountFormErrors( document );
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { associateErrors, findForm, labelKey };
}
