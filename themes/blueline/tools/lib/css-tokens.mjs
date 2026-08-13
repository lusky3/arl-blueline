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

/**
 * Strip CSS block comments. Done before any structural scanning so that a
 * `:root` or a brace appearing in prose cannot be mistaken for real syntax --
 * assets/src/css/editor.css genuinely has ":root" in a comment 27 lines above
 * its actual :root rule, and the previous indexOf-based scan matched that one.
 *
 * @param {string} source CSS source text.
 * @return {string} Source with comments replaced by equivalent-length padding.
 */
function stripComments( source ) {
	return source.replace( /\/\*[\s\S]*?\*\//g, ( match ) =>
		match.replace( /[^\n]/g, ' ' )
	);
}

/**
 * Extract every `--bl-*` declaration from the first `:root` rule (optionally a
 * grouped selector such as `:root, .editor-styles-wrapper`).
 *
 * @param {string} source CSS source text.
 * @return {Map<string,string>} Token name to raw, trimmed value.
 */
export function extractRootTokens( source ) {
	const clean = stripComments( source );

	const selector = /(^|[};])\s*:root\b[^{]*\{/.exec( clean );
	if ( ! selector ) {
		throw new Error( 'no :root rule found' );
	}

	const braceStart = selector.index + selector[ 0 ].length - 1;
	let depth = 0;
	let end = braceStart;
	for ( ; end < clean.length; end++ ) {
		if ( clean[ end ] === '{' ) {
			depth++;
		} else if ( clean[ end ] === '}' ) {
			depth--;
			if ( depth === 0 ) {
				break;
			}
		}
	}

	const block = clean.slice( braceStart + 1, end );
	const tokens = new Map();
	const declaration = /(--bl-[\w-]+)\s*:\s*([^;]+);/g;
	let match;
	while ( ( match = declaration.exec( block ) ) !== null ) {
		tokens.set( match[ 1 ], match[ 2 ].trim() );
	}
	return tokens;
}
