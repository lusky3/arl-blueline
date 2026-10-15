/**
 * Venue maps.
 *
 * 1. Event pages: the venue map is a click-to-load placeholder
 *    (blueline_render_venue_map_placeholder(), inc/sportspress/venues.php).
 *    Leaflet and the map tiles were the slowest thing on an event page to
 *    load, so they are fetched only when someone asks for the map, and the
 *    map is drawn into the placeholder's own fixed-height box so nothing
 *    below it moves. The options mirror SportsPress's own map (its
 *    OpenStreetMap module's show_venue_map()): no zoom control, no
 *    dragging or zooming, a single marker, and a click through to Google
 *    Maps.
 *
 * 2. The venue page keeps SportsPress' live Leaflet map, which needs a
 *    repaint after its container settles. Leaflet lays tiles out for the
 *    container size it sees at init and does not notice later changes.
 *    SportsPress initialises the map inside a table cell whose width is not
 *    final at that moment -- the surrounding table, its scroll wrapper and the
 *    web fonts all resolve afterwards -- so the map ends up with tiles for a
 *    narrower box than it actually occupies, leaving a blank strip down one
 *    side that reads as a half-loaded map. Measured on the same map: the
 *    container's right edge was at 359px while the furthest tile reached only
 *    290px, with three tiles rendered. After a resize the same map had six
 *    tiles reaching past the container, as it should. Leaflet's own
 *    `trackResize` (on by default) already re-measures on window resize, so
 *    the fix is to tell it the layout changed rather than to reach inside for
 *    the map instance -- which SportsPress does not expose anywhere.
 */

const CONTAINER = '.leaflet-container';
const PLACEHOLDER = '[data-bl-venue-map]';

const TILES = {
	roadmap: {
		url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
		attribution:
			'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors',
	},
	satellite: {
		url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
		attribution:
			'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community',
	},
};

/**
 * The map settings carried on a placeholder's data attributes, or null when
 * they are unusable (no coordinates, or Leaflet's URLs missing).
 *
 * @param {Object} dataset The placeholder's dataset.
 * @return {Object|null} Map settings.
 */
function readMapConfig( dataset ) {
	if ( ! dataset ) {
		return null;
	}

	const lat = Number.parseFloat( dataset.lat );
	const lng = Number.parseFloat( dataset.lng );

	if (
		! Number.isFinite( lat ) ||
		! Number.isFinite( lng ) ||
		! dataset.leafletJs ||
		! dataset.leafletCss
	) {
		return null;
	}

	const zoom = Number.parseInt( dataset.zoom, 10 );

	return {
		lat,
		lng,
		zoom: zoom >= 1 && zoom <= 18 ? zoom : 15,
		tiles: TILES[ dataset.maptype ] || TILES.roadmap,
		link: dataset.link || '',
		label: dataset.mapLabel || '',
		leafletJs: dataset.leafletJs,
		leafletCss: dataset.leafletCss,
	};
}

/**
 * Draw the map the way SportsPress does.
 *
 * @param {Object}  L      Leaflet.
 * @param {Element} canvas Element to draw into.
 * @param {Object}  config From readMapConfig().
 * @return {Object} The Leaflet map.
 */
function drawMap( L, canvas, config ) {
	const map = L.map( canvas, { zoomControl: false } ).setView(
		[ config.lat, config.lng ],
		config.zoom
	);

	L.tileLayer( config.tiles.url, {
		attribution: config.tiles.attribution,
		maxZoom: 18,
	} ).addTo( map );
	L.marker( [ config.lat, config.lng ] ).addTo( map );

	map.dragging.disable();
	map.touchZoom.disable();
	map.doubleClickZoom.disable();
	map.scrollWheelZoom.disable();

	return map;
}

const loads = new Map();

/**
 * Add a stylesheet or script to <head> once per URL and settle when it has
 * loaded.
 *
 * @param {Document} doc The document.
 * @param {string}   tag 'link' or 'script'.
 * @param {string}   url Asset URL.
 * @return {Promise<void>} Settles when the asset has loaded or failed.
 */
function loadAsset( doc, tag, url ) {
	if ( ! loads.has( url ) ) {
		loads.set(
			url,
			new Promise( ( resolve, reject ) => {
				const el = doc.createElement( tag );

				if ( 'link' === tag ) {
					el.rel = 'stylesheet';
					el.href = url;
				} else {
					el.src = url;
				}

				el.addEventListener( 'load', () => resolve() );
				el.addEventListener( 'error', () => {
					loads.delete( url );
					el.remove();
					reject( new Error( url ) );
				} );
				doc.head.appendChild( el );
			} )
		);
	}

	return loads.get( url );
}

/**
 * Load Leaflet's stylesheet and script (once) and hand back the library.
 *
 * @param {Document} doc    The document.
 * @param {Object}   win    The window.
 * @param {Object}   config From readMapConfig().
 * @return {Promise<Object>} Leaflet.
 */
function loadLeaflet( doc, win, config ) {
	if ( win.L ) {
		return Promise.resolve( win.L );
	}

	return Promise.all( [
		loadAsset( doc, 'link', config.leafletCss ),
		loadAsset( doc, 'script', config.leafletJs ),
	] ).then( () => win.L );
}

/**
 * Replace a placeholder's contents with the live map. The map sits in a link
 * to Google Maps (the map itself takes no input), which receives focus so a
 * keyboard user is not dropped back at the top of the page when the button
 * they pressed disappears.
 *
 * @param {Element} placeholder The [data-bl-venue-map] element.
 * @param {Object}  deps        { doc, load }: load( config ) resolves to Leaflet.
 * @return {Promise<Object|null>} The Leaflet map, or null if it could not open.
 */
function openMap( placeholder, deps ) {
	const config = readMapConfig( placeholder.dataset );

	if ( ! config || placeholder.getAttribute( 'aria-busy' ) === 'true' ) {
		return Promise.resolve( null );
	}

	placeholder.setAttribute( 'aria-busy', 'true' );

	return deps
		.load( config )
		.then( ( L ) => {
			const frame = deps.doc.createElement( config.link ? 'a' : 'div' );
			const canvas = deps.doc.createElement( 'div' );

			frame.className = 'bl-venue-map__frame';
			canvas.className = 'bl-venue-map__canvas';

			if ( config.link ) {
				frame.href = config.link;
				frame.target = '_blank';
				frame.rel = 'noopener';

				if ( config.label ) {
					frame.setAttribute( 'aria-label', config.label );
				}
			}

			frame.appendChild( canvas );
			placeholder.replaceChildren( frame );
			placeholder.classList.add( 'is-open' );
			placeholder.removeAttribute( 'aria-busy' );

			const map = drawMap( L, canvas, config );

			if ( config.link ) {
				frame.focus();
			}

			return map;
		} )
		.catch( () => {
			placeholder.removeAttribute( 'aria-busy' );

			return null;
		} );
}

/**
 * Wire every click-to-load placeholder's "Open map" button.
 *
 * @return {void}
 */
function initClickToLoad() {
	document.querySelectorAll( PLACEHOLDER ).forEach( ( placeholder ) => {
		const button = placeholder.querySelector( '.bl-venue-map__open' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', () => {
			openMap( placeholder, {
				doc: document,
				load: ( config ) => loadLeaflet( document, window, config ),
			} );
		} );
	} );
}

/**
 * Ask Leaflet to re-measure. Dispatching the event it already listens for is
 * deliberate: the alternative is grabbing a private map handle that neither
 * Leaflet nor SportsPress publishes, which would break on any update to either.
 *
 * @return {void}
 */
function nudgeLeaflet() {
	window.dispatchEvent( new Event( 'resize' ) );
}

function initVenueMap() {
	const containers = document.querySelectorAll( CONTAINER );

	if ( ! containers.length ) {
		return;
	}

	// One nudge once everything that affects width has settled: late
	// stylesheets, the scroll wrapper, and web fonts.
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( nudgeLeaflet );
	}

	window.requestAnimationFrame( nudgeLeaflet );

	if ( 'undefined' === typeof window.ResizeObserver ) {
		return;
	}

	/*
	 * And again whenever a container's own box changes afterwards -- opening
	 * the tab a map sits in, or the sidebar reflowing, both resize it long
	 * after load. Guarded against the observer reacting to the relayout its own
	 * nudge causes, which would otherwise loop.
	 */
	let settling = false;

	const observer = new window.ResizeObserver( () => {
		if ( settling ) {
			return;
		}

		settling = true;
		nudgeLeaflet();

		window.setTimeout( () => {
			settling = false;
		}, 250 );
	} );

	containers.forEach( ( el ) => observer.observe( el ) );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initClickToLoad );
		document.addEventListener( 'DOMContentLoaded', initVenueMap );
	} else {
		initClickToLoad();
		initVenueMap();
	}

	// Maps inside a tab panel only get a real size when the tab is first
	// shown, which happens after DOMContentLoaded and after load.
	window.addEventListener( 'load', initVenueMap );
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { readMapConfig, drawMap, openMap, TILES };
}
