import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, relative, resolve } from 'node:path';
import { extractRootTokens, extractTokensForSelector, extractRuleBlock, resolveColorToken, normalizeValue } from './lib/css-tokens.mjs';
import { contrastRatio, evaluateRule } from './lib/contrast.mjs';

const here = dirname( fileURLToPath( import.meta.url ) );

/**
 * Log one check's result and return whether it passed.
 *
 * This is the shared "evaluate -> log ok/FAIL -> tally" shape every check
 * below repeats: it only owns the logging line itself, never the meaning of
 * `ok` -- each check still decides for itself what "ok" means and what
 * message to print, so the report of what's actually being asserted stays in
 * the check, not buried in this helper.
 *
 * @param {boolean} ok      Whether the check passed.
 * @param {string}  message Message to print (without the leading ok/FAIL tag).
 * @return {boolean} `ok`, unchanged -- lets call sites tally failures inline.
 */
function report( ok, message ) {
	console.log( `${ ok ? 'ok  ' : 'FAIL' } ${ message }` );
	return ok;
}

/**
 * Light-mode pass: every rule in contrast-rules.json checked against
 * style.css's plain `:root` tokens.
 *
 * @param {Map<string,string>} styleTokens style.css's `:root` tokens.
 * @param {Array<Object>}      rules       Parsed contrast-rules.json rules.
 * @return {number} Number of failing rules.
 */
function checkLightContrast( styleTokens, rules ) {
	let failed = 0;
	for ( const rule of rules ) {
		const result = evaluateRule( rule, styleTokens );
		if ( ! report( result.ok, `${ result.description }: ${ result.ratio.toFixed( 2 ) } (${ result.bound })` ) ) {
			failed++;
		}
	}
	return failed;
}

/**
 * Dark-mode pass. Design spec docs/superpowers/specs/2026-08-22-blueline-
 * theme-toggle-design.md §7: the light palette above is guarded by
 * checkLightContrast(); nothing previously guarded the dark redefinitions in
 * style.css's `:root[data-theme="dark"]` block, so a future edit to either
 * palette could silently drift out of AA with no build failure. Every rule
 * flagged `"themeAware": true` in contrast-rules.json is re-evaluated here
 * against the dark tokens merged over the light ones (a dark declaration
 * only overrides the handful of tokens that actually redefine themselves --
 * every structural/spacing/chrome token not mentioned there keeps its light
 * value, which is correct: those are exactly the tokens this feature leaves
 * untouched). Rules with no such flag are chrome or fixed accent pairings
 * that never change between palettes, so re-checking them here would only
 * repeat the same light-mode number under a misleading "(dark)" label.
 *
 * @param {Map<string,string>} styleTokens style.css's `:root` tokens.
 * @param {string}             css         Full style.css source.
 * @param {Array<Object>}      rules       Parsed contrast-rules.json rules.
 * @return {number} Number of failing rules.
 */
function checkDarkContrast( styleTokens, css, rules ) {
	const darkOverrides = extractTokensForSelector( css, ':root[data-theme="dark"]' );
	const darkTokens = new Map( [ ...styleTokens, ...darkOverrides ] );

	let failed = 0;
	for ( const rule of rules ) {
		if ( ! rule.themeAware ) {
			continue;
		}
		const result = evaluateRule( rule, darkTokens );
		if ( ! report( result.ok, `${ result.description } (dark): ${ result.ratio.toFixed( 2 ) } (${ result.bound })` ) ) {
			failed++;
		}
	}
	return failed;
}

/**
 * Token parity: assets/src/css/editor.css duplicates a hand-picked subset of
 * style.css's --bl-* tokens by value, because WordPress's editor-styles
 * injection path has no way to @import or otherwise share style.css's own
 * :root block into the block-editor canvas iframe (see editor.css's own
 * top-of-file comment for why). Nothing previously kept the two copies in
 * sync -- a token changed in style.css and forgotten in editor.css would
 * silently desync the editor preview from the real front end (Task 5
 * deferred finding, rolled into Task 16 item c).
 *
 * This check is deliberately ONE-DIRECTIONAL: editor.css is allowed to omit
 * tokens it has no use for (e.g. --bl-focus, the semantic status colours),
 * but every token it DOES declare must exactly match style.css's value, and
 * it must not invent a --bl-* name style.css doesn't define at all.
 *
 * @param {Map<string,string>} styleTokens style.css's `:root` tokens.
 * @return {number} Number of failing tokens.
 */
function checkEditorTokenParity( styleTokens ) {
	const editorCssPath = resolve( here, '../assets/src/css/editor.css' );
	const editorCss     = readFileSync( editorCssPath, 'utf8' );
	const editorTokens  = extractRootTokens( editorCss );

	let failed = 0;
	for ( const [ name, editorValue ] of editorTokens ) {
		if ( ! styleTokens.has( name ) ) {
			report( false, `editor.css declares ${ name }, which style.css does not define at all` );
			failed++;
			continue;
		}
		const styleValue = normalizeValue( styleTokens.get( name ) );
		const normalizedEditorValue = normalizeValue( editorValue );
		if ( styleValue !== normalizedEditorValue ) {
			report( false, `${ name } drifted: style.css has "${ styleTokens.get( name ) }", editor.css has "${ editorValue }"` );
			failed++;
		}
	}

	if ( ! failed ) {
		report( true, `editor.css's ${ editorTokens.size } duplicated tokens match style.css byte-for-byte` );
	}
	return failed;
}

/**
 * The same drift problem, one more place: inc/team-colors.php has to compute
 * WCAG contrast server-side to derive a readable foreground for each team's
 * own colour, and PHP cannot read a CSS custom property. So it mirrors two
 * tokens as constants. If style.css changes and those constants do not, every
 * team page silently derives its foregrounds against the wrong ground -- the
 * failure would be invisible until someone noticed unreadable text on a team
 * page, which is exactly what that module exists to prevent.
 *
 * @param {Map<string,string>} styleTokens style.css's `:root` tokens.
 * @return {number} Number of failing constants.
 */
function checkTeamColorsParity( styleTokens ) {
	const teamColorsPath = resolve( here, '../inc/team-colors.php' );
	const teamColorsSrc  = readFileSync( teamColorsPath, 'utf8' );

	const phpConstant = ( name ) => {
		const m = teamColorsSrc.match(
			new RegExp( `const\\s+${ name }\\s*=\\s*'(#[0-9a-fA-F]{6})'` )
		);
		if ( ! m ) {
			throw new Error( `${ name } not found in inc/team-colors.php` );
		}
		return m[ 1 ].toLowerCase();
	};

	const MIRRORED = [
		[ 'BLUELINE_TOKEN_INK', '--bl-ink' ],
		[ 'BLUELINE_TOKEN_PAPER', '--bl-paper' ],
	];

	let failed = 0;
	for ( const [ constName, tokenName ] of MIRRORED ) {
		const cssValue = normalizeValue( styleTokens.get( tokenName ) || '' ).toLowerCase();
		const phpValue = phpConstant( constName );
		if ( cssValue !== phpValue ) {
			report( false, `${ constName } is ${ phpValue }, but style.css's ${ tokenName } is ${ cssValue }` );
			failed++;
		}
	}

	if ( ! failed ) {
		report( true, `team-colors.php's ${ MIRRORED.length } mirrored tokens match style.css` );
	}
	return failed;
}

/**
 * Finding 11: SportsPress's OWN output, not this theme's tokens, printed a
 * WCAG failure straight onto the page -- SP_League_Table::data() emits the
 * STRK column as raw `<span style="color:#888888">`, 3.40:1 on white, below
 * the 4.5:1 AA floor. This is not a --bl-* token, so it cannot be asserted
 * the way the palette above is; sportspress.css instead carries a scoped,
 * `!important` override (the one thing that can still beat an inline style
 * attribute) neutralising it. This section is the guard: it asserts that
 * override still exists, still targets the right cell, and still resolves
 * to an AA-passing colour -- so the rule can't be quietly deleted, retargeted,
 * or drift toward a token that itself fails contrast, without this build
 * catching it. This is "sample rendered SportsPress output" in the sense
 * this toolchain can support without a browser dependency: a known-real
 * fixture of what the plugin actually emits (confirmed live on /standings),
 * checked against both its own raw value AND this theme's neutralising fix.
 *
 * The rule block itself is located with `extractRuleBlock()` -- the same
 * brace-depth-counting primitive `extractTokensForSelector()` uses -- rather
 * than a second, non-brace-aware regex.
 *
 * @param {Map<string,string>} styleTokens style.css's `:root` tokens.
 * @return {number} Number of failing fixtures.
 */
function checkSportsPressFixture( styleTokens ) {
	const token = ( name ) => resolveColorToken( styleTokens, `--${ name }` );

	const spCssPath = resolve( here, '../assets/src/css/sportspress.css' );
	const spCss = readFileSync( spCssPath, 'utf8' );

	const SP_INLINE_COLOR_FIXTURES = [
		{
			description: 'league table STRK column outcome span (SP_League_Table::data(), confirmed live on /standings)',
			selectorHint: '.sp-data-table td.data-strk span[style]',
			rawColor: '#888888',
			background: 'bl-paper',
		},
	];

	let failed = 0;
	for ( const fixture of SP_INLINE_COLOR_FIXTURES ) {
		const rawRatio = contrastRatio( fixture.rawColor, token( fixture.background ) );
		console.log( `--   ${ fixture.description }: raw inline colour is ${ rawRatio.toFixed( 2 ) } on paper (min 4.5) -- expected to fail, that's the bug` );

		// Find the CSS rule block whose selector is the fixture's hint and
		// declares `color: ... !important` -- the neutralising override.
		let block;
		try {
			block = extractRuleBlock( spCss, fixture.selectorHint );
		} catch {
			block = null;
		}
		const decl = block && block.match( /color\s*:\s*(var\(\s*--([\w-]+)\s*\)|#[0-9a-fA-F]{6})\s*!important/ );
		const matchedRatio = decl
			? contrastRatio( decl[ 2 ] ? token( decl[ 2 ] ) : decl[ 1 ], token( fixture.background ) )
			: null;

		if ( null === matchedRatio ) {
			report( false, `no !important colour override found in sportspress.css for a selector matching "${ fixture.selectorHint }" -- the ${ fixture.rawColor } inline-style failure is unguarded again` );
			failed++;
			continue;
		}

		if ( ! report( matchedRatio >= 4.5, `sportspress.css neutralises it to ${ matchedRatio.toFixed( 2 ) } on paper (min 4.5)` ) ) {
			failed++;
		}
	}

	return failed;
}

/**
 * Recursively collect every `.css` file under `dir`.
 *
 * @param {string} dir Directory to walk.
 * @return {string[]} Absolute file paths.
 */
function collectCssFiles( dir ) {
	const out = [];
	for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
		const full = resolve( dir, entry.name );
		if ( entry.isDirectory() ) {
			out.push( ...collectCssFiles( full ) );
		} else if ( entry.isFile() && entry.name.endsWith( '.css' ) ) {
			out.push( full );
		}
	}
	return out;
}

/**
 * Finding: four CSS rules hard-coded the RGB channels of a token instead of
 * referencing it, so overriding the token moved the text colour while its
 * background stayed put -- breaking a pairing one of those files claimed in a
 * comment had been "verified by hand". This forbids reintroducing that.
 *
 * Two follow-up gaps in the original version of this lint:
 *
 * 1. It only scanned assets/src/css, non-recursively. Spec P0.6 named FOUR
 *    offenders, one of which -- style.css's --bl-shadow-card /
 *    --bl-shadow-raised -- lives outside that directory entirely, so the
 *    file the spec actually named was never covered by the guard meant to
 *    stop it being reintroduced. readdirSync() was also flat, so a future
 *    subdirectory under assets/src/css would go unscanned with no warning.
 * 2. style.css DOES still contain --bl-shadow-card's rgba(19, 35, 67, ...),
 *    but that one is intentional (see style.css's own comment there): a
 *    shadow composites over whatever ground it happens to land on, which
 *    varies per placement, so alpha-blending against a token via
 *    color-mix() -- which always mixes toward one fixed second colour --
 *    would be the wrong tool, not a fix. That single line is the only
 *    documented exemption this lint carries; anything else matching a
 *    token's channels still fails.
 *
 * @param {Map<string,string>} styleTokens style.css's `:root` tokens.
 * @return {number} Number of failing hard-coded matches.
 */
function checkNoLiteralRgba( styleTokens ) {
	const cssDir = resolve( here, '../assets/src/css' );
	const stylePath = resolve( here, '../style.css' );

	const tokenRgbs = new Map();
	for ( const [ name, raw ] of styleTokens ) {
		const value = normalizeValue( raw );
		if ( /^#[0-9a-f]{6}$/.test( value ) ) {
			const n = parseInt( value.slice( 1 ), 16 );
			tokenRgbs.set( `${ ( n >> 16 ) & 255 },${ ( n >> 8 ) & 255 },${ n & 255 }`, name );
		}
	}

	// Explicit, commented allowlist: `${relative-path}::${r,g,b}` entries that
	// are PERMITTED to keep a token's literal channels inside an rgba(), with
	// the reason recorded right here rather than only in a CSS comment a lint
	// exception can't see.
	const RGBA_LINT_ALLOWLIST = new Map( [
		[
			'style.css::20,24,31',
			"--bl-shadow-card's alpha-composited shadow intentionally keeps --bl-ink's literal channels -- a shadow composites over whatever ground it falls on, so color-mix() against one fixed ground would be wrong here. See style.css's own comment above --bl-shadow-card/--bl-shadow-raised.",
		],
	] );

	const themeRoot = resolve( here, '..' );
	const scanTargets = [ ...collectCssFiles( cssDir ), stylePath ];

	let failed = 0;
	for ( const filePath of scanTargets ) {
		const relPath = relative( themeRoot, filePath );
		const src = readFileSync( filePath, 'utf8' );
		const re = /rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/g;
		let m;
		while ( ( m = re.exec( src ) ) !== null ) {
			const key = `${ +m[ 1 ] },${ +m[ 2 ] },${ +m[ 3 ] }`;
			if ( ! tokenRgbs.has( key ) ) {
				continue;
			}
			if ( RGBA_LINT_ALLOWLIST.has( `${ relPath }::${ key }` ) ) {
				continue;
			}
			report( false, `${ relPath } hard-codes the channels of ${ tokenRgbs.get( key ) } -- use color-mix() with the token` );
			failed++;
		}
	}
	if ( ! failed ) {
		report( true, `no CSS file hard-codes a token's RGB channels outside the ${ RGBA_LINT_ALLOWLIST.size } documented exemption(s)` );
	}
	return failed;
}

/**
 * Run every check in sequence and exit non-zero if any of them failed.
 */
function main() {
	const css = readFileSync( resolve( here, '../style.css' ), 'utf8' );
	const styleTokens = extractRootTokens( css );

	const { rules } = JSON.parse(
		readFileSync( resolve( here, 'contrast-rules.json' ), 'utf8' )
	);

	let failed = 0;
	failed += checkLightContrast( styleTokens, rules );
	failed += checkDarkContrast( styleTokens, css, rules );
	failed += checkEditorTokenParity( styleTokens );
	failed += checkTeamColorsParity( styleTokens );
	failed += checkSportsPressFixture( styleTokens );
	failed += checkNoLiteralRgba( styleTokens );

	process.exit( failed ? 1 : 0 );
}

main();
