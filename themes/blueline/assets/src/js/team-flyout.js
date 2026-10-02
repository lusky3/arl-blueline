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

if ( typeof document !== 'undefined' ) {
	const flyout = document.querySelector( '.bl-team-flyout' );

	// Escape closes it from the tab or the panel, on any input (A11Y-07).
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
