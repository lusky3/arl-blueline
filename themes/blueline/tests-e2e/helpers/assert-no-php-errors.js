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
 * nothing to do with this theme. These four strings are what PHP itself
 * emits verbatim (with `display_errors` on, which this environment's
 * WP_DEBUG=true implies) for a fatal, a parse error, an uncaught
 * exception's stack trace header, or a WSOD -- not something any
 * WordPress admin UI plugin would coincidentally print.
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
