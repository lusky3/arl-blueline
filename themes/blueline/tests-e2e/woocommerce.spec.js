const { test, expect } = require( '@playwright/test' );
const { assertNoPhpErrors } = require( './helpers/assert-no-php-errors' );
const {
	assertNoA11yViolations,
} = require( './helpers/assert-no-a11y-violations' );

/**
 * Covers this theme's woocommerce/ template overrides against a real,
 * active WooCommerce install -- not just that the PHP files parse
 * (composer lint already asserts that), but that WooCommerce's own hooks
 * and this theme's overrides render together without a fatal, on the
 * pages every WooCommerce store has by default (WooCommerce creates
 * Shop/Cart/Checkout/My account on activation).
 */
test.describe( 'WooCommerce pages, against a clean install with no products', () => {
	test( 'shop page loads under this theme, empty catalog included', async ( { page } ) => {
		const response = await page.goto( '/shop/', { waitUntil: 'domcontentloaded' } );

		expect( response.status() ).toBe( 200 );
		assertNoPhpErrors( await page.content(), 'shop page' );
	} );

	test( 'cart page loads under this theme', async ( { page } ) => {
		const response = await page.goto( '/cart/', { waitUntil: 'domcontentloaded' } );

		expect( response.status() ).toBe( 200 );
		assertNoPhpErrors( await page.content(), 'cart page' );
	} );

	test( 'my account page loads under this theme', async ( { page } ) => {
		// Auto-login (this environment's mu-plugin, see
		// ghcr.io/lusky3/sportspress-sandbox's README) means this request
		// is already authenticated as admin -- exercises the logged-in
		// account-dashboard branch of woocommerce/myaccount/dashboard.php,
		// not the login-form branch a logged-out visitor would hit.
		const response = await page.goto( '/my-account/', { waitUntil: 'domcontentloaded' } );

		expect( response.status() ).toBe( 200 );
		assertNoPhpErrors( await page.content(), 'my account page' );
	} );

	test( 'shop, cart, and my account pages have no critical/serious axe-core violations', async ( {
		page,
	} ) => {
		await page.goto( '/shop/', { waitUntil: 'domcontentloaded' } );
		await assertNoA11yViolations( page, 'shop page' );

		await page.goto( '/cart/', { waitUntil: 'domcontentloaded' } );
		await assertNoA11yViolations( page, 'cart page' );

		await page.goto( '/my-account/', { waitUntil: 'domcontentloaded' } );
		await assertNoA11yViolations( page, 'my account page' );
	} );
} );
