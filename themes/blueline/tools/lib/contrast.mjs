import { resolveColorToken } from './css-tokens.mjs';

const channel = ( c ) => {
	const s = c / 255;
	return s <= 0.04045 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
};

/**
 * WCAG relative luminance of a `#rrggbb` colour.
 *
 * @param {string} hex Lowercase 6-digit hex.
 * @return {number} Relative luminance, 0..1.
 */
export function luminance( hex ) {
	const n = parseInt( hex.slice( 1 ), 16 );
	return (
		0.2126 * channel( ( n >> 16 ) & 255 ) +
		0.7152 * channel( ( n >> 8 ) & 255 ) +
		0.0722 * channel( n & 255 )
	);
}

/**
 * WCAG contrast ratio between two colours.
 *
 * @param {string} a Hex colour.
 * @param {string} b Hex colour.
 * @return {number} Ratio, 1..21.
 */
export function contrastRatio( a, b ) {
	const x = luminance( a );
	const y = luminance( b );
	const hi = Math.max( x, y );
	const lo = Math.min( x, y );
	return ( hi + 0.05 ) / ( lo + 0.05 );
}

/**
 * Check whether a rule endpoint (`fg` or `bg`) is well-formed: either a
 * non-empty token-name string, or a mix descriptor `{ mix: [ string, number,
 * string ] }` whose percentage is a number in [0, 100].
 *
 * @param {*} value Candidate endpoint value.
 * @return {boolean} True if `value` is a valid endpoint shape.
 */
function isWellFormedEndpoint( value ) {
	if ( typeof value === 'string' ) {
		return value.length > 0;
	}

	if ( value && typeof value === 'object' && Array.isArray( value.mix ) ) {
		const [ a, percent, b ] = value.mix;
		return (
			value.mix.length === 3 &&
			typeof a === 'string' &&
			a.length > 0 &&
			typeof b === 'string' &&
			b.length > 0 &&
			typeof percent === 'number' &&
			! Number.isNaN( percent ) &&
			percent >= 0 &&
			percent <= 100
		);
	}

	return false;
}

/**
 * Validate the shape of one rule from contrast-rules.json.
 *
 * This is a cross-language contract: a PHP validator (P1) and a panel readout
 * (P2) will each independently read the same JSON, and Tasks 6-8 add 18 more
 * rules to this exact file. A malformed rule must fail loudly, at the point
 * every consumer shares, rather than silently doing the wrong thing (honouring
 * only `max` when both bounds are present) or producing `undefined` in output
 * (when neither bound, or `id`/`fg`/`bg`, is present).
 *
 * An endpoint (`fg` or `bg`) is valid when it is either a non-empty token-name
 * string, or a mix descriptor `{ mix: [ tokenA, percent, tokenB ] }` with
 * `percent` a number between 0 and 100 inclusive.
 *
 * @param {Object} rule Rule object as parsed from contrast-rules.json.
 * @throws {Error} If the rule is missing a required field, carries a
 *                 malformed `fg`/`bg` endpoint, or carries both `min` and
 *                 `max`, or neither.
 */
export function validateRule( rule ) {
	if ( ! rule || typeof rule !== 'object' ) {
		throw new Error( `contrast rule is not an object: ${ JSON.stringify( rule ) }` );
	}

	if ( typeof rule.id !== 'string' || ! rule.id ) {
		throw new Error(
			`contrast rule ${ JSON.stringify( rule ) } is missing a string "id"`
		);
	}

	for ( const key of [ 'fg', 'bg' ] ) {
		if ( isWellFormedEndpoint( rule[ key ] ) ) {
			continue;
		}
		if ( rule[ key ] === undefined || rule[ key ] === null || rule[ key ] === '' ) {
			throw new Error(
				`contrast rule "${ rule.id }" is missing a string "${ key }" token`
			);
		}
		throw new Error(
			`contrast rule "${ rule.id }" has a malformed "${ key }" endpoint: expected a non-empty string token name or a { mix: [ tokenA, percent, tokenB ] } descriptor with percent in 0..100, got ${ JSON.stringify( rule[ key ] ) }`
		);
	}

	const hasMin = typeof rule.min === 'number';
	const hasMax = typeof rule.max === 'number';

	if ( hasMin && hasMax ) {
		throw new Error(
			`contrast rule "${ rule.id }" carries both "min" and "max" -- exactly one is required, not both`
		);
	}

	if ( ! hasMin && ! hasMax ) {
		throw new Error(
			`contrast rule "${ rule.id }" carries neither "min" nor "max" as a number -- exactly one is required`
		);
	}
}

/**
 * Emulate `color-mix(in srgb, a p%, b)`.
 *
 * CSS mixes in the given colour space without gamma-decoding for srgb, so this
 * is a plain per-channel linear interpolation on the 0-255 values, rounded the
 * way browsers round.
 *
 * NOTE: P1's PHP validator must implement this identically. A parity test
 * covers it; if this changes, that test must change with it.
 *
 * @param {string} a       Hex colour mixed in at `percent`.
 * @param {number} percent 0-100.
 * @param {string} b       Hex colour making up the remainder.
 * @return {string} Lowercase `#rrggbb`.
 */
export function mixSrgb( a, percent, b ) {
	const weight = percent / 100;
	const na = parseInt( a.slice( 1 ), 16 );
	const nb = parseInt( b.slice( 1 ), 16 );
	const chan = ( shift ) => {
		const ca = ( na >> shift ) & 255;
		const cb = ( nb >> shift ) & 255;
		return Math.round( ca * weight + cb * ( 1 - weight ) );
	};
	const hex = ( v ) => v.toString( 16 ).padStart( 2, '0' );
	return `#${ hex( chan( 16 ) ) }${ hex( chan( 8 ) ) }${ hex( chan( 0 ) ) }`;
}

/**
 * Resolve a rule endpoint: either a token name, or a mix descriptor.
 *
 * @param {string|Object}      endpoint Token name or `{ mix: [ a, pct, b ] }`.
 * @param {Map<string,string>} tokens   Token map.
 * @return {string} Hex colour.
 */
function resolveEndpoint( endpoint, tokens ) {
	if ( typeof endpoint === 'string' ) {
		return resolveColorToken( tokens, endpoint );
	}
	if ( endpoint && Array.isArray( endpoint.mix ) ) {
		const [ a, percent, b ] = endpoint.mix;
		return mixSrgb(
			resolveColorToken( tokens, a ),
			percent,
			resolveColorToken( tokens, b )
		);
	}
	throw new Error( `unsupported rule endpoint: ${ JSON.stringify( endpoint ) }` );
}

/**
 * Evaluate one rule from contrast-rules.json against a token map.
 *
 * A rule carries either `min` (the usual case: this pairing must be at least
 * this readable) or `max` (an inverse guard: this pairing must stay UNreadable,
 * so that a fill-only token cannot quietly become usable as text).
 *
 * @param {Object}             rule   Rule object.
 * @param {Map<string,string>} tokens Token map.
 * @return {{id:string,description:string,ok:boolean,ratio:number,bound:string}}
 */
export function evaluateRule( rule, tokens ) {
	validateRule( rule );

	const fg = resolveEndpoint( rule.fg, tokens );
	const bg = resolveEndpoint( rule.bg, tokens );
	const ratio = contrastRatio( fg, bg );
	const ok =
		rule.max !== undefined ? ratio < rule.max : ratio >= rule.min;

	return {
		id: rule.id,
		description: rule.description,
		ok,
		ratio,
		bound: rule.max !== undefined ? `max ${ rule.max }` : `min ${ rule.min }`,
	};
}
