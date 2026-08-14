/**
 * Sticky header behaviour, checked in a real browser.
 *
 * These assertions exist at this layer on purpose. Every claim below --
 * "the header is fixed", "the logo shrinks", "nothing shifts", "the sponsor is
 * hidden on small screens" -- is a product of cascade, layout and a scroll
 * event together, and is invisible to a PHP unit test and to a markup diff.
 * The markup can be perfect and the header still not stick.
 *
 * Runs against the deployed staging site rather than a fixture, because the
 * thing under test is the built stylesheet plus WordPress' own admin-bar
 * markup, not a hand-assembled page.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const SITE = process.env.BLUELINE_SITE_URL || 'https://staging.rookiehockey.ca';

const REST_LOGO = 80;
const STUCK_LOGO = 48;

/**
 * Read an element's rendered box and a couple of computed values.
 *
 * @param {import('@playwright/test').Page} page     Page under test.
 * @param {string}                          selector CSS selector.
 * @return {Promise<Object>} Measurements.
 */
async function measure( page, selector ) {
	return page.evaluate( ( sel ) => {
		const el = document.querySelector( sel );

		if ( ! el ) {
			return null;
		}

		const rect = el.getBoundingClientRect();
		const cs = window.getComputedStyle( el );

		return {
			top: Math.round( rect.top ),
			height: Math.round( rect.height ),
			width: Math.round( rect.width ),
			position: cs.position,
			display: cs.display,
		};
	}, selector );
}

test.describe( 'sticky header', () => {
	test.beforeEach( async ( { page } ) => {
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.goto( SITE, { waitUntil: 'domcontentloaded' } );
	} );

	test( 'header is fixed and the logo rests at its full size', async ( { page } ) => {
		const header = await measure( page, '.bl-header' );
		const logo = await measure( page, '.bl-header__brand img' );

		expect( header.position ).toBe( 'fixed' );
		expect( logo.height ).toBe( REST_LOGO );

		// A 1:1 mark must stay square at whatever height it is given.
		expect( logo.width ).toBe( logo.height );
	} );

	test( 'the logo shrinks on scroll and returns', async ( { page } ) => {
		expect( ( await measure( page, '.bl-header__brand img' ) ).height ).toBe( REST_LOGO );

		await page.evaluate( () => window.scrollTo( 0, 600 ) );
		await expect
			.poll( async () => ( await measure( page, '.bl-header__brand img' ) ).height )
			.toBe( STUCK_LOGO );

		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await expect
			.poll( async () => ( await measure( page, '.bl-header__brand img' ) ).height )
			.toBe( REST_LOGO );
	} );

	test( 'the header stays pinned to the top of the viewport while scrolled', async ( { page } ) => {
		await page.evaluate( () => window.scrollTo( 0, 1200 ) );
		await page.waitForTimeout( 400 );

		const header = await measure( page, '.bl-header' );

		// Logged out there is no admin bar, so the header sits flush at 0.
		expect( header.top ).toBe( 0 );
	} );

	/*
	 * The whole reason the header is fixed with a constant-height spacer rather
	 * than sticky. A sticky header that shrinks hands its lost height back to
	 * the document and drags the content up under the reader mid-scroll. This
	 * measures the actual symptom -- content moving relative to the scroll
	 * position -- rather than trusting the mechanism.
	 */
	test( 'shrinking the header does not move page content', async ( { page } ) => {
		/**
		 * Where #main sits in the DOCUMENT (not the viewport), which is the
		 * quantity that must not change. If the header gave height back to the
		 * flow when it shrank, everything after it would move up and this
		 * number would drop by exactly that delta.
		 *
		 * @return {Promise<number>} Absolute document offset of #main.
		 */
		const documentTopOfMain = () =>
			page.evaluate( () => {
				const el = document.querySelector( '#main' );
				return Math.round( el.getBoundingClientRect().top + window.scrollY );
			} );

		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.waitForTimeout( 400 );

		const expandedOffset = await documentTopOfMain();
		const spacerAtRest = await measure( page, '.bl-header__spacer' );
		const logoAtRest = await measure( page, '.bl-header__brand img' );

		await page.evaluate( () => window.scrollTo( 0, 600 ) );
		await expect
			.poll( async () => ( await measure( page, '.bl-header__brand img' ) ).height )
			.toBe( STUCK_LOGO );

		const shrunkOffset = await documentTopOfMain();
		const spacerStuck = await measure( page, '.bl-header__spacer' );

		// Guard the premise: the header really did shrink between the two
		// readings, so an equal offset means "did not move", not "never moved".
		expect( logoAtRest.height ).toBe( REST_LOGO );
		expect( spacerStuck.height ).toBe( spacerAtRest.height );

		// The actual claim.
		expect( shrunkOffset ).toBe( expandedOffset );
	} );

	/*
	 * The regression guard. The primary menu is flex:1 and expands to fill the
	 * row, and .bl-container caps that row at 1200px, so there is ~10px spare
	 * at every viewport from 1100 to 1680 -- putting anything else on that line
	 * wraps the menu onto two lines. Measured across the range because a wider
	 * screen does NOT create room; the container stops growing.
	 */
	/*
	 * 1024 is in the range and 960 is not, deliberately: below roughly 960 the
	 * menu already took two rows before any of this work, at the old 48px logo
	 * too (measured), because it is close to the 880px point where nav.css
	 * turns it into a drawer. Asserting one row there would be asserting a bug
	 * fix that was never made.
	 */
	for ( const width of [ 1680, 1440, 1280, 1100, 1024 ] ) {
		test( `the primary menu stays on one line at ${ width }px`, async ( { page } ) => {
			await page.setViewportSize( { width, height: 800 } );
			await page.waitForTimeout( 250 );

			const rows = await page.evaluate( () => {
				const menu = document.querySelector( '.bl-nav__menu' );
				return new Set(
					[ ...menu.children ].map( ( li ) =>
						Math.round( li.getBoundingClientRect().top )
					)
				).size;
			} );

			expect( rows ).toBe( 1 );
		} );
	}

	test( 'the sponsor sits below the bar, aligned to the content edge', async ( { page } ) => {
		const sponsors = await measure( page, '.bl-header__sponsors' );

		if ( 'none' === sponsors.display ) {
			test.skip( true, 'no sponsor slot at this width' );
		}

		const sponsorBox = await page.locator( '.bl-header__sponsors' ).boundingBox();
		const navBox = await page.locator( '.bl-nav' ).boundingBox();

		// Its own strip: below the nav, not overlapping it.
		expect( sponsorBox.y ).toBeGreaterThanOrEqual( navBox.y + navBox.height - 1 );

		const logo = page.locator( '.bl-header__sponsors img' ).first();

		if ( await logo.count() ) {
			const logoBox = await logo.boundingBox();
			const inner = await page.locator( '.bl-header__inner' ).boundingBox();

			// Right-aligned to the same edge the CTA cluster ends on, rather
			// than centred in the band.
			const contentRight = inner.x + inner.width;
			expect( Math.abs( contentRight - ( logoBox.x + logoBox.width ) ) ).toBeLessThan( 40 );

			// And bigger than the 176px cap the brief called out as too small.
			expect( logoBox.width ).toBeGreaterThan( 176 );
		}
	} );

	test( 'the sponsor slot is hidden once the nav becomes a drawer', async ( { page } ) => {
		await page.setViewportSize( { width: 800, height: 900 } );
		await page.waitForTimeout( 200 );

		const sponsors = await measure( page, '.bl-header__sponsors' );

		expect( sponsors.display ).toBe( 'none' );
	} );

	test( 'the page never scrolls horizontally at 360px', async ( { page } ) => {
		await page.setViewportSize( { width: 360, height: 780 } );
		await page.waitForTimeout( 200 );

		const overflows = await page.evaluate(
			() => document.documentElement.scrollWidth > document.documentElement.clientWidth
		);

		expect( overflows ).toBe( false );
	} );
} );

test.describe( 'footer team directory', () => {
	test( 'renders the configured teams as labelled links', async ( { page } ) => {
		await page.goto( SITE, { waitUntil: 'domcontentloaded' } );

		const nav = page.locator( 'nav.bl-footer__teams' );
		await expect( nav ).toHaveAttribute( 'aria-label', 'Teams' );

		const links = nav.locator( '.bl-footer__teams-link' );
		expect( await links.count() ).toBeGreaterThan( 0 );

		// WCAG 2.2 target size, and the reason min-height is on the link rather
		// than the crest: not every team has a crest uploaded.
		const box = await links.first().boundingBox();
		expect( box.height ).toBeGreaterThanOrEqual( 24 );
	} );
} );
