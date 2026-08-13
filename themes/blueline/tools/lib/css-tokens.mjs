/**
 * Normalise a CSS declaration value for comparison: expand 3-digit hex to 6,
 * lowercase hex, collapse runs of whitespace. Mirrors the behaviour the
 * editor.css parity check has always relied on -- style.css writes
 * `--bl-white: #FFFFFF` while editor.css writes `#fff`, and those must
 * compare equal.
 *
 * @param {string} value Raw declaration value.
 * @return {string} Normalised value.
 */
export function normalizeValue( value ) {
	const trimmed = value.trim();
	const hex3 = trimmed.match( /^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/ );
	if ( hex3 ) {
		return `#${ hex3[ 1 ] }${ hex3[ 1 ] }${ hex3[ 2 ] }${ hex3[ 2 ] }${ hex3[ 3 ] }${ hex3[ 3 ] }`.toLowerCase();
	}
	if ( /^#[0-9a-fA-F]{6}$/.test( trimmed ) ) {
		return trimmed.toLowerCase();
	}
	return trimmed.replace( /\s+/g, ' ' );
}
