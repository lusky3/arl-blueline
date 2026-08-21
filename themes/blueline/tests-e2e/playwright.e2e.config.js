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
	// 30s wasn't enough on GitHub's standard 2 vCPU/7GB runner: a real run
	// hit page.goto()'s navigation timeout outright on /shop/, sharing that
	// CPU budget with the container's own PHP-FPM + MariaDB and this job's
	// Chromium. .github/workflows/e2e.yml also runs an untimed warm-up pass
	// (opcache compile, query-plan cache) before this suite starts, but the
	// extra headroom here stays regardless.
	timeout: 60_000,
	// The target is one resource-constrained, all-in-one (nginx + PHP-FPM +
	// MariaDB in a single container) instance, not a horizontally-scalable
	// service -- fullyParallel workers genuinely starved each other out
	// under load (verified directly: a run that failed at 7 workers passed
	// cleanly at 1). tests-browser/'s config made the same call for the
	// same reason.
	fullyParallel: false,
	workers: 1,
	// Even single-worker, a `page.goto()` against this container stalls
	// intermittently for the full test timeout -- verified NOT to be a
	// logic bug: the exact same page in isolation, or paired with its
	// neighbour, is consistently fast (400-900ms); only a full-suite run
	// occasionally stalls, and not on a fixed page -- it rotated across
	// homepage, shop, and cart across different runs. `waitUntil:
	// 'domcontentloaded'` (see the goto() calls in tests-e2e/*.spec.js)
	// ruled out a hanging subresource fetch: it made no difference. The
	// remaining explanation is generalised contention: this container runs
	// nginx + PHP-FPM + MariaDB under supervisord, and GitHub's standard
	// runner gives the whole job (that stack, plus Node, plus this
	// suite's own Chromium) only 2 vCPUs -- an occasional multi-second
	// stall under that squeeze, on whichever process the OS scheduler
	// starves that moment, is expected, not a defect in this theme, this
	// suite, or the sandbox image. A single retry was observed to still
	// hit the same stall back-to-back on a real run, so this allows two;
	// a run that fails all three attempts is a different, real signal.
	retries: process.env.CI ? 2 : 0,
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
