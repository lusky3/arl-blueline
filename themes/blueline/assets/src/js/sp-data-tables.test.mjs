import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const {
	SORT_BUTTON_CLASS,
	fixSortableHeaders,
	fixPagination,
	focusTargetAfterDraw,
	onPaginationKeydown,
} = require( './sp-data-tables.js' );

/*
 * Tiny fake DOM (house rule: no jsdom): elements with attributes, classes,
 * children, and querySelector(All) for the single-class / tag selectors the
 * module uses.
 */
function el( tag, { classes = [], attrs = {}, children = [] } = {} ) {
	const node = {
		tagName: tag.toUpperCase(),
		attrs: { ...attrs },
		classes: [ ...classes ],
		childNodes: [],
		parent: null,
		isConnected: true,
		clicked: 0,
		classList: {
			contains: ( name ) => node.classes.includes( name ),
		},
		get firstChild() {
			return node.childNodes[ 0 ] || null;
		},
		get className() {
			return node.classes.join( ' ' );
		},
		set className( value ) {
			node.classes = value.split( /\s+/ ).filter( Boolean );
		},
		set type( value ) {
			node.attrs.type = value;
		},
		getAttribute: ( name ) => ( name in node.attrs ? node.attrs[ name ] : null ),
		setAttribute: ( name, value ) => {
			node.attrs[ name ] = String( value );
		},
		removeAttribute: ( name ) => {
			delete node.attrs[ name ];
		},
		hasAttribute: ( name ) => name in node.attrs,
		appendChild: ( child ) => {
			if ( child.parent ) {
				child.parent.childNodes = child.parent.childNodes.filter(
					( c ) => c !== child
				);
			}
			child.parent = node;
			node.childNodes.push( child );
			return child;
		},
		click: () => {
			node.clicked += 1;
		},
		matches: ( selector ) => matches( node, selector ),
		querySelectorAll: ( selector ) => descendants( node ).filter( ( d ) => matches( d, selector ) ),
		querySelector: ( selector ) => node.querySelectorAll( selector )[ 0 ] || null,
	};
	children.forEach( ( child ) => node.appendChild( child ) );
	return node;
}

function text( value ) {
	return { nodeType: 3, textContent: value, parent: null, tagName: '#text', classes: [], attrs: {}, childNodes: [] };
}

function descendants( node ) {
	return node.childNodes.flatMap( ( c ) => [ c, ...descendants( c ) ] );
}

function matchesCompound( node, compound ) {
	const parts = compound.match( /^[a-z]+|\.[\w-]+|\[[\w-]+(?:="[^"]*")?\]/gi ) || [];
	return parts.every( ( part ) => {
		if ( part.startsWith( '.' ) ) {
			return node.classes.includes( part.slice( 1 ) );
		}
		if ( part.startsWith( '[' ) ) {
			const [ , name, value ] = part.match( /\[([\w-]+)(?:="([^"]*)")?\]/ );
			return undefined === value ? name in node.attrs : node.attrs[ name ] === value;
		}
		return node.tagName === part.toUpperCase();
	} );
}

function matches( node, selector ) {
	if ( ! node.attrs ) {
		return false;
	}
	const chain = selector.trim().split( /\s+/ );
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
}

const doc = { createElement: ( tag ) => el( tag ) };

/**
 * A standings table as DataTables leaves it: role="grid", every sortable
 * header a tab stop named by its stripped text.
 *
 * @return {Object} Fake table.
 */
function initialisedTable() {
	const gp = el( 'th', {
		classes: [ 'data-gp', 'sorting' ],
		attrs: { tabindex: '0', 'aria-label': 'GP (games played)', 'aria-controls': 't1' },
		children: [ el( 'abbr', { attrs: { title: 'Games played' }, children: [ text( 'GP' ) ] } ) ],
	} );
	const pts = el( 'th', {
		classes: [ 'data-pts', 'sorting_desc' ],
		attrs: { tabindex: '0', 'aria-label': 'PTS', 'aria-sort': 'descending' },
		children: [ text( 'PTS' ) ],
	} );
	const plain = el( 'th', {
		classes: [ 'data-name', 'sorting_disabled' ],
		attrs: { 'aria-label': 'Team' },
		children: [ text( 'Team' ) ],
	} );
	const head = el( 'thead', { children: [ el( 'tr', { children: [ plain, gp, pts ] } ) ] } );
	const table = el( 'table', { classes: [ 'sp-data-table', 'dataTable' ], attrs: { role: 'grid', id: 't1' }, children: [ head ] } );
	table.tHead = head;
	return { table, gp, pts, plain };
}

test( 'the misleading grid role goes, so the table keeps native table semantics', () => {
	const { table } = initialisedTable();

	fixSortableHeaders( table, doc );

	assert.equal( table.getAttribute( 'role' ), null );
} );

test( 'a role the page set itself is left alone', () => {
	const { table } = initialisedTable();
	table.setAttribute( 'role', 'table' );

	fixSortableHeaders( table, doc );

	assert.equal( table.getAttribute( 'role' ), 'table' );
} );

test( 'a sortable header leaves the tab order and its text moves into a sort button', () => {
	const { table, gp } = initialisedTable();

	fixSortableHeaders( table, doc );

	assert.equal( gp.getAttribute( 'tabindex' ), null );
	assert.equal( gp.getAttribute( 'aria-label' ), null, 'the header is named by its content again' );
	assert.equal( gp.childNodes.length, 1 );

	const button = gp.childNodes[ 0 ];
	assert.equal( button.tagName, 'BUTTON' );
	assert.equal( button.getAttribute( 'type' ), 'button' );
	assert.ok( button.classes.includes( SORT_BUTTON_CLASS ) );
	assert.equal( button.childNodes[ 0 ].tagName, 'ABBR', 'the abbreviation and its expansion travel with the text' );
} );

test( 'the sorted column keeps the aria-sort DataTables set', () => {
	const { table, pts } = initialisedTable();

	fixSortableHeaders( table, doc );

	assert.equal( pts.getAttribute( 'aria-sort' ), 'descending' );
	assert.equal( pts.childNodes[ 0 ].tagName, 'BUTTON' );
} );

test( 'a header that cannot sort gets no button, only loses the duplicate label', () => {
	const { table, plain } = initialisedTable();

	fixSortableHeaders( table, doc );

	assert.equal( plain.getAttribute( 'aria-label' ), null );
	assert.equal( plain.childNodes[ 0 ].nodeType, 3 );
} );

test( 'running again after a sort only strips the label DataTables put back', () => {
	const { table, gp } = initialisedTable();

	fixSortableHeaders( table, doc );
	gp.setAttribute( 'aria-label', 'GP (games played)' );
	fixSortableHeaders( table, doc );

	assert.equal( gp.getAttribute( 'aria-label' ), null );
	assert.equal( gp.childNodes.length, 1 );
	assert.equal( gp.childNodes[ 0 ].childNodes[ 0 ].tagName, 'ABBR', 'no button nested in a button' );
} );

/**
 * Pagination as DataTables 1.10 renders it on page 1 of 3.
 *
 * @return {Object} Fake container and its buttons.
 */
function pagination() {
	const button = ( classes, label ) =>
		el( 'a', { classes: [ 'paginate_button', ...classes ], attrs: { tabindex: '0', 'aria-controls': 't1' }, children: [ text( label ) ] } );
	const previous = button( [ 'previous', 'disabled' ], 'Previous' );
	const one = button( [ 'current' ], '1' );
	const two = button( [], '2' );
	const next = button( [ 'next' ], 'Next' );
	const paginate = el( 'div', {
		classes: [ 'dataTables_paginate' ],
		children: [ previous, el( 'span', { children: [ one, two ] } ), next ],
	} );
	return { paginate, previous, one, two, next };
}

test( 'the disabled page button leaves the tab order and says it is disabled', () => {
	const { paginate, previous, next } = pagination();

	fixPagination( paginate );

	assert.equal( previous.getAttribute( 'tabindex' ), '-1' );
	assert.equal( previous.getAttribute( 'aria-disabled' ), 'true' );
	assert.equal( next.getAttribute( 'tabindex' ), '0' );
	assert.equal( next.getAttribute( 'aria-disabled' ), null );
} );

test( 'the current page is exposed as the current page', () => {
	const { paginate, one, two } = pagination();

	fixPagination( paginate );

	assert.equal( one.getAttribute( 'aria-current' ), 'page' );
	assert.equal( two.getAttribute( 'aria-current' ), null );
} );

test( 'href-less page links are announced as buttons', () => {
	const { paginate, previous, two } = pagination();

	fixPagination( paginate );

	assert.equal( previous.getAttribute( 'role' ), 'button' );
	assert.equal( two.getAttribute( 'role' ), 'button' );
} );

test( 'after a redraw destroyed the focused page button, focus goes to the current page', () => {
	const { paginate, one } = pagination();
	const body = el( 'body' );
	const gone = el( 'a', { classes: [ 'paginate_button', 'previous' ], attrs: { 'aria-controls': 't1' } } );
	gone.isConnected = false;

	assert.equal( focusTargetAfterDraw( gone, body, body, paginate, 't1' ), one );
	assert.equal( focusTargetAfterDraw( gone, null, body, paginate, 't1' ), one );
} );

test( 'focus is left alone when it survived the redraw or was never on these buttons', () => {
	const { paginate, two } = pagination();
	const body = el( 'body' );
	const gone = el( 'a', { classes: [ 'paginate_button' ], attrs: { 'aria-controls': 't1' } } );
	gone.isConnected = false;

	assert.equal( focusTargetAfterDraw( gone, two, body, paginate, 't1' ), null, 'DataTables already re-focused one' );
	assert.equal( focusTargetAfterDraw( two, body, body, paginate, 't1' ), null, 'still in the page' );
	assert.equal( focusTargetAfterDraw( gone, body, body, paginate, 't2' ), null, 'another table' );
	assert.equal( focusTargetAfterDraw( el( 'input' ), body, body, paginate, 't1' ), null );
	assert.equal( focusTargetAfterDraw( null, body, body, paginate, 't1' ), null );
} );

test( 'Space activates an enabled page button and never a disabled one', () => {
	const { paginate, two, previous } = pagination();
	fixPagination( paginate );

	let prevented = 0;
	const press = ( target, key ) =>
		onPaginationKeydown( { key, target, preventDefault: () => ( prevented += 1 ) } );

	press( two, ' ' );
	press( previous, ' ' );
	press( two, 'a' );

	assert.equal( two.clicked, 1 );
	assert.equal( previous.clicked, 0 );
	assert.equal( prevented, 2, 'Space never scrolls the page from a page button' );
} );
