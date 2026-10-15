/**
 * Waits for the page to reach a stable state instead of sleeping a fixed
 * number of milliseconds. A fixed sleep is either too short on a slow run
 * (flaky) or longer than needed on every run (slow); these wait exactly as
 * long as the thing being waited for takes.
 */

/**
 * Resolves once the CSS transitions and short animations running in the
 * document have finished, then lets two frames paint so layout reads see the
 * end state. These are what a click or a resize sets off (the drawer
 * sliding in, the toggle turning into an X). Ambient animations are not
 * waited for: the infinite occasion effects never finish, and the team
 * flyout's attention pulse runs for 12 seconds without moving anything a
 * spec measures.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<void>}
 */
async function settleAnimations( page ) {
	await page.evaluate( async () => {
		const frames = () =>
			new Promise( ( resolve ) =>
				requestAnimationFrame( () => requestAnimationFrame( resolve ) )
			);

		// A transition triggered by the click that preceded this call only
		// exists once the browser has recalculated style, so look after a frame.
		await frames();

		const SHORT_MS = 3000;
		const settling = document.getAnimations().filter( ( a ) => {
			const end = a.effect?.getComputedTiming().endTime;

			return Number.isFinite( end ) && end <= SHORT_MS;
		} );

		await Promise.all( settling.map( ( a ) => a.finished.catch( () => {} ) ) );
		await frames();
	} );
}

module.exports = { settleAnimations };
