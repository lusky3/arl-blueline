/**
 * "Jump to a teammate" player selector: navigate on the "Go" button's
 * activation, never on the <select>'s own `change` event.
 *
 * sportspress/player-selector.php's own docblock explains why: SportsPress's
 * stock `.sp-selector-redirect` binding (its always-loaded
 * assets/js/sportspress.js) navigates on a bare `change`, and arrow-keying a
 * CLOSED <select> fires `change` per keystroke in every major browser
 * without opening the options list -- so a keyboard/screen-reader user
 * previewing the list got redirected on the first keypress. This script
 * deliberately does not bind `change` at all; only a real click/Enter/Space
 * activation of the "Go" button navigates, matching WCAG 3.2.2's requirement
 * that a change of context follow an explicit submission, not a value edit.
 *
 * Vanilla ES2017+, no dependencies.
 */
( function () {
	'use strict';

	document
		.querySelectorAll( '.bl-sp-player-selector__go' )
		.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				const row = button.closest( '.bl-sp-player-selector__row' );
				const select = row
					? row.querySelector( '.sp-player-selector' )
					: null;
				const url = select ? select.value : '';

				if ( url ) {
					window.location.assign( url );
				}
			} );
		} );
} )();
