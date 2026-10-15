/**
 * Venue map, Past Meetings alignment, and the homepage fixture link.
 *
 * All four assertions here are about rendered geometry or resolved links, not
 * markup: the Past Meetings title had the right class and the right
 * text-align and was still 137px off centre, and the map had the right element
 * at the right size while only two thirds of it was painted.
 */

const { test, expect } = require( '@playwright/test' );
const { findEventPath, findVenuePath, withRequest } = require( './helpers/site-data.js' );

test.describe( 'event page', () => {
	/**
	 * An event whose page has both things measured below: the Past Meetings
	 * tab (the two teams have played before) and the venue map.
	 *
	 * @type {string|null}
	 */
	let eventPath = null;

	test.beforeAll( async ( { playwright }, testInfo ) => {
		// The event-page map is click-to-load: the page carries a placeholder
		// (data-bl-venue-map) and fetches Leaflet only when "Open map" is pressed.
		eventPath = await withRequest( playwright, testInfo, ( request ) =>
			findEventPath( request, [ 'data-sp-tab="past_meetings"', 'data-bl-venue-map' ] )
		);
	} );

	test.beforeEach( async ( { page } ) => {
		test.skip(
			null === eventPath,
			'no recent event on this site has both a Past Meetings tab and a venue map'
		);
		await page.setViewportSize( { width: 1200, height: 1000 } );
		await page.goto( eventPath, { waitUntil: 'networkidle' } );
	} );

	/*
	 * The fixture title is an <h4>, and .entry-content caps headings at 70ch
	 * for readable prose. A CENTRED heading in a container wider than that cap
	 * gets centred inside the shrunken box instead of the container -- measured
	 * 137px off in a 788px cell.
	 */
	test( 'the Past Meetings fixture title is centred in its cell', async ( { page } ) => {
		await page.click( 'a[data-sp-tab="past_meetings"]' );
		await expect( page.locator( '#sp-tab-content-past_meetings' ) ).toBeVisible();

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
		// Before the click: the placeholder, and no map or Leaflet on the page.
		const placeholder = page.locator( '[data-bl-venue-map]' );
		const open = placeholder.getByRole( 'button', { name: 'Open map' } );
		await expect( open ).toBeVisible();
		await expect( page.locator( '.leaflet-container' ) ).toHaveCount( 0 );
		expect( await page.evaluate( () => typeof window.L ) ).toBe( 'undefined' );

		await open.click();
		await page.waitForSelector( '.leaflet-container img.leaflet-tile' );

		// Leaflet keeps adding tiles as it lays the map out; poll the shortfall
		// until the right-most tile reaches the container's edge (or time out).
		// Before the fix the tiles stopped 69px short, leaving a blank strip.
		await expect
			.poll( () =>
				page.evaluate( () => {
					const c = document.querySelector( '.leaflet-container' );
					const r = c.getBoundingClientRect();
					const tiles = [ ...c.querySelectorAll( 'img.leaflet-tile' ) ];
					const tilesRight = Math.max(
						...tiles.map( ( t ) => t.getBoundingClientRect().right )
					);

					return Math.round( r.right ) - Math.round( tilesRight );
				} )
			)
			.toBeLessThanOrEqual( 0 );
	} );
} );

test( 'the arena page shows a map of the arena', async ( { page, request } ) => {
	const venuePath = await findVenuePath( request, [ 'bl-sp-venue-header__map' ] );
	test.skip( null === venuePath, 'no arena on this site has a map' );

	await page.setViewportSize( { width: 1200, height: 1000 } );
	await page.goto( venuePath, { waitUntil: 'networkidle' } );

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
	await page.goto( '/', { waitUntil: 'networkidle' } );

	test.skip(
		0 === ( await page.locator( '.bl-next-games__item' ).count() ),
		'the homepage lists no upcoming games on this site right now'
	);

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
