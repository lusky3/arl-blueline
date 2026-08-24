/**
 * Venue map, Past Meetings alignment, and the homepage fixture link.
 *
 * All four assertions here are about rendered geometry or resolved links, not
 * markup: the Past Meetings title had the right class and the right
 * text-align and was still 137px off centre, and the map had the right element
 * at the right size while only two thirds of it was painted.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { SITE } = require( './helpers/site.js' );
const EVENT = `${ SITE }/?p=116460&post_type=sp_event`;

test.describe( 'event page', () => {
	test.beforeEach( async ( { page } ) => {
		await page.setViewportSize( { width: 1200, height: 1000 } );
		await page.goto( EVENT, { waitUntil: 'networkidle' } );
	} );

	/*
	 * The fixture title is an <h4>, and .entry-content caps headings at 70ch
	 * for readable prose. A CENTRED heading in a container wider than that cap
	 * gets centred inside the shrunken box instead of the container -- measured
	 * 137px off in a 788px cell.
	 */
	test( 'the Past Meetings fixture title is centred in its cell', async ( { page } ) => {
		await page.click( 'a[data-sp-tab="past_meetings"]' );
		await page.waitForTimeout( 500 );

		const geo = await page.evaluate( () => {
			const cell = document.querySelector(
				'#sp-tab-content-past_meetings .sp-event-blocks tbody td'
			);
			const title = cell.querySelector( '.sp-event-title' );

			const range = document.createRange();
			range.selectNodeContents( title );
			const t = range.getBoundingClientRect();
			const c = cell.getBoundingClientRect();

			return {
				titleMid: Math.round( t.x + t.width / 2 ),
				cellMid: Math.round( c.x + c.width / 2 ),
				cellWidth: Math.round( c.width ),
			};
		} );

		// Guard the premise: below ~70ch the cap never engages and this would
		// pass without proving anything.
		expect( geo.cellWidth ).toBeGreaterThan( 600 );
		expect( Math.abs( geo.titleMid - geo.cellMid ) ).toBeLessThanOrEqual( 2 );
	} );

	test( 'the venue map paints its whole container', async ( { page } ) => {
		await page.waitForSelector( '.leaflet-container img.leaflet-tile' );
		await page.waitForTimeout( 1500 );

		const cover = await page.evaluate( () => {
			const c = document.querySelector( '.leaflet-container' );
			const r = c.getBoundingClientRect();
			const tiles = [ ...c.querySelectorAll( 'img.leaflet-tile' ) ];

			return {
				containerRight: Math.round( r.right ),
				tilesRight: Math.round(
					Math.max( ...tiles.map( ( t ) => t.getBoundingClientRect().right ) )
				),
			};
		} );

		// Tiles must reach at least the container's edge. Before the fix they
		// stopped 69px short, leaving a blank strip.
		expect( cover.tilesRight ).toBeGreaterThanOrEqual( cover.containerRight );
	} );
} );

test( 'the arena page shows a map of the arena', async ( { page } ) => {
	await page.setViewportSize( { width: 1200, height: 1000 } );
	await page.goto( `${ SITE }/venue/red`, { waitUntil: 'networkidle' } );

	const map = page.locator( '.bl-sp-venue-header__map .leaflet-container' );
	await expect( map ).toBeVisible();

	const boxSize = await map.boundingBox();
	expect( boxSize.width ).toBeGreaterThan( 200 );
	expect( boxSize.height ).toBeGreaterThan( 100 );
} );

test( 'a homepage fixture links to its event, and the arena to the arena', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1200, height: 1000 } );
	await page.goto( SITE, { waitUntil: 'networkidle' } );

	const row = page.locator( '.bl-next-games__item' ).first();

	const title = row.locator( 'a.bl-next-games__title' );
	await expect( title ).toHaveCount( 1 );
	await expect( title ).toHaveAttribute( 'href', /\/event\// );

	const venue = row.locator( 'a.bl-next-games__venue' );
	await expect( venue ).toHaveAttribute( 'href', /\/venue\// );

	// Two destinations, so neither may be nested inside the other -- browsers
	// drop an inner anchor and the row would silently lose one of them.
	const nested = await row.evaluate(
		( el ) => !! el.querySelector( 'a a' )
	);
	expect( nested ).toBe( false );
} );
