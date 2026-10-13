/**
 * Blueline — sitewide team flyout, desktop hover convenience.
 *
 * <details>/<summary> already toggles on click/tap with no JavaScript at
 * all (see team-flyout.css's own docblock) -- this file adds ONLY the
 * "hovering the tab also opens it, moving the mouse away also closes it"
 * layer on top, for non-touch input, and it does so by setting the real
 * `open` attribute rather than a parallel CSS-only reveal.
 *
 * The previous version of this behaviour was CSS-only: a
 * `.bl-team-flyout:hover .bl-team-flyout__panel { ... }` rule, entirely
 * independent of `[open]`. That meant clicking the tab to close it while
 * still hovering it -- the only way to click it -- toggled the real `open`
 * attribute off correctly, but the separate `:hover` rule kept the panel
 * looking open regardless, since either condition alone was enough to
 * reveal it. Reported live as "clicking the tab doesn't close it again."
 * Making `[open]` the single source of truth here fixes that: a click's
 * native toggle is never fighting a competing always-on hover rule,
 * because there is no longer a second, independent rule.
 *
 * On hover-capable devices a click on the tab pins the panel open (it then
 * ignores mouseleave until the tab is clicked again, Escape is pressed, or
 * the visitor clicks outside), and an unpinned hover-open panel waits
 * HOVER_CLOSE_DELAY_MS before closing.
 *
 * Gated on `(hover: hover)`, matching the same media condition the old CSS
 * rule used -- touch devices report no hover capability, so this never
 * wires up there, and the native click/tap toggle (unaffected by any of
 * this) remains the only interaction on touch, exactly as before.
 */

// Grace period before a hover-opened panel closes, so crossing a gap on the way to a crest doesn't dismiss it.
const HOVER_CLOSE_DELAY_MS = 450;

// Set once the visitor has opened the flyout: the tab's attention pulse has done its job and stops for good (team-flyout.css).
const OPENED_KEY = 'blueline:team-flyout-opened';

/**
 * Whether focus has left the flyout for good, as opposed to moving between
 * its own controls or a click landing on its non-focusable padding.
 *
 * A null relatedTarget means focus went to nothing: the window lost focus, or
 * a pointer press landed on something unfocusable. Pressing inside the open
 * panel (its scrollbar, the gaps between crests) must not close it, so that
 * case only counts as leaving when the pointer is not over the flyout.
 *
 * @param {{contains: Function}} flyout        The flyout element.
 * @param {Element|null}         relatedTarget The element receiving focus, if any.
 * @param {boolean}              pointerInside Whether the pointer is over the flyout.
 * @return {boolean} True when the panel should close.
 */
function focusLeftFlyout( flyout, relatedTarget, pointerInside ) {
	if ( relatedTarget ) {
		return ! flyout.contains( relatedTarget );
	}

	return ! pointerInside;
}

/**
 * Whether this browser has opened the flyout before. Storage can throw
 * (disabled, private mode), in which case the pulse simply keeps its
 * three-pulse cap on every page.
 *
 * @param {{getItem: Function}} storage A localStorage-like object.
 * @return {boolean} True when it was opened on an earlier visit.
 */
function hasOpenedBefore( storage ) {
	try {
		return '1' === storage.getItem( OPENED_KEY );
	} catch {
		return false;
	}
}

/**
 * Remember that the flyout has been opened. A failure to store is not worth
 * surfacing: the pulse is decorative.
 *
 * @param {{setItem: Function}} storage A localStorage-like object.
 */
function rememberOpened( storage ) {
	try {
		storage.setItem( OPENED_KEY, '1' );
	} catch {
		// Intentionally ignored -- see this function's own docblock.
	}
}

if ( typeof document !== 'undefined' ) {
	const flyout = document.querySelector( '.bl-team-flyout' );

	if ( flyout ) {
		// `window.localStorage` itself can throw on access, hence the getter.
		const getStorage = () => {
			try {
				return window.localStorage;
			} catch {
				return null;
			}
		};

		if ( getStorage() && hasOpenedBefore( getStorage() ) ) {
			flyout.classList.add( 'bl-team-flyout--seen' );
		}

		flyout.addEventListener( 'toggle', () => {
			if ( flyout.open ) {
				flyout.classList.add( 'bl-team-flyout--seen' );

				if ( getStorage() ) {
					rememberOpened( getStorage() );
				}
			}
		} );

		// Tabbing (or clicking) away closes it: left open, the panel sat over whatever received focus next.
		flyout.addEventListener( 'focusout', ( event ) => {
			if (
				flyout.open &&
				focusLeftFlyout(
					flyout,
					event.relatedTarget,
					flyout.matches( ':hover' )
				)
			) {
				flyout.open = false;
			}
		} );
	}

	// Escape closes it from the tab or the panel, on any input.
	if ( flyout ) {
		flyout.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' !== event.key || ! flyout.open ) {
				return;
			}

			flyout.open = false;

			const tab = flyout.querySelector( 'summary' );

			if ( tab ) {
				tab.focus();
			}
		} );
	}

	if ( flyout && window.matchMedia( '(hover: hover)' ).matches ) {
		const summary = flyout.querySelector( 'summary' );
		let pinned = false;
		let closeTimer = 0;

		const cancelClose = () => window.clearTimeout( closeTimer );

		// Any close (Escape, native toggle, timer) also drops the pin.
		flyout.addEventListener( 'toggle', () => {
			if ( ! flyout.open ) {
				pinned = false;
				cancelClose();
			}
		} );

		if ( summary ) {
			summary.addEventListener( 'mouseenter', () => {
				cancelClose();
				flyout.open = true;
			} );

			// Click pins it open like a tap does on mobile; clicking the pinned tab closes it.
			summary.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				cancelClose();
				pinned = ! pinned;
				flyout.open = pinned;
			} );
		}

		flyout.addEventListener( 'mouseenter', cancelClose );

		// Leaving the whole flyout (tab or open panel), not just the tab.
		flyout.addEventListener( 'mouseleave', () => {
			if ( pinned ) {
				return;
			}

			cancelClose();
			closeTimer = window.setTimeout( () => {
				flyout.open = false;
			}, HOVER_CLOSE_DELAY_MS );
		} );

		document.addEventListener( 'click', ( event ) => {
			if ( pinned && ! flyout.contains( event.target ) ) {
				flyout.open = false;
			}
		} );
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = {
		focusLeftFlyout,
		hasOpenedBefore,
		rememberOpened,
		OPENED_KEY,
	};
}
