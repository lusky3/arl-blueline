/**
 * Mobile submenu toggling, the homepage fixture row on a phone, the table edge
 * fade, and the schedule's column headers.
 *
 * The submenu and the fade are both cases where the JS was already correct and
 * the CSS silently overrode it, so both are asserted on what the browser
 * actually computes rather than on state the script believes it set.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const SITE = process.env.BLUELINE_SITE_URL || 'https://staging.rookiehockey.ca';
const PHONE = { width: 390, height: 800 };

test.describe( 'mobile submenu', () => {
	test.beforeEach( async ( { page } ) => {
		await page.setViewportSize( PHONE );
		await page.goto( SITE, { waitUntil: 'domcontentloaded' } );
		await page.click( '.bl-nav__toggle' );
		await page.waitForTimeout( 300 );
	} );

	const read = ( page ) =>
		page.evaluate( () => {
			const item = document.querySelector( '.bl-nav__item--parent' );
			const sub = item.querySelector( ':scope > .bl-nav__submenu' );
			const btn = item.querySelector( ':scope > .bl-nav__toggle-sub' );

			return {
				display: window.getComputedStyle( sub ).display,
				expanded: btn.getAttribute( 'aria-expanded' ),
			};
		} );

	test( 'the chevron opens and then closes the submenu', async ( { page } ) => {
		const chevron = '.bl-nav__item--parent > .bl-nav__toggle-sub';

		expect( ( await read( page ) ).display ).toBe( 'none' );

		await page.click( chevron );
		await page.waitForTimeout( 250 );
		expect( ( await read( page ) ).display ).toBe( 'block' );

		await page.click( chevron );
		await page.waitForTimeout( 250 );

		const after = await read( page );

		// Was 'block' while aria said false: :focus-within kept the submenu
		// open because the button it was toggled with still held focus.
		expect( after.display ).toBe( 'none' );
		expect( after.expanded ).toBe( 'false' );
	} );

	test( 'what is announced matches what is shown', async ( { page } ) => {
		const chevron = '.bl-nav__item--parent > .bl-nav__toggle-sub';

		for ( const _ of [ 1, 2, 3 ] ) {
			await page.click( chevron );
			await page.waitForTimeout( 250 );

			const s = await read( page );
			const shown = 'none' !== s.display;

			expect( shown ).toBe( 'true' === s.expanded );
		}
	} );
} );

test( 'the homepage fixture row stacks on a phone', async ( { page } ) => {
	await page.setViewportSize( PHONE );
	await page.goto( SITE, { waitUntil: 'networkidle' } );

	const geo = await page.evaluate( () => {
		const row = document.querySelector( '.bl-next-games__item' );
		const box = ( sel ) => {
			const r = row.querySelector( sel ).getBoundingClientRect();
			return { x: Math.round( r.x ), w: Math.round( r.width ), y: Math.round( r.y ) };
		};
		return { title: box( '.bl-next-games__title' ), venue: box( '.bl-next-games__venue' ) };
	} );

	// Stacked, not side by side: same left edge, same width, venue below.
	expect( geo.title.x ).toBe( geo.venue.x );
	expect( geo.title.w ).toBe( geo.venue.w );
	expect( geo.venue.y ).toBeGreaterThan( geo.title.y );

	// And the fixture is no longer the squeezed one -- it used to lose most of
	// the row to the arena's long label.
	expect( geo.title.w ).toBeGreaterThan( 200 );
} );

test.describe( 'table edge fade', () => {
	test( 'fades only the side with hidden content', async ( { page } ) => {
		await page.setViewportSize( PHONE );
		await page.goto( `${ SITE }/team/mammoth`, { waitUntil: 'networkidle' } );
		await page.waitForTimeout( 900 );

		const sel =
			'.bl-table-scroll, .sp-scrollable-table-wrapper, table.bl-table-self-scroll';

		const overflow = await page.evaluate( ( s ) => {
			const el = [ ...document.querySelectorAll( s ) ].find(
				( e ) => e.scrollWidth - e.clientWidth > 2
			);
			return el ? el.scrollWidth - el.clientWidth : 0;
		}, sel );

		// Premise: without an overflowing table this asserts nothing.
		expect( overflow ).toBeGreaterThan( 10 );

		const at = async ( position ) =>
			page.evaluate(
				( { s, position: p } ) => {
					const el = [ ...document.querySelectorAll( s ) ].find(
						( e ) => e.scrollWidth - e.clientWidth > 2
					);
					el.scrollLeft = 'end' === p ? el.scrollWidth : 0;
					return new Promise( ( resolve ) =>
						requestAnimationFrame( () =>
							requestAnimationFrame( () =>
								resolve( {
									start: el.hasAttribute( 'data-fade-start' ),
									end: el.hasAttribute( 'data-fade-end' ),
								} )
							)
						)
					);
				},
				{ s: sel, position }
			);

		const start = await at( 'start' );
		expect( start.start ).toBe( false ); // nothing hidden to the left
		expect( start.end ).toBe( true );

		const end = await at( 'end' );
		expect( end.start ).toBe( true );
		expect( end.end ).toBe( false ); // nothing hidden to the right
	} );

	test( 'a table that fits is not faded at all', async ( { page } ) => {
		await page.setViewportSize( { width: 1400, height: 900 } );
		await page.goto( `${ SITE }/team/mammoth`, { waitUntil: 'networkidle' } );
		await page.waitForTimeout( 900 );

		const masked = await page.evaluate( () =>
			[
				...document.querySelectorAll(
					'.bl-table-scroll, .sp-scrollable-table-wrapper, table.bl-table-self-scroll'
				),
			]
				.filter( ( el ) => el.scrollWidth - el.clientWidth <= 2 )
				.map( ( el ) => window.getComputedStyle( el ).maskImage )
		);

		expect( masked.length ).toBeGreaterThan( 0 );

		for ( const mask of masked ) {
			expect( mask ).toBe( 'none' );
		}
	} );
} );

test( 'the schedule has one time column and a result column', async ( { page } ) => {
	await page.setViewportSize( { width: 1200, height: 900 } );
	await page.goto( `${ SITE }/schedule`, { waitUntil: 'networkidle' } );

	const headers = await page.evaluate( () =>
		[ ...document.querySelectorAll( '.sp-event-list thead th' ) ].map( ( th ) =>
			th.textContent.trim()
		)
	);

	expect( headers ).toContain( 'Time' );
	expect( headers ).toContain( 'Result' );

	// The old header read "Time/Results" beside a "Time" column, so the table
	// appeared to have two time columns, one permanently an em dash.
	expect( headers ).not.toContain( 'Time/Results' );
	expect( headers.filter( ( h ) => /time/i.test( h ) ) ).toHaveLength( 1 );
} );
