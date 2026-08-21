const { test, expect } = require( '@playwright/test' );
const { assertNoPhpErrors } = require( './helpers/assert-no-php-errors' );

/**
 * Covers sportspress/single-team.php (this theme's SportsPress template
 * override) and inc/team-colors.php's derived-foreground logic against a
 * real `sp_team` post with real `sp_colors` postmeta -- not a PHPUnit stub,
 * an actual SportsPress-registered post type rendered through the actual
 * template hierarchy.
 *
 * The slug/colour here must match whatever
 * .github/workflows/e2e.yml's seeding step creates -- overridable via env
 * vars so a local run against a differently-seeded environment (or a
 * future seeding change) doesn't require editing this file.
 */
const teamSlug = process.env.BLUELINE_E2E_TEAM_SLUG || 'testville-icebreakers';
const teamName = process.env.BLUELINE_E2E_TEAM_NAME || 'Testville Icebreakers';
const teamColorHex = ( process.env.BLUELINE_E2E_TEAM_COLOR || '#0b3d91' ).toLowerCase();

test.describe( 'SportsPress team page, against a real sp_team post with real sp_colors', () => {
	test( 'renders via this theme\'s single-team.php override, no PHP error', async ( { page } ) => {
		const response = await page.goto( `/team/${ teamSlug }/`, { waitUntil: 'domcontentloaded' } );

		expect( response.status() ).toBe( 200 );

		const html = await page.content();
		assertNoPhpErrors( html, 'team page' );

		await expect( page.getByRole( 'heading', { name: teamName } ) ).toBeVisible();
	} );

	test( "derives the team's colour from sp_colors, not a placeholder", async ( { page } ) => {
		await page.goto( `/team/${ teamSlug }/`, { waitUntil: 'domcontentloaded' } );

		// blueline_team_color_style_attr() (inc/team-colors.php) prints
		// --bl-team-primary/-on-primary/-accent as custom properties on
		// #main (sportspress/single-team.php) -- verified directly against
		// a live container: `style="--bl-team-primary: #0b3d91; ..."`.
		// Asserting via an auto-retrying locator, not a one-shot
		// `page.content()` string check: the latter can observe a
		// still-settling DOM under load and read as a false negative that
		// has nothing to do with whether the colour actually flowed
		// through -- exactly what a locator assertion's built-in retry
		// exists to absorb.
		await expect( page.locator( '#main' ) ).toHaveAttribute(
			'style',
			new RegExp( `--bl-team-primary:\\s*${ teamColorHex }`, 'i' )
		);
	} );
} );
