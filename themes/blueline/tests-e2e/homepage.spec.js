const { test, expect } = require( '@playwright/test' );
const { assertNoPhpErrors } = require( './helpers/assert-no-php-errors' );

test.describe( 'Homepage, against a clean WordPress + WooCommerce + SportsPress install', () => {
	test( 'loads, is served by this theme, and leaks no PHP error', async ( { page } ) => {
		const response = await page.goto( '/' );

		expect( response.status() ).toBe( 200 );

		const html = await page.content();
		assertNoPhpErrors( html, 'homepage' );

		// wp_body_open()/body_class() should carry the theme's own body
		// classes -- confirms the environment actually activated blueline,
		// not just that SOME theme rendered a 200.
		await expect( page.locator( 'body.theme-blueline' ) ).toHaveCount( 1 );
	} );

	test( "loads the theme's own stylesheet, not a fallback theme's", async ( { page } ) => {
		await page.goto( '/' );

		const styleHref = await page
			.locator( '#blueline-tokens-css' )
			.getAttribute( 'href' );

		expect( styleHref ).toContain( '/wp-content/themes/blueline/style.css' );
	} );
} );
