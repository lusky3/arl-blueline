/**
 * Band photography, checked where it renders.
 *
 * The unit tests cover which photograph is chosen and that the opacity stays
 * under its contrast ceiling. What they cannot see is whether the layer is
 * actually painted, whether the fallback texture appears where it is not, and
 * whether the two ever show at once -- all of which are cascade outcomes.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const SITE = process.env.BLUELINE_SITE_URL || 'https://staging.rookiehockey.ca';

/**
 * Read the hero's photo layer and its fallback rings.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<Object>} State of both textures.
 */
async function heroTextures( page ) {
	return page.evaluate( () => {
		const hero = document.querySelector( '.bl-hero' );

		if ( ! hero ) {
			return null;
		}

		const before = window.getComputedStyle( hero, '::before' );
		const ring = hero.querySelector( '.bl-hero__ring' );

		return {
			optedIn: hero.classList.contains( 'bl-band-photo' ),
			photoShown: 'none' !== before.display,
			photoOpacity: parseFloat( before.opacity ),
			photoUrl: before.backgroundImage,
			ringShown: !! ring && 'none' !== window.getComputedStyle( ring ).display,
		};
	} );
}

test.describe( 'hero band photography', () => {
	test( 'the photograph paints on a desktop hero', async ( { page } ) => {
		await page.setViewportSize( { width: 1440, height: 800 } );
		await page.goto( SITE, { waitUntil: 'networkidle' } );

		const t = await heroTextures( page );

		expect( t.optedIn ).toBe( true );
		expect( t.photoShown ).toBe( true );
		expect( t.photoUrl ).toContain( '/assets/images/bands/' );
		expect( t.photoUrl ).toContain( '.webp' );
	} );

	test( 'the photograph and the rings are never both visible', async ( { page } ) => {
		for ( const width of [ 1440, 1024, 800, 599, 390 ] ) {
			await page.setViewportSize( { width, height: 800 } );
			await page.goto( SITE, { waitUntil: 'networkidle' } );
			await page.waitForTimeout( 200 );

			const t = await heroTextures( page );

			expect( t.photoShown && t.ringShown, `both textures at ${ width }px` ).toBe(
				false
			);

			// ...and never neither, which is how the first version of this
			// shipped: the photo hidden by the width rule and the rings hidden
			// by the opt-in class, leaving a phone with no texture at all.
			expect( t.photoShown || t.ringShown, `no texture at ${ width }px` ).toBe(
				true
			);
		}
	} );

	test( 'a phone gets the rings, not the photograph', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 780 } );
		await page.goto( SITE, { waitUntil: 'networkidle' } );

		const t = await heroTextures( page );

		expect( t.photoShown ).toBe( false );
		expect( t.ringShown ).toBe( true );
	} );

	test( 'the opacity on the page matches the audited ceiling', async ( { page } ) => {
		await page.setViewportSize( { width: 1440, height: 800 } );
		await page.goto( SITE, { waitUntil: 'networkidle' } );

		const t = await heroTextures( page );

		// 0.20 is where --bl-pale copy on --bl-ink reaches 4.5:1; see
		// BandPhotoTest for the measured table.
		expect( t.photoOpacity ).toBeLessThanOrEqual( 0.2 );
	} );

	test( 'the photograph is actually fetched and is a real image', async ( { page } ) => {
		const responses = [];

		page.on( 'response', ( r ) => {
			if ( r.url().includes( '/assets/images/bands/' ) ) {
				responses.push( { status: r.status(), url: r.url() } );
			}
		} );

		await page.setViewportSize( { width: 1440, height: 800 } );
		await page.goto( SITE, { waitUntil: 'networkidle' } );
		await page.waitForTimeout( 800 );

		expect( responses.length ).toBeGreaterThan( 0 );

		for ( const r of responses ) {
			expect( r.status, r.url ).toBe( 200 );
		}

		// Exactly one: the band pulls a single photograph, not the whole set.
		expect( responses.length ).toBe( 1 );
	} );

	test( 'the photographer is credited in the footer', async ( { page } ) => {
		await page.goto( SITE, { waitUntil: 'domcontentloaded' } );

		await expect( page.locator( '.bl-footer__credit' ) ).toContainText(
			'Michael Durrant'
		);
	} );
} );
