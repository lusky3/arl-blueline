/**
 * Playwright config for the "browser guard" -- see
 * tests-browser/panel-dom-persistence.spec.js's own docblock for the class
 * of bug this exists to catch and why no PHPUnit test (including
 * tests/NoticeDivGuardTest.php, a source scan) can catch it: a third-party
 * wp-admin plugin (Capabilities Pro's admin-notices "declutter" module)
 * removes theme-rendered elements from the DOM client-side, entirely after
 * PHP has already sent a byte-for-byte-correct response. Only a real
 * browser, against a real install carrying that real plugin, can see it.
 *
 * Deliberately NOT wired into `npm run check` (see that script's five other
 * gates in package.json): this hits a real WordPress install over the
 * network and depends on that install's actual third-party plugin stack --
 * neither property any of `check`'s other gates have, or should have. Run
 * by hand, on demand, via `npm run test:browser`, exactly the way
 * scripts/smoke-staging.sh is a manually-invoked script rather than part of
 * any automated gate -- see docs/DESIGN.md's Contributing section.
 *
 * Deliberately does NOT reuse @wordpress/scripts' own
 * config/playwright.config.js / config/playwright/global-setup.js (both
 * present in node_modules/@wordpress/scripts/config/): those assume a
 * `wp-env`-managed install (a `webServer: 'npm run wp-env start'` entry)
 * and authenticate via @wordpress/e2e-test-utils-playwright's own
 * admin/password REST bootstrap. Neither applies to this project's actual
 * target: a real ddev-hosted clone of this site's plugin stack
 * (~/arl-local, see tests-browser/global-setup.js's own docblock), which
 * has no wp-env involvement and authenticates a different way entirely
 * (the `automatic-login` plugin, server-side, no credentials the test
 * itself needs to know). Providing this file at the theme root means
 * `wp-scripts test-playwright` (see the `test:browser` script in
 * package.json) picks THIS config up instead of its own bundled default --
 * see node_modules/@wordpress/scripts/scripts/test-playwright.js's own
 * `hasProjectFile( 'playwright.config.js' )` check.
 */
const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

/**
 * Overridable so this can point somewhere other than the local ddev
 * install this was written and verified against (see this project's own
 * task brief: "Prefer local ddev... only use staging if local proves
 * unworkable"). Nothing in this repo ever hardcodes a credentialed target;
 * BLUELINE_E2E_BASE_URL is the only thing that decides where this check
 * runs, and no credential of any kind lives in this file or in git.
 */
const baseURL = process.env.BLUELINE_E2E_BASE_URL || 'http://arl-local.ddev.site';

module.exports = defineConfig( {
	testDir: './tests-browser',
	timeout: 60_000,
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ] ],
	// tests-browser/global-setup.js's whole job is to fail fast, with a
	// clear and specific message, when the target environment cannot serve
	// this check at all -- see that file's own docblock for why this is
	// not optional. Without it, an unreachable target would surface as
	// individual test timeouts with no explanation of what to start or set.
	globalSetup: require.resolve( './tests-browser/global-setup.js' ),
	outputDir: path.join( __dirname, 'artifacts', 'browser-test-results' ),
	use: {
		baseURL,
		headless: true,
		ignoreHTTPSErrors: true,
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
