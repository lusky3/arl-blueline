/**
 * Blueline — WooCommerce checkout field description accessibility fix.
 *
 * Checkout-field guidance lives in a
 * `description` rather than a placeholder (which truncates and disappears
 * once the field has a value) -- see inc/woocommerce.php's
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
 * The checkout plugin's selectWoo fields render comboboxes named only
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

// The same Checkout Field Editor Pro selects render on the checkout and on the
// account's Edit Account / Addresses forms, so every one of them needs naming.
const SELECT2_FORMS_SELECTOR =
	'form.checkout, .woocommerce-MyAccount-content form';

/**
 * Keep one form's select2 comboboxes named.
 *
 * @param {Element} form Form to watch.
 * @param {Window}  win  Window providing MutationObserver and setTimeout.
 */
function watchSelect2Labels( form, win ) {
	let queued = false;
	const run = () => {
		queued = false;
		labelSelect2Fields( form );
	};
	// selectWoo initialises after this script and re-renders on open/close.
	new win.MutationObserver( () => {
		if ( ! queued ) {
			queued = true;
			win.setTimeout( run, 0 );
		}
	} ).observe( form, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'aria-expanded' ],
	} );
	run();
}

/**
 * @param {Document} doc Document holding the forms.
 * @param {Window}   win Window providing MutationObserver and setTimeout.
 */
function initSelect2Labels( doc, win ) {
	if ( 'undefined' === typeof win.MutationObserver ) {
		return;
	}
	doc.querySelectorAll( SELECT2_FORMS_SELECTOR ).forEach( ( form ) =>
		watchSelect2Labels( form, win )
	);
}

/**
 * Checkout Field Editor Pro prints its section titles on Edit Account as <h4>s
 * straight under the page's <h1>, skipping two levels. The tag cannot be
 * changed from here, so give them the level they should have.
 *
 * @param {Element} root Where to look.
 */
function levelAccountSectionHeadings( root ) {
	root.querySelectorAll(
		'.woocommerce-MyAccount-content .arl_sectiontitle'
	).forEach( ( title ) => {
		if ( title.hasAttribute( 'aria-level' ) ) {
			return;
		}
		title.setAttribute( 'role', 'heading' );
		title.setAttribute( 'aria-level', '2' );
	} );
}

/**
 * The Edit Account fields WooCommerce stars as required carry no `required`
 * attribute, so a blank one submits and fails only on the server, with no
 * message beside the field. Plain inputs only: a select2-backed <select> is
 * hidden from the user, and the browser cannot show its validation bubble.
 *
 * @param {Element} root Where to look.
 */
function markStarredFieldsRequired( root ) {
	root.querySelectorAll( '.woocommerce-EditAccountForm .form-row' ).forEach(
		( row ) => {
			if ( ! row.querySelector( 'label abbr.required' ) ) {
				return;
			}
			const field = row.querySelector(
				'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), textarea'
			);
			if ( field && ! field.hasAttribute( 'required' ) ) {
				field.setAttribute( 'required', '' );
				field.setAttribute( 'aria-required', 'true' );
			}
		}
	);
}

const ADDRESS_AUTOCOMPLETE = {
	address_1: 'address-line1',
	address_2: 'address-line2',
};

/**
 * The checkout plugin sets autocomplete="off" on the street address lines,
 * which defeats the browser's address fill on the form that most needs it.
 *
 * @param {Element} root Where to look.
 */
function fixAddressAutocomplete( root ) {
	root.querySelectorAll( 'input[autocomplete="off"]' ).forEach( ( input ) => {
		const match = /^(?:billing|shipping)_(address_[12])$/.exec(
			input.id || ''
		);
		if ( match ) {
			input.setAttribute(
				'autocomplete',
				ADDRESS_AUTOCOMPLETE[ match[ 1 ] ]
			);
		}
	} );
}

function initWooCommerceEnhancements() {
	fixCheckoutFieldDescriptionAriaHidden();
	fixAddressAutocomplete( document );
	levelAccountSectionHeadings( document );
	markStarredFieldsRequired( document );
	initSelect2Labels( document, window );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener(
			'DOMContentLoaded',
			initWooCommerceEnhancements
		);
	} else {
		initWooCommerceEnhancements();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = {
		select2LabelledBy,
		labelSelect2Fields,
		SELECT2_FORMS_SELECTOR,
		initSelect2Labels,
		levelAccountSectionHeadings,
		markStarredFieldsRequired,
		fixAddressAutocomplete,
	};
}
