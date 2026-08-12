/**
 * Blueline — SportsPress sponsor loader shift guard.
 *
 * [sponsors] with orderby="rand" (both the header and footer sponsor slots
 * on this site) renders `.sp-sponsors-loader` empty and fills it by AJAX
 * after first paint (sportspress-pro's own sportspress-sponsors.js);
 * SportsPress's own inline script separately moves the whole
 * `.sp-header-sponsors` block into `.bl-header__sponsors` on document
 * ready (sportspress_header_sponsors_selector, inc/sportspress.php). Both
 * are plugin behaviour this theme does not stop -- see sportspress.css's
 * own comment on `.sp-sponsors-loader` and header.css's own comment on
 * `.bl-header__sponsors` for the CSS half of this fix: BOTH elements
 * reserve their final box unconditionally, in plain CSS, from the very
 * first layout -- not gated behind any class this script adds. That is
 * deliberate: a `defer`red script (this one) only runs before
 * DOMContentLoaded, by which point the browser can already have painted
 * the page at its unreserved size, so trying to add a "reserve" class from
 * here would itself be too late to prevent the shift it exists to avoid
 * (measured live during development -- see header.css's own comment for
 * the numbers).
 *
 * What CSS genuinely cannot do on its own is tell "nothing has arrived
 * yet" apart from "a sponsor slot that is enabled but genuinely has
 * nothing to show" -- both look identical, an element with no children.
 * This script's only job is supplying that one missing signal, by
 * collapsing the reservation back down once it is actually confirmed
 * empty:
 *
 *   - `.sp-header-sponsors` never existing anywhere in the initial HTML
 *     means the header slot is off site-wide (SportsPress renders it
 *     synchronously whenever the slot is on at all) -- nothing is ever
 *     coming, so `.bl-header__sponsors` collapses immediately.
 *   - Once SportsPress's own AJAX call for a `.sp-sponsors-loader` has
 *     completed, whether it actually produced any content settles whether
 *     that loader (and its header/footer ancestors) should stay reserved
 *     or collapse.
 *
 * Depends on jQuery deliberately (unlike navigation.js/account.js): the
 * event this needs -- "SportsPress's own sp_sponsors AJAX request has
 * finished" -- is only observable via jQuery's own global ajaxComplete
 * event, since SportsPress issues that request with $.post(). A
 * MutationObserver was tried and discarded: a response that comes back
 * empty produces no DOM mutation at all on a loader that was already
 * empty (jQuery's `.html('')` on an already-empty node changes nothing),
 * so an observer would simply never fire for exactly the "genuinely
 * empty" case this script exists to catch. jQuery is guaranteed present
 * whenever `.sp-sponsors-loader` exists at all -- SportsPress's own script
 * declares it as a dependency -- but this still degrades harmlessly if it
 * is ever missing.
 *
 * @param {Function|undefined} $ jQuery, if loaded.
 */
( function ( $ ) {
	'use strict';

	if ( ! $ ) {
		return;
	}

	const EMPTY_CLASS = 'is-empty';

	const headerBox = document.querySelector( '.bl-header__sponsors' );
	const headerSponsors = document.querySelector( '.sp-header-sponsors' );

	// The header slot is off site-wide -- nothing will ever fill this
	// box, so the unconditional CSS reservation (header.css) collapses
	// right back down.
	if ( headerBox && ! headerSponsors ) {
		headerBox.classList.add( EMPTY_CLASS );
	}

	const loaders = document.querySelectorAll( '.sp-sponsors-loader' );

	if ( ! loaders.length ) {
		return;
	}

	/**
	 * Reconcile one loader's (and its relevant ancestors') reserved/empty
	 * state against whatever content it currently holds.
	 *
	 * @param {Element} loader A `.sp-sponsors-loader` element.
	 */
	function reconcile( loader ) {
		const hasContent =
			loader.children.length > 0 || '' !== loader.textContent.trim();

		loader.classList.toggle( EMPTY_CLASS, ! hasContent );

		const wrapper = loader.closest( '.sp-sponsors' );
		if ( wrapper ) {
			wrapper.classList.toggle( EMPTY_CLASS, ! hasContent );
		}

		const footerBand = loader.closest( '.sp-footer-sponsors' );
		if ( footerBand ) {
			footerBand.classList.toggle( EMPTY_CLASS, ! hasContent );
		}

		if (
			headerBox &&
			headerSponsors &&
			headerSponsors.contains( loader )
		) {
			headerBox.classList.toggle( EMPTY_CLASS, ! hasContent );
		}
	}

	// sportspress-sponsors.js's own $.post({action: 'sp_sponsors', ...})
	// call is the only AJAX request this page ever issues for a sponsor
	// loader, one per `.sp-sponsors-loader` element; ajaxComplete fires
	// after its success callback (self.html(response)) has already run,
	// so every loader's content is settled by the time this re-checks all
	// of them. Re-checking every loader on every matching event (there are
	// never more than two, header + footer) is simpler and just as cheap
	// as tracking which request belonged to which element.
	$( document ).on( 'ajaxComplete', function ( event, xhr, settings ) {
		if (
			! settings ||
			! settings.data ||
			-1 === String( settings.data ).indexOf( 'action=sp_sponsors' )
		) {
			return;
		}

		Array.prototype.forEach.call( loaders, reconcile );
	} );
} )( window.jQuery );
