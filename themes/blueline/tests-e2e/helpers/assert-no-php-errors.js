/**
 * Asserts a rendered page contains none of PHP's own error output
 * signatures.
 *
 * Deliberately NOT a check for the word "deprecated": Query Monitor (one
 * of this environment's pre-installed debug plugins, see
 * ghcr.io/lusky3/sportspress-sandbox's README) renders admin-bar panel
 * markup that legitimately contains that word in its own UI copy
 * regardless of whether anything was actually deprecated on the request --
 * asserting its absence would fail on every page for a reason that has
 * nothing to do with this theme. The first four strings are what PHP itself
 * emits verbatim for a fatal, a parse error or an uncaught exception when
 * errors are displayed. The sandbox sets WP_DEBUG_DISPLAY to false, though,
 * so there a fatal is caught by WordPress' fatal-error handler instead and
 * the page is its "critical error" screen: that sentence is the fifth
 * signature. (The specs also assert a 200, which that screen is not.)
 *
 * @param {string} html Full page HTML, e.g. from `page.content()`.
 * @param {string} context Short label for the assertion failure message.
 */
function assertNoPhpErrors( html, context ) {
	const signatures = [
		'Fatal error',
		'Parse error',
		'Uncaught Error',
		'Uncaught Exception',
		'There has been a critical error on this website',
	];

	for ( const signature of signatures ) {
		if ( html.includes( signature ) ) {
			throw new Error(
				`${ context }: page contains "${ signature }" -- a real PHP error leaked into the response`
			);
		}
	}
}

module.exports = { assertNoPhpErrors };
