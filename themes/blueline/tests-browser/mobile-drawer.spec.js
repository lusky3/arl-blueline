/**
 * Mobile drawer behaviour, checked in a real browser.
 *
 * The bug these exist for was invisible to every other layer: the markup was
 * correct, the JS was correct, aria-expanded was correct, and the drawer's own
 * links were reachable. What was wrong was purely which box painted on top --
 * the drawer (position:fixed, z-index:50) is a descendant of the header bar, so
 * the bar's z-index:60 did nothing for the bar's own children and the drawer
 * covered the hamburger that opens it. Only a hit test at real coordinates
 * catches that.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const SITE = process.env.BLUELINE_SITE_URL || 'https://staging.rookiehockey.ca';

const PHONE = { width: 390, height: 780 };

test.describe( 'mobile drawer', () => {
	test.beforeEach( async ( { page } ) => {
		await page.setViewportSize( PHONE );
		await page.goto( SITE, { waitUntil: 'domcontentloaded' } );
	} );

	/**
	 * Whatever the browser would actually deliver a tap at the centre of the
	 * given element to.
	 *
	 * @param {import('@playwright/test').Page} page     Page under test.
	 * @param {string}                          selector Element to aim at.
	 * @return {Promise<Object>} What was hit and whether it was the target.
	 */
	async function hitTest( page, selector ) {
		return page.evaluate( ( sel ) => {
			const el = document.querySelector( sel );
			const r = el.getBoundingClientRect();
			const hit = document.elementFromPoint(
				Math.round( r.x + r.width / 2 ),
				Math.round( r.y + r.height / 2 )
			);

			return {
				isTarget: hit === el || el.contains( hit ),
				hit: hit
					? hit.tagName +
					  ( hit.className ? '.' + hit.className.toString().split( ' ' )[ 0 ] : '' )
					: null,
			};
		}, selector );
	}

	test( 'the toggle opens the drawer', async ( { page } ) => {
		const toggle = page.locator( '.bl-nav__toggle' );

		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		await toggle.click();
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( page.locator( '.bl-nav__menu' ) ).toBeVisible();
	} );

	/*
	 * THE regression guard. Before the fix this returned the drawer's own UL,
	 * meaning a tap aimed at the hamburger never reached it.
	 */
	test( 'the toggle is still the topmost element while the drawer is open', async ( {
		page,
	} ) => {
		await page.locator( '.bl-nav__toggle' ).click();
		await page.waitForTimeout( 300 );

		const result = await hitTest( page, '.bl-nav__toggle' );

		expect( result.isTarget ).toBe( true );
	} );

	test( 'tapping the toggle again closes the drawer', async ( { page } ) => {
		const toggle = page.locator( '.bl-nav__toggle' );

		await toggle.click();
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );

		// A real click, not a dispatched event: a synthetic event would be
		// delivered to the element regardless of what is painted over it, which
		// is exactly the failure being guarded against.
		await toggle.click();

		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( page.locator( '.bl-nav__menu' ) ).toBeHidden();
	} );

	test( 'the first menu item is not hidden behind the header bar', async ( { page } ) => {
		await page.locator( '.bl-nav__toggle' ).click();
		await page.waitForTimeout( 300 );

		const firstLink = '.bl-nav__menu > li:first-child > a';
		const result = await hitTest( page, firstLink );

		expect( result.isTarget ).toBe( true );

		// And it sits below the bar rather than tucked under it.
		const linkBox = await page.locator( firstLink ).boundingBox();
		const barBox = await page.locator( '.bl-header__inner' ).boundingBox();

		expect( linkBox.y ).toBeGreaterThanOrEqual( barBox.y + barBox.height - 1 );
	} );

	test( 'the brand and account controls stay visible over the drawer', async ( { page } ) => {
		await page.locator( '.bl-nav__toggle' ).click();
		await page.waitForTimeout( 300 );

		expect( ( await hitTest( page, '.bl-header__brand a' ) ).isTarget ).toBe( true );
	} );

	test( 'the toggle shows an X while open', async ( { page } ) => {
		const bars = page.locator( '.bl-nav__toggle-bars' );

		const middleBefore = await bars.evaluate(
			( el ) => window.getComputedStyle( el ).backgroundColor
		);

		await page.locator( '.bl-nav__toggle' ).click();
		await page.waitForTimeout( 300 );

		const state = await bars.evaluate( ( el ) => ( {
			middle: window.getComputedStyle( el ).backgroundColor,
			before: window.getComputedStyle( el, '::before' ).transform,
			after: window.getComputedStyle( el, '::after' ).transform,
		} ) );

		// Middle bar hidden, outer two rotated into a cross.
		expect( state.middle ).not.toBe( middleBefore );
		expect( state.before ).not.toBe( 'none' );
		expect( state.after ).not.toBe( 'none' );
		expect( state.before ).not.toBe( state.after );
	} );

	test( 'Escape still closes the drawer and restores focus', async ( { page } ) => {
		const toggle = page.locator( '.bl-nav__toggle' );

		await toggle.click();
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );

		await page.keyboard.press( 'Escape' );

		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( toggle ).toBeFocused();
	} );

	test( 'the open drawer does not make the page scroll sideways', async ( { page } ) => {
		await page.locator( '.bl-nav__toggle' ).click();
		await page.waitForTimeout( 300 );

		const overflows = await page.evaluate(
			() =>
				document.documentElement.scrollWidth >
				document.documentElement.clientWidth
		);

		expect( overflows ).toBe( false );
	} );
} );
