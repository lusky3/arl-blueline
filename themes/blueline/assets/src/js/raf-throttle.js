/**
 * Coalesce rapid, repeated calls to at most one call per animation frame.
 *
 * `scroll` (and similarly high-frequency events) fire far more often than the
 * browser paints, and reading layout in the handler itself would force a
 * layout flush on every one of those events rather than once per frame. Both
 * sticky-header.js and table-scroll.js re-implemented the same "queued
 * boolean + requestAnimationFrame" idiom from scratch to guard against that;
 * this is the one shared version.
 *
 * @param {Function} fn Function to run, at most once per frame, with the
 *                      most recent call's arguments.
 * @return {Function} Throttled wrapper around `fn`.
 */
export function rafThrottle( fn ) {
	let queued = false;
	let lastArgs = [];

	return ( ...args ) => {
		lastArgs = args;

		if ( queued ) {
			return;
		}

		queued = true;

		window.requestAnimationFrame( () => {
			queued = false;
			fn( ...lastArgs );
		} );
	};
}
