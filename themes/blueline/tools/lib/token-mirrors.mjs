import { normalizeValue, resolveColorToken } from './css-tokens.mjs';

/**
 * Where a design token's value is repeated outside CSS, and nothing but this
 * file notices when one copy changes:
 *
 * - inc/team-colors.php's BLUELINE_TOKEN_* constants, each wired to a CSS
 *   variable by blueline_brand_color_tokens() (PHP cannot read a custom
 *   property, and the contrast maths needs the hex).
 * - inc/team-colors.php's blueline_static_token_hex() map, the four non-brand
 *   tokens the same maths uses as the other side of a rule.
 * - assets/src/js/settings-occasions.js's DEFAULT_OCCASIONS_DATA.inkHex, the
 *   fallback for when the page's own data is missing.
 * - inc/settings/admin-assets.php's pass/fail colours in the contrast report.
 *
 * (editor.css's duplicated tokens and the `theme-color` meta, which now reads
 * the resolved ink-deep token, are covered elsewhere.)
 *
 * Every pattern below is anchored on code that exists today, and each check
 * reports a problem when its pattern finds nothing at all, so a refactor that
 * renames the thing cannot turn a guard into one that silently checks nothing.
 */

/**
 * The value of $tokenName in style.css's :root, as a lowercase hex.
 *
 * @param {Map<string,string>} styleTokens style.css's `:root` tokens.
 * @param {string}             tokenName   e.g. '--bl-ink'.
 * @return {string} Lowercase `#rrggbb`.
 */
function styleHex( styleTokens, tokenName ) {
	return resolveColorToken( styleTokens, tokenName );
}

/**
 * Compare one mirrored value with its token and record a problem on mismatch.
 *
 * @param {string[]}           problems    Accumulator.
 * @param {Map<string,string>} styleTokens style.css's `:root` tokens.
 * @param {string}             tokenName   e.g. '--bl-ink'.
 * @param {string}             mirrorHex   The mirrored hex.
 * @param {string}             where       Human description of the mirror.
 */
function compare( problems, styleTokens, tokenName, mirrorHex, where ) {
	if ( ! styleTokens.has( tokenName ) ) {
		problems.push( `${ where } mirrors ${ tokenName }, which style.css does not define` );
		return;
	}
	const expected = styleHex( styleTokens, tokenName );
	const actual = normalizeValue( mirrorHex );
	if ( expected !== actual ) {
		problems.push( `${ where } is ${ actual }, but style.css's ${ tokenName } is ${ expected }` );
	}
}

/**
 * Find every mirrored token value that no longer matches style.css.
 *
 * @param {Map<string,string>} styleTokens style.css's `:root` tokens.
 * @param {Object}             sources
 * @param {string}             sources.teamColorsPhp  inc/team-colors.php source.
 * @param {string}             sources.occasionsJs    assets/src/js/settings-occasions.js source.
 * @param {string}             sources.adminAssetsPhp inc/settings/admin-assets.php source.
 * @return {string[]} One message per problem; empty when every mirror agrees.
 */
export function findTokenMirrorDrift( styleTokens, { teamColorsPhp, occasionsJs, adminAssetsPhp } ) {
	const problems = [];

	// 1. The twelve brand constants, found through the css_var => constant
	// pairs blueline_brand_color_tokens() declares, so a new token is checked
	// the moment it is added and a mis-wired one (right hex, wrong variable) fails.
	const constants = new Map(
		[ ...teamColorsPhp.matchAll( /^const\s+(BLUELINE_TOKEN_[A-Z_]+)\s*=\s*'(#[0-9a-fA-F]{6})'/gm ) ].map( ( m ) => [ m[ 1 ], m[ 2 ] ] )
	);
	const pairs = [ ...teamColorsPhp.matchAll( /'css_var'\s*=>\s*'(--bl-[a-z-]+)',\s*'default_hex'\s*=>\s*(BLUELINE_TOKEN_[A-Z_]+)/g ) ];
	if ( pairs.length === 0 ) {
		problems.push( "team-colors.php: found no css_var/default_hex pairs in blueline_brand_color_tokens() -- this guard's pattern no longer matches the code" );
	}
	for ( const [ , tokenName, constName ] of pairs ) {
		if ( ! constants.has( constName ) ) {
			problems.push( `team-colors.php: ${ constName } is used for ${ tokenName } but is not declared as a hex constant` );
			continue;
		}
		compare( problems, styleTokens, tokenName, constants.get( constName ), `team-colors.php ${ constName }` );
	}
	for ( const constName of constants.keys() ) {
		if ( ! pairs.some( ( p ) => p[ 2 ] === constName ) ) {
			problems.push( `team-colors.php: ${ constName } is declared but blueline_brand_color_tokens() never uses it` );
		}
	}

	// 2. blueline_static_token_hex()'s closed map.
	const staticFn = teamColorsPhp.match( /function blueline_static_token_hex\([^)]*\)[^{]*\{([\s\S]*?)\n\}/ );
	const staticPairs = staticFn ? [ ...staticFn[ 1 ].matchAll( /'(--bl-[a-z-]+)'\s*=>\s*'(#[0-9a-fA-F]{6})'/g ) ] : [];
	if ( staticPairs.length === 0 ) {
		problems.push( "team-colors.php: found no entries in blueline_static_token_hex() -- this guard's pattern no longer matches the code" );
	}
	for ( const [ , tokenName, hex ] of staticPairs ) {
		compare( problems, styleTokens, tokenName, hex, `team-colors.php blueline_static_token_hex() ${ tokenName }` );
	}

	// 3. The occasions admin script's fallback ink.
	const inkDefault = occasionsJs.match( /DEFAULT_OCCASIONS_DATA\s*=\s*\{\s*inkHex:\s*'(#[0-9a-fA-F]{6})'/ );
	if ( ! inkDefault ) {
		problems.push( "settings-occasions.js: found no DEFAULT_OCCASIONS_DATA.inkHex -- this guard's pattern no longer matches the code" );
	} else {
		compare( problems, styleTokens, '--bl-ink', inkDefault[ 1 ], 'settings-occasions.js DEFAULT_OCCASIONS_DATA.inkHex' );
	}

	// 4. The contrast report's pass and fail colours.
	const report = [
		[ 'bl-contrast-pass', '--bl-success' ],
		[ 'bl-contrast-fail', '--bl-danger' ],
	];
	for ( const [ className, tokenName ] of report ) {
		const m = adminAssetsPhp.match( new RegExp( `\\.${ className }\\{color:(#[0-9a-fA-F]{6})` ) );
		if ( ! m ) {
			problems.push( `admin-assets.php: found no .${ className } colour -- this guard's pattern no longer matches the code` );
			continue;
		}
		compare( problems, styleTokens, tokenName, m[ 1 ], `admin-assets.php .${ className }` );
	}

	return problems;
}
