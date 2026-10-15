import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { HINT_CLASS, HINT_TEXT, hintLink, hintLinksWithin } = require(
	'./new-tab-links.js'
);

// Fake elements (house rule: no jsdom).
function el( tag, attrs = {}, text = '', children = [] ) {
	const self = {
		tagName: tag.toUpperCase(),
		attrs: { ...attrs },
		className: '',
		textContent: text,
		children: [ ...children ],
		getAttribute: ( name ) => ( name in self.attrs ? self.attrs[ name ] : null ),
		setAttribute: ( name, value ) => {
			self.attrs[ name ] = String( value );
		},
		appendChild: ( child ) => {
			self.children.push( child );
			self.textContent += child.textContent;
			return child;
		},
		querySelector: ( selector ) =>
			selector === `.${ HINT_CLASS }`
				? self.children.find( ( c ) => c.className.split( ' ' ).includes( HINT_CLASS ) ) || null
				: null,
		querySelectorAll: ( selector ) => {
			if ( 'a[target]' === selector ) {
				return self.children.filter( ( c ) => 'A' === c.tagName && 'target' in c.attrs );
			}
			return 'img' === selector ? self.children.filter( ( c ) => 'IMG' === c.tagName ) : [];
		},
		matches: ( selector ) => 'a[target]' === selector && 'A' === self.tagName && 'target' in self.attrs,
	};
	return self;
}

const doc = { createElement: ( tag ) => el( tag ) };

test( 'a new-tab link gets visually hidden text saying so', () => {
	const link = el( 'a', { href: 'https://sponsor.example', target: '_blank' }, 'Arcturus' );

	assert.equal( hintLink( link, doc ), true );

	const hint = link.children[ 0 ];
	assert.equal( hint.className, `screen-reader-text ${ HINT_CLASS }` );
	assert.equal( hint.textContent, ` ${ HINT_TEXT }` );
	assert.equal( link.textContent, 'Arcturus (opens in a new tab)' );
} );

test( 'a link named by aria-label gets the words in its label, where they are read', () => {
	const link = el( 'a', { target: '_BLANK', 'aria-label': 'Ottawa map ' } );

	hintLink( link, doc );

	assert.equal( link.getAttribute( 'aria-label' ), 'Ottawa map (opens in a new tab)' );
	assert.equal( link.children.length, 0 );
} );

test( 'links that open in place, or already say so, are left alone', () => {
	const same = el( 'a', { href: '/faqs' }, 'FAQs' );
	const self = el( 'a', { target: '_self' }, 'Rules' );
	const says = el( 'a', { target: '_blank' }, 'Photos (opens in a new window)' );
	const label = el( 'a', { target: '_blank', 'aria-label': 'Map, opens in new tab' } );

	[ same, self, says, label ].forEach( ( link ) => assert.equal( hintLink( link, doc ), false ) );
	assert.equal( label.getAttribute( 'aria-label' ), 'Map, opens in new tab' );
} );

test( 'a Page Links To "#new_tab" link is left to the plugin until the page has loaded', () => {
	const loading = { ...doc, readyState: 'interactive' };
	const link = el( 'a', { href: 'https://www.flickr.com/groups/arl_photos/pool/#new_tab', target: '_blank' }, 'Photos' );

	// The plugin builds its aria-label from the link's text on DOMContentLoaded:
	// a hint added first would be read twice.
	assert.equal( hintLink( link, loading ), false );
	assert.equal( link.children.length, 0 );

	// After load the marker is still there only when the plugin is not active.
	assert.equal( hintLink( link, { ...doc, readyState: 'complete' } ), true );
	assert.equal( link.textContent, 'Photos (opens in a new tab)' );

	// With the plugin active it has named the link by then.
	const named = el( 'a', { href: 'https://www.flickr.com/groups/arl_photos/pool/', target: '_blank', 'aria-label': 'Photos (opens in a new tab)' }, 'Photos' );
	assert.equal( hintLink( named, { ...doc, readyState: 'complete' } ), false );
	assert.equal( named.getAttribute( 'aria-label' ), 'Photos (opens in a new tab)' );
} );

test( 'an image-only link named by its alt (a sponsor logo) gets the hint', () => {
	const link = el( 'a', { target: '_blank' }, '', [ el( 'img', { alt: 'Arcturus' } ) ] );

	assert.equal( hintLink( link, doc ), true );
} );

test( 'a link with no name of its own is left for its author to fix, not named "(opens in a new tab)"', () => {
	const empty = el( 'a', { target: '_blank', href: '/zamboni.jpg' } );
	const blankImage = el( 'a', { target: '_blank' }, '  ', [ el( 'img', { alt: '' } ) ] );

	assert.equal( hintLink( empty, doc ), false );
	assert.equal( hintLink( blankImage, doc ), false );
	assert.equal( empty.children.length, 0 );
} );

test( 'a link named by aria-labelledby is left alone: content inside it is never read', () => {
	const link = el( 'a', { target: '_blank', 'aria-labelledby': 'x' }, 'Map' );

	assert.equal( hintLink( link, doc ), false );
} );

test( 'running twice never doubles the hint', () => {
	const link = el( 'a', { target: '_blank' }, 'Arcturus' );

	hintLink( link, doc );
	hintLink( link, doc );

	assert.equal( link.children.length, 1 );
} );

test( 'a subtree added later (SportsPress sponsor AJAX) is covered, root included', () => {
	const inner = el( 'a', { target: '_blank' }, 'Inner' );
	const wrapper = el( 'div', {}, '', [ inner ] );
	const lone = el( 'a', { target: '_blank' }, 'Lone' );

	hintLinksWithin( wrapper, doc );
	hintLinksWithin( lone, doc );

	assert.equal( inner.children.length, 1 );
	assert.equal( lone.children.length, 1 );
} );
