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
 * Validate the shape of one rule from contrast-rules.json.
 *
 * This is a cross-language contract: a PHP validator (P1) and a panel readout
 * (P2) will each independently read the same JSON, and Tasks 6-8 add 18 more
 * rules to this exact file. A malformed rule must fail loudly, at the point
 * every consumer shares, rather than silently doing the wrong thing (honouring
 * only `max` when both bounds are present) or producing `undefined` in output
 * (when neither bound, or `id`/`fg`/`bg`, is present).
 *
 * @param {Object} rule Rule object as parsed from contrast-rules.json.
 * @throws {Error} If the rule is missing a required field, or carries both
 *                 `min` and `max`, or neither.
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

	if ( typeof rule.fg !== 'string' || ! rule.fg ) {
		throw new Error( `contrast rule "${ rule.id }" is missing a string "fg" token` );
	}

	if ( typeof rule.bg !== 'string' || ! rule.bg ) {
		throw new Error( `contrast rule "${ rule.id }" is missing a string "bg" token` );
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

	const fg = resolveColorToken( tokens, rule.fg );
	const bg = resolveColorToken( tokens, rule.bg );
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
