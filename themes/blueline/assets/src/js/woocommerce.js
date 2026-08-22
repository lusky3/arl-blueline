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

if ( 'loading' === document.readyState ) {
	document.addEventListener(
		'DOMContentLoaded',
		fixCheckoutFieldDescriptionAriaHidden
	);
} else {
	fixCheckoutFieldDescriptionAriaHidden();
}
