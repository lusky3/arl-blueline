/**
 * Accessible semantics for SportsPress's sortable and paginated tables.
 *
 * SportsPress's own sportspress.js turns every `.sp-data-table` with the
 * sortable or paginated class into a jQuery DataTable (1.10.x). DataTables
 * then marks the table up for a widget it does not implement:
 *
 *   - `role="grid"` on the <table>. That tells a screen reader to switch to
 *     application mode and expect arrow-key cell navigation, which DataTables
 *     never wires up. Removing it falls back to the native table role.
 *   - every sortable <th> becomes a tab stop (`tabindex="0"`) named by an
 *     `aria-label` built from its stripped text. That is 14 extra tab stops per
 *     standings table, and the label drops the column's expansion. Instead the
 *     header's text moves into a real <button>, the <th> leaves the tab order
 *     and loses the label, and DataTables' own `aria-sort` still marks the
 *     sorted column. A click or Enter on the button bubbles to the <th>, where
 *     DataTables' own handler sorts, so the sort logic is untouched.
 *   - the pagination "buttons" are `<a tabindex="0">` with no href and no role.
 *     The disabled one ("Previous" on page 1) stays in the tab order while
 *     invisible, and the current page is not exposed. Each gets role="button";
 *     disabled ones leave the tab order with `aria-disabled`; the current page
 *     gets `aria-current="page"`.
 *
 * DataTables redraws the pagination (and re-sets the header labels) on every
 * draw, so the fixes run again on its `draw.dt` event. Its events are jQuery
 * events, which only jQuery handlers see; jQuery is always present wherever
 * DataTables is, because DataTables needs it.
 *
 * Focus: a page button is destroyed and rebuilt by the redraw it triggers.
 * DataTables re-focuses the rebuilt button itself, except for the first one
 * ("Previous", whose index is 0) and except when that button is now disabled.
 * Either way focus would fall to <body>, so after a draw it goes to the current
 * page's button instead.
 */

const SORTED_CLASSES = [ 'sorting', 'sorting_asc', 'sorting_desc' ];
const SORT_BUTTON_CLASS = 'bl-sort-button';

/**
 * Whether a header cell is one DataTables made sortable.
 *
 * @param {Element} th Header cell.
 * @return {boolean} Sortable.
 */
function isSortableHeader( th ) {
	return SORTED_CLASSES.some( ( name ) => th.classList.contains( name ) );
}

/**
 * Native table semantics and a real sort button per sortable column.
 *
 * Safe to run more than once: a header that already holds its button is only
 * re-cleaned (DataTables puts the `aria-label` back on every sort).
 *
 * @param {Element}  table DataTables-initialised <table>.
 * @param {Document} doc   Document that creates the buttons.
 * @return {void}
 */
function fixSortableHeaders( table, doc ) {
	if ( 'grid' === table.getAttribute( 'role' ) ) {
		table.removeAttribute( 'role' );
	}

	const head = table.tHead;

	if ( ! head ) {
		return;
	}

	head.querySelectorAll( 'th' ).forEach( ( th ) => {
		th.removeAttribute( 'aria-label' );

		if ( ! isSortableHeader( th ) ) {
			return;
		}

		th.removeAttribute( 'tabindex' );

		if ( th.querySelector( `.${ SORT_BUTTON_CLASS }` ) ) {
			return;
		}

		const button = doc.createElement( 'button' );
		button.type = 'button';
		button.className = SORT_BUTTON_CLASS;

		while ( th.firstChild ) {
			button.appendChild( th.firstChild );
		}

		th.appendChild( button );
	} );
}

/**
 * Expose each pagination control's state and keep disabled ones out of the
 * tab order.
 *
 * @param {Element} paginate `.dataTables_paginate` container.
 * @return {void}
 */
function fixPagination( paginate ) {
	paginate.querySelectorAll( '.paginate_button' ).forEach( ( button ) => {
		if ( 'A' === button.tagName && ! button.hasAttribute( 'href' ) ) {
			button.setAttribute( 'role', 'button' );
		}

		if ( button.classList.contains( 'disabled' ) ) {
			button.setAttribute( 'tabindex', '-1' );
			button.setAttribute( 'aria-disabled', 'true' );
		} else {
			button.removeAttribute( 'aria-disabled' );
		}

		if ( button.classList.contains( 'current' ) ) {
			button.setAttribute( 'aria-current', 'page' );
		} else {
			button.removeAttribute( 'aria-current' );
		}
	} );
}

/**
 * The pagination container that belongs to a table, if it has one.
 *
 * @param {Element} table DataTables-initialised <table>.
 * @return {Element|null} `.dataTables_paginate`, or null.
 */
function paginationFor( table ) {
	const wrapper = table.closest( '.dataTables_wrapper' );

	return wrapper ? wrapper.querySelector( '.dataTables_paginate' ) : null;
}

/**
 * Where focus should go after a redraw rebuilt the page buttons, or null to
 * leave it alone.
 *
 * @param {Element|null} lastFocused The element that had focus before the draw.
 * @param {Element|null} active      document.activeElement now.
 * @param {Element|null} body        document.body.
 * @param {Element}      paginate    This table's pagination container.
 * @param {string}       tableId     This table's id (the buttons' aria-controls).
 * @return {Element|null} Button to focus.
 */
function focusTargetAfterDraw( lastFocused, active, body, paginate, tableId ) {
	if ( ! lastFocused || lastFocused.isConnected ) {
		return null;
	}

	if (
		! lastFocused.classList ||
		! lastFocused.classList.contains( 'paginate_button' ) ||
		tableId !== lastFocused.getAttribute( 'aria-controls' )
	) {
		return null;
	}

	if ( active && active !== body ) {
		return null;
	}

	const buttons = Array.from(
		paginate.querySelectorAll( '.paginate_button' )
	);

	return (
		buttons.find( ( button ) => button.classList.contains( 'current' ) ) ||
		buttons.find(
			( button ) => ! button.classList.contains( 'disabled' )
		) ||
		null
	);
}

/**
 * Apply every fix to one table.
 *
 * @param {Element}  table DataTables-initialised <table>.
 * @param {Document} doc   Document.
 * @return {void}
 */
function fixTable( table, doc ) {
	fixSortableHeaders( table, doc );

	const paginate = paginationFor( table );

	if ( paginate ) {
		fixPagination( paginate );
	}
}

/**
 * Space activates a role="button" link, as it would a real button (Enter is
 * already handled by DataTables' own keypress handler).
 *
 * @param {KeyboardEvent} event Keydown.
 * @return {void}
 */
function onPaginationKeydown( event ) {
	const target = event.target;

	if (
		' ' !== event.key ||
		! target.matches ||
		! target.matches(
			'.dataTables_paginate .paginate_button[role="button"]'
		)
	) {
		return;
	}

	event.preventDefault();

	if ( 'true' !== target.getAttribute( 'aria-disabled' ) ) {
		target.click();
	}
}

function initSpDataTables() {
	document
		.querySelectorAll( 'table.dataTable' )
		.forEach( ( table ) => fixTable( table, document ) );

	let lastFocused = null;

	document.addEventListener( 'focusin', ( event ) => {
		lastFocused = event.target;
	} );
	document.addEventListener( 'keydown', onPaginationKeydown );

	if ( ! window.jQuery ) {
		return;
	}

	window.jQuery( document ).on( 'init.dt draw.dt', ( event ) => {
		const table = event.target;

		if ( ! table || 'TABLE' !== table.tagName ) {
			return;
		}

		const doc = table.ownerDocument;

		fixTable( table, doc );

		const paginate = paginationFor( table );
		const target = paginate
			? focusTargetAfterDraw(
					lastFocused,
					doc.activeElement,
					doc.body,
					paginate,
					table.id
				)
			: null;

		if ( target ) {
			target.focus();
		}
	} );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initSpDataTables );
	} else {
		initSpDataTables();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = {
		SORT_BUTTON_CLASS,
		fixSortableHeaders,
		fixPagination,
		focusTargetAfterDraw,
		onPaginationKeydown,
	};
}
