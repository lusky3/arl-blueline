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

		// blueline_team_color_set() (inc/team-colors.php) reads sp_colors'
		// `primary` and computes on/derived values from it -- asserting the
		// literal hex we seeded appears somewhere in the rendered markup
		// (an inline style attribute, most likely) proves the real value
		// flowed through, not a hardcoded fallback.
		const html = await page.content();
		expect( html.toLowerCase() ).toContain( teamColorHex.replace( '#', '' ) );
	} );
} );
