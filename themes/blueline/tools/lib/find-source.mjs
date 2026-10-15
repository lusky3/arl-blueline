import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Read a front-end source file by name, wherever it sits under assets/src.
 *
 * The guards in tools/ care that a value in one script agrees with its twin elsewhere, not which folder the
 * script lives in, so they look it up by name: moving a script between folders (or into a per-page bundle)
 * must not break them. A name that matches no file, or more than one, throws, so a rename is noticed
 * rather than checked against nothing.
 *
 * @param {string} srcDir   The theme's assets/src directory.
 * @param {string} fileName Base name, e.g. 'footer-theme-toggle.js'.
 * @return {string} The file's text.
 */
export function readSourceByName( srcDir, fileName ) {
	const matches = [];
	const walk = ( dir ) => {
		for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
			const path = join( dir, entry.name );
			if ( entry.isDirectory() ) {
				walk( path );
			} else if ( entry.name === fileName ) {
				matches.push( path );
			}
		}
	};
	walk( srcDir );

	if ( matches.length !== 1 ) {
		throw new Error( `expected exactly one ${ fileName } under ${ srcDir }, found ${ matches.length }` );
	}
	return readFileSync( matches[ 0 ], 'utf8' );
}
