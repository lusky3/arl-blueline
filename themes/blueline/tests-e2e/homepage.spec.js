const { test, expect } = require( '@playwright/test' );
const { assertNoPhpErrors } = require( './helpers/assert-no-php-errors' );
const {
	assertNoA11yViolations,
} = require( './helpers/assert-no-a11y-violations' );

/**
 * Must match .github/workflows/e2e.yml's own seeding step -- overridable
 * via env var so a local run against a differently-seeded environment
 * doesn't require editing this file, same convention as
 * sportspress-team.spec.js's team fixtures.
 */
const seasonLabel = process.env.BLUELINE_E2E_SEASON_LABEL || 'E2E Test Season';

test.describe( 'Homepage, against a clean WordPress + WooCommerce + SportsPress install', () => {
	test( 'loads, is served by this theme, and leaks no PHP error', async ( { page } ) => {
		const response = await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		expect( response.status() ).toBe( 200 );

		const html = await page.content();
		assertNoPhpErrors( html, 'homepage' );

		// wp_body_open()/body_class() should carry the theme's own body
		// classes -- confirms the environment actually activated blueline,
		// not just that SOME theme rendered a 200.
		await expect( page.locator( 'body.theme-blueline' ) ).toHaveCount( 1 );
	} );

	test( 'has no critical/serious axe-core accessibility violations', async ( {
		page,
	} ) => {
		await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		await assertNoA11yViolations( page, 'homepage' );
	} );

	test( "loads the theme's own stylesheet, not a fallback theme's", async ( { page } ) => {
		await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		const styleHref = await page
			.locator( '#blueline-tokens-css' )
			.getAttribute( 'href' );

		expect( styleHref ).toContain( '/wp-content/themes/blueline/style.css' );
	} );
} );

/**
 * Covers blueline_homepage_standings_tabs() (inc/homepage-modules.php)
 * against real sp_table posts -- .github/workflows/e2e.yml's own seeding
 * step creates two divisions for one season, specifically so this suite's
 * axe-core scan (see the describe block above) exercises the actual
 * tab-strip markup at least once, not only its empty-state branch. Before
 * this fixture existed, every homepage test in this file ran against a
 * standings module with zero divisions to show.
 */
test.describe( 'Homepage standings module, with more than one division seeded', () => {
	test( 'renders one tab per division, radios in the accessibility tree', async ( {
		page,
	} ) => {
		await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		const tabs = page.locator( '.bl-standings-tabs' );

		await expect( tabs.locator( '.bl-standings-tabs__input' ) ).toHaveCount(
			2
		);

		// Native radios, not a custom widget -- getByRole confirms they're
		// exposed to assistive tech the same way any other radio group is.
		await expect( tabs.getByRole( 'radio' ) ).toHaveCount( 2 );
	} );

	test( 'the first division is shown by default', async ( { page } ) => {
		await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		await expect(
			page.getByText( `Division 1 | ${ seasonLabel }`, { exact: false } )
		).toBeVisible();
	} );

	test( 'clicking a tab switches which division is visible -- no page reload, no JavaScript required', async ( {
		page,
	} ) => {
		// The visibility swap is pure CSS (blueline_homepage_standings_tabs()'s
		// own docblock): assets/src/js/standings-tabs.js is bundled into the
		// same single assets/dist/index.js every other entry file shares
		// (webpack.config.js), so there is no separate file to block just
		// for it -- blocking the whole bundle instead proves the tab strip's
		// core interaction needs none of this theme's own JS at all.
		// Registered before goto(), not after -- the script would already
		// have run otherwise.
		await page.route( '**/assets/dist/index.js', ( route ) => route.abort() );

		await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		const tabs = page.locator( '.bl-standings-tabs' );
		await tabs.getByRole( 'radio', { name: '2' } ).check( { force: true } );

		await expect(
			page.getByText( `Division 2 | ${ seasonLabel }`, { exact: false } )
		).toBeVisible();
		await expect(
			page.getByText( `Division 1 | ${ seasonLabel }`, { exact: false } )
		).toBeHidden();
	} );

	test( 'has no critical/serious axe-core accessibility violations with a division switched', async ( {
		page,
	} ) => {
		await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		const tabs = page.locator( '.bl-standings-tabs' );
		await tabs.getByRole( 'radio', { name: '2' } ).check( { force: true } );

		await assertNoA11yViolations( page, 'homepage, division 2 selected' );
	} );
} );
