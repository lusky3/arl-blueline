/**
 * Blueline — WooCommerce checkout field description accessibility fix.
 *
 * Live-review findings 1 & 2 move checkout-field guidance out of a
 * placeholder (which truncates and disappears once the field has a value)
 * and into a `description` -- see inc/woocommerce.php's
 * blueline_wc_checkout_field_guidance(), and woocommerce.css's own comment
 * on the matching `.woocommerce-input-wrapper .description` visibility fix
 * this pairs with (WooCommerce core ships that element `display: none` by
 * default, with no theme or plugin JS anywhere that ever reveals it).
 *
 * That CSS fix alone would only help sighted users. WooCommerce Checkout
 * Field Editor Pro renders every field's description with `aria-hidden`
 * hard-coded to the literal string "true" (confirmed live: every
 * `.description` span this checkout renders, including ones this theme
 * never touches, carries `aria-hidden="true"`) on the SAME element each
 * field's `<input>` already points to via `aria-describedby`. Per how
 * browsers actually resolve an accessible description
 * (https://www.w3.org/TR/accname-1.2/), `aria-hidden="true"` on an
 * aria-describedby target removes it from the accessibility tree, which in
 * every browser tested here means the description text this theme just
 * made visible is announced to NO screen reader user at all -- the exact
 * opposite of the sighted-only fix this script exists to close.
 *
 * This is plugin-authored markup this theme cannot edit directly (see
 * inc/woocommerce.php's own file-level docblock on that boundary), so the
 * fix lives here as a small, one-time DOM correction rather than a
 * template override: flip `aria-hidden` to "false" (never removed
 * outright, in case something else genuinely depends on the attribute
 * existing) on every checkout field description, immediately, before a
 * screen reader user's assistive tech has any reason to have read the
 * element yet.
 */

function fixCheckoutFieldDescriptionAriaHidden() {
	document
		.querySelectorAll(
			'.woocommerce-input-wrapper .description[aria-hidden="true"]'
		)
		.forEach( ( description ) => {
			description.setAttribute( 'aria-hidden', 'false' );
		} );
}

/**
 * aria-labelledby for a select2 combobox: the field's own label first, then
 * whatever select2 already pointed at (its rendered-value span).
 *
 * @param {string|null} existing Current aria-labelledby value.
 * @param {string}      labelId  The field label's id.
 * @return {string} New aria-labelledby value.
 */
function select2LabelledBy( existing, labelId ) {
	const ids = ( existing || '' ).split( /\s+/ ).filter( Boolean );
	return ids.includes( labelId )
		? ids.join( ' ' )
		: [ labelId, ...ids ].join( ' ' );
}

/**
 * D-13: the checkout plugin's selectWoo fields render comboboxes named only
 * by their placeholder span (axe aria-input-field-name), and the multi-select
 * wrapper carries aria-expanded/aria-haspopup with no role (aria-allowed-attr).
 * Point each at its <label>, and drop the attributes the role-less wrapper
 * may not carry.
 *
 * @param {Element} root Where to look.
 */
function labelSelect2Fields( root ) {
	root.querySelectorAll( '.form-row .select2-selection' ).forEach(
		( selection ) => {
			const label = selection
				.closest( '.form-row' )
				.querySelector( 'label[for]' );
			if ( ! label ) {
				return;
			}
			if ( ! label.id ) {
				label.id = label.htmlFor + '-label';
			}
			// The combobox (or the multi-select's search field) plus the
			// role="textbox" value span inside a single select.
			[
				selection.matches( '[role="combobox"]' )
					? selection
					: selection.querySelector( '.select2-search__field' ),
				selection.querySelector( '[role="textbox"]' ),
			].forEach( ( named ) => {
				if ( named ) {
					named.setAttribute(
						'aria-labelledby',
						select2LabelledBy(
							named.getAttribute( 'aria-labelledby' ),
							label.id
						)
					);
				}
			} );
			if ( ! selection.hasAttribute( 'role' ) ) {
				selection.removeAttribute( 'aria-expanded' );
				selection.removeAttribute( 'aria-haspopup' );
			}
		}
	);
}

function initCheckoutSelect2Labels() {
	const form = document.querySelector( 'form.checkout' );
	if ( ! form || 'undefined' === typeof window.MutationObserver ) {
		return;
	}
	let queued = false;
	const run = () => {
		queued = false;
		labelSelect2Fields( form );
	};
	// selectWoo initialises after this script and re-renders on open/close.
	new window.MutationObserver( () => {
		if ( ! queued ) {
			queued = true;
			window.setTimeout( run, 0 );
		}
	} ).observe( form, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'aria-expanded' ],
	} );
	run();
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', () => {
			fixCheckoutFieldDescriptionAriaHidden();
			initCheckoutSelect2Labels();
		} );
	} else {
		fixCheckoutFieldDescriptionAriaHidden();
		initCheckoutSelect2Labels();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { select2LabelledBy, labelSelect2Fields };
}
