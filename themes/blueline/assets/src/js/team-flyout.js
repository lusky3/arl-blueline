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
 * Gated on `(hover: hover)`, matching the same media condition the old CSS
 * rule used -- touch devices report no hover capability, so this never
 * wires up there, and the native click/tap toggle (unaffected by any of
 * this) remains the only interaction on touch, exactly as before.
 */

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

		if ( summary ) {
			summary.addEventListener( 'mouseenter', () => {
				flyout.open = true;
			} );
		}

		// Leaving the whole flyout (tab or open panel), not just the tab --
		// moving from the tab into the panel to reach a team link must not
		// close it.
		flyout.addEventListener( 'mouseleave', () => {
			flyout.open = false;
		} );
	}
}
