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
	$path  = BLUELINE_DIR . '/style.css';
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
		BLUELINE_URI . '/style.css', // The parent's tokens, even under a child theme.
		array(),
		blueline_stylesheet_version()
	);

	wp_enqueue_style(
		'blueline',
		BLUELINE_URI . '/assets/dist/index.css',
		array( 'blueline-tokens' ),
		blueline_dist_version( 'index' )
	);
	// wp-scripts' build already emits assets/dist/index-rtl.css (kept in
	// lockstep with index.css by the same build step); without this call
	// WordPress never knows that file exists, so an RTL locale would
	// silently get the LTR stylesheet with none of its logical-property
	// fixes. This is the one line core actually requires to enable the
	// swap -- see wp_style_add_data()'s 'rtl' key in wp-includes/functions.wp-styles.php.
	wp_style_add_data( 'blueline', 'rtl', 'replace' );

	foreach ( blueline_template_style_bundles() as $bundle ) {
		wp_enqueue_style(
			'blueline-' . $bundle,
			BLUELINE_URI . '/assets/dist/' . $bundle . '.css',
			array( 'blueline' ),
			blueline_dist_version( $bundle )
		);
		wp_style_add_data( 'blueline-' . $bundle, 'rtl', 'replace' );
	}

	wp_enqueue_script(
		'blueline',
		BLUELINE_URI . '/assets/dist/index.js',
		// jquery: assets/src/js/sponsors.js listens for jQuery's own global
		// ajaxComplete event to know when SportsPress's sponsor-loader AJAX
		// call has settled. `defer` already guarantees this script runs
		// after any classic (non-deferred) script -- which is how
		// SportsPress enqueues jQuery -- so this dependency is declared for
		// correctness rather than to fix an observed ordering bug.
		array( 'jquery' ),
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

/**
 * PERF-10: the per-template stylesheets (webpack entries split out of
 * index.css) this request needs, in their former import order so the cascade
 * between them is unchanged. Each gate mirrors the only code that can emit
 * that partial's selectors:
 *
 * - occasions: blueline_render_occasion_effects() prints nothing without an
 *   active occasion.
 * - homepage: template-homepage.php's hero/modules.
 * - woocommerce: WooCommerce templates/shortcodes/blocks, the same gate that
 *   already drops WooCommerce's own CSS (blueline_is_commerce_request()).
 * - forms: Contact Form 7 markup, the same gate as CF7's own assets.
 * - account: My Account endpoints (body.woocommerce-account).
 *
 * @return string[] Bundle basenames under assets/dist/.
 */
function blueline_template_style_bundles(): array {
	$gates = array(
		'occasions'   => null !== blueline_resolve_active_occasion(),
		'homepage'    => is_front_page() || is_page_template( 'template-homepage.php' ),
		'woocommerce' => function_exists( 'blueline_is_commerce_request' ) && blueline_is_commerce_request(),
		'forms'       => blueline_request_needs_cf7(),
		'account'     => function_exists( 'is_account_page' ) && is_account_page(),
	);

	return array_keys( array_filter( $gates ) );
}

/**
 * Whether this request can render a Contact Form 7 form: a form shortcode or
 * block in the queried post or an active widget, or an account page (plugins
 * render forms there outside post content).
 *
 * @return bool
 */
function blueline_request_needs_cf7(): bool {
	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		return true;
	}

	return blueline_request_content_contains( array( '[contact-form-7', '[contact-form ', 'wp:contact-form-7/' ) );
}

/**
 * Whether the queried post's content, or any active text/block/HTML widget,
 * mentions one of $needles (shortcode openers or block names). Used to keep a
 * plugin's assets only where its shortcode or block can actually render.
 *
 * @param string[] $needles Literal substrings, e.g. '[contact-form-7'.
 * @return bool
 */
function blueline_request_content_contains( array $needles ): bool {
	$haystacks = array();
	$queried   = get_queried_object();

	if ( $queried instanceof WP_Post ) {
		$haystacks[] = (string) $queried->post_content;
	}

	foreach ( blueline_active_widget_contents() as $content ) {
		$haystacks[] = $content;
	}

	foreach ( $haystacks as $haystack ) {
		foreach ( $needles as $needle ) {
			if ( '' !== $haystack && false !== stripos( $haystack, $needle ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * The stored content of every active block, text and custom HTML widget.
 *
 * @return string[]
 */
function blueline_active_widget_contents(): array {
	static $contents = null;

	if ( null !== $contents ) {
		return $contents;
	}

	$contents = array();
	$types    = array(
		'block'       => 'content',
		'text'        => 'text',
		'custom_html' => 'content',
	);

	foreach ( wp_get_sidebars_widgets() as $sidebar => $widget_ids ) {
		if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $widget_ids ) ) {
			continue;
		}

		foreach ( $widget_ids as $widget_id ) {
			if ( ! preg_match( '/^(block|text|custom_html)-(\d+)$/', (string) $widget_id, $m ) ) {
				continue;
			}

			$instances = get_option( 'widget_' . $m[1] );
			$field     = $types[ $m[1] ];

			if ( isset( $instances[ (int) $m[2] ][ $field ] ) && is_string( $instances[ (int) $m[2] ][ $field ] ) ) {
				$contents[] = $instances[ (int) $m[2] ][ $field ];
			}
		}
	}

	return $contents;
}

add_action( 'wp_enqueue_scripts', 'blueline_dequeue_unused_plugin_assets', 1000 );
add_action( 'wp_print_footer_scripts', 'blueline_dequeue_unused_plugin_assets', 1 );
/**
 * PERF-05: drop plugin assets on requests where their feature cannot render.
 *
 * - Contact Form 7 (+ Conditional Fields): only pages/widgets carrying a form
 *   shortcode or block keep it; account pages keep it too, since plugins
 *   render forms there outside post content.
 * - SportsPress Facebook SDK (pulls connect.facebook.net): only needed by the
 *   SportsPress Facebook widget, so it stays only while one is active.
 *
 * Deliberately left alone: dashicons (sportspress/event-list.php's video/
 * camera icons and the Quotes Llama widget use it logged-out), SportsPress's
 * own stylesheets (assets/src/css/sportspress.css builds on them), and
 * WooCommerce order attribution (must run on landing pages to attribute orders).
 *
 * @return void
 */
function blueline_dequeue_unused_plugin_assets(): void {
	if ( is_admin() ) {
		return;
	}

	if ( ! blueline_request_needs_cf7() ) {
		foreach ( array( 'contact-form-7', 'swv', 'wpcf7cf-scripts' ) as $handle ) {
			wp_dequeue_script( $handle );
		}

		foreach ( array( 'contact-form-7', 'cf7cf-style' ) as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	if ( ! is_active_widget( false, false, 'sportspress-facebook', true ) ) {
		wp_dequeue_script( 'sportspress-facebook-sdk' );
	}
}

add_action( 'wp_head', 'blueline_preload_fonts', 1 );
/**
 * Preload the self-hosted webfonts used above the fold on every page: the
 * Inter variable font (body copy, all weights), the Barlow Condensed 800
 * italic static instance (h1-h4, the design's signature heavy-italic caps)
 * and 700 italic (the primary nav -- PERF-08: arriving late, it re-wrapped
 * the nav and shifted the page). Preloading more than this defeats the
 * purpose of preloading.
 */
function blueline_preload_fonts() {
	$fonts = array(
		'inter-variable.woff2',
		'barlow-condensed-800italic.woff2',
		'barlow-condensed-700italic.woff2',
	);

	foreach ( $fonts as $font ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( BLUELINE_URI . '/assets/dist/fonts/' . $font )
		);
	}
}

/*
 * Editor preview styling is intentionally NOT wired up via
 * enqueue_block_editor_assets()/wp_enqueue_style() here. That hook prints
 * into the actual wp-admin document (the Post/Page edit screen chrome),
 * not just the editor canvas iframe -- so a stylesheet enqueued that way
 * stays live and unscoped in wp-admin, which previously leaked this
 * theme's bare `body`/`a`/`h1-h4` rules onto the edit-screen UI itself.
 * It also can't reliably deliver style.css's --bl-* tokens into the
 * canvas iframe: WordPress only clones an admin-enqueued stylesheet into
 * that iframe when its rules contain a .wp-block or .editor-styles-wrapper
 * selector (see getCompatibilityStyles() in the block-editor package),
 * and a pure `:root { --bl-*: ... }` file matches neither.
 *
 * assets/dist/editor.css is instead registered via
 * add_theme_support('editor-styles') + add_editor_style() in
 * inc/setup.php -- the supported mechanism, which reads the file
 * server-side and injects it only inside the canvas iframe's
 * .editor-styles-wrapper.
 */
