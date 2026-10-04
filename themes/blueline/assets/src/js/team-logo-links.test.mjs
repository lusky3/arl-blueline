import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { fixTeamLogoLinks, fixRedundantAlts, run } = require(
	'./team-logo-links.js'
);

/*
 * Tiny fake DOM (house rule: no jsdom). Supports exactly what the module
 * uses: get/setAttribute, textContent, querySelector(All) and closest() for
 * comma lists of descendant selectors built from tag, .class and [attr].
 */

/**
 * Whether a node matches one compound selector (e.g. `a[href]`, `td.x`).
 *
 * @param {Object} node     Fake node.
 * @param {string} compound Compound selector.
 * @return {boolean} Match.
 */
function matchesCompound( node, compound ) {
	const parts = compound.match( /[a-z0-9]+|\.[\w-]+|\[[\w-]+\]/gi ) || [];

	return parts.every( ( part ) => {
		if ( part.startsWith( '.' ) ) {
			return node.classes.includes( part.slice( 1 ) );
		}
		if ( part.startsWith( '[' ) ) {
			return part.slice( 1, -1 ) in node.attrs;
		}
		return node.tag === part.toLowerCase();
	} );
}

/**
 * Whether a node matches a (comma-separated, descendant) selector.
 *
 * @param {Object} node     Fake node.
 * @param {string} selector Selector list.
 * @return {boolean} Match.
 */
function matches( node, selector ) {
	return selector.split( ',' ).some( ( one ) => {
		const chain = one.trim().split( /\s+/ );
		if ( ! matchesCompound( node, chain.pop() ) ) {
			return false;
		}
		let ancestor = node.parent;
		while ( chain.length && ancestor ) {
			if ( matchesCompound( ancestor, chain[ chain.length - 1 ] ) ) {
				chain.pop();
			}
			ancestor = ancestor.parent;
		}
		return 0 === chain.length;
	} );
}

/**
 * Build a fake element.
 *
 * @param {string}          tag      Tag plus classes, e.g. `td.data-team`.
 * @param {Object}          attrs    Attributes.
 * @param {Array<Object|string>} children Child nodes or text.
 * @return {Object} Fake node.
 */
function el( tag, attrs = {}, children = [] ) {
	const [ name, ...classes ] = tag.split( '.' );
	const node = {
		tag: name,
		classes,
		attrs: { ...attrs },
		children: [],
		parent: null,
		getAttribute: ( n ) => ( n in node.attrs ? node.attrs[ n ] : null ),
		setAttribute( n, v ) {
			node.attrs[ n ] = String( v );
		},
		get textContent() {
			return node.children
				.map( ( c ) => ( 'string' === typeof c ? c : c.textContent ) )
				.join( '' );
		},
		querySelectorAll( selector ) {
			const out = [];
			const walk = ( n ) =>
				n.children.forEach( ( c ) => {
					if ( 'string' !== typeof c ) {
						if ( matches( c, selector ) ) {
							out.push( c );
						}
						walk( c );
					}
				} );
			walk( node );
			return out;
		},
		querySelector: ( selector ) =>
			node.querySelectorAll( selector )[ 0 ] || null,
		closest( selector ) {
			for ( let n = node; n; n = n.parent ) {
				if ( matches( n, selector ) ) {
					return n;
				}
			}
			return null;
		},
	};
	children.forEach( ( c ) => {
		if ( 'string' !== typeof c ) {
			c.parent = node;
		}
		node.children.push( c );
	} );
	return node;
}

test( 'player-list Team cell: logo-only link is named from the image title', () => {
	const link = el( 'a', { href: '/team/blue-chips' }, [
		el( 'span.team-logo', {}, [
			el( 'img', { alt: '', title: 'Blue Chips (Hollis Wealth)' } ),
		] ),
	] );
	const root = el( 'tbody', {}, [
		el( 'tr', {}, [
			el( 'td.data-name', {}, [ el( 'a', { href: '/player/x' }, [ 'X' ] ) ] ),
			el( 'td.data-team', {}, [ link ] ),
		] ),
	] );

	assert.equal( fixTeamLogoLinks( root ), 1 );
	assert.equal( link.attrs[ 'aria-label' ], 'Blue Chips (Hollis Wealth)' );
	assert.equal( link.attrs[ 'aria-hidden' ], undefined );
} );

test( 'team gallery: logo link duplicating the caption link is hidden', () => {
	const logo = el( 'a', { href: '/team/bruins' }, [ el( 'img', { alt: '' } ) ] );
	const named = el( 'a', { href: '/team/blueliners' }, [
		el( 'img', { alt: 'A round logo for a hockey team' } ),
	] );
	const root = el( 'div', {}, [
		el( 'dl.gallery-item', {}, [
			el( 'dt.gallery-icon', {}, [ logo ] ),
			el( 'a', { href: '/team/bruins' }, [ el( 'dd', {}, [ 'Bruins' ] ) ] ),
		] ),
		el( 'dl.gallery-item', {}, [
			el( 'dt.gallery-icon', {}, [ named ] ),
			el( 'a', { href: '/team/blueliners' }, [
				el( 'dd', {}, [ 'Blueliners' ] ),
			] ),
		] ),
	] );

	assert.equal( fixTeamLogoLinks( root ), 2 );
	assert.equal( logo.attrs[ 'aria-hidden' ], 'true' );
	assert.equal( logo.attrs.tabindex, '-1' );
	assert.equal( named.attrs[ 'aria-hidden' ], 'true' );
} );

test( 'countdown .team-logo link with no alt is named from its own title', () => {
	const link = el( 'a.team-logo', { href: '/team/x', title: 'Soy Saucers' }, [
		el( 'img', { alt: '' } ),
	] );

	assert.equal( fixTeamLogoLinks( el( 'h3', {}, [ link ] ) ), 1 );
	assert.equal( link.attrs[ 'aria-label' ], 'Soy Saucers' );
} );

test( 'a .team-logo link with no image and no text is hidden', () => {
	const link = el( 'a', { href: '/team/x' } );
	const root = el( 'span.team-logo', { title: 'Soy Saucers' }, [ link ] );

	assert.equal( fixTeamLogoLinks( root ), 1 );
	assert.equal( link.attrs[ 'aria-hidden' ], 'true' );
} );

test( 'named and text links are left alone', () => {
	const withAlt = el( 'a', { href: '/s' }, [ el( 'img', { alt: 'Arcturus' } ) ] );
	const withText = el( 'a', { href: '/t' }, [ 'Ducks' ] );
	const withLabel = el( 'a', { href: '/u', 'aria-label': 'Ducks page' }, [
		el( 'img', { alt: '' } ),
	] );
	const untitled = el( 'a', { href: '/v' }, [ el( 'img', { alt: '' } ) ] );
	const root = el( 'figure', {}, [ withAlt, withText, withLabel, untitled ] );

	assert.equal( fixTeamLogoLinks( root ), 0 );
	assert.deepEqual( withAlt.attrs, { href: '/s' } );
	assert.equal( withLabel.attrs[ 'aria-label' ], 'Ducks page' );
	assert.equal( untitled.attrs[ 'aria-label' ], undefined );
} );

test( 'crest or photo beside its own name gets an empty alt', () => {
	const inLink = el( 'img', { alt: 'A round logo for Blueliners' } );
	const inCell = el( 'img', { alt: 'Red Wings' } );
	const photo = el( 'img', { alt: 'Ian Graham' } );
	const logoOnly = el( 'img', { alt: 'Orange Crush' } );
	const root = el( 'table.sp-data-table', {}, [
		el( 'td.data-home', {}, [
			el( 'a', { href: '/team/blueliners' }, [
				'Blueliners ',
				el( 'span.team-logo', {}, [ inLink ] ),
			] ),
		] ),
		el( 'td.data-name', {}, [
			el( 'span.team-logo', {}, [ inCell ] ),
			el( 'a', { href: '/team/red-wings' }, [ 'Red Wings' ] ),
		] ),
		el( 'td.data-name', {}, [
			el( 'a', { href: '/player/ian' }, [
				el( 'span.player-photo', {}, [ photo ] ),
				'Ian Graham',
			] ),
		] ),
		el( 'td.data-team', {}, [
			el( 'a', { href: '/team/orange' }, [
				el( 'span.team-logo', {}, [ logoOnly ] ),
			] ),
		] ),
	] );

	assert.equal( fixRedundantAlts( root ), 3 );
	assert.equal( inLink.attrs.alt, '' );
	assert.equal( inCell.attrs.alt, '' );
	assert.equal( photo.attrs.alt, '' );
	assert.equal( logoOnly.attrs.alt, 'Orange Crush' );
} );

test( 'run() never strips the only name a logo-only link has', () => {
	const img = el( 'img', { alt: 'Kings', title: 'Kings' } );
	const link = el( 'a', { href: '/team/kings' }, [
		el( 'span.team-logo', {}, [ img ] ),
	] );

	run( el( 'tr', {}, [ el( 'td.data-team', {}, [ link ] ) ] ) );

	assert.equal( img.attrs.alt, 'Kings' );
	assert.equal( link.attrs[ 'aria-label' ], undefined );
} );

test( 'a missing root is a no-op', () => {
	assert.equal( fixTeamLogoLinks( null ), 0 );
	assert.equal( fixRedundantAlts( null ), 0 );
} );
