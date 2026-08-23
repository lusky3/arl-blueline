const AxeBuilder = require( '@axe-core/playwright' ).default;

/**
 * Run an axe-core accessibility scan against the page's current state and
 * fail with a readable summary if it finds a critical or serious
 * violation.
 *
 * Scoped to critical/serious impact only, not every axe finding: this
 * theme already has a dedicated, much stricter WCAG AA contrast gate
 * (tools/check-contrast.mjs, run against every --bl-* token pair in both
 * palettes) and a hand-rolled focus trap/aria-expanded discipline
 * (assets/src/js/navigation.js) -- axe's own "moderate"/"minor" findings
 * on third-party SportsPress/WooCommerce markup this theme only skins
 * would be noise this suite can't act on. Critical/serious findings
 * (missing form labels, invalid ARIA, duplicate ids) are the class of
 * structural defect no other tool in this project's own check pipeline
 * can catch at all.
 *
 * Two elements are excluded for the same reason -- neither is this
 * theme's markup, and neither is something a real site visitor ever sees:
 * - #wpadminbar: WordPress core's own admin toolbar. Present on every WP
 *   theme, not something blueline generates or can restructure. It only
 *   renders here because this test environment's auto-login mu-plugin
 *   authenticates every request as admin.
 * - .coming-soon-footer-banner: WooCommerce's own admin-only "finish
 *   setting up your store" nag, shown while site visibility is still
 *   "Coming soon" (this environment's default, unconfigured state). It
 *   disappears the moment a merchant marks the store live, and WooCommerce
 *   itself owns the markup, not any template this theme overrides.
 *
 * @param {import('@playwright/test').Page} page    The page to scan, in whatever state the caller has already navigated it to.
 * @param {string}                          context Short label for the assertion failure message.
 */
async function assertNoA11yViolations( page, context ) {
	const results = await new AxeBuilder( { page } )
		.exclude( '#wpadminbar' )
		.exclude( '.coming-soon-footer-banner' )
		.analyze();

	const blocking = results.violations.filter(
		( violation ) =>
			'critical' === violation.impact || 'serious' === violation.impact
	);

	if ( blocking.length ) {
		const summary = blocking
			.map(
				( violation ) =>
					`- [${ violation.impact }] ${ violation.id }: ${
						violation.help
					} (${ violation.nodes.length } element(s))`
			)
			.join( '\n' );

		throw new Error(
			`${ context }: axe-core found ${ blocking.length } critical/serious accessibility violation(s):\n${ summary }`
		);
	}
}

module.exports = { assertNoA11yViolations };
