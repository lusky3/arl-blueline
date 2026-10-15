/**
 * Tidies the Quotes Llama sidebar widget (third-party plugin) as it renders
 * and as it swaps quotes in.
 *
 * The plugin builds its author photo <img> in JavaScript with no alt
 * attribute, so screen readers read out the file URL. The author's name is
 * printed right beside it, which makes the photo decorative: give any such
 * image an empty alt. Images that already carry an alt (even an empty one)
 * are left alone.
 *
 * The theme draws curly quote marks around each quote (layout.css). Quotes
 * typed with their own marks then showed two pairs, and straight " and '
 * inside a quote sat next to the curly ones: the wrapping pair is dropped and
 * the rest are curled.
 */

const WIDGET_SELECTOR = '.widget_widgetquotesllama';
const QUOTE_SELECTOR = '.quotes-llama-widget-next-more';

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
 * Curl straight quote marks. A double or single quote opens at the start of
 * the text or after a space, an opening bracket, a dash or an opening double
 * mark (when a word follows it), and closes everywhere else, so apostrophes
 * (don't, players') become ’. A single quote before a decade ('90s) is an
 * apostrophe too.
 *
 * @param {string} text Quote text.
 * @return {string} The text with typographic marks.
 */
function curlQuotes( text ) {
	return text
		.replace( /(^|[\s([{—–-])"/g, '$1“' )
		.replace( /"/g, '”' )
		.replace( /'(?=\d\ds\b)/g, '’' )
		.replace( /(^|[\s([{“—–-])'(?=\S)/g, '$1‘' )
		.replace( /'/g, '’' );
}

/**
 * One quote's text, ready to sit between the theme's own curly marks: one
 * wrapping pair of double quotes (straight or curly) is dropped, the rest
 * curled.
 *
 * @param {string} text Quote text as the plugin printed it.
 * @return {string} Tidied text.
 */
function tidyQuoteText( text ) {
	const trimmed = String( text ).trim();
	// Only a pair with no other double quote between them: '"Go" is what I
	// "need"' starts and ends with a mark, but they are not one pair.
	const wrapped = /^["“”]([^"“”]*)["“”]$/.exec( trimmed );

	return curlQuotes( wrapped ? wrapped[ 1 ].trim() : trimmed );
}

/**
 * Tidy the quote text inside root. Only text nodes change, so links or
 * emphasis the plugin prints keep their markup; the wrapping pair is taken
 * from the first and last text, when they are the quote's edges.
 *
 * @param {Element|null} root Widget element (or any container).
 * @return {number} How many quotes changed.
 */
function tidyQuotes( root ) {
	if ( ! root || typeof root.querySelectorAll !== 'function' ) {
		return 0;
	}

	let changed = 0;

	root.querySelectorAll( QUOTE_SELECTOR ).forEach( ( quote ) => {
		const nodes = Array.prototype.slice.call( quote.childNodes || [] );
		const texts = nodes.filter( ( node ) => 3 === node.nodeType );

		if ( ! texts.length ) {
			return;
		}

		const first = texts[ 0 ];
		const last = texts[ texts.length - 1 ];
		const before = texts.map( ( node ) => node.nodeValue ).join( '\u0000' );

		if ( 1 === texts.length && 1 === nodes.length ) {
			first.nodeValue = tidyQuoteText( first.nodeValue );
		} else {
			if ( nodes[ 0 ] === first ) {
				first.nodeValue = first.nodeValue.replace( /^\s*["“]/, '' );
			}
			if ( nodes[ nodes.length - 1 ] === last ) {
				last.nodeValue = last.nodeValue.replace( /["”]\s*$/, '' );
			}
			texts.forEach( ( node ) => {
				node.nodeValue = curlQuotes( node.nodeValue );
			} );
		}

		if (
			texts.map( ( node ) => node.nodeValue ).join( '\u0000' ) !== before
		) {
			changed++;
		}
	} );

	return changed;
}

/**
 * Fix each widget as rendered, then keep fixing it as the plugin swaps
 * quotes in.
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
		tidyQuotes( widget );

		if ( typeof window.MutationObserver === 'function' ) {
			// Editing a text node's value is not a childList change, so the
			// tidy below never re-triggers this observer.
			new window.MutationObserver( () => {
				markDecorativeImages( widget );
				tidyQuotes( widget );
			} ).observe( widget, {
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
	module.exports = {
		markDecorativeImages,
		tidyQuoteText,
		tidyQuotes,
		WIDGET_SELECTOR,
	};
}
