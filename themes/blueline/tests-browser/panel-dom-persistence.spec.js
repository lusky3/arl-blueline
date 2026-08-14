/**
 * The browser guard for the class of bug closed out by fix rounds 3-5 of
 * Task 7 (see git log, tests/NoticeDivGuardTest.php's own docblock, and
 * inc/settings/page.php's "Never a <div> for the error summary" section):
 * a third-party wp-admin plugin active on every real install this theme
 * ships to (Capabilities Pro's admin-notices "declutter" module) removes,
 * client-side, ANY `<div>` on any wp-admin screen whose `class` attribute
 * contains "notice", "error", "warning", "info" or "updated" as a
 * substring. PHP's own output was correct every single time this bug
 * shipped -- the raw HTTP response body always contained the element,
 * fully formed. It vanished only in the browser, after the plugin's own
 * JS ran. Three independent instances of this shipped, one at a time,
 * before anyone thought to check with an actual browser: the settings
 * error summary, the "Settings saved." confirmation, and the manual
 * page-cache-purge notice (inc/settings/cache.php) -- the last of which is,
 * today, the ENTIRE shipped behaviour of the cache-purge requirement, since
 * the guarded automatic purge ships off by default.
 *
 * ## Why tests/NoticeDivGuardTest.php (a PHP source scan) cannot close this
 *
 * That test bans a `<div>` whose class attribute contains those five
 * substrings, scanned out of this theme's own .php source. It is real and
 * worth keeping, but a source scan can only ever see how markup is
 * WRITTEN, never what a browser actually renders: single-quoted
 * attributes, a `printf()`-templated tag, string concatenation built up
 * across several lines, or a helper function that emits the tag from
 * somewhere the scanner excludes would all defeat it while still shipping
 * the exact same rendered `<div class="notice ...">` a real browser -- and
 * this plugin's real jQuery selector -- would still remove. No amount of
 * regex sophistication on the PHP source closes that gap, because the gap
 * is not in the PHP: it is in a THIRD-PARTY PLUGIN'S OWN CLIENT-SIDE JS,
 * which no PHPUnit test, no WP-CLI render, and no HTTP-level smoke test
 * (scripts/smoke-staging.sh) can execute or see. Only an actual browser,
 * loading an actual page, on an actual install carrying that actual
 * plugin, can.
 *
 * ## The central technique: diff the server's own response against the DOM
 *
 * Every prior fix here was found and confirmed the same way: compare the
 * raw HTTP response body (which was ALWAYS correct) against the live DOM
 * after the page finishes loading and every wp-admin plugin's own JS has
 * had a chance to run. `assertNothingServerRenderedVanished()` below is
 * that comparison, generalised into a reusable assertion rather than
 * three one-off "does element X still exist" checks: it extracts every
 * theme-emitted identifier from BOTH the raw response and the live DOM,
 * and fails, by name, on anything present in the first but missing from
 * the second. This is deliberately NOT "assert the error summary exists"
 * (an instance guard, the exact shape that let two more instances of this
 * same bug slip past a previous single-purpose test -- see
 * SettingsPageTest's history, superseded by NoticeDivGuardTest.php for the
 * same reason) -- it guards the CLASS of bug: whatever this theme rendered,
 * for ANY reason, must still be there once the page has finished loading,
 * full stop, with no enumerated list of "known" elements to keep in sync
 * by hand as new notices get added.
 *
 * Because this operates on the browser's OWN rendered output rather than
 * PHP source text, none of the tricks that defeat the source-scan guard
 * (quoting style, templating, concatenation, indirection) have any
 * purchase here at all: it does not matter how the HTML was assembled,
 * only what bytes came out the other end and what the DOM looks like
 * after JS has had its say.
 *
 * Two independent identifier schemes are tracked, because this theme's
 * own notices do not consistently carry both:
 *
 *  1. Anything carrying a `blueline-`-prefixed `id` or a `bl-`-prefixed
 *     class token, anywhere on the page -- covers the error summary's own
 *     id, every per-field input/error id, `.bl-settings`, `.bl-settings-field`
 *     (and its `--error` modifier), `.bl-settings-field__error`, and
 *     `.bl-cache-purge-notice`.
 *  2. Within the settings page's own `<div class="wrap bl-settings">`
 *     container specifically (never the whole document -- see
 *     `extractPanelWrapHtml()`'s own docblock for why that scoping
 *     matters), a count of elements whose class attribute contains one of
 *     NoticeDivGuardTest.php's own five forbidden substrings. This is the
 *     one that catches the "Settings saved." confirmation: its own
 *     `<section class="notice notice-success">` carries NO `bl-`-prefixed
 *     class at all (checked directly against inc/settings/page.php as of
 *     this file's own writing) -- (1) alone would miss a regression in
 *     that exact element, which is precisely the shape of gap this file
 *     exists to close rather than repeat.
 *
 * @see tests-browser/global-setup.js for why this suite fails fast, before
 *      any test runs, if the target cannot serve it at all.
 */

const { test, expect } = require( '@playwright/test' );

test.describe.configure( { mode: 'serial' } );

const PANEL_PATH = '/wp-admin/themes.php?page=blueline&tab=content';
const EMAIL_FIELD_SELECTOR = '#blueline-field-contact_email';
const ERROR_SUMMARY_ID = 'blueline-settings-error-summary';

/**
 * NoticeDivGuardTest.php's own FORBIDDEN_SUBSTRINGS, duplicated here
 * deliberately rather than imported: this is a Node/Playwright file with
 * no access to that PHP class, and the whole point of this constant is
 * that it is the exact selector Capabilities Pro's own JS uses (per that
 * test's docblock, confirmed by reading the plugin's own source) -- it is
 * not this project's to redefine independently in two places that could
 * drift, only to state once per language this project's tooling runs in.
 */
const FORBIDDEN_NOTICE_SUBSTRINGS = [ 'notice', 'error', 'warning', 'info', 'updated' ];

/**
 * Every `blueline-`-prefixed id in `html`.
 *
 * @param {string} html
 * @return {Set<string>}
 */
function extractBluelineIds( html ) {
	const ids = new Set();
	const re = /\bid\s*=\s*(["'])(blueline-[^"']+)\1/gi;
	let match;
	while ( ( match = re.exec( html ) ) ) {
		ids.add( match[ 2 ] );
	}
	return ids;
}

/**
 * How many times each `bl-`-prefixed class token appears (across every
 * element's `class` attribute) in `html`.
 *
 * @param {string} html
 * @return {Map<string, number>}
 */
function countBlClassTokens( html ) {
	const counts = new Map();
	const classAttrRe = /\bclass\s*=\s*(["'])([^"']*)\1/gi;
	let match;
	while ( ( match = classAttrRe.exec( html ) ) ) {
		for ( const token of match[ 2 ].split( /\s+/ ) ) {
			if ( token.startsWith( 'bl-' ) ) {
				counts.set( token, ( counts.get( token ) || 0 ) + 1 );
			}
		}
	}
	return counts;
}

/**
 * Slices out exactly the settings page's own
 * `<div class="wrap bl-settings">...</div>` from a full admin-page HTML
 * document, by walking `<div>`/`</div>` tags from that opening tag and
 * tracking nesting depth until it returns to zero -- NOT a full HTML
 * parser (this project deliberately avoids adding one; see this repo's
 * "add no new dependencies" constraint), just enough tag-balance tracking
 * to find the matching close of one specific, known-unique div.
 *
 * Scoping matters here: WordPress renders this theme's own notices INSIDE
 * this container, but also renders a great deal of OTHER plugins' own
 * unrelated admin notices, toolbars, and widgets elsewhere on the same
 * page -- many of which legitimately carry a class containing "notice",
 * "info", or "updated" and may be dismissed, relocated, or removed by
 * their OWN JS for reasons that have nothing to do with the bug this file
 * guards. Counting forbidden-substring classes across the WHOLE document
 * would make this assertion fail on unrelated, expected third-party
 * behaviour; scoping it to this theme's own rendered container is what
 * keeps it specific to elements THIS theme is responsible for.
 *
 * @param {string} html Full admin-page HTML.
 * @return {string} The wrap's own HTML, or '' if the marker was not found.
 */
function extractPanelWrapHtml( html ) {
	const startMarker = '<div class="wrap bl-settings">';
	const start = html.indexOf( startMarker );
	if ( -1 === start ) {
		return '';
	}

	const tagRe = /<\/?div\b[^>]*>/gi;
	tagRe.lastIndex = start;
	let depth = 0;
	let match;
	while ( ( match = tagRe.exec( html ) ) ) {
		if ( match[ 0 ].startsWith( '</' ) ) {
			depth--;
			if ( 0 === depth ) {
				return html.slice( start, tagRe.lastIndex );
			}
		} else {
			depth++;
		}
	}

	// Unbalanced (should not happen against real rendered HTML) -- fall
	// back to "everything from the marker to the end" rather than
	// silently returning nothing, which would make every assertion below
	// pass vacuously.
	return html.slice( start );
}

/**
 * How many elements within `wrapHtml` carry each forbidden substring
 * somewhere in their `class` attribute.
 *
 * @param {string} wrapHtml Output of extractPanelWrapHtml().
 * @return {Map<string, number>}
 */
function countForbiddenNoticeSubstrings( wrapHtml ) {
	const counts = new Map();
	const classAttrRe = /\bclass\s*=\s*(["'])([^"']*)\1/gi;
	let match;
	while ( ( match = classAttrRe.exec( wrapHtml ) ) ) {
		const classValue = match[ 2 ].toLowerCase();
		for ( const needle of FORBIDDEN_NOTICE_SUBSTRINGS ) {
			if ( classValue.includes( needle ) ) {
				counts.set( needle, ( counts.get( needle ) || 0 ) + 1 );
			}
		}
	}
	return counts;
}

/**
 * The central assertion this whole file exists for: compares the RAW HTTP
 * response body of a real page load (`rawHtml`, always correct -- see
 * this file's own top docblock) against the LIVE DOM (`page.content()`,
 * i.e. after the browser has parsed that response AND run every script on
 * the page, including any third-party plugin's own). Fails, naming
 * exactly what disappeared, if anything the server sent is no longer
 * present.
 *
 * `rawHtml` MUST be the exact response body of the SAME navigation that
 * produced the page's current DOM -- e.g. `( await page.goto( url ) ).text()`
 * -- rather than a fresh, independent re-fetch of `page.url()` taken after
 * the fact. That distinction is load-bearing, not stylistic: WordPress'
 * own `get_settings_errors()` (wp-includes/option.php) reads this page's
 * field errors and "Settings saved."/notice messages out of a ONE-TIME
 * transient, consumed only on a request that still carries
 * `?settings-updated=true` in its query string -- and this page's own
 * `common.js` (WordPress core, loaded on every wp-admin screen) rewrites
 * the visible URL via `history.replaceState()` to strip that exact
 * parameter within moments of load, with no second network request. A
 * re-fetch of `page.url()` taken even slightly later hits that
 * already-rewritten URL, finds no `settings-updated=true`, and gets back
 * a page with NO notices at all -- correct behaviour from WordPress' own
 * one-time-message design, but a silent false negative for this
 * assertion if mistaken for "the ground truth". Capturing the response at
 * navigation time sidesteps that entirely: it is not a second request,
 * so nothing about it can race the URL rewrite.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} rawHtml The body of the response that produced the
 *                          page's current DOM (see above).
 * @param {string} label Identifies which scenario failed, in the message.
 */
async function assertNothingServerRenderedVanished( page, rawHtml, label ) {
	const liveHtml = await page.content();

	// Sanity precondition, not the assertion itself: if this ever fails,
	// every check below would otherwise pass vacuously (comparing "nothing
	// found" against "nothing found"), which would silently stop guarding
	// anything while still reporting green.
	expect(
		rawHtml.includes( '<div class="wrap bl-settings">' ),
		`${ label }: the captured server response does not contain the settings panel's own wrap at all -- this assertion cannot mean anything until that's true (wrong URL? login/theme-activation problem? see tests-browser/global-setup.js).`
	).toBe( true );

	const serverIds = extractBluelineIds( rawHtml );
	const liveIds = extractBluelineIds( liveHtml );
	const missingIds = [ ...serverIds ].filter( ( id ) => ! liveIds.has( id ) );

	expect(
		missingIds,
		`${ label }: the server rendered these element ids, but they are gone from the live DOM after the page's scripts ran -- a third-party admin-notice-declutter plugin removes exactly this pattern client-side (see this file's own docblock, and tests/NoticeDivGuardTest.php)`
	).toEqual( [] );

	const serverBlClasses = countBlClassTokens( rawHtml );
	const liveBlClasses = countBlClassTokens( liveHtml );
	const shrunkBlClasses = [];
	for ( const [ cls, serverCount ] of serverBlClasses.entries() ) {
		const liveCount = liveBlClasses.get( cls ) || 0;
		if ( liveCount < serverCount ) {
			shrunkBlClasses.push( `${ cls } (server: ${ serverCount }, live DOM: ${ liveCount })` );
		}
	}

	expect(
		shrunkBlClasses,
		`${ label }: fewer elements carry these blueline classes in the live DOM than the server actually rendered -- something was removed client-side`
	).toEqual( [] );

	const serverWrap = extractPanelWrapHtml( rawHtml );
	const liveWrap = extractPanelWrapHtml( liveHtml );
	const serverNoticeCounts = countForbiddenNoticeSubstrings( serverWrap );
	const liveNoticeCounts = countForbiddenNoticeSubstrings( liveWrap );
	const shrunkNoticeSubstrings = [];
	for ( const [ needle, serverCount ] of serverNoticeCounts.entries() ) {
		const liveCount = liveNoticeCounts.get( needle ) || 0;
		if ( liveCount < serverCount ) {
			shrunkNoticeSubstrings.push( `"${ needle }" (server: ${ serverCount }, live DOM: ${ liveCount })` );
		}
	}

	expect(
		shrunkNoticeSubstrings,
		`${ label }: within the settings panel's own container, fewer elements carry a class matching one of NoticeDivGuardTest.php's forbidden substrings in the live DOM than the server actually rendered -- e.g. the "Settings saved." confirmation or the error summary was removed client-side after being rendered correctly`
	).toEqual( [] );
}

/**
 * Capabilities Pro's own admin-notices removal, and this page's own focus
 * script (see blueline_settings_focus_summary_script()'s docblock), both
 * run from a jQuery `ready()`/`setTimeout(fn, 0)` chain rather than
 * synchronously during page load. This waits a fixed, generous beat for
 * both to have had their turn before this file reads the DOM -- consistent
 * with this being an on-demand, manually-invoked check (like
 * scripts/smoke-staging.sh), not a latency-sensitive CI gate.
 *
 * @param {import('@playwright/test').Page} page
 */
async function settleAfterClientSideScripts( page ) {
	await page.waitForTimeout( 400 );
}

/**
 * Submits the settings form containing `EMAIL_FIELD_SELECTOR`, with
 * `overrides` applied on top of every field's current value, and returns
 * the resulting redirect URL -- WITHOUT ever clicking the Save button.
 *
 * This is a deliberate choice, not a shortcut: this specific ddev-hosted
 * install carries a large, unrelated plugin stack (~700 requests and
 * several megabytes of JS on this one admin screen -- WooCommerce blocks,
 * Gutenberg, jQuery UI, three role-manager UIs, a heartbeat poller...),
 * and a real mouse click on the Save button proved measurably flaky
 * against it while writing this file: the same `.click()`, on the same
 * element, at the same coordinates, sometimes fired and sometimes silently
 * did nothing, with no dialog, no console error, and no failed request to
 * explain it. That flakiness lives entirely in third-party admin-screen
 * JS this project does not own and this file is not trying to guard.
 *
 * What this file DOES need to guard only starts existing after the save
 * has happened: the response to the resulting page load. So this performs
 * the exact same HTTP request a real click would have (same URL, same
 * method, same fields -- extracted live from the form via `FormData` so a
 * future field added to the schema is included automatically, not a
 * hand-maintained list) via `page.context().request`, which shares the
 * browser context's own cookies, then loads the resulting redirect with a
 * real `page.goto()` -- a genuine top-level navigation, running every
 * script on the resulting page exactly as a real click's own
 * POST-redirect-GET would. Nothing about the DOM-persistence, focus, aria,
 * or console-error assertions below can tell the difference.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Record<string, string>} overrides Field values to override, keyed
 *                                             by their `name` attribute
 *                                             (e.g. `blueline_settings[contact_email]`).
 * @return {Promise<string>} The absolute URL of the page after the save.
 */
async function submitPanelForm( page, overrides ) {
	const { action, pairs } = await page.evaluate( ( formOverrides ) => {
		const anchorField = document.getElementById( 'blueline-field-contact_email' );
		const form = anchorField.closest( 'form' );
		const formData = new FormData( form );
		for ( const [ key, value ] of Object.entries( formOverrides ) ) {
			formData.delete( key );
			formData.append( key, value );
		}
		// `form.action` is shadowed by this same form's own
		// `<input type="hidden" name="action" value="update">` (a real
		// WordPress Settings API quirk, not a bug here) -- the DOM
		// property resolves to that INPUT ELEMENT rather than the URL
		// string once a form control shares its name, so the actual URL
		// must come from the attribute instead.
		return { action: form.getAttribute( 'action' ), pairs: [ ...formData.entries() ] };
	}, overrides );

	const body = new URLSearchParams();
	for ( const [ key, value ] of pairs ) {
		body.append( key, value );
	}

	const response = await page.context().request.post( action, {
		headers: { 'content-type': 'application/x-www-form-urlencoded' },
		data: body.toString(),
		maxRedirects: 0,
	} );

	expect(
		response.status(),
		`submitting the settings form should redirect (302), got ${ response.status() }`
	).toBe( 302 );

	return new URL( response.headers().location, page.url() ).toString();
}

test( 'the panel survives every third-party script that runs against it', async ( { page } ) => {
	const consoleErrors = [];
	page.on( 'console', ( msg ) => {
		if ( 'error' === msg.type() ) {
			consoleErrors.push( msg.text() );
		}
	} );
	page.on( 'pageerror', ( err ) => consoleErrors.push( `uncaught page error: ${ err.message }` ) );

	await test.step( 'initial load: nothing the server rendered is missing from the DOM', async () => {
		const response = await page.goto( PANEL_PATH, { waitUntil: 'load' } );
		const rawHtml = await response.text();
		await settleAfterClientSideScripts( page );
		await assertNothingServerRenderedVanished( page, rawHtml, 'initial panel load' );
	} );

	// Round-tripped back to its original value at the end of this test, so
	// a real run against ~/arl-local leaves that install's data unchanged.
	const originalEmail = await page.inputValue( EMAIL_FIELD_SELECTOR );

	await test.step( 'a failed save: error summary survives, focus moves to it, aria wiring resolves', async () => {
		const redirectUrl = await submitPanelForm( page, {
			'blueline_settings[contact_email]': 'not-an-email',
		} );
		const response = await page.goto( redirectUrl, { waitUntil: 'load' } );
		const rawHtml = await response.text();
		await settleAfterClientSideScripts( page );

		// The central assertion first: if this element (or any other) was
		// swept from the DOM, THIS is what must fail and name it -- not a
		// focus timeout or a missing-attribute check further down, which
		// would report a confusing symptom instead of the actual cause.
		await assertNothingServerRenderedVanished( page, rawHtml, 'failed save' );

		const activeElementId = await page.evaluate( () => document.activeElement && document.activeElement.id );
		expect(
			activeElementId,
			'focus must land on the error summary after a failed save, not stay on <body> -- see blueline_settings_focus_summary_script()\'s docblock for why a plain `autofocus` attribute alone does not do this'
		).toBe( ERROR_SUMMARY_ID );

		const emailInput = page.locator( EMAIL_FIELD_SELECTOR );
		await expect( emailInput ).toHaveAttribute( 'aria-invalid', 'true' );
		const describedBy = await emailInput.getAttribute( 'aria-describedby' );
		expect( describedBy, 'the failed field must carry aria-describedby' ).toBeTruthy();
		const describedElement = page.locator( `#${ describedBy }` );
		await expect(
			describedElement,
			`aria-describedby="${ describedBy }" must resolve to a real element in the DOM`
		).toHaveCount( 1 );
		await expect( describedElement ).toBeVisible();
	} );

	await test.step( 'a successful save actually displays its confirmation', async () => {
		const redirectUrl = await submitPanelForm( page, {
			'blueline_settings[contact_email]': originalEmail,
		} );
		const response = await page.goto( redirectUrl, { waitUntil: 'load' } );
		const rawHtml = await response.text();
		await settleAfterClientSideScripts( page );

		await assertNothingServerRenderedVanished( page, rawHtml, 'successful save' );

		const successNotice = page.locator( '.notice.notice-success', { hasText: 'Settings saved.' } );
		await expect(
			successNotice,
			'a successful save must actually display a visible "Settings saved." confirmation -- not merely include it, unrendered, in the response body'
		).toBeVisible();
	} );

	await test.step( 'no console errors were raised anywhere in this scenario', async () => {
		expect( consoleErrors, 'the panel page must not raise any console errors' ).toEqual( [] );
	} );
} );
