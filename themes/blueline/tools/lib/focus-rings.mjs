/**
 * Focus-ring lint (QA pass: B-02/C-02/D-03). The two-tone indicator only
 * works when the halo box-shadow is actually painted, and three ways kept
 * dropping it: a component focus rule setting its own box-shadow, a rule
 * ringing a *different* element (`input:focus-visible ~ label`) with an
 * outline only, and `outline: none` with nothing in its place. Each such
 * rule must use var(--bl-focus-ring) / var(--bl-focus-ring-inset).
 */

const RING = /var\(\s*--bl-focus-ring(-inset)?\s*[,)]/;

/**
 * Every innermost `selector { declarations }` block, comments stripped.
 *
 * @param {string} css CSS source.
 * @return {Array<{selector: string, body: string}>} Rule blocks.
 */
export function extractRules( css ) {
	const src = css.replace( /\/\*[\s\S]*?\*\//g, '' );
	const rules = [];
	const re = /([^{}]+)\{([^{}]*)\}/g;
	let m;
	while ( ( m = re.exec( src ) ) !== null ) {
		rules.push( { selector: m[ 1 ].trim().replace( /\s+/g, ' ' ), body: m[ 2 ] } );
	}
	return rules;
}

/**
 * Last value declared for `prop` in a declaration block, or null.
 *
 * @param {string} body Declaration block.
 * @param {string} prop Property name.
 * @return {string|null} Value.
 */
function declared( body, prop ) {
	let value = null;
	for ( const decl of body.split( ';' ) ) {
		const i = decl.indexOf( ':' );
		if ( i > 0 && decl.slice( 0, i ).trim() === prop ) {
			value = decl.slice( i + 1 ).trim();
		}
	}
	return value;
}

/**
 * Problems in one stylesheet.
 *
 * @param {string}   css       CSS source.
 * @param {string[]} allowlist Selector substrings exempt from the `outline: none` rule.
 * @return {string[]} Human-readable problems (empty when clean).
 */
export function findFocusRingProblems( css, allowlist = [] ) {
	const problems = [];
	const rules = extractRules( css );

	// A resting box-shadow on a focusable element outranks base.css's bare
	// :focus-visible halo, so that element needs its own ringed focus rule.
	const ringed = new Set();
	for ( const { selector, body } of rules ) {
		const shadow = declared( body, 'box-shadow' );
		if ( shadow !== null && RING.test( shadow ) ) {
			selector.split( ',' ).forEach( ( s ) => ringed.add( s.trim().replace( /:focus-visible$/, '' ) ) );
		}
	}
	for ( const { selector, body } of rules ) {
		const shadow = declared( body, 'box-shadow' );
		if ( shadow === null || shadow === 'none' || /:(focus|hover|active)/.test( selector ) ) {
			continue;
		}
		for ( const part of selector.split( ',' ).map( ( s ) => s.trim() ) ) {
			const subject = part.split( /[\s>+~]+/ ).pop();
			if ( /^(a|button|input|select|textarea|summary)\b|\.button\b|#place_order/.test( subject ) && ! ringed.has( part ) ) {
				problems.push( `${ part }: resting box-shadow outranks the base halo -- add ${ part }:focus-visible { box-shadow: var(--bl-focus-ring), ... }` );
			}
		}
	}

	for ( const { selector, body } of rules ) {
		const parts = selector.split( ',' ).map( ( s ) => s.trim() ).filter( ( s ) => /:focus(-visible)?\b/.test( s ) );
		if ( ! parts.length ) {
			continue;
		}
		const outline = declared( body, 'outline' );
		const shadow = declared( body, 'box-shadow' );
		const hasRing = shadow !== null && RING.test( shadow );
		// Box-shadow on a pseudo-element is that box's own paint, not the ring.
		const onPseudo = parts.every( ( s ) => /::?(before|after)\s*$/.test( s ) );
		// The ring lands on another element when a combinator follows the :focus pseudo.
		const proxied = parts.some( ( s ) => /:focus(-visible)?\b[^\s~+>]*\s*[\s~+>]\s*\S/.test( s ) );

		if ( outline !== null && /^(none|0)\b/.test( outline ) ) {
			if ( ! hasRing && ! allowlist.some( ( a ) => selector.includes( a ) ) ) {
				problems.push( `${ selector }: outline: ${ outline } with no var(--bl-focus-ring) replacement` );
			}
			continue;
		}
		if ( shadow !== null && shadow !== 'none' && ! hasRing && ! onPseudo ) {
			problems.push( `${ selector }: box-shadow drops the halo -- start it with var(--bl-focus-ring)` );
			continue;
		}
		if ( outline !== null && proxied && ! hasRing ) {
			problems.push( `${ selector }: rings another element with an outline only -- add box-shadow: var(--bl-focus-ring)` );
		}
	}
	return problems;
}
