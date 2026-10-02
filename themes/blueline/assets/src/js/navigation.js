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
import { rafThrottle } from './raf-throttle.js';

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

	const trapRoot =
		( toggle && toggle.closest( '.bl-header__inner' ) ) || menu;

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

		// The bar's brand, CTA and X toggle are painted over the drawer, so
		// they belong in the cycle too (A11Y-11).
		const focusable = getFocusable( trapRoot );
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
				window.matchMedia( '(min-width: 960px)' ).matches
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
	 * @param {Element} item The `.bl-nav__item--parent` <li>.
	 * @param {boolean} open Whether the submenu should be open.
	 */
	function setSubmenuOpen( item, open ) {
		const subButton = item.querySelector( ':scope > .bl-nav__toggle-sub' );

		item.setAttribute( 'data-open', open ? 'true' : 'false' );
		if ( open ) {
			item.removeAttribute( 'data-dismissed' );
		}
		if ( subButton ) {
			subButton.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
		syncExpanded();
	}

	const desktopQuery = window.matchMedia( '(min-width: 960px)' );

	function isSubmenuShown( item ) {
		const submenu = item.querySelector( ':scope > .bl-nav__submenu' );
		return (
			!! submenu && 'none' !== window.getComputedStyle( submenu ).display
		);
	}

	/*
	 * Desktop dropdowns also open on :hover/:focus-within (nav.css), which
	 * data-open never sees; mirror what is actually shown (A11Y-06).
	 */
	const syncExpanded = rafThrottle( function () {
		Array.prototype.forEach.call(
			nav.querySelectorAll( '.bl-nav__item--parent' ),
			function ( item ) {
				const subButton = item.querySelector(
					':scope > .bl-nav__toggle-sub'
				);
				if ( subButton ) {
					subButton.setAttribute(
						'aria-expanded',
						isSubmenuShown( item ) ? 'true' : 'false'
					);
				}
			}
		);
	} );

	[ 'focusin', 'focusout', 'mouseover', 'mouseout' ].forEach(
		function ( type ) {
			nav.addEventListener( type, syncExpanded );
		}
	);

	/**
	 * Close every currently open top-level submenu.
	 *
	 * Shared by the outside-click and Escape-key handlers below, both of
	 * which react to "the reader has moved on" by collapsing whatever tap-
	 * opened submenu was left open.
	 *
	 * @param {function(Element): boolean} [shouldClose] Called with each open
	 *                                                   item; skip closing it when this returns false. Omit to close
	 *                                                   every open item unconditionally.
	 */
	function closeAllOpenSubmenus( shouldClose ) {
		Array.prototype.forEach.call(
			nav.querySelectorAll( '.bl-nav__item--parent[data-open="true"]' ),
			function ( openItem ) {
				if ( shouldClose && ! shouldClose( openItem ) ) {
					return;
				}
				setSubmenuOpen( openItem, false );
			}
		);
	}

	const parentItems = nav.querySelectorAll( '.bl-nav__item--parent' );

	Array.prototype.forEach.call( parentItems, function ( item ) {
		const subButton = item.querySelector( ':scope > .bl-nav__toggle-sub' );
		const link = item.querySelector( ':scope > .bl-nav__link' );

		// An Escape dismissal lasts until the pointer or focus comes back.
		item.addEventListener( 'mouseenter', function () {
			item.removeAttribute( 'data-dismissed' );
		} );
		item.addEventListener( 'focusout', function ( event ) {
			if ( ! item.contains( event.relatedTarget ) ) {
				item.removeAttribute( 'data-dismissed' );
			}
		} );

		if ( subButton ) {
			subButton.addEventListener( 'click', function () {
				setSubmenuOpen(
					item,
					'true' !== item.getAttribute( 'data-open' )
				);
			} );
		}

		if ( link && '#' === link.getAttribute( 'href' ) ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				setSubmenuOpen(
					item,
					'true' !== item.getAttribute( 'data-open' )
				);
			} );
		}
	} );

	// Polish beyond the minimum drawer spec: a submenu opened by tap
	// (rather than hover/focus) collapses again on outside click or
	// Escape, so it doesn't stay stuck open after the user moves on.
	document.addEventListener( 'click', function ( event ) {
		closeAllOpenSubmenus( function ( openItem ) {
			return ! openItem.contains( event.target );
		} );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key ) {
			return;
		}
		let focusedItem = null;

		closeAllOpenSubmenus( function ( openItem ) {
			if ( openItem.contains( openItem.ownerDocument.activeElement ) ) {
				focusedItem = openItem;
			}
			return true;
		} );

		/*
		 * A11y finding: closeAllOpenSubmenus() above only ever touches
		 * data-open/aria-expanded -- the JS side of "is this submenu
		 * open". On desktop the dropdown ALSO opens on its own via
		 * nav.css's `:focus-within` rule, which keeps matching for as
		 * long as focus remains anywhere inside `.bl-nav__item`,
		 * independently of the data-open/aria-expanded state just
		 * flipped. Confirmed live: without the focus move below, Escape
		 * correctly announced aria-expanded="false" while the panel
		 * stayed visibly open and un-Tab-able-out-of. Moving focus back
		 * to the trigger both matches the WAI-ARIA disclosure pattern
		 * (focus on the trigger alone does not end :focus-within, though;
		 * see the dismissal below).
		 */
		if ( focusedItem ) {
			const subButton = focusedItem.querySelector(
				':scope > .bl-nav__toggle-sub'
			);

			if ( subButton ) {
				subButton.focus();
			}
		}

		/*
		 * ...but focus on the trigger still satisfies :focus-within, so a
		 * focus- or hover-opened desktop dropdown needs an explicit dismissal
		 * (A11Y-06). Skipped when Escape just closed a nested submenu, so the
		 * parent dropdown stays open for one more Escape.
		 */
		if ( ! desktopQuery.matches ) {
			return;
		}

		const active = nav.ownerDocument.activeElement;
		const topItem =
			active && active.closest
				? active.closest( '.bl-nav__menu > .bl-nav__item--parent' )
				: null;

		if (
			! topItem ||
			( focusedItem && focusedItem !== topItem ) ||
			! isSubmenuShown( topItem )
		) {
			return;
		}

		topItem.setAttribute( 'data-dismissed', '' );
		syncExpanded();

		const topButton = topItem.querySelector(
			':scope > .bl-nav__toggle-sub'
		);

		if ( topButton ) {
			topButton.focus();
		}
	} );
} )();
