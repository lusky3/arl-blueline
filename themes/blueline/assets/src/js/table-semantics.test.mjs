import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { restoreTableRoles, headerRole } = require( './table-semantics.js' );

// Fake elements (house rule: no jsdom): attributes plus the table API
// (tHead, tBodies, tFoot, rows, cells) the module reads.
function node( tag, attrs = {} ) {
	const self = {
		tagName: tag.toUpperCase(),
		attrs: { ...attrs },
		getAttribute: ( name ) => ( name in self.attrs ? self.attrs[ name ] : null ),
		setAttribute: ( name, value ) => {
			self.attrs[ name ] = String( value );
		},
		hasAttribute: ( name ) => name in self.attrs,
	};
	return self;
}

function row( cells ) {
	const tr = node( 'tr' );
	tr.cells = cells;
	return tr;
}

/**
 * A scrolling standings-like table: a header row, two body rows each led by a
 * row header, and a footer row.
 *
 * @param {Object} tableAttrs Attributes on the <table>.
 * @return {Object} Fake table and its parts.
 */
function table( tableAttrs = {} ) {
	const colHead = node( 'th', { scope: 'col' } );
	const bareHead = node( 'th' );
	const head = row( [ colHead, bareHead ] );
	const rowHead = node( 'th', { scope: 'row' } );
	const cell = node( 'td' );
	const body1 = row( [ rowHead, cell ] );
	const body2 = row( [ node( 'th', { scope: 'row' } ), node( 'td', { role: 'presentation' } ) ] );
	const foot = row( [ node( 'td' ), node( 'td' ) ] );
	const thead = node( 'thead' );
	const tbody = node( 'tbody' );
	const tfoot = node( 'tfoot' );
	const t = node( 'table', tableAttrs );
	t.tHead = thead;
	t.tBodies = [ tbody ];
	t.tFoot = tfoot;
	t.rows = [ head, body1, body2, foot ];
	return { t, thead, tbody, tfoot, head, body1, body2, colHead, bareHead, rowHead, cell };
}

const display = ( value ) => () => value;

test( 'a table laid out as a table needs nothing', () => {
	const { t, head, cell } = table();

	assert.equal( restoreTableRoles( t, display( 'table' ) ), false );
	assert.equal( t.getAttribute( 'role' ), null );
	assert.equal( head.getAttribute( 'role' ), null );
	assert.equal( cell.getAttribute( 'role' ), null );
} );

test( 'a display:block table gets the whole table role family back', () => {
	const parts = table();

	assert.equal( restoreTableRoles( parts.t, display( 'block' ) ), true );
	assert.equal( parts.t.getAttribute( 'role' ), 'table' );
	assert.equal( parts.thead.getAttribute( 'role' ), 'rowgroup' );
	assert.equal( parts.tbody.getAttribute( 'role' ), 'rowgroup' );
	assert.equal( parts.tfoot.getAttribute( 'role' ), 'rowgroup' );
	assert.equal( parts.head.getAttribute( 'role' ), 'row' );
	assert.equal( parts.colHead.getAttribute( 'role' ), 'columnheader' );
	assert.equal( parts.bareHead.getAttribute( 'role' ), 'columnheader' );
	assert.equal( parts.rowHead.getAttribute( 'role' ), 'rowheader' );
	assert.equal( parts.cell.getAttribute( 'role' ), 'cell' );
} );

test( 'roles an author already set win', () => {
	const parts = table();

	restoreTableRoles( parts.t, display( 'block' ) );

	assert.equal( parts.body2.cells[ 1 ].getAttribute( 'role' ), 'presentation' );
} );

test( 'a table with its own non-grid role is left alone', () => {
	const { t, head } = table( { role: 'presentation' } );

	assert.equal( restoreTableRoles( t, display( 'block' ) ), false );
	assert.equal( t.getAttribute( 'role' ), 'presentation' );
	assert.equal( head.getAttribute( 'role' ), null );
} );

test( "DataTables' grid role becomes a plain table role", () => {
	const { t } = table( { role: 'grid' } );

	restoreTableRoles( t, display( 'block' ) );

	assert.equal( t.getAttribute( 'role' ), 'table' );
} );

test( 'a table without a header or footer section still gets its rows done', () => {
	const parts = table();
	parts.t.tHead = null;
	parts.t.tFoot = null;

	restoreTableRoles( parts.t, display( 'block' ) );

	assert.equal( parts.body1.getAttribute( 'role' ), 'row' );
} );

test( 'header role follows scope', () => {
	assert.equal( headerRole( node( 'th', { scope: 'ROW' } ) ), 'rowheader' );
	assert.equal( headerRole( node( 'th', { scope: 'rowgroup' } ) ), 'rowheader' );
	assert.equal( headerRole( node( 'th', { scope: 'colgroup' } ) ), 'columnheader' );
	assert.equal( headerRole( node( 'th' ) ), 'columnheader' );
} );
