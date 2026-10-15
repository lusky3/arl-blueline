import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { extractRootTokens } from './css-tokens.mjs';
import { findTokenMirrorDrift } from './token-mirrors.mjs';
import { readSourceByName } from './find-source.mjs';

const here = dirname( fileURLToPath( import.meta.url ) );
const read = ( rel ) => readFileSync( resolve( here, '../..', rel ), 'utf8' );

const styleTokens = extractRootTokens( read( 'style.css' ) );
const real = {
	teamColorsPhp: read( 'inc/team-colors.php' ),
	occasionsJs: readSourceByName( resolve( here, '../../assets/src' ), 'settings-occasions.js' ),
	adminAssetsPhp: read( 'inc/settings/admin-assets.php' ),
};

test( 'every mirrored token value in the repository matches style.css', () => {
	assert.deepEqual( findTokenMirrorDrift( styleTokens, real ), [] );
} );

test( 'the twelve brand constants are all checked, not a sample', () => {
	const pairs = real.teamColorsPhp.match( /'css_var'\s*=>\s*'--bl-[a-z-]+',\s*'default_hex'\s*=>\s*BLUELINE_TOKEN_/g );
	assert.equal( pairs.length, 12 );
} );

test( 'a drifted brand constant is reported against the variable it is wired to', () => {
	const drifted = real.teamColorsPhp.replace( "BLUELINE_TOKEN_ICE         = '#74C0E1'", "BLUELINE_TOKEN_ICE         = '#74C0E2'" );
	assert.notEqual( drifted, real.teamColorsPhp, 'the fixture edit applied' );
	const problems = findTokenMirrorDrift( styleTokens, { ...real, teamColorsPhp: drifted } );
	assert.equal( problems.length, 1 );
	assert.match( problems[ 0 ], /BLUELINE_TOKEN_ICE is #74c0e2, but style\.css's --bl-ice is #74c0e1/ );
} );

test( 'a constant wired to the wrong variable is reported even when its own hex is unchanged', () => {
	const miswired = real.teamColorsPhp.replace( "'default_hex' => BLUELINE_TOKEN_PALE", "'default_hex' => BLUELINE_TOKEN_ICE" );
	const problems = findTokenMirrorDrift( styleTokens, { ...real, teamColorsPhp: miswired } );
	assert.ok( problems.some( ( p ) => /--bl-pale/.test( p ) ) );
	assert.ok( problems.some( ( p ) => /BLUELINE_TOKEN_PALE is declared but/.test( p ) ) );
} );

test( 'a drifted entry in the static token map is reported', () => {
	const drifted = real.teamColorsPhp.replace( "'--bl-border'        => '#DBE7F0'", "'--bl-border'        => '#DBE7F1'" );
	assert.notEqual( drifted, real.teamColorsPhp, 'the fixture edit applied' );
	const problems = findTokenMirrorDrift( styleTokens, { ...real, teamColorsPhp: drifted } );
	assert.equal( problems.length, 1 );
	assert.match( problems[ 0 ], /--bl-border is #dbe7f1, but style\.css's --bl-border is #dbe7f0/ );
} );

test( "a drifted occasions-script fallback ink and a drifted report colour are reported", () => {
	const problems = findTokenMirrorDrift( styleTokens, {
		...real,
		occasionsJs: real.occasionsJs.replace( "inkHex: '#132343'", "inkHex: '#132344'" ),
		adminAssetsPhp: real.adminAssetsPhp.replace( '.bl-contrast-fail{color:#A32C1B', '.bl-contrast-fail{color:#A32C1C' ),
	} );
	assert.equal( problems.length, 2 );
	assert.ok( problems.some( ( p ) => /inkHex is #132344, but style\.css's --bl-ink is #132343/.test( p ) ) );
	assert.ok( problems.some( ( p ) => /\.bl-contrast-fail is #a32c1c, but style\.css's --bl-danger is #a32c1b/.test( p ) ) );
} );

test( 'a pattern that finds nothing is a problem, not a pass', () => {
	const problems = findTokenMirrorDrift( styleTokens, { teamColorsPhp: '', occasionsJs: '', adminAssetsPhp: '' } );
	assert.ok( problems.length >= 5, 'every mirror reports that it found nothing to check' );
} );
