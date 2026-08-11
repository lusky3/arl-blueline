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

process.exit( failed ? 1 : 0 );
