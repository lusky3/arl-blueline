<?php
/**
 * Asset enqueueing: theme CSS/JS, self-hosted font preloads, editor assets.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'blueline_enqueue_assets' );
/**
 * Enqueue the theme's built CSS/JS and comment-reply where needed.
 */
function blueline_enqueue_assets() {
	// style.css is the theme's only source of the :root --bl-* custom
	// properties (tokens); WordPress never loads it automatically just
	// because it's the theme stylesheet header file, so it must be
	// enqueued explicitly or every var(--bl-*) in assets/dist/index.css
	// resolves to nothing.
	wp_enqueue_style(
		'blueline-tokens',
		get_stylesheet_uri(),
		array(),
		BLUELINE_VERSION
	);

	wp_enqueue_style(
		'blueline',
		BLUELINE_URI . '/assets/dist/index.css',
		array( 'blueline-tokens' ),
		BLUELINE_VERSION
	);

	wp_enqueue_script(
		'blueline',
		BLUELINE_URI . '/assets/dist/index.js',
		array(),
		BLUELINE_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}

add_action( 'wp_head', 'blueline_preload_fonts', 1 );
/**
 * Preload the two self-hosted webfonts used on every page: the Inter
 * variable font (body copy, all weights) and the Barlow Condensed 800
 * italic static instance (h1-h4, the design's signature heavy-italic caps).
 * Preloading more than this defeats the purpose of preloading.
 */
function blueline_preload_fonts() {
	$fonts = array(
		'inter-variable.woff2',
		'barlow-condensed-800italic.woff2',
	);

	foreach ( $fonts as $font ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( BLUELINE_URI . '/assets/dist/fonts/' . $font )
		);
	}
}

add_action( 'enqueue_block_editor_assets', 'blueline_enqueue_editor_assets' );
/**
 * Enqueue editor-only styles so front-end visitors never load them.
 */
function blueline_enqueue_editor_assets() {
	wp_enqueue_style(
		'blueline-editor',
		BLUELINE_URI . '/assets/dist/editor.css',
		array(),
		BLUELINE_VERSION
	);
}
