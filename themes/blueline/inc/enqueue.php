<?php
/**
 * Asset enqueueing: theme CSS/JS, self-hosted font preloads, editor assets.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cache-busting version for a webpack-built asset, read from the
 * `{$handle}.asset.php` file @wordpress/scripts generates next to every
 * build output (assets/dist/index.asset.php, editor.asset.php, ...).
 * Its `version` is a content hash of that build, produced automatically
 * by DependencyExtractionWebpackPlugin, so it changes on every asset
 * change with nothing for a contributor to remember to bump. Falls back
 * to BLUELINE_VERSION only if the asset.php is missing (an un-built or
 * broken dist/), so a broken build degrades rather than silently reusing
 * a stale, unrelated hash.
 *
 * @param string $handle Asset basename without extension, e.g. 'index'.
 * @return string
 */
function blueline_dist_version( $handle ) {
	$asset_file = BLUELINE_DIR . '/assets/dist/' . $handle . '.asset.php';

	if ( file_exists( $asset_file ) ) {
		$asset = include $asset_file;
		if ( is_array( $asset ) && ! empty( $asset['version'] ) ) {
			return (string) $asset['version'];
		}
	}

	return BLUELINE_VERSION;
}

/**
 * Cache-busting version for style.css: it's hand-edited outside the
 * webpack build (it's the theme's only source of the :root --bl-* design
 * tokens — see blueline_enqueue_assets() below), so it has no *.asset.php
 * companion and needs its own mechanism. filemtime() changes automatically
 * whenever the file is saved and, unlike a hand-maintained constant,
 * cannot be forgotten. scripts/deploy-theme.sh ships it via `rsync -a`,
 * which preserves source mtimes (verified empirically — see the Task 4
 * fix report), so the mtime read here is the real last-edit time, not a
 * deploy-time artifact, and survives the deploy intact.
 *
 * @return string
 */
function blueline_stylesheet_version() {
	$path  = get_stylesheet_directory() . '/style.css';
	$mtime = file_exists( $path ) ? filemtime( $path ) : false;

	return $mtime ? (string) $mtime : BLUELINE_VERSION;
}

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
		blueline_stylesheet_version()
	);

	wp_enqueue_style(
		'blueline',
		BLUELINE_URI . '/assets/dist/index.css',
		array( 'blueline-tokens' ),
		blueline_dist_version( 'index' )
	);

	wp_enqueue_script(
		'blueline',
		BLUELINE_URI . '/assets/dist/index.js',
		array(),
		blueline_dist_version( 'index' ),
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
 *
 * The editor canvas is a separate iframe document, so it does not inherit
 * the --bl-* custom properties defined in style.css's :root just because
 * they're loaded on the surrounding wp-admin page -- the same class of gap
 * Task 4 found and fixed for the front end (style.css was never enqueued
 * there either). Block editor mirrors stylesheets enqueued on this hook
 * into its canvas iframe, so enqueueing the tokens here, as a dependency
 * of editor.css, gets both into the iframe together.
 */
function blueline_enqueue_editor_assets() {
	wp_enqueue_style(
		'blueline-tokens',
		get_stylesheet_uri(),
		array(),
		blueline_stylesheet_version()
	);

	wp_enqueue_style(
		'blueline-editor',
		BLUELINE_URI . '/assets/dist/editor.css',
		array( 'blueline-tokens' ),
		blueline_dist_version( 'editor' )
	);
}
