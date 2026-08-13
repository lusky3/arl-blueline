import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { contrastRatio, evaluateRule, mixSrgb, validateRule } from './contrast.mjs';
import { extractRootTokens, resolveColorToken } from './css-tokens.mjs';

const round = ( n ) => Number( n.toFixed( 2 ) );

test( 'contrastRatio matches known WCAG values', () => {
	assert.equal( round( contrastRatio( '#000000', '#ffffff' ) ), 21 );
	assert.equal( round( contrastRatio( '#ffffff', '#ffffff' ) ), 1 );
	assert.equal( round( contrastRatio( '#132343', '#f7fbfc' ) ), 14.94 );
	assert.equal( round( contrastRatio( '#3f6e9d', '#f7fbfc' ) ), 5.13 );
} );

test( 'contrastRatio is symmetric', () => {
	assert.equal(
		contrastRatio( '#132343', '#f7fbfc' ),
		contrastRatio( '#f7fbfc', '#132343' )
	);
} );

test( 'evaluateRule passes a satisfied min rule', () => {
	const tokens = new Map( [
		[ '--bl-ink', '#132343' ],
		[ '--bl-paper', '#F7FBFC' ],
	] );
	const result = evaluateRule(
		{ id: 'x', description: 'ink on paper', fg: '--bl-ink', bg: '--bl-paper', min: 4.5 },
		tokens
	);
	assert.equal( result.ok, true );
	assert.equal( round( result.ratio ), 14.94 );
} );

test( 'evaluateRule fails an unsatisfied min rule', () => {
	const tokens = new Map( [
		[ '--bl-ice', '#74C0E1' ],
		[ '--bl-paper', '#F7FBFC' ],
	] );
	const result = evaluateRule(
		{ id: 'y', description: 'ice on paper', fg: '--bl-ice', bg: '--bl-paper', min: 4.5 },
		tokens
	);
	assert.equal( result.ok, false );
} );

test( 'evaluateRule supports a max bound for fill-only tokens', () => {
	const tokens = new Map( [
		[ '--bl-ice', '#74C0E1' ],
		[ '--bl-paper', '#F7FBFC' ],
	] );
	const result = evaluateRule(
		{ id: 'z', description: 'ice unusable as text', fg: '--bl-ice', bg: '--bl-paper', max: 3.0 },
		tokens
	);
	assert.equal( result.ok, true );
} );

test( 'validateRule throws when a rule carries both min and max', () => {
	assert.throws(
		() => validateRule( { id: 'both', fg: '--bl-ink', bg: '--bl-paper', min: 4.5, max: 3.0 } ),
		/carries both "min" and "max"/
	);
} );

test( 'validateRule throws when a rule carries neither min nor max', () => {
	assert.throws(
		() => validateRule( { id: 'neither', fg: '--bl-ink', bg: '--bl-paper' } ),
		/carries neither "min" nor "max"/
	);
} );

test( 'validateRule throws when id is missing', () => {
	assert.throws(
		() => validateRule( { fg: '--bl-ink', bg: '--bl-paper', min: 4.5 } ),
		/missing a string "id"/
	);
} );

test( 'validateRule throws when fg is missing', () => {
	assert.throws(
		() => validateRule( { id: 'no-fg', bg: '--bl-paper', min: 4.5 } ),
		/missing a string "fg" token/
	);
} );

test( 'validateRule throws when bg is missing', () => {
	assert.throws(
		() => validateRule( { id: 'no-bg', fg: '--bl-ink', min: 4.5 } ),
		/missing a string "bg" token/
	);
} );

test( 'validateRule accepts a well-formed min rule and a well-formed max rule', () => {
	assert.doesNotThrow( () =>
		validateRule( { id: 'a', fg: '--bl-ink', bg: '--bl-paper', min: 4.5 } )
	);
	assert.doesNotThrow( () =>
		validateRule( { id: 'b', fg: '--bl-ink', bg: '--bl-paper', max: 3.0 } )
	);
} );

test( 'validateRule throws when a mix descriptor has the wrong arity', () => {
	assert.throws(
		() =>
			validateRule( {
				id: 'bad-mix-arity',
				fg: '--bl-ink',
				bg: { mix: [ '--bl-success', 8 ] },
				min: 4.5,
			} ),
		/malformed "bg" endpoint/
	);
} );

test( 'validateRule throws when a mix descriptor has a non-numeric percentage', () => {
	assert.throws(
		() =>
			validateRule( {
				id: 'bad-mix-percent-type',
				fg: '--bl-ink',
				bg: { mix: [ '--bl-success', '8', '--bl-white' ] },
				min: 4.5,
			} ),
		/malformed "bg" endpoint/
	);
} );

test( 'validateRule throws when a mix descriptor has an out-of-range percentage', () => {
	assert.throws(
		() =>
			validateRule( {
				id: 'bad-mix-percent-range',
				fg: '--bl-ink',
				bg: { mix: [ '--bl-success', 101, '--bl-white' ] },
				min: 4.5,
			} ),
		/malformed "bg" endpoint/
	);
} );

test( 'validateRule accepts a well-formed mix descriptor', () => {
	assert.doesNotThrow( () =>
		validateRule( {
			id: 'good-mix',
			fg: '--bl-ink',
			bg: { mix: [ '--bl-success', 8, '--bl-white' ] },
			min: 4.5,
		} )
	);
} );

test( 'mixSrgb at 0% returns the second colour', () => {
	assert.equal( mixSrgb( '#000000', 0, '#ffffff' ), '#ffffff' );
} );

test( 'mixSrgb at 100% returns the first colour', () => {
	assert.equal( mixSrgb( '#000000', 100, '#ffffff' ), '#000000' );
} );

test( 'mixSrgb at 50% is the midpoint', () => {
	assert.equal( mixSrgb( '#000000', 50, '#ffffff' ), '#808080' );
} );

test( 'mixSrgb matches the 8% notice tint used in account.css', () => {
	// color-mix(in srgb, #1F7A4D 8%, #ffffff)
	assert.equal( mixSrgb( '#1f7a4d', 8, '#ffffff' ), '#edf4f1' );
} );

test( 'evaluateRule resolves a mix background', () => {
	const tokens = new Map( [
		[ '--bl-ink', '#132343' ],
		[ '--bl-success', '#1F7A4D' ],
		[ '--bl-white', '#FFFFFF' ],
	] );
	const result = evaluateRule(
		{
			id: 'ink-on-success-tint',
			description: 'body text on the success notice tint',
			fg: '--bl-ink',
			bg: { mix: [ '--bl-success', 8, '--bl-white' ] },
			min: 4.5,
		},
		tokens
	);
	assert.equal( result.ok, true );
	assert.ok( result.ratio > 12 );
} );

// Integration tests against the real tools/contrast-rules.json and the real
// style.css -- not hand-built fixtures. Tasks 6-8 add 18 more rules to this
// exact file, so these guard the table itself, not just the code that reads it.
const here = dirname( fileURLToPath( import.meta.url ) );
const contrastRulesJson = JSON.parse(
	readFileSync( resolve( here, '../contrast-rules.json' ), 'utf8' )
);
const { rules } = contrastRulesJson;
const styleTokens = extractRootTokens(
	readFileSync( resolve( here, '../../style.css' ), 'utf8' )
);

test( 'every rule in contrast-rules.json is well-formed', () => {
	for ( const rule of rules ) {
		assert.doesNotThrow(
			() => validateRule( rule ),
			`rule "${ rule.id }" should be well-formed`
		);
	}
} );

test( 'every rule id in contrast-rules.json is unique', () => {
	const ids = rules.map( ( rule ) => rule.id );
	assert.equal(
		new Set( ids ).size,
		ids.length,
		`duplicate rule id found among: ${ ids.join( ', ' ) }`
	);
} );

test( 'every fg/bg token in contrast-rules.json resolves against style.css', () => {
	for ( const rule of rules ) {
		// fg/bg are plain token-name strings today. A future `{ mix: [...] }`
		// endpoint form (Task 5) will not be a string; skip those here rather
		// than rewrite this assertion when that form arrives -- it only makes
		// a claim about the string case that exists now.
		if ( typeof rule.fg === 'string' ) {
			assert.doesNotThrow(
				() => resolveColorToken( styleTokens, rule.fg ),
				`rule "${ rule.id }"'s fg token "${ rule.fg }" should resolve against style.css`
			);
		}
		if ( typeof rule.bg === 'string' ) {
			assert.doesNotThrow(
				() => resolveColorToken( styleTokens, rule.bg ),
				`rule "${ rule.id }"'s bg token "${ rule.bg }" should resolve against style.css`
			);
		}
	}
} );

// contrast-rules.json declares an explicit top-level "thresholds" object,
// read verbatim by inc/team-colors.php's blueline_contrast_threshold()
// (Fix 1). It used to be inferred as body = max(rules[].min), large =
// min(rules[].min) -- a heuristic over data that any future rule could
// silently move. These pin the declared contract itself, not just the code
// that reads it.
test( 'contrast-rules.json declares explicit top-level thresholds', () => {
	assert.ok(
		contrastRulesJson.thresholds && typeof contrastRulesJson.thresholds === 'object',
		'contrast-rules.json must declare a top-level "thresholds" object'
	);
	assert.equal( contrastRulesJson.thresholds.body, 4.5 );
	assert.equal( contrastRulesJson.thresholds.large, 3.0 );
} );

