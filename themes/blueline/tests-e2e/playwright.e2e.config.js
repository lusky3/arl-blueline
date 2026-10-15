/**
 * Playwright config for the CI-run WordPress + WooCommerce + SportsPress
 * suite.
 *
 * Deliberately a SEPARATE config/directory from `playwright.config.js` /
 * `tests-browser/`: that suite targets a real ddev clone of this site's
 * FULL third-party plugin stack (~/arl-local) and is run by hand, never in
 * CI -- see that config's own docblock. This one targets a disposable,
 * reproducible WordPress + WooCommerce + SportsPress environment spun up
 * fresh in `.github/workflows/e2e.yml` from
 * ghcr.io/lusky3/sportspress-sandbox/sportspress-test-env (the same image
 * the SportsPress-Admin-Tools project's own CI uses), with only this
 * theme, WooCommerce, SportsPress, and the environment's own debug/dev
 * plugins active -- no proprietary plugins, no production data. It exists
 * to catch "does the theme's WooCommerce/SportsPress template overrides
 * fatal against a clean install" -- a different, narrower question than
 * tests-browser/'s "does a specific real third-party plugin corrupt the
 * DOM after PHP renders it".
 */
const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

/**
 * Overridable so this can point at a differently-ported or externally
 * managed instance of the same test-env image. Defaults to the port
 * .github/workflows/e2e.yml maps the container to.
 */
const baseURL = process.env.BLUELINE_E2E_BASE_URL || 'http://localhost:8082';

module.exports = defineConfig( {
	testDir: __dirname,
	// Pages in this sandbox render in about a second once
	// .github/workflows/e2e.yml's warm-up pass has compiled them. 30s is
	// generous headroom on a shared runner while keeping the worst case
	// (every test failing twice) inside that job's timeout-minutes.
	timeout: 30_000,
	// The target is one all-in-one container (nginx + PHP-FPM + MariaDB),
	// not a horizontally-scalable service: parallel workers starved each
	// other out under load. tests-browser/'s config made the same call.
	fullyParallel: false,
	workers: 1,
	// Intermittent 60s `page.goto()` stalls are not runner contention: nginx in the
	// sandbox image could not write its FastCGI temp directory, so any response large enough to be
	// buffered to disk (the debug toolbars made most pages 0.6-1MB) was cut
	// off mid-body and the browser waited for the rest until it timed out.
	// e2e.yml fixes that directory's ownership and verifies it. One
	// retry stays as insurance for a genuinely transient runner hiccup; a
	// test that needed it is reported as flaky (the `github` reporter
	// annotates it), so a returning pattern is visible rather than absorbed.
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'list' ], [ 'github' ] ] : [ [ 'list' ] ],
	outputDir: path.join( __dirname, '..', 'artifacts', 'e2e-test-results' ),
	use: {
		baseURL,
		headless: true,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
