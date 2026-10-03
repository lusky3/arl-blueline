import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { markDecorativeImages, WIDGET_SELECTOR } = require( './quotes-llama-alt.js' );

/**
 * Minimal fake img: attributes in a plain object, matching the other
 * *.test.mjs files' no-real-DOM convention.
 *
 * @param {object} attributes Initial attributes.
 * @return {object} A fake img element.
 */
function fakeImg( attributes = {} ) {
	return {
		attributes: { ...attributes },
		setAttribute( name, value ) {
			this.attributes[ name ] = value;
		},
	};
}

/**
 * Fake container whose querySelectorAll( 'img:not([alt])' ) returns the
 * images that currently lack an alt attribute.
 *
 * @param {object[]} images Fake img elements.
 * @return {object} A fake container element.
 */
function fakeRoot( images ) {
	return {
		querySelectorAll( selector ) {
			assert.equal( selector, 'img:not([alt])' );
			return images.filter( ( img ) => ! ( 'alt' in img.attributes ) );
		},
	};
}

test( 'markDecorativeImages gives an image with no alt an empty alt', () => {
	const img = fakeImg( { src: 'brodeur.jpg' } );

	assert.equal( markDecorativeImages( fakeRoot( [ img ] ) ), 1 );
	assert.equal( img.attributes.alt, '' );
} );

test( 'markDecorativeImages leaves an existing alt untouched', () => {
	const described = fakeImg( { alt: 'Martin Brodeur' } );
	const empty = fakeImg( { alt: '' } );

	assert.equal( markDecorativeImages( fakeRoot( [ described, empty ] ) ), 0 );
	assert.equal( described.attributes.alt, 'Martin Brodeur' );
	assert.equal( empty.attributes.alt, '' );
} );

test( 'markDecorativeImages is null-safe', () => {
	assert.equal( markDecorativeImages( null ), 0 );
	assert.equal( markDecorativeImages( {} ), 0 );
} );

test( 'WIDGET_SELECTOR targets the Quotes Llama widget wrapper', () => {
	assert.equal( WIDGET_SELECTOR, '.widget_widgetquotesllama' );
} );
