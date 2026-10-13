/**
 * Blueline — keep keyboard focus somewhere real when a dismissible piece of
 * chrome (the announcement banner, the floating next-game widget) hides
 * itself.
 *
 * Setting `hidden` on an element that holds focus drops focus to <body>, so
 * the next Tab restarts from the top of the document and a screen reader
 * loses its place. This moves focus first -- but only when it is actually
 * inside the thing being dismissed, so a mouse click on a browser that does
 * not focus buttons (Safari) never yanks the page around.
 */

/**
 * The first candidate that can still receive focus, or null. A candidate
 * that has since been removed from the document (or is not focusable at all)
 * is skipped, so the caller can list "where the visitor just was" ahead of a
 * guaranteed landmark such as <main>.
 *
 * @param {Array<Element|null|undefined>} candidates Preferred targets, best first.
 * @return {Element|null} The first usable target.
 */
function pickFocusTarget( candidates ) {
	for ( const candidate of candidates ) {
		if (
			candidate &&
			candidate.isConnected &&
			'function' === typeof candidate.focus
		) {
			return candidate;
		}
	}

	return null;
}

/**
 * If focus is inside `container`, move it to the first usable candidate.
 * Call BEFORE hiding the container: focus() on an element inside a hidden
 * subtree is a no-op, and hiding first has already lost the position.
 *
 * @param {Element}                       container  The element about to be hidden.
 * @param {Array<Element|null|undefined>} candidates Preferred targets, best first.
 * @param {Element|null}                  active     The currently focused element.
 * @return {boolean} Whether focus was moved.
 */
function moveFocusOut( container, candidates, active ) {
	if ( ! active || ! container.contains( active ) ) {
		return false;
	}

	const target = pickFocusTarget( candidates );

	if ( ! target ) {
		return false;
	}

	// preventScroll: the visitor dismissed something, they did not ask to be
	// taken somewhere.
	target.focus( { preventScroll: true } );

	return true;
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { pickFocusTarget, moveFocusOut };
}
