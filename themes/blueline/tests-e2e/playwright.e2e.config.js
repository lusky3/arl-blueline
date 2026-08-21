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
	timeout: 30_000,
	// The target is one resource-constrained, all-in-one (nginx + PHP-FPM +
	// MariaDB in a single container) instance, not a horizontally-scalable
	// service -- fullyParallel workers genuinely starved each other out
	// under load (verified directly: a run that failed at 7 workers passed
	// cleanly at 1). tests-browser/'s config made the same call for the
	// same reason.
	fullyParallel: false,
	workers: 1,
	// Even single-worker, a `page.goto()` against this container fails
	// intermittently (~1 in 10-20 runs observed directly, always on
	// navigation itself, never on an assertion after a successful load) --
	// most likely first-request-per-session cold-start latency (PHP-FPM
	// worker spin-up, WooCommerce's session/cart-token bootstrap) rather
	// than anything this theme does; `--repeat-each` in a single process
	// never reproduced it, only separate cold invocations did. Retrying
	// once absorbs it in CI without masking a real, repeatable failure --
	// a retry that also fails is a different, real signal, not this flake.
	retries: process.env.CI ? 1 : 0,
	reporter: [ [ 'list' ] ],
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
