import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const {
	markDecorativeImages,
	tidyQuoteText,
	tidyQuotes,
	WIDGET_SELECTOR,
} = require( './quotes-llama-alt.js' );

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

test( 'tidyQuoteText drops a wrapping pair of straight or curly quotes', () => {
	assert.equal( tidyQuoteText( '"Skate hard."' ), 'Skate hard.' );
	assert.equal( tidyQuoteText( '“Skate hard.”' ), 'Skate hard.' );
	assert.equal( tidyQuoteText( '  "Skate hard." ' ), 'Skate hard.' );
} );

test( 'tidyQuoteText curls the marks inside a quote', () => {
	assert.equal(
		tidyQuoteText( 'I\'m happiest when the coach says "go".' ),
		'I’m happiest when the coach says “go”.'
	);
} );

test( 'tidyQuoteText opens single quotes after a space, bracket or dash', () => {
	assert.equal(
		tidyQuoteText(
			"People would say, 'Girls don't play hockey. Girls don't skate.' I would say, 'Watch this'."
		),
		'People would say, ‘Girls don’t play hockey. Girls don’t skate.’ I would say, ‘Watch this’.'
	);
	assert.equal( tidyQuoteText( "'Skate' (or 'glide')" ), '‘Skate’ (or ‘glide’)' );
	assert.equal(
		tidyQuoteText( 'She said "\'Go\' means go."' ),
		'She said “‘Go’ means go.”'
	);
} );

test( 'tidyQuoteText keeps apostrophes and decades as closing marks', () => {
	assert.equal(
		tidyQuoteText( "We've played since the '90s." ),
		'We’ve played since the ’90s.'
	);
	assert.equal( tidyQuoteText( "Players' bench ' alone" ), 'Players’ bench ’ alone' );
} );

test( 'tidyQuoteText leaves a quote that only starts with a quoted word', () => {
	assert.equal(
		tidyQuoteText( '"Go" is the only word I need on the bench.' ),
		'“Go” is the only word I need on the bench.'
	);
	assert.equal(
		tidyQuoteText( '"Go" is what I "need"' ),
		'“Go” is what I “need”'
	);
} );

/**
 * Fake text node.
 *
 * @param {string} value Text.
 * @return {object} A fake text node.
 */
function fakeText( value ) {
	return { nodeType: 3, nodeValue: value };
}

/**
 * Fake container holding quote spans with the given child nodes.
 *
 * @param {object[][]} quotes Child nodes of each quote span.
 * @return {object} A fake widget element.
 */
function fakeWidget( quotes ) {
	return {
		querySelectorAll( selector ) {
			assert.equal( selector, '.quotes-llama-widget-next-more' );
			return quotes.map( ( childNodes ) => ( { childNodes } ) );
		},
	};
}

test( 'tidyQuotes rewrites a plain-text quote in place', () => {
	const text = fakeText( '"Never played? Perfect."' );

	assert.equal( tidyQuotes( fakeWidget( [ [ text ] ] ) ), 1 );
	assert.equal( text.nodeValue, 'Never played? Perfect.' );
} );

test( 'tidyQuotes keeps markup and only trims the outer edges', () => {
	const head = fakeText( '"Keep your ' );
	const em = { nodeType: 1, nodeValue: null };
	const tail = fakeText( ' up, it\'s a game."' );

	tidyQuotes( fakeWidget( [ [ head, em, tail ] ] ) );

	assert.equal( head.nodeValue, 'Keep your ' );
	assert.equal( tail.nodeValue, ' up, it’s a game.' );
} );

test( 'tidyQuotes reports nothing changed for an already tidy quote', () => {
	assert.equal(
		tidyQuotes( fakeWidget( [ [ fakeText( 'Skate hard.' ) ] ] ) ),
		0
	);
	assert.equal( tidyQuotes( null ), 0 );
} );
