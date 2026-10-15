/**
 * Finds the real content a spec needs on whatever site BLUELINE_E2E_BASE_URL
 * points at, instead of naming a post ID, team or arena that only exists in
 * one database.
 *
 * Everything here reads SportsPress' public REST API (sportspress/v2) and,
 * where a spec needs more than "any event", fetches the candidate's page and
 * checks it actually renders what the spec measures. A spec that cannot find
 * suitable content skips with the reason, rather than failing on data or
 * passing without proving anything.
 */

/**
 * Path (and query) of a URL, so a link the API built for one host still
 * resolves against the configured baseURL.
 *
 * @param {string} link Absolute URL.
 * @return {string} Path with query string.
 */
function pathOf( link ) {
	const url = new URL( link );

	return url.pathname + url.search;
}

/**
 * Links of up to `perPage` items from a sportspress/v2 collection.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request fixture.
 * @param {string}                                       route   Collection route, e.g. 'events'.
 * @param {Object<string, string|number>}                query   Extra query arguments.
 * @return {Promise<string[]>} Site-relative paths, in API order.
 */
async function listPaths( request, route, query = {} ) {
	const params = new URLSearchParams( { per_page: '10', _fields: 'link', ...query } );
	const response = await request.get( `/wp-json/sportspress/v2/${ route }?${ params }` );

	if ( ! response.ok() ) {
		return [];
	}

	const items = await response.json();

	return Array.isArray( items ) ? items.map( ( item ) => pathOf( item.link ) ) : [];
}

/**
 * First candidate path whose served HTML contains every marker.
 *
 * @param {import('@playwright/test').APIRequestContext} request    Request fixture.
 * @param {string[]}                                     candidates Paths to try, in order.
 * @param {string[]}                                     markers    Substrings the page must contain.
 * @return {Promise<string|null>} Matching path, or null when none match.
 */
async function firstPageContaining( request, candidates, markers ) {
	for ( const path of candidates ) {
		const response = await request.get( path );

		if ( ! response.ok() ) {
			continue;
		}

		const html = await response.text();

		if ( markers.every( ( marker ) => html.includes( marker ) ) ) {
			return path;
		}
	}

	return null;
}

/**
 * An event page that renders every marker given, from the 20 latest-dated
 * events: the scheduled games still to come, then the most recent results.
 * (Anonymous REST requests may not filter by status=future, so date order
 * is what separates "this season" from years of archive.)
 *
 * @param {import('@playwright/test').APIRequestContext} request Request fixture.
 * @param {string[]}                                     markers Substrings the event page must contain.
 * @return {Promise<string|null>} Event path, or null.
 */
async function findEventPath( request, markers ) {
	const latest = await listPaths( request, 'events', {
		per_page: '20',
		orderby: 'date',
		order: 'desc',
	} );

	return firstPageContaining( request, latest, markers );
}

/**
 * Team page paths, in the API's order.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request fixture.
 * @return {Promise<string[]>} Team paths.
 */
async function teamPaths( request ) {
	return listPaths( request, 'teams' );
}

/**
 * A venue (arena) page that renders every marker given.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request fixture.
 * @param {string[]}                                     markers Substrings the venue page must contain.
 * @return {Promise<string|null>} Venue path, or null.
 */
async function findVenuePath( request, markers ) {
	return firstPageContaining( request, await listPaths( request, 'venues' ), markers );
}

/**
 * Runs `fn` with a request context bound to the project's baseURL, for
 * beforeAll hooks, which cannot use the test-scoped `request` fixture.
 *
 * @template T
 * @param {import('@playwright/test').PlaywrightWorkerArgs['playwright']} playwright Worker fixture.
 * @param {import('@playwright/test').TestInfo}                           testInfo   Hook's test info.
 * @param {function(import('@playwright/test').APIRequestContext): Promise<T>} fn   Work to do.
 * @return {Promise<T>} Whatever `fn` returns.
 */
async function withRequest( playwright, testInfo, fn ) {
	const { baseURL, ignoreHTTPSErrors } = testInfo.project.use;
	const request = await playwright.request.newContext( { baseURL, ignoreHTTPSErrors } );

	try {
		return await fn( request );
	} finally {
		await request.dispose();
	}
}

module.exports = { findEventPath, findVenuePath, teamPaths, withRequest };
