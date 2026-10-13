/**
 * Contact Form 7: name, autocomplete and keyboard hints for the fields.
 *
 * The form's markup lives in the CF7 editor, not in this theme, and the
 * stock "Name" field is two inputs (first, last) inside ONE <label> that
 * carry only a placeholder each -- so the second input has no accessible
 * name at all, and the first is just called "Name". None of the inputs say
 * what they hold, either, so browsers cannot offer to fill them (WCAG 1.3.5).
 * This closes both gaps from the field's own name/type/placeholder, for any
 * CF7 form, so a form edited later keeps working without a code change.
 *
 * Progressive enhancement: without this script the form still submits; only
 * the extra hints are missing.
 *
 * Vanilla ES2017+, no dependencies.
 */

const FIELD_SELECTOR =
	'.wpcf7-form input:not([type="hidden"]):not([type="submit"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]), .wpcf7-form textarea';

// First match wins, so the specific name parts come before the generic ones.
const AUTOCOMPLETE_BY_NAME = [
	[ /(?:^|[-_])(?:first|given)[-_]?name$/i, 'given-name' ],
	[ /(?:^|[-_])(?:last|family|sur)[-_]?name$/i, 'family-name' ],
	[ /e-?mail/i, 'email' ],
	[ /(?:phone|(?:^|[-_])tel(?:$|[-_]))/i, 'tel' ],
	[ /^(?:your[-_])?(?:full[-_])?name$/i, 'name' ],
];

/**
 * The autocomplete token a field should carry, judged from its name and type.
 *
 * @param {string} name Field name attribute.
 * @param {string} type Field type attribute.
 * @return {string|null} Token, or null when nothing reliable applies.
 */
function autocompleteFor( name, type ) {
	if ( 'email' === type ) {
		return 'email';
	}
	if ( 'tel' === type ) {
		return 'tel';
	}
	const found = AUTOCOMPLETE_BY_NAME.find( ( [ pattern ] ) =>
		pattern.test( name || '' )
	);
	return found ? found[ 1 ] : null;
}

/**
 * Whether the field already has something a screen reader can name it by.
 *
 * @param {Element}  input Field.
 * @param {Document} doc   Owner document.
 * @return {boolean} True when it is named.
 */
function hasOwnName( input, doc ) {
	if (
		input.hasAttribute( 'aria-label' ) ||
		input.hasAttribute( 'aria-labelledby' )
	) {
		return true;
	}
	return Boolean(
		input.id && doc.querySelector( `label[for="${ input.id }"]` )
	);
}

/**
 * The text a <label> shows itself, leaving out the controls it wraps.
 *
 * @param {Element} label Label element.
 * @return {string} Normalised text.
 */
function ownLabelText( label ) {
	return Array.from( label.childNodes )
		.filter( ( node ) => 3 === node.nodeType )
		.map( ( node ) => node.textContent )
		.join( ' ' )
		.replace( /\s+/g, ' ' )
		.trim();
}

/**
 * Name an input that has none. Two or more inputs in one label (First + Last
 * under "Name") each get "<placeholder> <label>" -- "First name", "Last name";
 * a lone placeholder-only input is named by its placeholder.
 *
 * @param {Element}  input Field.
 * @param {Document} doc   Owner document.
 */
function nameField( input, doc ) {
	const placeholder = ( input.getAttribute( 'placeholder' ) || '' ).trim();
	if ( ! placeholder || hasOwnName( input, doc ) ) {
		return;
	}

	const label = input.closest( 'label' );
	if ( ! label ) {
		input.setAttribute( 'aria-label', placeholder );
		return;
	}

	const siblings = label.querySelectorAll( 'input, select, textarea' ).length;
	const text = ownLabelText( label );
	if ( siblings > 1 && text ) {
		input.setAttribute(
			'aria-label',
			`${ placeholder } ${ text.toLowerCase() }`
		);
	}
}

/**
 * @param {Document} doc Document holding the forms.
 */
function enhanceContactForms( doc ) {
	doc.querySelectorAll( FIELD_SELECTOR ).forEach( ( input ) => {
		const type = ( input.getAttribute( 'type' ) || 'text' ).toLowerCase();
		const token = input.hasAttribute( 'autocomplete' )
			? null
			: autocompleteFor( input.getAttribute( 'name' ), type );

		if ( token ) {
			input.setAttribute( 'autocomplete', token );
			if ( 'text' === type && 'email' === token ) {
				input.setAttribute( 'inputmode', 'email' );
			}
			if ( 'text' === type && 'tel' === token ) {
				input.setAttribute( 'inputmode', 'tel' );
			}
		}

		nameField( input, doc );
	} );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', () =>
			enhanceContactForms( document )
		);
	} else {
		enhanceContactForms( document );
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { autocompleteFor, enhanceContactForms, nameField };
}
