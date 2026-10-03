/**
 * A11Y-08: the Quotes Llama sidebar widget (third-party plugin) builds its
 * author photo <img> in JavaScript with no alt attribute, so screen readers
 * read out the file URL. The author's name is printed right beside it, which
 * makes the photo decorative: give any such image an empty alt. Images that
 * already carry an alt (even an empty one) are left alone.
 */

const WIDGET_SELECTOR = '.widget_widgetquotesllama';

/**
 * Set alt="" on every img inside root that has no alt attribute at all.
 *
 * @param {Element|null} root Widget element (or any container).
 * @return {number} How many images were changed.
 */
function markDecorativeImages( root ) {
	if ( ! root || typeof root.querySelectorAll !== 'function' ) {
		return 0;
	}

	let changed = 0;

	root.querySelectorAll( 'img:not([alt])' ).forEach( ( img ) => {
		img.setAttribute( 'alt', '' );
		changed++;
	} );

	return changed;
}

/**
 * Fix images already in each widget, then keep fixing them as the plugin
 * swaps quotes in.
 *
 * @return {void}
 */
function initQuotesLlamaAlt() {
	const widgets = document.querySelectorAll( WIDGET_SELECTOR );

	if ( ! widgets.length ) {
		return;
	}

	widgets.forEach( ( widget ) => {
		markDecorativeImages( widget );

		if ( typeof window.MutationObserver === 'function' ) {
			new window.MutationObserver( () =>
				markDecorativeImages( widget )
			).observe( widget, {
				childList: true,
				subtree: true,
				attributes: true,
				attributeFilter: [ 'alt', 'src' ],
			} );
		}
	} );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initQuotesLlamaAlt );
	} else {
		initQuotesLlamaAlt();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { markDecorativeImages, WIDGET_SELECTOR };
}
