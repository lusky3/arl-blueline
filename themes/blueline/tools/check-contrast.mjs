import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const css = readFileSync( resolve( here, '../style.css' ), 'utf8' );

const token = ( name ) => {
	const m = css.match( new RegExp( `--${ name }:\\s*(#[0-9a-fA-F]{6})` ) );
	if ( ! m ) throw new Error( `token --${ name } not found in style.css` );
	return m[ 1 ];
};

/**
 * Extracts every `--bl-*: value;` declaration from the first `:root { ... }`
 * (or `:root, <selector> { ... }`) block found in a CSS source string, as a
 * Map of token name -> trimmed value string (e.g. "#132343", or
 * `clamp(1.125rem, 0.5vw + 1rem, 1.25rem)`).
 *
 * @param {string} source CSS source text.
 * @returns {Map<string, string>}
 */
function extractRootTokens( source ) {
	const rootStart = source.indexOf( ':root' );
	if ( rootStart === -1 ) throw new Error( 'no :root block found' );

	const braceStart = source.indexOf( '{', rootStart );
	let depth = 0;
	let i = braceStart;
	for ( ; i < source.length; i++ ) {
		if ( source[ i ] === '{' ) depth++;
		else if ( source[ i ] === '}' ) {
			depth--;
			if ( depth === 0 ) break;
		}
	}
	const block = source.slice( braceStart + 1, i );

	const tokens = new Map();
	const re = /(--bl-[\w-]+)\s*:\s*([^;]+);/g;
	let m;
	while ( ( m = re.exec( block ) ) !== null ) {
		tokens.set( m[ 1 ], m[ 2 ].trim().toLowerCase() );
	}
	return tokens;
}

const lin = ( c ) => { c /= 255; return c <= 0.04045 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ); };
const lum = ( hex ) => {
	const n = parseInt( hex.slice( 1 ), 16 );
	return 0.2126 * lin( n >> 16 & 255 ) + 0.7152 * lin( n >> 8 & 255 ) + 0.0722 * lin( n & 255 );
};
const ratio = ( a, b ) => { const x = lum( a ), y = lum( b ); const hi = Math.max( x, y ), lo = Math.min( x, y ); return ( hi + 0.05 ) / ( lo + 0.05 ); };

// [ description, fg token, bg token, minimum ]
const RULES = [
	[ 'body text on paper',        'bl-ink',         'bl-paper', 4.5 ],
	[ 'secondary text on paper',   'bl-ink-mid',     'bl-paper', 4.5 ],
	[ 'accent text on paper',      'bl-accent-text', 'bl-paper', 4.5 ],
	[ 'ink on ice fill (button)',  'bl-ink',         'bl-ice',   4.5 ],
	[ 'paper text on ink',         'bl-paper',       'bl-ink',   4.5 ],
	[ 'pale text on ink',          'bl-pale',        'bl-ink',   4.5 ],
	[ 'ice text on ink',           'bl-ice',         'bl-ink',   4.5 ],
	[ 'steel border on paper',     'bl-steel',       'bl-paper', 3.0 ],
	// bl-ink-deep is first used (header/nav/footer chrome) in Task 4.
	[ 'paper text on ink-deep',    'bl-paper',       'bl-ink-deep', 4.5 ],
	[ 'pale text on ink-deep',     'bl-pale',        'bl-ink-deep', 4.5 ],
];

let failed = 0;
for ( const [ desc, fg, bg, min ] of RULES ) {
	const r = ratio( token( fg ), token( bg ) );
	const ok = r >= min;
	if ( ! ok ) failed++;
	console.log( `${ ok ? 'ok  ' : 'FAIL' } ${ desc }: ${ r.toFixed( 2 ) } (min ${ min })` );
}

// Guard the rule that is easy to violate by accident.
const iceOnPaper = ratio( token( 'bl-ice' ), token( 'bl-paper' ) );
if ( iceOnPaper >= 3.0 ) {
	console.log( `FAIL --bl-ice is ${ iceOnPaper.toFixed( 2 ) } on paper; it is a FILL token and must stay decorative` );
	failed++;
} else {
	console.log( `ok   --bl-ice correctly unusable as light-bg text (${ iceOnPaper.toFixed( 2 ) })` );
}

/*
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
 */
const styleTokens  = extractRootTokens( css );
const editorCssPath = resolve( here, '../assets/src/css/editor.css' );
const editorCss     = readFileSync( editorCssPath, 'utf8' );
const editorTokens  = extractRootTokens( editorCss );

const normalizeValue = ( value ) => {
	const hex3 = value.match( /^#([0-9a-f])([0-9a-f])([0-9a-f])$/ );
	if ( hex3 ) return `#${ hex3[ 1 ] }${ hex3[ 1 ] }${ hex3[ 2 ] }${ hex3[ 2 ] }${ hex3[ 3 ] }${ hex3[ 3 ] }`;
	return value.replace( /\s+/g, ' ' );
};

let parityFailed = 0;
for ( const [ name, editorValue ] of editorTokens ) {
	if ( ! styleTokens.has( name ) ) {
		console.log( `FAIL editor.css declares ${ name }, which style.css does not define at all` );
		parityFailed++;
		continue;
	}
	const styleValue = normalizeValue( styleTokens.get( name ) );
	const normalizedEditorValue = normalizeValue( editorValue );
	if ( styleValue !== normalizedEditorValue ) {
		console.log( `FAIL ${ name } drifted: style.css has "${ styleTokens.get( name ) }", editor.css has "${ editorValue }"` );
		parityFailed++;
	}
}

if ( parityFailed ) {
	failed += parityFailed;
} else {
	console.log( `ok   editor.css's ${ editorTokens.size } duplicated tokens match style.css byte-for-byte` );
}

/*
 * The same drift problem, one more place: inc/team-colors.php has to compute
 * WCAG contrast server-side to derive a readable foreground for each team's
 * own colour, and PHP cannot read a CSS custom property. So it mirrors two
 * tokens as constants. If style.css changes and those constants do not, every
 * team page silently derives its foregrounds against the wrong ground -- the
 * failure would be invisible until someone noticed unreadable text on a team
 * page, which is exactly what that module exists to prevent.
 */
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

let mirrorFailed = 0;
for ( const [ constName, tokenName ] of MIRRORED ) {
	const cssValue = normalizeValue( styleTokens.get( tokenName ) || '' ).toLowerCase();
	const phpValue = phpConstant( constName );
	if ( cssValue !== phpValue ) {
		console.log(
			`FAIL ${ constName } is ${ phpValue }, but style.css's ${ tokenName } is ${ cssValue }`
		);
		mirrorFailed++;
	}
}

if ( mirrorFailed ) {
	failed += mirrorFailed;
} else {
	console.log( `ok   team-colors.php's ${ MIRRORED.length } mirrored tokens match style.css` );
}

/*
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
 */
const spCssPath = resolve( here, '../assets/src/css/sportspress.css' );
const spCss = readFileSync( spCssPath, 'utf8' );

const SP_INLINE_COLOR_FIXTURES = [
	{
		description: 'league table STRK column outcome span (SP_League_Table::data(), confirmed live on /standings)',
		selectorHint: 'data-strk',
		rawColor: '#888888',
		background: 'bl-paper',
	},
];

let spFailed = 0;
for ( const fixture of SP_INLINE_COLOR_FIXTURES ) {
	const rawRatio = ratio( fixture.rawColor, token( fixture.background ) );
	console.log( `--   ${ fixture.description }: raw inline colour is ${ rawRatio.toFixed( 2 ) } on paper (min 4.5) -- expected to fail, that's the bug` );

	// Find the CSS rule block whose selector mentions the fixture's hint and
	// declares `color: ... !important` -- the neutralising override.
	const ruleRe = new RegExp( `[^{}]*${ fixture.selectorHint }[^{}]*\\{([^}]*)\\}`, 'g' );
	let matchedRatio = null;
	let m;
	while ( ( m = ruleRe.exec( spCss ) ) !== null ) {
		const decl = m[ 1 ].match( /color\s*:\s*(var\(\s*--([\w-]+)\s*\)|#[0-9a-fA-F]{6})\s*!important/ );
		if ( ! decl ) continue;
		const resolved = decl[ 2 ] ? token( decl[ 2 ] ) : decl[ 1 ];
		matchedRatio = ratio( resolved, token( fixture.background ) );
		break;
	}

	if ( null === matchedRatio ) {
		console.log( `FAIL no !important colour override found in sportspress.css for a selector matching "${ fixture.selectorHint }" -- the ${ fixture.rawColor } inline-style failure is unguarded again` );
		spFailed++;
		continue;
	}

	const ok = matchedRatio >= 4.5;
	console.log( `${ ok ? 'ok  ' : 'FAIL' } sportspress.css neutralises it to ${ matchedRatio.toFixed( 2 ) } on paper (min 4.5)` );
	if ( ! ok ) spFailed++;
}

failed += spFailed;

process.exit( failed ? 1 : 0 );
