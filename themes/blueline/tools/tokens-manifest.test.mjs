import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { extractRootTokens } from './lib/css-tokens.mjs';

const here = dirname( fileURLToPath( import.meta.url ) );
const styleCss = readFileSync( resolve( here, '../style.css' ), 'utf8' );
const styleTokens = extractRootTokens( styleCss );

const manifest = JSON.parse(
	readFileSync( resolve( here, 'tokens.json' ), 'utf8' )
);
const { rules: contrastRules } = JSON.parse(
	readFileSync( resolve( here, 'contrast-rules.json' ), 'utf8' )
);

test( 'tokens.json declares every --bl-* token style.css defines, and no others', () => {
	const manifestNames = new Set( Object.keys( manifest.tokens ) );
	const styleNames = new Set( styleTokens.keys() );

	for ( const name of styleNames ) {
		assert.ok( manifestNames.has( name ), `tokens.json is missing ${ name }` );
	}
	for ( const name of manifestNames ) {
		assert.ok( styleNames.has( name ), `tokens.json declares ${ name }, which style.css does not define` );
	}
} );

test( 'every manifest entry declares type, group, tier, bounds and tunable', () => {
	for ( const [ name, entry ] of Object.entries( manifest.tokens ) ) {
		assert.equal( typeof entry.type, 'string', `${ name }.type` );
		assert.equal( typeof entry.group, 'string', `${ name }.group` );
		assert.equal( typeof entry.tier, 'string', `${ name }.tier` );
		assert.ok( 'bounds' in entry, `${ name }.bounds` );
		assert.equal( typeof entry.tunable, 'boolean', `${ name }.tunable` );
	}
} );

test( 'exactly 13 tunable tokens: 9 brand palette, 3 semantic, and occasion-accent', () => {
	const tunable = Object.entries( manifest.tokens ).filter( ( [ , e ] ) => e.tunable );
	const tunableNames = tunable.map( ( [ name ] ) => name ).sort();
	const expectedNames = [
		'--bl-accent-text',
		'--bl-danger',
		'--bl-ice',
		'--bl-ink',
		'--bl-ink-deep',
		'--bl-ink-mid',
		'--bl-occasion-accent',
		'--bl-pale',
		'--bl-paper',
		'--bl-steel',
		'--bl-success',
		'--bl-warning',
		'--bl-white'
	];
	assert.equal( tunable.length, 13, 'exactly 13 tunable tokens' );
	assert.deepEqual( tunableNames, expectedNames, 'tunable token names match the expected set' );
} );

test( '--bl-occasion-accent defaults to var(--bl-ice) in style.css', () => {
	assert.equal( styleTokens.get( '--bl-occasion-accent' ), 'var(--bl-ice)' );
} );

test( "--bl-occasion-accent's declared bounds name real contrast-rules.json rule ids", () => {
	const ruleIds = new Set( contrastRules.map( ( r ) => r.id ) );
	const bounds = manifest.tokens[ '--bl-occasion-accent' ].bounds;
	assert.ok( Array.isArray( bounds.contrast_rules ) && bounds.contrast_rules.length > 0 );
	for ( const id of bounds.contrast_rules ) {
		assert.ok( ruleIds.has( id ), `bounds names rule "${ id }", which contrast-rules.json does not declare` );
	}
} );
