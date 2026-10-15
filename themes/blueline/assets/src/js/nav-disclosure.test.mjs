import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const {
	submenuTriggers,
	focusableTrigger,
	labelToggles,
	disclosureNextOpen,
} = require( './nav-disclosure.js' );

// Fake elements (house rule: no jsdom): an <li> answering the two
// `:scope > …` queries navigation.js makes.
function control( tag, attrs = {} ) {
	return {
		tagName: tag.toUpperCase(),
		getAttribute: ( name ) => ( name in attrs ? attrs[ name ] : null ),
	};
}

function item( label, chevron ) {
	return {
		querySelector: ( selector ) =>
			( {
				':scope > .bl-nav__link': label,
				':scope > .bl-nav__toggle-sub': chevron,
			} )[ selector ] || null,
	};
}

test( 'a "#" parent: the label button is the control, and both buttons track the state', () => {
	const label = control( 'button' );
	const chevron = control( 'button', { tabindex: '-1', 'aria-hidden': 'true' } );
	const li = item( label, chevron );

	assert.deepEqual( submenuTriggers( li ), [ label, chevron ] );
	assert.equal( focusableTrigger( li ), label );
	assert.equal( labelToggles( label ), true );
} );

test( 'a parent with a destination: the link navigates and the chevron is the control', () => {
	const label = control( 'a', { href: '/rosters' } );
	const chevron = control( 'button' );
	const li = item( label, chevron );

	assert.deepEqual( submenuTriggers( li ), [ chevron ] );
	assert.equal( focusableTrigger( li ), chevron );
	assert.equal( labelToggles( label ), false );
} );

test( 'a legacy bare "#" link still toggles rather than jumping to the top', () => {
	assert.equal( labelToggles( control( 'a', { href: '#' } ) ), true );
	assert.equal( labelToggles( null ), false );
} );

test( 'an item with no chevron at all still has its label button', () => {
	const label = control( 'button' );

	assert.deepEqual( submenuTriggers( item( label, null ) ), [ label ] );
	assert.equal( focusableTrigger( item( null, null ) ), null );
} );

test( 'Enter or Space on a shown dropdown closes it, wherever it was opened from', () => {
	// Desktop: tabbing onto "League Info" shows it through :focus-within,
	// with data-open never set. The control still has to close it.
	assert.equal( disclosureNextOpen( { shown: true, pinned: false, pointerOver: false } ), false );
	assert.equal( disclosureNextOpen( { shown: true, pinned: true, pointerOver: false } ), false );
} );

test( 'a closed or dismissed dropdown opens', () => {
	assert.equal( disclosureNextOpen( { shown: false, pinned: false, pointerOver: false } ), true );
	assert.equal( disclosureNextOpen( { shown: false, pinned: false, pointerOver: true } ), true );
} );

test( 'a click on a dropdown the pointer has just opened by hover pins it rather than closing it', () => {
	assert.equal( disclosureNextOpen( { shown: true, pinned: false, pointerOver: true } ), true );

	// The next click on the pinned dropdown closes it.
	assert.equal( disclosureNextOpen( { shown: true, pinned: true, pointerOver: true } ), false );
} );
