/**
 * Blueline primary navigation.
 *
 * Progressive enhancement over the primary menu markup rendered by
 * Blueline_Nav_Walker: without this script every link still works and
 * every submenu still opens on hover/:focus-within (pure CSS). This adds:
 *
 *   - the mobile drawer (hamburger toggle, focus trap, Escape/outside-click
 *     close, focus restored to the toggle on close);
 *   - a click/tap affordance for opening submenus, which matters on touch
 *     devices that have no hover state at all;
 *   - honouring prefers-reduced-motion by only opting in to the chevron's
 *     CSS transition when the user hasn't asked to reduce motion.
 *
 * Vanilla ES2017+, no dependencies.
 */
( function () {
	'use strict';

	const nav = document.querySelector( '.bl-nav' );
	if ( ! nav ) {
		return;
	}

	const toggle = document.querySelector( '.bl-nav__toggle' );
	const menu = toggle
		? document.getElementById( toggle.getAttribute( 'aria-controls' ) )
		: null;

	if ( ! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		document.documentElement.classList.add( 'bl-motion-ok' );
	}

	const FOCUSABLE_SELECTOR = [
		'a[href]',
		'button:not([disabled])',
		'input:not([disabled])',
		'select:not([disabled])',
		'textarea:not([disabled])',
		'[tabindex]:not([tabindex="-1"])',
	].join( ',' );

	function getFocusable( container ) {
		return Array.prototype.slice
			.call( container.querySelectorAll( FOCUSABLE_SELECTOR ) )
			.filter( function ( el ) {
				return el.offsetParent !== null;
			} );
	}

	let isDrawerOpen = false;

	function onDrawerKeydown( event ) {
		if ( ! isDrawerOpen ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			event.preventDefault();
			closeDrawer( true );
			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		const focusable = getFocusable( menu );
		if ( ! focusable.length ) {
			return;
		}

		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];

		const active = menu.ownerDocument.activeElement;

		if ( event.shiftKey && active === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && active === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	function onOutsideClick( event ) {
		if ( ! isDrawerOpen ) {
			return;
		}
		if (
			menu.contains( event.target ) ||
			toggle.contains( event.target )
		) {
			return;
		}
		closeDrawer( false );
	}

	function openDrawer() {
		isDrawerOpen = true;
		document.body.classList.add( 'bl-nav-open' );
		toggle.setAttribute( 'aria-expanded', 'true' );

		// Listeners are added on the CAPTURING phase, which has already
		// run for the click that opened the drawer by the time this
		// handler executes — so this does not immediately re-fire and
		// close the drawer it just opened.
		document.addEventListener( 'keydown', onDrawerKeydown, true );
		document.addEventListener( 'click', onOutsideClick, true );

		const focusable = getFocusable( menu );
		if ( focusable.length ) {
			focusable[ 0 ].focus();
		}
	}

	function closeDrawer( restoreFocus ) {
		isDrawerOpen = false;
		document.body.classList.remove( 'bl-nav-open' );
		toggle.setAttribute( 'aria-expanded', 'false' );

		document.removeEventListener( 'keydown', onDrawerKeydown, true );
		document.removeEventListener( 'click', onOutsideClick, true );

		if ( restoreFocus ) {
			toggle.focus();
		}
	}

	if ( toggle && menu ) {
		toggle.addEventListener( 'click', function () {
			if ( isDrawerOpen ) {
				closeDrawer( false );
			} else {
				openDrawer();
			}
		} );

		window.addEventListener( 'resize', function () {
			if (
				isDrawerOpen &&
				window.matchMedia( '(min-width: 880px)' ).matches
			) {
				closeDrawer( false );
			}
		} );
	}

	/**
	 * Submenu click/tap toggles.
	 *
	 * Each menu item with children (Blueline_Nav_Walker) has a sibling
	 * <button class="bl-nav__toggle-sub" aria-expanded aria-controls>.
	 * A parent link whose href is a bare "#" (e.g. "League Info", which
	 * exists only to hold a submenu) has nowhere to navigate to, so
	 * clicking/tapping the label itself toggles the submenu too.
	 *
	 * @param {Element}      item      The `.bl-nav__item--parent` <li>.
	 * @param {Element|null} subButton Its `.bl-nav__toggle-sub` <button>, if any.
	 * @param {boolean}      open      Whether the submenu should be open.
	 */
	function setSubmenuOpen( item, subButton, open ) {
		item.setAttribute( 'data-open', open ? 'true' : 'false' );
		if ( subButton ) {
			subButton.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	}

	const parentItems = nav.querySelectorAll( '.bl-nav__item--parent' );

	Array.prototype.forEach.call( parentItems, function ( item ) {
		const subButton = item.querySelector( ':scope > .bl-nav__toggle-sub' );
		const link = item.querySelector( ':scope > .bl-nav__link' );

		if ( subButton ) {
			subButton.addEventListener( 'click', function () {
				setSubmenuOpen(
					item,
					subButton,
					'true' !== item.getAttribute( 'data-open' )
				);
			} );
		}

		if ( link && '#' === link.getAttribute( 'href' ) ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				setSubmenuOpen(
					item,
					subButton,
					'true' !== item.getAttribute( 'data-open' )
				);
			} );
		}
	} );

	// Polish beyond the minimum drawer spec: a submenu opened by tap
	// (rather than hover/focus) collapses again on outside click or
	// Escape, so it doesn't stay stuck open after the user moves on.
	document.addEventListener( 'click', function ( event ) {
		Array.prototype.forEach.call(
			nav.querySelectorAll( '.bl-nav__item--parent[data-open="true"]' ),
			function ( openItem ) {
				if ( ! openItem.contains( event.target ) ) {
					setSubmenuOpen(
						openItem,
						openItem.querySelector(
							':scope > .bl-nav__toggle-sub'
						),
						false
					);
				}
			}
		);
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key ) {
			return;
		}
		Array.prototype.forEach.call(
			nav.querySelectorAll( '.bl-nav__item--parent[data-open="true"]' ),
			function ( openItem ) {
				setSubmenuOpen(
					openItem,
					openItem.querySelector( ':scope > .bl-nav__toggle-sub' ),
					false
				);
			}
		);
	} );
} )();
