import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { blOccasionContrastRatio, blOccasionIsHex, blOccasionApplyRowKey } =
	require( './settings-occasions.js' );

test( 'blOccasionContrastRatio: black on white is the maximum ratio', () => {
	assert.ok( blOccasionContrastRatio( '#000000', '#ffffff' ) > 20 );
} );

test( 'blOccasionContrastRatio: a colour against itself is exactly 1', () => {
	assert.equal( blOccasionContrastRatio( '#132343', '#132343' ), 1 );
} );

test( 'blOccasionContrastRatio: order of arguments does not matter', () => {
	const a = blOccasionContrastRatio( '#132343', '#ffffff' );
	const b = blOccasionContrastRatio( '#ffffff', '#132343' );
	assert.equal( a, b );
} );

test( 'blOccasionIsHex: accepts a well-formed 6-digit hex colour', () => {
	assert.equal( blOccasionIsHex( '#132343' ), true );
	assert.equal( blOccasionIsHex( '#ABCDEF' ), true );
} );

test( 'blOccasionIsHex: rejects anything else', () => {
	assert.equal( blOccasionIsHex( '132343' ), false );
	assert.equal( blOccasionIsHex( '#12334' ), false );
	assert.equal( blOccasionIsHex( 'not-a-colour' ), false );
	assert.equal( blOccasionIsHex( '' ), false );
} );

/*
 * PHP<->JS parity fixture (design spec §5.1's fourth ruling): these
 * exact hex pairs and their expected ratios were computed once,
 * directly, with this exact formula, and confirmed identical (to 6
 * decimal places) against inc/team-colors.php's
 * blueline_contrast_ratio() by running both side by side -- see
 * tests/OccasionsContrastParityTest.php, which pins the SAME table on
 * the PHP side. Neither file imports the other; if
 * blOccasionContrastRatio() or blueline_contrast_ratio() ever drifts
 * from this formula, whichever one moved fails its OWN half of this
 * pinned table, which is the whole point: a silent, one-sided drift is
 * exactly what the ruling is guarding against.
 */
const PARITY_FIXTURE = [
	[ '#132343', '#ffffff', 15.565337 ],
	[ '#132343', '#000000', 1.349152 ],
	[ '#132343', '#132343', 1.0 ],
	[ '#132343', '#274a63', 1.664712 ],
	[ '#132343', '#f7fbfc', 14.942248 ],
	[ '#132343', '#c8102e', 2.645692 ],
	[ '#132343', '#ffd700', 11.097474 ],
];

test( 'blOccasionContrastRatio matches the PHP<->JS parity fixture', () => {
	for ( const [ a, b, expected ] of PARITY_FIXTURE ) {
		const actual = blOccasionContrastRatio( a, b );
		assert.ok(
			Math.abs( actual - expected ) < 0.0005,
			`${ a } vs ${ b }: expected ${ expected }, got ${ actual }`
		);
	}
} );

/*
 * blOccasionApplyRowKey() is exercised against a deliberately tiny
 * stand-in for a cloned `<template>` row rather than a real DOM: this
 * repo has no jsdom/happy-dom dependency of its own (`npm run test:js`
 * is plain `node --test`), and adding a whole DOM implementation to
 * assert three attribute rewrites would be a much bigger dependency
 * than the thing under test. The stand-in implements exactly what the
 * function touches -- querySelectorAll() over `[name]`, `[id]` and
 * `[for]`, the `name`/`id` properties, and getAttribute()/
 * setAttribute() for `for` -- so a regression in any of the three
 * rewrites still fails here. The server side of the same contract (that
 * the template row really does render `__TEMPLATE__` inside its ids and
 * `for`s, so there is something to rewrite) is pinned in PHP by
 * tests/SettingsOccasionsTabTest.php's
 * test_the_template_rows_ids_and_label_associations_carry_the_placeholder().
 */
function fakeTemplateRow() {
	const field = ( id, name ) => ( { id, name } );
	const label = ( forValue ) => {
		const attrs = { for: forValue };
		return {
			getAttribute: ( key ) => attrs[ key ],
			setAttribute: ( key, value ) => {
				attrs[ key ] = value;
			},
		};
	};

	const nodes = [
		field(
			'bl-occasion-__TEMPLATE__-label',
			'blueline_settings[occasions][__TEMPLATE__][label]'
		),
		field(
			'bl-occasion-__TEMPLATE__-accent',
			'blueline_settings[occasions][__TEMPLATE__][accent]'
		),
		// The hidden `_original_id` field: a name, but no id at all.
		{ name: 'blueline_settings[occasions][__TEMPLATE__][_original_id]' },
		label( 'bl-occasion-__TEMPLATE__-label' ),
		label( 'bl-occasion-__TEMPLATE__-accent' ),
	];

	return {
		nodes,
		querySelectorAll: ( selector ) =>
			nodes.filter( ( node ) => {
				if ( '[name]' === selector ) {
					return undefined !== node.name;
				}
				if ( '[id]' === selector ) {
					return undefined !== node.id;
				}
				return 'function' === typeof node.getAttribute;
			} ),
	};
}

test( 'blOccasionApplyRowKey: rewrites name, id AND for off the placeholder', () => {
	const row = fakeTemplateRow();

	blOccasionApplyRowKey( row, '__new_1' );

	const [ labelField, accentField, originalId, labelCaption, accentCaption ] =
		row.nodes;

	assert.equal(
		labelField.name,
		'blueline_settings[occasions][__new_1][label]'
	);
	assert.equal(
		originalId.name,
		'blueline_settings[occasions][__new_1][_original_id]'
	);
	assert.equal( labelField.id, 'bl-occasion-__new_1-label' );
	assert.equal( accentField.id, 'bl-occasion-__new_1-accent' );
	assert.equal(
		labelCaption.getAttribute( 'for' ),
		'bl-occasion-__new_1-label'
	);
	assert.equal(
		accentCaption.getAttribute( 'for' ),
		'bl-occasion-__new_1-accent'
	);
} );

test( 'blOccasionApplyRowKey: two added rows share no id, and each label points at its own row', () => {
	const first = fakeTemplateRow();
	const second = fakeTemplateRow();

	blOccasionApplyRowKey( first, '__new_1' );
	blOccasionApplyRowKey( second, '__new_2' );

	const ids = ( row ) =>
		row.nodes
			.filter( ( node ) => undefined !== node.id )
			.map( ( node ) => node.id );

	for ( const id of ids( first ) ) {
		assert.ok(
			! ids( second ).includes( id ),
			`id ${ id } collides between two added rows`
		);
	}

	// Each caption must resolve to an id that exists in ITS OWN row.
	for ( const row of [ first, second ] ) {
		const rowIds = ids( row );
		row.nodes
			.filter( ( node ) => 'function' === typeof node.getAttribute )
			.forEach( ( caption ) => {
				assert.ok(
					rowIds.includes( caption.getAttribute( 'for' ) ),
					`caption for="${ caption.getAttribute(
						'for'
					) }" does not match any id in its own row`
				);
			} );
	}

	assert.ok(
		! ids( first ).some( ( id ) => id.includes( '__TEMPLATE__' ) ),
		'the placeholder must not survive the rewrite'
	);
} );
