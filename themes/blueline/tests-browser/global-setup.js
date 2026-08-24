/**
 * Fails the whole browser-guard run fast, with a specific and actionable
 * message, when the target environment cannot serve this check at all --
 * rather than letting an unreachable host surface as a wall of individual
 * test timeouts (or, worse, tests that silently no-op and report green).
 *
 * This check is worthless against anything but a real WordPress install
 * carrying the real third-party plugin responsible for the bug class it
 * guards (Capabilities Pro's admin-notices "declutter" module -- see
 * ../tests-browser/panel-dom-persistence.spec.js's own docblock). Local
 * ddev (~/arl-local, http://arl-local.ddev.site) is the preferred target:
 * it carries capabilities-pro AND automatic-login (so no credential of any
 * kind needs to exist in this repo, this file, or the test itself -- see
 * "Authentication" below). BLUELINE_E2E_BASE_URL overrides the target for
 * anything else (e.g. staging), but nothing here attempts a credentialed
 * login: if a future target needs one, its credentials must come from
 * environment variables the CI/shell already has, never from this repo.
 *
 * ## Authentication
 *
 * This install's `automatic-login` plugin (see its own
 * wp-content/plugins/automatic-login/plugin.php) logs any signed-out
 * request to a wp-admin URL in automatically, server-side, on the very
 * first hit -- driven by wp-config-local.php's own
 * AUTOMATIC_LOGIN_USER_LOGIN/AUTOMATIC_LOGIN_USER_PASSWORD constants (a
 * local, gitignored file this repo never reads or writes). Neither this
 * file nor the spec file ever needs to know a username or password: a
 * plain, cookie-less GET of a wp-admin URL against the target already
 * comes back authenticated. That plugin must be ACTIVE for this to work
 * (see this project's own task record for the one-line `ddev wp plugin
 * activate automatic-login` this required on a fresh ddev checkout) --
 * the panel-reachability check below fails with a specific message
 * naming exactly that if it is not.
 *
 * @param {import('@playwright/test').FullConfig} config
 * @return {Promise<void>}
 */

const { request } = require( '@playwright/test' );

/**
 * The panel URL this whole check exists to exercise -- if this doesn't
 * come back looking like the theme's own settings page, nothing else in
 * this suite can mean anything.
 */
const PANEL_PATH = '/wp-admin/themes.php?page=blueline&tab=content';

/**
 * A string that only ever appears in the rendered HTML of
 * inc/settings/page.php's own `blueline_settings_render_page()` wrap --
 * see that function's `<div class="wrap bl-settings">`. Used here purely
 * as a reachability/identity check, not as this suite's actual assertion
 * (the spec file does its own, much more careful, extraction).
 */
const PANEL_MARKER = 'wrap bl-settings';

/**
 * Check A: is the host reachable at all.
 *
 * @param {import('@playwright/test').APIRequestContext} ctx     Request context.
 * @param {string}                                       baseURL Target base URL.
 * @return {Promise<import('@playwright/test').APIResponse>} The `/` response.
 * @throws {Error} If the request itself fails (DNS, connection refused, timeout, ...).
 */
async function checkHostReachable( ctx, baseURL ) {
	try {
		return await ctx.get( '/', { timeout: 15_000 } );
	} catch ( err ) {
		throw new Error(
			'\n\nblueline browser guard: could not reach ' + baseURL + ' at all (' + err.message + ').\n' +
			'This check needs a REAL WordPress install carrying the real plugin stack the bug\n' +
			'it guards depends on -- it is worthless against anything else (see this file\'s own\n' +
			'docblock, and docs/DESIGN.md\'s Contributing section).\n\n' +
			'If you meant local ddev (the preferred target): run `ddev start` from ~/arl-local,\n' +
			'then re-run `npm run test:browser`.\n\n' +
			'Otherwise, point this at a reachable target with BLUELINE_E2E_BASE_URL=<url>.\n'
		);
	}
}

/**
 * Check B: the host answered, but is it actually serving the site (as
 * opposed to, say, ddev's shared router answering for a stopped project).
 *
 * @param {import('@playwright/test').APIResponse} rootResponse The `/` response.
 * @param {string}                                 baseURL      Target base URL.
 * @return {void}
 * @throws {Error} If the response was not ok().
 */
function checkHostServesRealSite( rootResponse, baseURL ) {
	if ( rootResponse.ok() ) {
		return;
	}

	// A DNS-resolvable, TCP-reachable ddev URL with the actual project
	// STOPPED does not refuse the connection -- ddev's shared router
	// container is still listening and answers with a 404 (not the
	// project's own 200) for any project it isn't currently routing to.
	// That is exactly as actionable as a connection refusal, so it gets
	// the same "run ddev start" guidance, not a generic HTTP-code dump.
	const looksLikeStoppedDdev = /\.ddev\.site$/i.test( new URL( baseURL ).hostname );

	throw new Error(
		'\n\nblueline browser guard: ' + baseURL + ' responded HTTP ' + rootResponse.status() + ' for `/`' +
		' -- reachable, but not serving a real site.\n\n' +
		( looksLikeStoppedDdev
			? 'This is exactly what a STOPPED ddev project looks like (the router container\n' +
			  'answers, the project behind it does not). Run `ddev start` from ~/arl-local,\n' +
			  'then re-run `npm run test:browser`.\n'
			: 'Is BLUELINE_E2E_BASE_URL pointed at the right environment, and is it actually up?\n'
		)
	);
}

/**
 * Check C: the panel URL is reachable AND identifiably the theme's own
 * settings page, not (for instance) a login form automatic-login failed
 * to bypass.
 *
 * @param {import('@playwright/test').APIRequestContext} ctx     Request context.
 * @param {string}                                       baseURL Target base URL.
 * @return {Promise<void>}
 * @throws {Error} If the panel request fails, or the marker is missing.
 */
async function checkPanelMarkerPresent( ctx, baseURL ) {
	let panelResponse;
	let panelBody;
	try {
		panelResponse = await ctx.get( PANEL_PATH, { timeout: 15_000 } );
		panelBody = await panelResponse.text();
	} catch ( err ) {
		throw new Error(
			'blueline browser guard: reached ' + baseURL + ' but the panel URL (' + PANEL_PATH + ') ' +
			'failed: ' + err.message
		);
	}

	if ( panelBody.includes( PANEL_MARKER ) ) {
		return;
	}

	const looksLikeLogin = /loginform|user_login/i.test( panelBody );

	throw new Error(
		'\n\nblueline browser guard: reached ' + baseURL + PANEL_PATH + ' (HTTP ' + panelResponse.status() + ')\n' +
		'but the response does not contain the settings panel\'s own wrap ("' + PANEL_MARKER + '").\n\n' +
		( looksLikeLogin
			? 'This looks like a LOGIN FORM, not the panel -- automatic-login is not completing.\n' +
			  'On ~/arl-local: confirm the plugin is active (`ddev wp plugin activate automatic-login`)\n' +
			  'and that wp-config-local.php still defines AUTOMATIC_LOGIN_USER_LOGIN/PASSWORD\n' +
			  'for a real administrator on this install, and WP_ENVIRONMENT_TYPE as \'local\'.\n'
			: 'Most likely the `blueline` theme is not deployed and/or not the active theme on\n' +
			  'this install. From themes/blueline/, run:\n' +
			  '    ./scripts/deploy-theme.sh local\n' +
			  'then activate it (`ddev wp theme activate blueline`, or set the `template`/\n' +
			  '`stylesheet` options directly if wp-cli refuses on a "Requires at least" version\n' +
			  'mismatch -- see this project\'s own task record for why that mismatch is expected\n' +
			  'on ~/arl-local today).\n'
		)
	);
}

module.exports = async ( config ) => {
	const { baseURL } = config.projects[ 0 ].use;

	const ctx = await request.newContext( { baseURL, ignoreHTTPSErrors: true } );

	try {
		const rootResponse = await checkHostReachable( ctx, baseURL );
		checkHostServesRealSite( rootResponse, baseURL );
		await checkPanelMarkerPresent( ctx, baseURL );
	} finally {
		await ctx.dispose();
	}
};
