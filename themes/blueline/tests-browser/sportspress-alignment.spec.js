/**
 * Alignment of the SportsPress fixture surfaces.
 *
 * SportsPress renders a fixture mirrored -- home is name-then-crest, away is
 * crest-then-name, and its always-loaded core stylesheet aligns the schedule's
 * two team columns inward. On the event page that is only symmetric when both
 * team names happen to be the same length, so the lockup there is rebuilt as two
 * equal columns; the assertions are geometric because "is the kick-off time
 * actually on the centre line" is the property that was broken, and no amount of
 * checking class names would have caught it.
 *
 * The schedule TABLE is the opposite case: Result is a fixed-width column and
 * Home/Away share equal widths, so the mirrored lockup is symmetric by
 * construction there and is kept. What broke it was centring each crest-and-name
 * group in its cell, which put a crest wherever the name's length dictated.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { SITE } = require( './helpers/site.js' );

// Hammers (a long name) vs Kings (a short one) -- the pairing that exposed the
// drift, since the error is half the difference between the two names.
const EVENT = `${ SITE }/?p=116460&post_type=sp_event`;

/**
 * Rounded box for a selector.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @param {string}                          sel  CSS selector.
 * @return {Promise<Object>} Box with midpoints.
 * @throws {Error} If no element matches `sel`.
 */
async function box( page, sel ) {
	return page.evaluate( ( s ) => {
		const el = document.querySelector( s );

		if ( ! el ) {
			throw new Error( `box(): no element matched selector "${ s }"` );
		}

		const r = el.getBoundingClientRect();

		return {
			x: Math.round( r.x ),
			y: Math.round( r.y ),
			w: Math.round( r.width ),
			h: Math.round( r.height ),
			midX: Math.round( r.x + r.width / 2 ),
			midY: Math.round( r.y + r.height / 2 ),
		};
	}, sel );
}

test.describe( 'event fixture lockup', () => {
	test.beforeEach( async ( { page } ) => {
		await page.setViewportSize( { width: 1200, height: 900 } );
		await page.goto( EVENT, { waitUntil: 'networkidle' } );
	} );

	test( 'the kick-off time sits on the lockup centre line', async ( { page } ) => {
		const wrap = await box( page, '.sp-event-logos' );
		const time = await box( page, '.sp-event-logos .sp-event-logos-time' );

		// Was 16px off -- exactly half the difference between "Hammers" and
		// "Kings" -- because the names sat on the outside.
		expect( Math.abs( time.midX - wrap.midX ) ).toBeLessThanOrEqual( 2 );
	} );

	test( 'both teams take an equal share of the row', async ( { page } ) => {
		const widths = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.sp-event-logos .sp-team-logo' ) ].map( ( el ) =>
				Math.round( el.getBoundingClientRect().width )
			)
		);

		expect( widths ).toHaveLength( 2 );
		expect( Math.abs( widths[ 0 ] - widths[ 1 ] ) ).toBeLessThanOrEqual( 1 );
	} );

	test( 'every crest sits above its own team name', async ( { page } ) => {
		const stacked = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.sp-event-logos .sp-team-logo' ) ].map( ( el ) => {
				const img = el.querySelector( 'img' ).getBoundingClientRect();
				const name = el.querySelector( '.sp-team-name' ).getBoundingClientRect();

				return {
					crestAbove: img.bottom <= name.top + 1,
					centred: Math.abs(
						img.x + img.width / 2 - ( name.x + name.width / 2 )
					) <= 2,
				};
			} )
		);

		expect( stacked ).toHaveLength( 2 );

		for ( const team of stacked ) {
			expect( team.crestAbove ).toBe( true );
			expect( team.centred ).toBe( true );
		}
	} );

	test( 'the two crests are symmetric about the time', async ( { page } ) => {
		const time = await box( page, '.sp-event-logos .sp-event-logos-time' );
		const mids = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.sp-event-logos .sp-team-logo img' ) ].map( ( el ) => {
				const r = el.getBoundingClientRect();
				return Math.round( r.x + r.width / 2 );
			} )
		);

		const left = time.midX - mids[ 0 ];
		const right = mids[ 1 ] - time.midX;

		expect( Math.abs( left - right ) ).toBeLessThanOrEqual( 2 );
	} );
} );

test.describe( 'schedule table team columns', () => {
	test.beforeEach( async ( { page } ) => {
		await page.setViewportSize( { width: 1200, height: 900 } );
		await page.goto( `${ SITE }/schedule`, { waitUntil: 'networkidle' } );
	} );

	test( 'home and away cells and their headers point inward', async ( { page } ) => {
		const aligns = await page.evaluate( () => {
			const pick = ( sel ) => {
				const el = document.querySelector( sel );
				return el ? window.getComputedStyle( el ).textAlign : null;
			};

			return {
				homeCell: pick( '.sp-event-list td.data-home' ),
				awayCell: pick( '.sp-event-list td.data-away' ),
				homeHead: pick( '.sp-event-list th.data-home' ),
				awayHead: pick( '.sp-event-list th.data-away' ),
			};
		} );

		// Home hugs the Result column from the left, Away from the right. These
		// were `center` when each crest-and-name group floated in the middle of
		// its cell, which let the crest wander with the name's length.
		expect( aligns.homeCell ).toBe( 'end' );
		expect( aligns.homeHead ).toBe( 'end' );
		expect( aligns.awayCell ).toBe( 'start' );
		expect( aligns.awayHead ).toBe( 'start' );
	} );

	test( 'the lockups mirror: home is name-then-crest, away is crest-then-name', async ( { page } ) => {
		const order = await page.evaluate( () => {
			const read = ( sel ) => {
				const cell = document.querySelector( sel );

				if ( ! cell ) {
					return null;
				}

				const img = cell.querySelector( 'img' );

				if ( ! img ) {
					return null;
				}

				// The team name is a bare text node with no element to measure,
				// so range over THAT NODE only. Ranging over the whole link
				// would include the crest and make the comparison below
				// trivially true.
				const link = cell.querySelector( 'a' );
				const textNode = [ ...link.childNodes ].find(
					( n ) => n.nodeType === Node.TEXT_NODE && n.textContent.trim().length
				);

				if ( ! textNode ) {
					return null;
				}

				const range = document.createRange();
				range.selectNode( textNode );

				return {
					crestX: Math.round( img.getBoundingClientRect().x ),
					nameX: Math.round( range.getBoundingClientRect().x ),
					name: textNode.textContent.trim(),
				};
			};

			return {
				home: read( '.sp-event-list td.data-home.has-logo' ),
				away: read( '.sp-event-list td.data-away.has-logo' ),
			};
		} );

		// Both sides must actually be measurable, or this asserts nothing.
		expect( order.home ).not.toBeNull();
		expect( order.away ).not.toBeNull();

		// Home: the name sits left of its crest, so the crest is the edge next to
		// the Result column. Away: the crest leads, for the same reason.
		expect(
			order.home.crestX,
			`home cell (${ order.home.name }) should end with its crest`
		).toBeGreaterThan( order.home.nameX );
		expect(
			order.away.crestX,
			`away cell (${ order.away.name }) should lead with its crest`
		).toBeLessThan( order.away.nameX );
	} );

	test( 'every crest sits the same distance from the Result column in every row', async ( { page } ) => {
		const gaps = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.sp-event-list tbody tr' ) ]
				.map( ( row ) => {
					const home = row.querySelector( 'td.data-home img' );
					const away = row.querySelector( 'td.data-away img' );
					const time = row.querySelector( 'td.data-time' );

					if ( ! home || ! away || ! time ) {
						return null;
					}

					const t = time.getBoundingClientRect();

					return {
						home: Math.round( t.left - home.getBoundingClientRect().right ),
						away: Math.round( away.getBoundingClientRect().left - t.right ),
					};
				} )
				.filter( Boolean )
		);

		// Enough rows that the spread below means something.
		expect( gaps.length ).toBeGreaterThanOrEqual( 3 );

		const spread = ( key ) => {
			const values = gaps.map( ( g ) => g[ key ] );
			return Math.max( ...values ) - Math.min( ...values );
		};

		// Crests are 30-32px boxes, so a couple of pixels is image shape, not
		// drift. Centred groups put the same crest 16-56px apart row to row.
		expect( spread( 'home' ), 'home crest distance varies by row' ).toBeLessThanOrEqual( 3 );
		expect( spread( 'away' ), 'away crest distance varies by row' ).toBeLessThanOrEqual( 3 );

		// And the two sides mirror each other.
		for ( const gap of gaps ) {
			expect( Math.abs( gap.home - gap.away ) ).toBeLessThanOrEqual( 3 );
		}
	} );
} );
