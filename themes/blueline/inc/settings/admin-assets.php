<?php
/**
 * Appearance -> Blueline admin assets: the per-tab enqueue callbacks and their
 * inline CSS. Loaded last by inc/settings/page.php, so these
 * admin_enqueue_scripts callbacks keep registering after its focus script.
 * "This file's own" constraints below are inc/settings/page.php's docblock.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue one of the admin panel's plain source scripts from assets/src/js/,
 * versioned by its filemtime().
 *
 * @param string   $handle Script handle.
 * @param string   $file   File name under assets/src/js/.
 * @param string[] $deps   Script dependencies.
 * @return void
 */
function blueline_settings_enqueue_source_script( string $handle, string $file, array $deps = array() ): void {
	$relative = '/assets/src/js/' . $file;
	$path     = BLUELINE_DIR . $relative;

	wp_enqueue_script(
		$handle,
		BLUELINE_URI . $relative,
		$deps,
		file_exists( $path ) ? (string) filemtime( $path ) : '1',
		true
	);
}

add_action( 'admin_enqueue_scripts', 'blueline_settings_maybe_enqueue_photo_picker' );
/**
 * Enqueue the media library and the hero-photograph picker, on this page only.
 *
 * Enqueued as a PLAIN SOURCE FILE, not a webpack entry, which is a deliberate
 * continuation of this file's own "no webpack entry" constraint rather than an
 * oversight: the script has no imports, no JSX and no dependencies beyond
 * wp.media, so a build step would buy nothing but a build step. It is still
 * linted (npm run lint:js covers assets/src/js) and still shipped by the same
 * rsync as everything else.
 *
 * wp_enqueue_media() is what actually makes wp.media exist; without it the
 * picker button renders and does nothing, which is the same graceful state as
 * having no JavaScript at all.
 *
 * Versioned by the source file's filemtime() (see
 * blueline_settings_enqueue_source_script()), so a changed picker busts its
 * own cache.
 *
 * @param string $hook_suffix The current admin screen's hook suffix.
 * @return void
 */
function blueline_settings_maybe_enqueue_photo_picker( string $hook_suffix ): void {
	if ( blueline_settings_page_hook() !== $hook_suffix ) {
		return;
	}

	// Only the Appearance tab has a photograph field; loading the whole media
	// library on the Content tab would be a large download for nothing.
	if ( 'appearance' !== blueline_settings_current_tab() ) {
		return;
	}

	wp_enqueue_media();

	blueline_settings_enqueue_source_script( 'blueline-settings-photos', 'settings-photos.js', array( 'media-editor' ) );

	wp_add_inline_style( 'wp-admin', blueline_settings_photo_picker_styles() );
}

add_action( 'admin_enqueue_scripts', 'blueline_settings_maybe_enqueue_brand_colors' );
/**
 * Enqueue the brand-colors live-contrast script, only on the Appearance
 * tab — same scoping reasoning as
 * blueline_settings_maybe_enqueue_photo_picker() (this file), which
 * enqueues wp_enqueue_media() only there for the same reason.
 *
 * @param string $hook_suffix The current admin screen's hook suffix.
 * @return void
 */
function blueline_settings_maybe_enqueue_brand_colors( string $hook_suffix ): void {
	if ( blueline_settings_page_hook() !== $hook_suffix ) {
		return;
	}

	if ( 'appearance' !== blueline_settings_current_tab() ) {
		return;
	}

	blueline_settings_enqueue_source_script( 'blueline-settings-brand-colors', 'settings-brand-colors.js' );

	wp_add_inline_script(
		'blueline-settings-brand-colors',
		'window.blSettingsBrandColorRules = ' . wp_json_encode( blueline_brand_color_js_rules() ) . ';',
		'before'
	);

	wp_add_inline_style( 'wp-admin', blueline_settings_brand_colors_contrast_styles() );
}

/**
 * Real, visually-distinct styling for the `.bl-contrast-pass`/
 * `.bl-contrast-fail` classes blueline_settings_render_color_field() (this
 * file) and settings-brand-colors.js's `updateReadout()` both apply to
 * each contrast rule's readout line. Before this, neither class had any
 * CSS rule anywhere in the theme, so a passing and a failing rule rendered
 * as identical plain grey text inside the surrounding `<p class="description">`
 * -- defeating the entire point of the advisory contrast warning.
 *
 * Small enough to inline, same precedent as
 * blueline_settings_photo_picker_styles() (this file): scoped tightly
 * enough that it cannot reach anything else in wp-admin.
 *
 * @return string
 */
function blueline_settings_brand_colors_contrast_styles(): string {
	return '.bl-contrast-pass{color:#1F7A4D;}'
		. '.bl-contrast-fail{color:#A32C1B;font-weight:600;}';
}

/**
 * The picker's own layout. Small enough to inline, and scoped tightly enough
 * that it cannot reach anything else in wp-admin.
 *
 * @return string
 */
function blueline_settings_photo_picker_styles(): string {
	return '.bl-photos__list{margin:0;padding:0;list-style:none;}'
		. '.bl-photos__item{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid #dcdcde;}'
		. '.bl-photos__thumb{width:60px;height:60px;object-fit:cover;border-radius:3px;background:#f0f0f1;}'
		. '.bl-photos__align{margin-inline-start:auto;}'
		. '.bl-photos__remove{color:#b32d2e;}'
		. '.bl-photos__empty{color:#646970;font-style:italic;}';
}

add_action( 'admin_enqueue_scripts', 'blueline_settings_maybe_enqueue_occasions_script' );
/**
 * Enqueue the Occasions tab's live contrast-readout/repeater script, on
 * this page's Occasions tab only.
 *
 * A plain source file, no webpack entry, the same deliberate choice
 * blueline_settings_maybe_enqueue_photo_picker()'s own docblock
 * explains for settings-photos.js: no imports, no JSX, no dependencies.
 * Still linted (npm run lint:js) and still shipped by the same rsync
 * as everything else.
 *
 * blueline_settings_inputs_hash()-adjacent values (BLUELINE_TOKEN_INK
 * and blueline_contrast_threshold( 'body' )) are read here,
 * server-side, and handed to the script as inline JSON:
 * real settings data the JS math needs but must never hardcode
 * independently, which would be a third place these values could
 * drift out of sync from inc/team-colors.php.
 *
 * @param string $hook_suffix The current admin screen's hook suffix.
 * @return void
 */
function blueline_settings_maybe_enqueue_occasions_script( string $hook_suffix ): void {
	if ( blueline_settings_page_hook() !== $hook_suffix ) {
		return;
	}

	if ( 'occasions' !== blueline_settings_current_tab() ) {
		return;
	}

	blueline_settings_enqueue_source_script( 'blueline-settings-occasions', 'settings-occasions.js' );

	// Inline JSON, not wp_localize_script(), so the threshold stays a number.
	wp_add_inline_script(
		'blueline-settings-occasions',
		'window.blOccasionsData = ' . wp_json_encode(
			array(
				'inkHex'    => BLUELINE_TOKEN_INK,
				'threshold' => blueline_contrast_threshold( 'body' ),
			)
		) . ';',
		'before'
	);

	wp_add_inline_style( 'wp-admin', blueline_settings_occasions_styles() );
}

/**
 * The "How Occasions work" disclosure's summary was 18px tall, under the 24px WCAG 2.5.8
 * target minimum. Small enough to inline (same precedent as the photo picker's styles).
 *
 * @return string
 */
function blueline_settings_occasions_styles(): string {
	return '.bl-occasions__help summary{cursor:pointer;min-height:24px;padding-block:4px;box-sizing:border-box;}';
}
