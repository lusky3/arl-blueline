<?php
/**
 * Occasions: the block-editor filter that carries the resolved accent into
 * the editor canvas, so the editor and the front end agree.
 *
 * Loaded by inc/occasions.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Append occasion CSS to the block editor canvas's own style list, via
 * the one filter core actually clones into `.editor-styles-wrapper`,
 * per design spec §5/§7.9, and see inc/enqueue.php's own docblock
 * (immediately preceding its `add_theme_support( 'editor-styles' )` /
 * `add_editor_style()` pair in inc/setup.php) for why
 * `enqueue_block_editor_assets()` was tried and reverted instead.
 *
 * Emits ONLY `--bl-occasion-accent` (the sole occasion-related custom
 * property that exists), and only when an occasion is actually
 * resolved-active right now. When none is, the token's own CSS default
 * (`var(--bl-ice)`, already present in both style.css and
 * assets/src/css/editor.css) already applies, so there is nothing to
 * override and this filter changes nothing.
 *
 * @param array<string, mixed> $settings Block editor settings.
 * @return array<string, mixed>
 */
function blueline_occasion_editor_styles( array $settings ): array {
	$active = blueline_resolve_active_occasion();

	if ( null === $active ) {
		return $settings;
	}

	$accent = $active['resolved_accent'] ?? '';

	if ( ! preg_match( '/^#[0-9a-f]{6}$/', $accent ) ) {
		// Defensive: never emit anything that is not exactly the validated
		// hex shape the sanitizer/resolver already guarantee; this is
		// the last point before the value reaches raw CSS text.
		return $settings;
	}

	$styles   = isset( $settings['styles'] ) && is_array( $settings['styles'] ) ? $settings['styles'] : array();
	$styles[] = array(
		'css'            => ':root, .editor-styles-wrapper { --bl-occasion-accent: ' . $accent . '; }',
		'__unstableType' => 'theme',
	);

	$settings['styles'] = $styles;

	return $settings;
}
add_filter( 'block_editor_settings_all', 'blueline_occasion_editor_styles' );
