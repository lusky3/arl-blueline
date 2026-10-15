/**
 * Explicit table roles for tables the CSS no longer lays out as tables.
 *
 * A wide table that scrolls on its own (`table.bl-table-self-scroll`, a bare
 * classic-editor table in `.entry-content`, sportspress-chrome.css) is set to
 * `display: block`, and the phone layout of some SportsPress tables stacks rows
 * into cards. WebKit drops a table's semantics once its `display` is no longer
 * a table value, so VoiceOver reads such a table as loose text with no row or
 * column relationships. Chrome and Firefox keep them, which is why this never
 * shows up in a Chromium-only check.
 *
 * Restating the roles in ARIA (table, rowgroup, row, columnheader, rowheader,
 * cell) keeps the structure whatever the CSS does. Only tables whose computed
 * `display` is not a table value are touched; an author role (other than the
 * `grid` DataTables adds, which sp-data-tables.js removes anyway) is left as is.
 */

const TABLE_DISPLAYS = [ 'table', 'inline-table' ];

/**
 * Set $role on $node unless it already has a role of its own.
 *
 * @param {Element} node Element.
 * @param {string}  role ARIA role.
 * @return {void}
 */
function setRoleIfUnset( node, role ) {
	if ( node && ! node.hasAttribute( 'role' ) ) {
		node.setAttribute( 'role', role );
	}
}

/**
 * The role a header cell plays: a row header when its scope says so,
 * otherwise a column header.
 *
 * @param {Element} cell <th>.
 * @return {string} rowheader or columnheader.
 */
function headerRole( cell ) {
	const scope = ( cell.getAttribute( 'scope' ) || '' ).toLowerCase();

	return 'row' === scope || 'rowgroup' === scope
		? 'rowheader'
		: 'columnheader';
}

/**
 * Restore table semantics on one table, if its display needs it.
 *
 * @param {HTMLTableElement}          table      Table.
 * @param {function(Element): string} getDisplay Computed `display` of an element.
 * @return {boolean} Whether roles were added.
 */
function restoreTableRoles( table, getDisplay ) {
	if ( TABLE_DISPLAYS.includes( getDisplay( table ) ) ) {
		return false;
	}

	const role = table.getAttribute( 'role' );

	if ( role && 'grid' !== role ) {
		return false;
	}

	table.setAttribute( 'role', 'table' );

	[ table.tHead, ...Array.from( table.tBodies || [] ), table.tFoot ].forEach(
		( section ) => setRoleIfUnset( section, 'rowgroup' )
	);

	Array.from( table.rows || [] ).forEach( ( row ) => {
		setRoleIfUnset( row, 'row' );

		Array.from( row.cells || [] ).forEach( ( cell ) => {
			setRoleIfUnset(
				cell,
				'TH' === cell.tagName ? headerRole( cell ) : 'cell'
			);
		} );
	} );

	return true;
}

function restoreAllTableRoles() {
	const getDisplay = ( node ) => window.getComputedStyle( node ).display;

	document
		.querySelectorAll( 'table' )
		.forEach( ( table ) => restoreTableRoles( table, getDisplay ) );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', restoreAllTableRoles );
	} else {
		restoreAllTableRoles();
	}

	// A phone-width layout that stacks rows into cards only applies after a
	// resize or rotation; restating roles on a table already laid out as a
	// table is harmless, so this only ever adds.
	let resizeTimer = 0;

	window.addEventListener( 'resize', () => {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( restoreAllTableRoles, 250 );
	} );
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { restoreTableRoles, headerRole };
}
