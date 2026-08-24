/**
 * Put the calendar link matching the reader's platform first.
 *
 * There is no one subscribe URL that works everywhere. `webcal://` is what iOS
 * and macOS hand straight to Calendar, and what Outlook takes on Windows;
 * Android mostly ignores it and wants Google's own add-by-URL screen instead.
 * So the template renders both and this decides which leads.
 *
 * Deliberately REORDERS rather than hides. Platform detection from a user-agent
 * string is a guess, and the cost of guessing wrong has to stay "the reader
 * takes the second link" rather than "the reader cannot subscribe at all". A
 * desktop reader is also entitled to whichever calendar they actually use, so
 * on anything that is not clearly a phone both stay exactly as authored.
 */

import { onReady } from './dom-ready.js';

const WRAPPER = '[data-calendar-links]';

/**
 * Which calendar this device most likely wants, or null when it is not clearly
 * one or the other.
 *
 * Uses the platform hints where they exist and falls back to the UA string.
 * iPadOS reports itself as a Mac, so the touch-point check is what separates a
 * modern iPad from a desktop Mac -- both of which want webcal anyway, which is
 * why that branch is not laboured further.
 *
 * @return {string|null} 'apple', 'google', or null.
 */
function preferredCalendar() {
	const ua = window.navigator.userAgent || '';

	if ( /android/i.test( ua ) ) {
		return 'google';
	}

	if ( /iphone|ipad|ipod/i.test( ua ) ) {
		return 'apple';
	}

	// iPadOS 13+ masquerades as macOS; a Mac with a touchscreen is not a thing
	// that ships, so touch points disambiguate.
	if ( /macintosh/i.test( ua ) && window.navigator.maxTouchPoints > 1 ) {
		return 'apple';
	}

	return null;
}

function initCalendarLinks() {
	const wrappers = document.querySelectorAll( WRAPPER );

	if ( ! wrappers.length ) {
		return;
	}

	const preferred = preferredCalendar();

	if ( ! preferred ) {
		return;
	}

	wrappers.forEach( ( wrapper ) => {
		const match = wrapper.querySelector(
			`[data-calendar="${ preferred }"]`
		);

		if ( ! match ) {
			return;
		}

		// order:-1 rather than moving the node: the DOM order is the no-JS
		// order and stays authoritative for anyone reading the source or
		// tabbing with CSS disabled.
		match.style.order = '-1';
		wrapper.setAttribute( 'data-calendar-preferred', preferred );
	} );
}

onReady( initCalendarLinks );
