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

/**
 * Resolve a token to a literal value, following var() references.
 *
 * style.css defines four tokens by reference rather than by literal
 * (--bl-surface, --bl-surface-sunken, --bl-surface-inverse, --bl-focus).
 * Contrast rules need the resolved value, and the previous hex-only reader
 * threw on all four -- which is why none of them could ever appear in a
 * rule.
 *
 * @param {Map<string,string>} tokens Map from extractRootTokens().
 * @param {string}             name   Token name, e.g. '--bl-surface'.
 * @param {Set<string>}        [seen] Internal: names on the current resolution
 *                                    path (ancestors of `name`), not every
 *                                    token ever visited -- a diamond such as
 *                                    A -> B -> D and A -> C -> D is two
 *                                    independent, non-circular paths, so D
 *                                    must not still look "seen" once the
 *                                    B branch has finished with it.
 * @return {string} Fully-resolved, normalised value.
 */
export function resolveToken( tokens, name, seen = new Set() ) {
	if ( seen.has( name ) ) {
		throw new Error( `var() reference cycle at ${ name }` );
	}
	if ( ! tokens.has( name ) ) {
		throw new Error( `token ${ name } is not defined` );
	}
	seen.add( name );

	const resolved = tokens
		.get( name )
		.replace(
			/var\(\s*(--[\w-]+)\s*(?:,\s*([^)]+))?\)/g,
			( _match, ref, fallback ) => {
				if ( tokens.has( ref ) ) {
					return resolveToken( tokens, ref, seen );
				}
				if ( fallback !== undefined ) {
					return fallback.trim();
				}
				throw new Error( `token ${ ref } is not defined` );
			}
		);

	// Backtrack: `name` is only an ancestor while its own recursion is on the
	// stack. Removing it here lets a sibling branch re-visit the same
	// downstream token without tripping the cycle check above.
	seen.delete( name );

	return normalizeValue( resolved );
}

/**
 * Resolve a token that must be a plain 6-digit hex colour.
 *
 * @param {Map<string,string>} tokens Map from extractRootTokens().
 * @param {string}             name   Token name.
 * @return {string} Lowercase `#rrggbb`.
 */
export function resolveColorToken( tokens, name ) {
	const value = resolveToken( tokens, name );
	if ( ! /^#[0-9a-f]{6}$/.test( value ) ) {
		throw new Error( `token ${ name } resolved to "${ value }", not a hex colour` );
	}
	return value;
}
