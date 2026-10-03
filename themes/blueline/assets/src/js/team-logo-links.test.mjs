import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { fixTeamLogoLinks, LINK_SELECTOR } = require( './team-logo-links.js' );

/**
 * Minimal fake anchor inside a `.team-logo` wrapper (plain objects, matching the
 * other *.test.mjs files' no-real-DOM convention).
 *
 * @param {Object}      options        Options.
 * @param {string}      options.title  Wrapper title.
 * @param {string}      options.text   Link text.
 * @param {string|null} options.imgAlt Alt of an inner img, or null for no img.
 * @param {string}      options.label  Existing aria-label.
 * @return {Object} Fake link.
 */
function fakeLink( { title = '', text = '', imgAlt = null, label = '' } = {} ) {
	const attrs = label ? { 'aria-label': label } : {};
	const img = null === imgAlt ? null : { getAttribute: () => imgAlt };

	return {
		attrs,
		textContent: text,
		getAttribute: ( name ) => ( name in attrs ? attrs[ name ] : null ),
		setAttribute( name, value ) {
			attrs[ name ] = value;
		},
		querySelector: ( selector ) => ( 'img' === selector ? img : null ),
		closest: ( selector ) =>
			'.team-logo' === selector
				? { getAttribute: ( n ) => ( 'title' === n ? title : null ) }
				: null,
	};
}

/**
 * Fake root returning the given links for the logo selector.
 *
 * @param {object[]} links Fake links.
 * @return {Object} Fake root.
 */
function fakeRoot( links ) {
	return {
		querySelectorAll( selector ) {
			assert.equal( selector, LINK_SELECTOR );
			return links;
		},
	};
}

test( 'an image link with empty alt is named after the team', () => {
	const link = fakeLink( { title: 'Bruins', imgAlt: '' } );

	assert.equal( fixTeamLogoLinks( fakeRoot( [ link ] ) ), 1 );
	assert.equal( link.attrs[ 'aria-label' ], 'Bruins' );
	assert.equal( link.attrs[ 'aria-hidden' ], undefined );
} );

test( 'a completely empty link is hidden from assistive tech and the tab order', () => {
	const link = fakeLink( { title: 'Soy Saucers' } );

	assert.equal( fixTeamLogoLinks( fakeRoot( [ link ] ) ), 1 );
	assert.equal( link.attrs[ 'aria-hidden' ], 'true' );
	assert.equal( link.attrs.tabindex, '-1' );
	assert.equal( link.attrs[ 'aria-label' ], undefined );
} );

test( 'already-named links are left alone', () => {
	const withAlt = fakeLink( { title: 'Ducks', imgAlt: 'Ducks logo' } );
	const withText = fakeLink( { title: 'Ducks', text: 'Ducks' } );
	const withLabel = fakeLink( {
		title: 'Ducks',
		imgAlt: '',
		label: 'Ducks page',
	} );

	assert.equal(
		fixTeamLogoLinks( fakeRoot( [ withAlt, withText, withLabel ] ) ),
		0
	);
	assert.deepEqual( withAlt.attrs, {} );
	assert.equal( withLabel.attrs[ 'aria-label' ], 'Ducks page' );
} );

test( 'an image link with no title is left alone rather than hidden', () => {
	const link = fakeLink( { imgAlt: '' } );

	assert.equal( fixTeamLogoLinks( fakeRoot( [ link ] ) ), 0 );
	assert.deepEqual( link.attrs, {} );
} );

test( 'a missing root is a no-op', () => {
	assert.equal( fixTeamLogoLinks( null ), 0 );
} );
