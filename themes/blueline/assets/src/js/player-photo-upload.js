/**
 * Player Profile's photo pencil-icon: selecting a file submits the form
 * immediately, matching the "click the pencil, pick a photo, it's done"
 * pattern most people already know from other sites.
 *
 * Native, no-JS-required baseline underneath this: the file input itself
 * still works and the form still submits normally (a <label for> already
 * opens the file picker with no JS at all) -- this only removes the extra
 * step of finding and pressing a separate submit button. A visible
 * <noscript> button is the fallback for a visitor without JS, since the
 * only other way to submit here (the tiny pencil circle) is a label for
 * the file input, not a submit control itself.
 */

import { onReady } from './dom-ready.js';

function initPlayerPhotoUpload() {
	const input = document.querySelector( '.bl-player-profile__photo-input' );

	if ( ! input ) {
		return;
	}

	input.addEventListener( 'change', () => {
		if ( input.files && input.files.length ) {
			input.form.submit();
		}
	} );
}

onReady( initPlayerPhotoUpload );
