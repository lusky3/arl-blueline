/**
 * Which controls open a primary-nav submenu, for navigation.js.
 *
 * Blueline_Nav_Walker renders a parent item one of two ways:
 *
 *   - a parent with a destination: its link, plus a chevron
 *     <button class="bl-nav__toggle-sub"> that is the keyboard control;
 *   - a parent whose URL is "#" (e.g. "League Info"): the label itself is a
 *     <button class="bl-nav__link"> disclosure, and its chevron is a
 *     pointer-only duplicate (tabindex="-1", aria-hidden).
 *
 * Both buttons of the second kind carry aria-expanded (the chevron's drives its
 * rotation in nav.css), so every state change goes to all of an item's
 * triggers, while focus only ever goes to the one a keyboard can reach.
 */

/**
 * The item's own label control and chevron, in that order, where present.
 *
 * @param {Element} item `.bl-nav__item--parent` <li>.
 * @return {{label: Element|null, chevron: Element|null}} Controls.
 */
function itemControls( item ) {
	return {
		label: item.querySelector( ':scope > .bl-nav__link' ),
		chevron: item.querySelector( ':scope > .bl-nav__toggle-sub' ),
	};
}

/**
 * Whether the item's label is itself the disclosure button.
 *
 * @param {Element|null} label `.bl-nav__link`.
 * @return {boolean} Is a button.
 */
function isDisclosureLabel( label ) {
	return !! label && 'BUTTON' === label.tagName;
}

/**
 * Every control whose aria-expanded mirrors the submenu.
 *
 * @param {Element} item `.bl-nav__item--parent` <li>.
 * @return {Element[]} Controls.
 */
function submenuTriggers( item ) {
	const { label, chevron } = itemControls( item );

	return [ isDisclosureLabel( label ) ? label : null, chevron ].filter(
		Boolean
	);
}

/**
 * The trigger a keyboard user can reach: the label button for a "#" parent,
 * otherwise the chevron.
 *
 * @param {Element} item `.bl-nav__item--parent` <li>.
 * @return {Element|null} Control to focus.
 */
function focusableTrigger( item ) {
	const { label, chevron } = itemControls( item );

	return isDisclosureLabel( label ) ? label : chevron;
}

/**
 * Whether a click on the item's label should toggle its submenu instead of
 * navigating: a disclosure button, or a legacy bare "#" link.
 *
 * @param {Element|null} label `.bl-nav__link`.
 * @return {boolean} Toggles.
 */
function labelToggles( label ) {
	return (
		isDisclosureLabel( label ) ||
		( !! label && '#' === label.getAttribute( 'href' ) )
	);
}

/**
 * Whether a click on an item's disclosure control (or Enter/Space on it)
 * leaves the submenu open.
 *
 * It toggles what is shown, not the data-open flag: on desktop a dropdown also
 * shows through :hover and :focus-within, so a keyboard user who tabbed onto
 * "League Info" sees it open with data-open never set, and Enter must close
 * it. The exception is a pointer resting on a dropdown that hover opened and
 * no click has pinned: a click there pins it open rather than closing what the
 * user just watched appear.
 *
 * @param {Object}  state             Current state.
 * @param {boolean} state.shown       The submenu is visible now.
 * @param {boolean} state.pinned      data-open is "true".
 * @param {boolean} state.pointerOver A pointer click on a desktop item the pointer is over.
 * @return {boolean} Open afterwards.
 */
function disclosureNextOpen( { shown, pinned, pointerOver } ) {
	if ( pointerOver && ! pinned ) {
		return true;
	}

	return ! shown;
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = {
		itemControls,
		submenuTriggers,
		focusableTrigger,
		labelToggles,
		disclosureNextOpen,
	};
}
