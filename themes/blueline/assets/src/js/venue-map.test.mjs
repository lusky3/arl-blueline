import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { readMapConfig, drawMap, openMap, TILES } = require( './venue-map.js' );

const DATASET = {
	lat: '43.36113990718071',
	lng: '-79.80409741401672',
	zoom: '15',
	maptype: 'roadmap',
	link: 'https://www.google.com/maps/search/?api=1&query=43.36,-79.80',
	mapLabel: 'Map of Black (opens Google Maps in a new tab)',
	leafletJs: 'https://example.test/leaflet.js?ver=1.8.0',
	leafletCss: 'https://example.test/leaflet.css?ver=1.8.0',
};

/**
 * A fake Leaflet that records what the map was told to do.
 *
 * @return {Object} { L, calls }.
 */
function fakeLeaflet() {
	const calls = [];
	const disabled = [];
	const handler = ( name ) => ( {
		disable() {
			disabled.push( name );
		},
	} );
	const map = {
		setView( center, zoom ) {
			calls.push( [ 'setView', center, zoom ] );
			return map;
		},
		dragging: handler( 'dragging' ),
		touchZoom: handler( 'touchZoom' ),
		doubleClickZoom: handler( 'doubleClickZoom' ),
		scrollWheelZoom: handler( 'scrollWheelZoom' ),
	};
	const layer = ( kind, args ) => ( {
		addTo( target ) {
			calls.push( [ kind, ...args, target === map ] );
		},
	} );

	return {
		calls,
		disabled,
		map,
		L: {
			map( el, options ) {
				calls.push( [ 'map', el, options ] );
				return map;
			},
			tileLayer: ( ...args ) => layer( 'tileLayer', args ),
			marker: ( ...args ) => layer( 'marker', args ),
		},
	};
}

/**
 * Fake element: attributes, classes, children and focus.
 *
 * @param {string} tag Tag name.
 * @return {Object} Fake element.
 */
function fakeElement( tag ) {
	const el = {
		tagName: tag,
		attributes: {},
		children: [],
		classes: new Set(),
		focused: false,
		setAttribute( name, value ) {
			this.attributes[ name ] = String( value );
		},
		getAttribute( name ) {
			return name in this.attributes ? this.attributes[ name ] : null;
		},
		removeAttribute( name ) {
			delete this.attributes[ name ];
		},
		appendChild( child ) {
			this.children.push( child );
		},
		replaceChildren( ...children ) {
			this.children = children;
		},
		focus() {
			this.focused = true;
		},
	};
	el.classList = {
		add: ( name ) => el.classes.add( name ),
	};

	return el;
}

const fakeDoc = { createElement: fakeElement };

test( 'readMapConfig reads the placeholder data attributes', () => {
	const config = readMapConfig( DATASET );

	assert.equal( config.lat, 43.36113990718071 );
	assert.equal( config.lng, -79.80409741401672 );
	assert.equal( config.zoom, 15 );
	assert.equal( config.tiles, TILES.roadmap );
	assert.equal( config.leafletJs, DATASET.leafletJs );
	assert.equal( config.label, DATASET.mapLabel );
} );

test( 'readMapConfig picks satellite tiles and clamps a bad zoom', () => {
	const config = readMapConfig( { ...DATASET, maptype: 'satellite', zoom: '40' } );

	assert.equal( config.tiles, TILES.satellite );
	assert.equal( config.zoom, 15 );
} );

test( 'readMapConfig refuses missing coordinates or Leaflet URLs', () => {
	assert.equal( readMapConfig( { ...DATASET, lat: '' } ), null );
	assert.equal( readMapConfig( { ...DATASET, lng: 'west' } ), null );
	assert.equal( readMapConfig( { ...DATASET, leafletJs: '' } ), null );
	assert.equal( readMapConfig( { ...DATASET, leafletCss: undefined } ), null );
	assert.equal( readMapConfig( null ), null );
} );

test( 'drawMap draws the map the way SportsPress does: no controls, no interaction, one marker', () => {
	const { L, calls, disabled, map } = fakeLeaflet();
	const canvas = fakeElement( 'div' );
	const config = readMapConfig( DATASET );

	assert.equal( drawMap( L, canvas, config ), map );
	assert.deepEqual( calls[ 0 ], [ 'map', canvas, { zoomControl: false } ] );
	assert.deepEqual( calls[ 1 ], [ 'setView', [ config.lat, config.lng ], 15 ] );
	assert.deepEqual( calls[ 2 ], [
		'tileLayer',
		TILES.roadmap.url,
		{ attribution: TILES.roadmap.attribution, maxZoom: 18 },
		true,
	] );
	assert.deepEqual( calls[ 3 ], [ 'marker', [ config.lat, config.lng ], true ] );
	assert.deepEqual( disabled, [
		'dragging',
		'touchZoom',
		'doubleClickZoom',
		'scrollWheelZoom',
	] );
} );

test( 'openMap swaps the placeholder for the map, in a Google Maps link that takes focus', async () => {
	const { L } = fakeLeaflet();
	const placeholder = fakeElement( 'div' );
	placeholder.dataset = DATASET;
	let loadedWith = null;

	const map = await openMap( placeholder, {
		doc: fakeDoc,
		load: ( config ) => {
			loadedWith = config;
			return Promise.resolve( L );
		},
	} );

	assert.ok( map );
	assert.equal( loadedWith.leafletCss, DATASET.leafletCss );
	assert.equal( placeholder.children.length, 1 );

	const frame = placeholder.children[ 0 ];

	assert.equal( frame.tagName, 'a' );
	assert.equal( frame.href, DATASET.link );
	assert.equal( frame.target, '_blank' );
	assert.equal( frame.rel, 'noopener' );
	assert.equal( frame.attributes[ 'aria-label' ], DATASET.mapLabel );
	assert.equal( frame.children[ 0 ].className, 'bl-venue-map__canvas' );
	assert.ok( placeholder.classes.has( 'is-open' ) );
	assert.equal( placeholder.getAttribute( 'aria-busy' ), null );
	assert.equal( frame.focused, true );
} );

test( 'openMap leaves the placeholder as it was when Leaflet fails to load', async () => {
	const placeholder = fakeElement( 'div' );
	placeholder.dataset = DATASET;
	placeholder.children = [ 'button' ];

	const map = await openMap( placeholder, {
		doc: fakeDoc,
		load: () => Promise.reject( new Error( 'offline' ) ),
	} );

	assert.equal( map, null );
	assert.deepEqual( placeholder.children, [ 'button' ] );
	assert.equal( placeholder.getAttribute( 'aria-busy' ), null );
} );

test( 'openMap ignores a second click while the first is still loading', async () => {
	const placeholder = fakeElement( 'div' );
	placeholder.dataset = DATASET;
	let loads = 0;
	const deps = {
		doc: fakeDoc,
		load: () => {
			loads++;
			return new Promise( () => {} );
		},
	};

	openMap( placeholder, deps );
	const second = await openMap( placeholder, deps );

	assert.equal( second, null );
	assert.equal( loads, 1 );
} );
