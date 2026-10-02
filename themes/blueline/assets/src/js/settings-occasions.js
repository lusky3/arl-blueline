/**
 * The live contrast readout, colour-input syncing, and repeater
 * mechanics for the Occasions tab in Appearance -> Blueline.
 *
 * The ratio/luminance math below is a DELIBERATE, small,
 * self-contained DUPLICATE of blueline_contrast_ratio()/
 * blueline_relative_luminance() (inc/team-colors.php) -- not an import
 * of tools/lib/contrast.mjs, which is dev/build tooling, not a runtime
 * asset (design spec §5.1's fourth ruling). tests/OccasionsContrastParityTest.php
 * and this file's own settings-occasions.test.mjs both assert this
 * copy agrees with the PHP original at a fixed set of hex pairs -- if
 * this math ever changes, BOTH must change with it, or one of those
 * two tests fails.
 *
 * Progressive, not required: with this file absent, every row still
 * renders with its correct, server-computed (at last save) contrast
 * readout and override state -- only the LIVE update as an admin
 * edits a colour is lost, the same "accurate but not live"
 * degradation settings-photos.js's own docblock describes for its
 * "Add" button.
 *
 * The CommonJS export guard at the foot of this file is read only by
 * settings-occasions.test.mjs (Node's test runner); `module` is a
 * recognised global in this lint environment already, and is never
 * defined in a browser, so that guard is always skipped there.
 */

const ROOT = '[data-bl-occasions]';

/**
 * Fallback for `window.blOccasionsData` when the settings data
 * (inc/settings/page.php's inline JSON) is unavailable --
 * e.g. this script loaded outside the admin settings page it is written
 * for. Matches style.css's --bl-ink and the Occasions tab's default AA
 * threshold, so the live readout still shows a sane number rather than
 * throwing on a missing property.
 */
const DEFAULT_OCCASIONS_DATA = { inkHex: '#132343', threshold: 4.5 };

/**
 * WCAG relative luminance of a `#rrggbb` colour. Byte-for-byte the
 * same formula as inc/team-colors.php's blueline_relative_luminance().
 *
 * @param {string} hex 6-digit hex, with `#`.
 * @return {number} 0..1.
 */
function blOccasionLuminance( hex ) {
	const channel = ( byteHex ) => {
		const s = parseInt( byteHex, 16 ) / 255;
		return s <= 0.04045
			? s / 12.92
			: Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
	};
	return (
		0.2126 * channel( hex.slice( 1, 3 ) ) +
		0.7152 * channel( hex.slice( 3, 5 ) ) +
		0.0722 * channel( hex.slice( 5, 7 ) )
	);
}

/**
 * WCAG contrast ratio between two `#rrggbb` colours. Byte-for-byte the
 * same formula as inc/team-colors.php's blueline_contrast_ratio().
 *
 * @param {string} a Hex colour.
 * @param {string} b Hex colour.
 * @return {number} 1..21.
 */
function blOccasionContrastRatio( a, b ) {
	const la = blOccasionLuminance( a );
	const lb = blOccasionLuminance( b );
	const light = Math.max( la, lb );
	const dark = Math.min( la, lb );
	return ( light + 0.05 ) / ( dark + 0.05 );
}

/**
 * Whether $value looks like a real `#rrggbb` hex colour -- deliberately
 * strict, since this only decides whether to run the LIVE readout at
 * all; the authoritative check remains blueline_sanitize_hex_color()
 * at save time.
 *
 * @param {string} value Candidate value.
 * @return {boolean} Whether value is a well-formed `#rrggbb` colour.
 */
function blOccasionIsHex( value ) {
	return /^#[0-9a-f]{6}$/i.test( value );
}

/**
 * Update one row's contrast readout, override-notice visibility, and
 * colour-swatch mirror from its current hex text field value.
 *
 * @param {Element} row       A [data-bl-occasion-row] element.
 * @param {string}  inkHex    The site's ink token, from window.blOccasionsData.
 * @param {number}  threshold Minimum passing ratio, from window.blOccasionsData.
 * @return {void}
 */
function updateRow( row, inkHex, threshold ) {
	const hexInput = row.querySelector( '[data-bl-occasion-accent]' );
	const readout = row.querySelector( '[data-bl-occasion-contrast]' );

	if ( ! hexInput || ! readout ) {
		return;
	}

	const colorInput = row.querySelector( '[data-bl-occasion-color]' );

	const raw = hexInput.value.trim();
	let effective = inkHex;
	if ( blOccasionIsHex( raw ) ) {
		effective = raw;
	} else if ( colorInput ) {
		effective = colorInput.value;
	}

	if ( colorInput && blOccasionIsHex( raw ) ) {
		colorInput.value = raw;
	}

	if ( ! blOccasionIsHex( effective ) ) {
		return; // Nothing sensible to show yet (e.g. mid-edit); leave the last known-good readout in place.
	}

	const ratio = blOccasionContrastRatio( inkHex, effective );
	const passes = ratio >= threshold;

	readout.textContent = `Contrast against body text: ${ ratio.toFixed(
		1
	) }:1 (${ passes ? 'passes AA' : 'fails AA' })`;

	const notice = row.querySelector( '[data-bl-occasion-aa-notice]' );

	if ( notice ) {
		notice.hidden = passes;
	}
}

let addCounter = 0;

/**
 * Rewrite one cloned template row's `name`, `id` and `for` attributes
 * from the `__TEMPLATE__` placeholder to this row's own fresh key.
 *
 * All three matter, not just `name`. The server renders every field's
 * `id` as `bl-occasion-{rowKey}-{field}` and points each caption's
 * `<label for>` at it (blueline_settings_render_occasion_row(),
 * inc/settings/page.php) -- so a clone that renamed only `[name]` would
 * leave every added row sharing the template's ids, and every added
 * row's caption pointing at the FIRST added row's input: clicking the
 * second row's "Label" would focus the first row's field. Server-
 * rendered rows were never affected (their row key is a real, unique
 * occasion id); only JS-added ones.
 *
 * @param {Element} row    A cloned `[data-bl-occasion-row]` element.
 * @param {string}  rowKey This row's fresh, unique placeholder key.
 * @return {void}
 */
function blOccasionApplyRowKey( row, rowKey ) {
	row.querySelectorAll( '[name]' ).forEach( ( field ) => {
		field.name = field.name.replace( '__TEMPLATE__', rowKey );
	} );

	row.querySelectorAll( '[id]' ).forEach( ( field ) => {
		field.id = field.id.replace( '__TEMPLATE__', rowKey );
	} );

	row.querySelectorAll( '[for]' ).forEach( ( label ) => {
		label.setAttribute(
			'for',
			label.getAttribute( 'for' ).replace( '__TEMPLATE__', rowKey )
		);
	} );
}

/**
 * Fill a freshly cloned row's fields from a preset object.
 *
 * A preset only ever supplies the fields it cares about (a blank "Add"
 * click passes `{}`), so every field falls back to clearing itself rather
 * than being left at whatever the `<template>` markup happened to contain.
 *
 * @param {Element} row    A cloned `[data-bl-occasion-row]` element, already
 *                         past `blOccasionApplyRowKey()`.
 * @param {Object}  preset A blueline_occasion_presets() entry, or {} for blank.
 * @return {void}
 */
function populateRowFromPreset( row, preset ) {
	const setField = ( selector, value ) => {
		const field = row.querySelector( selector );
		if ( field && undefined !== value ) {
			field.value = value;
		}
	};

	setField( '[data-bl-occasion-label]', preset.label || '' );
	setField( '[data-bl-occasion-accent]', preset.accent || '' );
	setField( '[data-bl-occasion-line]', preset.line || '' );

	if ( preset.window ) {
		setField(
			'[data-bl-occasion-window-start]',
			preset.window.start_md || ''
		);
		setField( '[data-bl-occasion-window-end]', preset.window.end_md || '' );
	}

	[ 'type', 'motif', 'mode' ].forEach( ( key ) => {
		if ( ! preset[ key ] ) {
			return;
		}
		const field = row.querySelector( `[data-bl-occasion-${ key }]` );
		if ( field ) {
			field.value = preset[ key ];
		}
	} );
}

/**
 * Append a new row, cloned from the server-rendered `<template>`,
 * filled from either a preset or a blank shape.
 *
 * Every field in the clone is renamed to a fresh, unique placeholder
 * row key (`__new_{n}`) so two added rows in the same submission can
 * never collide as PHP array keys before the server ever gets a
 * chance to derive their real ids (design spec §5.1's first ruling)
 * -- unlike settings-photos.js's renumber(), no OTHER row's name
 * needs to change when one is added or removed, since `occasions` is
 * a map, not a contiguous indexed array.
 *
 * @param {Element} root   The field wrapper.
 * @param {Object}  preset A blueline_occasion_presets() entry, or {} for blank.
 * @return {void}
 */
function addRow( root, preset ) {
	const list = root.querySelector( '[data-bl-occasions-list]' );
	const template = root.querySelector( '[data-bl-occasions-template]' );

	if ( ! list || ! template ) {
		return;
	}

	const empty = root.querySelector( '[data-bl-occasions-empty]' );

	addCounter += 1;
	const rowKey = `__new_${ addCounter }`;

	const row = template.content.firstElementChild.cloneNode( true );

	blOccasionApplyRowKey( row, rowKey );
	populateRowFromPreset( row, preset );

	if ( empty ) {
		empty.hidden = true;
	}

	list.appendChild( row );

	const data = window.blOccasionsData || DEFAULT_OCCASIONS_DATA;
	updateRow( row, data.inkHex, data.threshold );
}

/**
 * Remove the row a "remove" control sits in, if the click landed on one.
 *
 * @param {Element} root  The field wrapper.
 * @param {Event}   event The delegated click event.
 * @return {void}
 */
function handleRemoveRowClick( root, event ) {
	const remove = event.target.closest( '[data-bl-occasion-remove]' );

	if ( ! remove ) {
		return;
	}

	const row = remove.closest( '[data-bl-occasion-row]' );
	if ( row ) {
		row.remove();
	}
}

/**
 * Append a blank row, if the click landed on the "add blank" control.
 *
 * @param {Element} root  The field wrapper.
 * @param {Event}   event The delegated click event.
 * @return {void}
 */
function handleAddBlankRowClick( root, event ) {
	if ( event.target.closest( '[data-bl-occasions-add-blank]' ) ) {
		addRow( root, {} );
	}
}

/**
 * Append a row from the currently selected preset, if the click landed on
 * the "add preset" control.
 *
 * @param {Element} root  The field wrapper.
 * @param {Event}   event The delegated click event.
 * @return {void}
 */
function handleAddPresetRowClick( root, event ) {
	if ( ! event.target.closest( '[data-bl-occasions-add-preset]' ) ) {
		return;
	}

	const select = root.querySelector( '[data-bl-occasions-preset-select]' );
	const chosen = select ? select.options[ select.selectedIndex ] : null;
	const preset =
		chosen && chosen.dataset.blOccasionPreset
			? JSON.parse( chosen.dataset.blOccasionPreset )
			: null;

	if ( preset ) {
		addRow( root, preset );
	}
}

function initOccasionsField( root ) {
	const data = window.blOccasionsData || DEFAULT_OCCASIONS_DATA;

	root.querySelectorAll( '[data-bl-occasion-row]' ).forEach( ( row ) => {
		updateRow( row, data.inkHex, data.threshold );
	} );

	root.addEventListener( 'input', ( event ) => {
		const row = event.target.closest( '[data-bl-occasion-row]' );

		if ( row && event.target.matches( '[data-bl-occasion-accent]' ) ) {
			updateRow( row, data.inkHex, data.threshold );
		}
	} );

	root.addEventListener( 'change', ( event ) => {
		const colorInput = event.target.closest( '[data-bl-occasion-color]' );

		if ( ! colorInput ) {
			return;
		}

		const row = colorInput.closest( '[data-bl-occasion-row]' );
		const hexInput = row
			? row.querySelector( '[data-bl-occasion-accent]' )
			: null;

		if ( row && hexInput ) {
			hexInput.value = colorInput.value;
			updateRow( row, data.inkHex, data.threshold );
		}
	} );

	// Three unrelated actions share this one delegated listener; each
	// handler is a no-op unless the click actually landed on its own
	// control, so trying all three in sequence is safe.
	root.addEventListener( 'click', ( event ) => {
		handleRemoveRowClick( root, event );
		handleAddBlankRowClick( root, event );
		handleAddPresetRowClick( root, event );
	} );
}

function init() {
	document.querySelectorAll( ROOT ).forEach( initOccasionsField );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = {
		blOccasionLuminance,
		blOccasionContrastRatio,
		blOccasionIsHex,
		blOccasionApplyRowKey,
	};
}
