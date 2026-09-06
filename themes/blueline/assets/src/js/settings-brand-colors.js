/**
 * Live contrast readouts and colour-input syncing for the Brand Colors
 * fields on the Appearance settings tab (inc/settings/page.php's `color`
 * field type).
 *
 * The ratio/luminance math below is a DELIBERATE, small, self-contained
 * DUPLICATE of blueline_relative_luminance()/blueline_contrast_ratio()
 * (inc/team-colors.php) — same trade-off settings-occasions.js's own
 * identical duplicate documents: this is a thin admin-UI convenience
 * recomputing what PHP already computed at render time, not a security
 * boundary, so importing build tooling into runtime admin JS for a
 * formula this small would be the wrong direction.
 */

function blBrandColorLuminance( hex ) {
	const clean = hex.replace( '#', '' );
	const rgb = [ 0, 2, 4 ].map(
		( i ) => parseInt( clean.substr( i, 2 ), 16 ) / 255
	);
	const linear = rgb.map( ( c ) =>
		c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 )
	);
	return 0.2126 * linear[ 0 ] + 0.7152 * linear[ 1 ] + 0.0722 * linear[ 2 ];
}

function blBrandColorContrastRatio( a, b ) {
	const la = blBrandColorLuminance( a );
	const lb = blBrandColorLuminance( b );
	const light = Math.max( la, lb );
	const dark = Math.min( la, lb );
	return ( light + 0.05 ) / ( dark + 0.05 );
}

function blBrandColorIsHex( value ) {
	return /^#[0-9a-fA-F]{6}$/.test( value );
}

function hexForRow( row ) {
	const hexInput = row.querySelector( '[data-bl-brand-color-hex]' );
	if ( ! hexInput ) {
		return '';
	}
	const value = hexInput.value.trim();
	return blBrandColorIsHex( value )
		? value
		: hexInput.getAttribute( 'placeholder' ) || '';
}

function currentHexForToken( tokenKey ) {
	const input = document.querySelector(
		`[data-bl-brand-color-hex][data-bl-brand-color-token="${ tokenKey }"]`
	);
	if ( ! input ) {
		return '';
	}
	return hexForRow( input.closest( 'tr' ) );
}

function updateReadout( row ) {
	const hexInput = row.querySelector( '[data-bl-brand-color-hex]' );
	const readout = row.querySelector( '[data-bl-brand-color-contrast]' );
	if ( ! hexInput || ! readout ) {
		return;
	}

	const tokenKey = hexInput.getAttribute( 'data-bl-brand-color-token' );
	const rules = window.blSettingsBrandColorRules || {};
	const tokenRules = rules[ tokenKey ] || [];
	const candidate = hexForRow( row );

	const parts = tokenRules.map( ( rule ) => {
		const otherHex = rule.otherTokenKey
			? currentHexForToken( rule.otherTokenKey )
			: rule.otherDefaultHex;
		const resolvedOther = otherHex || rule.otherDefaultHex;
		const ratio = blBrandColorContrastRatio( candidate, resolvedOther );

		let passes = true;
		if ( null !== rule.min && undefined !== rule.min ) {
			passes = ratio >= rule.min;
		} else if ( null !== rule.max && undefined !== rule.max ) {
			passes = ratio <= rule.max;
		}

		return { description: rule.description, ratio, passes };
	} );

	// Built as DOM nodes with textContent (rather than interpolated into an
	// innerHTML string) so `part.description` — sourced from
	// tools/contrast-rules.json today, but not guaranteed to stay that way
	// forever — can never be parsed as markup.
	readout.textContent = '';

	parts.forEach( ( part, index ) => {
		if ( index > 0 ) {
			readout.appendChild( document.createElement( 'br' ) );
		}

		const span = document.createElement( 'span' );
		span.className = part.passes ? 'bl-contrast-pass' : 'bl-contrast-fail';

		// A text label alongside the color coding so pass/fail doesn't rely
		// on color alone (WCAG 1.4.1) — kept in sync with the equivalent
		// PHP markup in inc/settings/page.php's
		// blueline_settings_render_color_field().
		const status = part.passes ? 'Pass' : 'Fail';
		span.textContent = `${ status } — ${
			part.description
		}: ${ part.ratio.toFixed( 2 ) }:1`;
		readout.appendChild( span );
	} );
}

function updateAllRows() {
	document
		.querySelectorAll( '[data-bl-brand-color-hex]' )
		.forEach( ( hexInput ) => {
			const row = hexInput.closest( 'tr' );
			if ( row ) {
				updateReadout( row );
			}
		} );
}

function init() {
	document
		.querySelectorAll( '[data-bl-brand-color-hex]' )
		.forEach( ( hexInput ) => {
			const row = hexInput.closest( 'tr' );
			const picker = row
				? row.querySelector( '[data-bl-brand-color-picker]' )
				: null;

			hexInput.addEventListener( 'input', () => {
				if ( picker && blBrandColorIsHex( hexInput.value.trim() ) ) {
					picker.value = hexInput.value.trim();
				}
				updateAllRows();
			} );

			if ( picker ) {
				picker.addEventListener( 'input', () => {
					hexInput.value = picker.value;
					updateAllRows();
				} );
			}
		} );

	updateAllRows();
}

if ( 'undefined' !== typeof document ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}

if ( 'undefined' !== typeof module && module.exports ) {
	module.exports = {
		blBrandColorLuminance,
		blBrandColorContrastRatio,
		blBrandColorIsHex,
	};
}
