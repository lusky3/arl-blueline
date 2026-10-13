/**
 * Full names for team names the schedule table had to clamp.
 *
 * The Home/Away columns are sized so every active team's name fits (see
 * `--bl-sched-home` in sportspress.css), but a name can still be longer than
 * its column: a retired team's full title, or a phone with large text. Such a
 * name wraps onto a second line and is clamped there with an ellipsis. This
 * offers the whole name instead of leaving the reader to guess:
 *
 * - mouse: the link carries a `title`, so hovering shows the browser tooltip;
 * - keyboard: focusing the link shows the bubble;
 * - touch: the FIRST tap on a clamped name shows the bubble and does not
 *   follow the link, a SECOND tap on the same name follows it. A name that
 *   fits is never touched, so an ordinary tap still goes straight to the team.
 *
 * The first-tap rule only ever applies to a clamped name, because a tap that
 * does not navigate is a cost and must be paid only where it buys something.
 */

const NAME_SELECTOR = '.sp-event-list.bl-sp-schedule .bl-sp-team-name';
const LINK_SELECTOR = 'a[data-bl-clipped]';
const TIP_ID = 'bl-sp-team-tip';

// A box exactly as wide as its content can still report a fractional
// remainder in some browsers' layout math; ignore it rather than flag a name
// that is in fact fully shown.
const SLOP = 1;

// How long a tap-revealed bubble stays before it hides itself.
const HIDE_AFTER_MS = 4000;

// Gap between the name and its bubble, and the minimum distance from either
// viewport edge.
const OFFSET = 6;
const EDGE = 8;

/**
 * Whether a name's text is bigger than the box showing it: wider (a word that
 * cannot fit its line) or taller (more lines than the clamp shows).
 *
 * @param {{scrollWidth: number, clientWidth: number, scrollHeight: number, clientHeight: number}} el Name element.
 * @return {boolean} True if the text is cut off.
 */
function isClipped( el ) {
	return (
		el.scrollWidth - el.clientWidth > SLOP ||
		el.scrollHeight - el.clientHeight > SLOP
	);
}

/**
 * What a scroll should do to the bubble.
 *
 * Focusing a link inside the table's horizontal scroller scrolls it into view,
 * so hiding on every scroll hid the bubble the focus had only just shown. While
 * its link keeps focus the bubble follows it instead; a tap-revealed bubble on
 * an unfocused link would otherwise float over the wrong row, so it hides.
 *
 * @param {{showing: boolean, focused: boolean}} state `showing` is whether the
 *                                                     bubble is up; `focused` whether its link has focus.
 * @return {'none'|'reposition'|'hide'} What to do.
 */
function resolveScroll( { showing, focused } ) {
	if ( ! showing ) {
		return 'none';
	}

	return focused ? 'reposition' : 'hide';
}

/**
 * What a click on a clamped name should do.
 *
 * @param {{pointerType: string, armed: boolean}} state `pointerType` is the
 *                                                      pointer that produced the click ('touch', 'pen', 'mouse', or '' for a
 *                                                      keyboard-synthesised click); `armed` is whether this same name's bubble
 *                                                      is already showing from an earlier tap.
 * @return {'reveal'|'navigate'} 'reveal' shows the bubble and cancels the
 *     navigation; 'navigate' lets the link through.
 */
function resolveTap( { pointerType, armed } ) {
	const isTouch = 'touch' === pointerType || 'pen' === pointerType;

	if ( ! isTouch || armed ) {
		return 'navigate';
	}

	return 'reveal';
}

/**
 * Left edge for a bubble so it stays inside the viewport.
 *
 * @param {number} desired  Where the bubble would like to start.
 * @param {number} width    The bubble's width.
 * @param {number} viewport The viewport's width.
 * @return {number} A left edge no closer than EDGE to either side (or EDGE
 *     itself when the bubble is wider than the viewport allows).
 */
function clampLeft( desired, width, viewport ) {
	const max = viewport - width - EDGE;

	return Math.max( EDGE, Math.min( desired, max ) );
}

let tip = null;
let shownLink = null;
let armedLink = null;
let hideTimer = 0;
let lastPointerType = '';

/**
 * The one shared bubble, created on first use.
 *
 * @return {HTMLElement} Bubble element.
 */
function getTip() {
	if ( ! tip ) {
		tip = document.createElement( 'span' );
		tip.id = TIP_ID;
		tip.className = 'bl-sp-team-tip';
		tip.setAttribute( 'role', 'tooltip' );
		tip.hidden = true;
		document.body.appendChild( tip );
	}

	return tip;
}

/**
 * Hide the bubble and forget which name it was armed for.
 *
 * @return {void}
 */
function hideTip() {
	window.clearTimeout( hideTimer );
	hideTimer = 0;

	if ( shownLink ) {
		shownLink.removeAttribute( 'aria-describedby' );
	}

	shownLink = null;
	armedLink = null;

	if ( tip ) {
		tip.hidden = true;
	}
}

/**
 * Put the bubble just below its link, inside the viewport.
 *
 * @param {Element} link The team link.
 * @return {void}
 */
function placeTip( link ) {
	const bubble = getTip();
	const rect = link.getBoundingClientRect();

	bubble.style.left = `${ clampLeft(
		rect.left + window.scrollX,
		bubble.offsetWidth,
		document.documentElement.clientWidth
	) }px`;
	bubble.style.top = `${ rect.bottom + window.scrollY + OFFSET }px`;
}

/**
 * Show the full name for a clamped link, just below it.
 *
 * @param {Element} link The team link.
 * @param {boolean} arm  Whether a further tap should now follow the link.
 * @return {void}
 */
function showTip( link, arm ) {
	hideTip();

	const bubble = getTip();

	bubble.textContent = ( link.textContent || '' )
		.replace( /\s+/g, ' ' )
		.trim();
	bubble.hidden = false;
	link.setAttribute( 'aria-describedby', TIP_ID );
	shownLink = link;

	placeTip( link );

	// A focus-shown bubble is not armed, so the click that follows a focus is
	// still read as a first tap; hideTip() on blur clears its describedby.
	if ( arm ) {
		armedLink = link;
		hideTimer = window.setTimeout( hideTip, HIDE_AFTER_MS );
	}
}

/**
 * Mark (or unmark) every clamped name's link, and give clamped ones a title.
 *
 * @return {void}
 */
function syncClamped() {
	document.querySelectorAll( NAME_SELECTOR ).forEach( ( nameEl ) => {
		const link = nameEl.closest( 'a' );

		if ( ! link ) {
			return;
		}

		if ( isClipped( nameEl ) ) {
			link.setAttribute( 'data-bl-clipped', '' );
			link.title = ( nameEl.textContent || '' )
				.replace( /\s+/g, ' ' )
				.trim();
		} else if ( link.hasAttribute( 'data-bl-clipped' ) ) {
			link.removeAttribute( 'data-bl-clipped' );
			link.removeAttribute( 'title' );

			if ( link === shownLink ) {
				hideTip();
			}
		}
	} );
}

/**
 * Pointer type behind a click. A keyboard-synthesised click has `detail` 0,
 * and must not inherit the type of an unrelated earlier touch.
 *
 * @param {MouseEvent} event Click event.
 * @return {string} 'touch', 'pen', 'mouse' or ''.
 */
function pointerTypeOf( event ) {
	if ( 0 === event.detail ) {
		return '';
	}

	return event.pointerType || lastPointerType;
}

function onClick( event ) {
	const link = event.target.closest
		? event.target.closest( LINK_SELECTOR )
		: null;

	if ( ! link ) {
		hideTip();
		return;
	}

	const action = resolveTap( {
		pointerType: pointerTypeOf( event ),
		armed: link === armedLink,
	} );

	if ( 'reveal' === action ) {
		event.preventDefault();
		showTip( link, true );
		return;
	}

	hideTip();
}

function onFocusIn( event ) {
	const link = event.target.closest
		? event.target.closest( LINK_SELECTOR )
		: null;

	if ( link ) {
		showTip( link, false );
	}
}

function onFocusOut( event ) {
	if ( event.target.closest && event.target.closest( LINK_SELECTOR ) ) {
		hideTip();
	}
}

function onScroll() {
	const action = resolveScroll( {
		showing: null !== shownLink,
		focused:
			null !== shownLink &&
			shownLink.ownerDocument.activeElement === shownLink,
	} );

	if ( 'reposition' === action ) {
		placeTip( shownLink );
	} else if ( 'hide' === action ) {
		hideTip();
	}
}

function initTeamNameTooltip() {
	if ( ! document.querySelector( NAME_SELECTOR ) ) {
		return;
	}

	// At most one re-measure per frame; resize fires far more often than that.
	let queued = false;
	const sync = () => {
		if ( queued ) {
			return;
		}

		queued = true;
		window.requestAnimationFrame( () => {
			queued = false;
			syncClamped();
		} );
	};

	syncClamped();

	window.addEventListener( 'resize', sync );
	window.addEventListener( 'load', sync );

	// Web fonts landing after first paint change every name's width.
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( sync );
	}

	document.addEventListener(
		'pointerdown',
		( event ) => {
			lastPointerType = event.pointerType || '';
		},
		true
	);
	document.addEventListener( 'click', onClick, true );
	document.addEventListener( 'focusin', onFocusIn );
	document.addEventListener( 'focusout', onFocusOut );
	document.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key ) {
			hideTip();
		}
	} );

	// Capturing, so a scroll inside the table's own scroller counts too.
	document.addEventListener( 'scroll', onScroll, true );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initTeamNameTooltip );
	} else {
		initTeamNameTooltip();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { isClipped, resolveTap, resolveScroll, clampLeft };
}
