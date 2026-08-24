/**
 * Run `fn` once the DOM is ready to be queried and mutated.
 *
 * `document.readyState` may already be past 'loading' by the time a script
 * runs (e.g. it was injected or deferred), so unconditionally waiting for
 * DOMContentLoaded would hang forever in that case; but a script that runs
 * while the document is still loading does need to wait for it. Every
 * front-end entry file was re-deriving this same two-branch check.
 *
 * @param {Function} fn Callback to run once the DOM is ready.
 * @return {void}
 */
export function onReady( fn ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', fn );
	} else {
		fn();
	}
}
