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
 * Drop plugin assets on requests where their feature cannot render. Most of
 * these load in <head> and block the first paint, so each one kept only where
 * it is used is time back on every other page.
 *
 * Deliberately left alone: SportsPress's general and icon stylesheets
 * (assets/src/css/sportspress.css builds on them), its sponsors stylesheet
 * (the footer sponsor band), and WooCommerce order attribution (must run on
 * landing pages to attribute orders).
 *
 * @return void
 */
function blueline_dequeue_unused_plugin_assets(): void {
	if ( is_admin() ) {
		return;
	}

	$drop = blueline_unused_plugin_asset_handles( blueline_plugin_asset_context() );

	foreach ( $drop['scripts'] as $handle ) {
		wp_dequeue_script( $handle );
	}

	foreach ( $drop['styles'] as $handle ) {
		wp_dequeue_style( $handle );
	}
}

/**
 * What the current request renders, as far as the plugin assets in
 * blueline_unused_plugin_asset_handles() care.
 *
 * @return array<string, bool>
 */
function blueline_plugin_asset_context(): array {
	$is_cart     = function_exists( 'is_cart' ) && is_cart();
	$is_checkout = function_exists( 'is_checkout' ) && is_checkout();
	$is_account  = function_exists( 'is_account_page' ) && is_account_page();

	return array(
		'cf7'                 => blueline_request_needs_cf7(),
		'facebook_widget'     => (bool) is_active_widget( false, false, 'sportspress-facebook', true ),
		'fue'                 => $is_account || $is_checkout || blueline_request_is_fue_endpoint()
			|| blueline_request_content_contains( array( '[fue_subscribe', '[fue_followup_subscriptions', '[woocommerce_followup_optout' ) ),
		'sp_event'            => is_singular( 'sp_event' )
			|| blueline_request_content_contains( array( '[event_full', '[event_statistics' ) ),
		'sp_matrix'           => is_singular( 'sp_calendar' ) || blueline_request_content_contains( array( '[event_matrix' ) ),
		'sp_scoreboard'       => (bool) get_option( 'sportspress_scoreboard_limit', 0 )
			|| (bool) is_active_widget( false, false, 'sportspress-event-scoreboard', true )
			|| blueline_request_content_contains( array( '[event_scoreboard' ) ),
		'sp_event_map'        => is_singular( 'sp_event' ) && blueline_venue_map_is_click_to_load(),
		'quotes_widget'       => blueline_quotes_llama_widget_rendered(),
		'logged_in'           => is_user_logged_in(),
		'payment_page'        => $is_cart || $is_checkout
			|| ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ),
		'keep_payment_assets' => (bool) apply_filters( 'blueline_keep_payment_scripts', false ),
	);
}

/**
 * The plugin script and style handles a request with this context does not
 * need. Pure, so the rules can be tested without WordPress.
 *
 * - Contact Form 7 (+ Conditional Fields): only pages/widgets carrying a form
 *   shortcode or block keep it; account pages keep it too, since plugins
 *   render forms there outside post content.
 * - SportsPress Facebook SDK (pulls connect.facebook.net): only the
 *   SportsPress Facebook widget needs it.
 * - Follow-Up Emails: its subscription form script and message styles serve
 *   My Account, checkout's opt-in, its own unsubscribe/preferences endpoints
 *   and its shortcodes, nothing else.
 * - SportsPress module stylesheets: the league menu never renders (its
 *   options are blanked on the front end, inc/sportspress/nav-options.php);
 *   the results matrix only renders on a calendar or its shortcode; match
 *   statistics and user scores only on an event; the scoreboard stylesheet
 *   (which also styles the theme's own `.sp-scoreboard` event hero) only on
 *   an event or when SportsPress's own scoreboard is in use.
 * - Leaflet: on a single event the venue map is click-to-load and fetches
 *   Leaflet itself (blueline_render_venue_map_placeholder()); the venue page
 *   keeps the live map.
 * - Quotes Llama's base stylesheet: only where its widget actually rendered.
 * - Dashicons: the admin bar needs it for signed-in visitors; every glyph a
 *   logged-out visitor can see is drawn by the theme instead.
 * - PayPal / Google Pay / Apple Pay scripts (which pull in the three payment
 *   SDKs): only cart, checkout (which includes the order-pay page) and My
 *   Account's add-payment-method page take a payment. The
 *   `blueline_keep_payment_scripts` filter restores them everywhere. The
 *   plugin's small stylesheet stays: it also sets the product page's
 *   add-to-cart form spacing.
 *
 * @param array<string, bool> $context From blueline_plugin_asset_context().
 * @return array{scripts: string[], styles: string[]}
 */
function blueline_unused_plugin_asset_handles( array $context ): array {
	$on      = static function ( string $key ) use ( $context ): bool {
		return ! empty( $context[ $key ] );
	};
	$scripts = array();
	$styles  = array();

	if ( ! $on( 'cf7' ) ) {
		array_push( $scripts, 'contact-form-7', 'swv', 'wpcf7cf-scripts' );
		array_push( $styles, 'contact-form-7', 'cf7cf-style' );
	}

	if ( ! $on( 'facebook_widget' ) ) {
		$scripts[] = 'sportspress-facebook-sdk';
	}

	if ( ! $on( 'fue' ) ) {
		$scripts[] = 'fue-account-subscriptions';
		$styles[]  = 'follow-up-emails';
	}

	$styles[] = 'sportspress-league-menu';

	if ( ! $on( 'sp_matrix' ) ) {
		$styles[] = 'sportspress-results-matrix';
	}

	if ( ! $on( 'sp_event' ) ) {
		array_push( $styles, 'sportspress-event-statistics', 'sportspress-user-scores' );
	}

	if ( ! $on( 'sp_event' ) && ! $on( 'sp_scoreboard' ) ) {
		array_push( $styles, 'sportspress-scoreboard', 'sportspress-scoreboard-ltr' );
	}

	if ( $on( 'sp_event_map' ) ) {
		$scripts[] = 'leaflet_js';
		$styles[]  = 'leaflet_stylesheet';
	}

	if ( ! $on( 'quotes_widget' ) ) {
		$styles[] = 'quotes-llama-css-style';
	}

	if ( ! $on( 'logged_in' ) ) {
		$styles[] = 'dashicons';
	}

	if ( ! $on( 'payment_page' ) && ! $on( 'keep_payment_assets' ) ) {
		array_push(
			$scripts,
			'angelleye_ppcp-common-functions',
			'angelleye-paypal-checkout-sdk',
			'angelleye_ppcp-apple-pay',
			'angelleye_ppcp-google-pay',
			'angelleye_ppcp',
			'angelleye-pay-later-messaging'
		);
	}

	return array(
		'scripts' => $scripts,
		'styles'  => $styles,
	);
}

/**
 * Whether this request is one of Follow-Up Emails' own endpoints
 * (unsubscribe, email subscriptions, email preferences), which it serves on
 * any URL from its query vars, outside My Account.
 *
 * @return bool
 */
function blueline_request_is_fue_endpoint(): bool {
	global $wp;

	if ( ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
		return false;
	}

	$subscriptions = (string) get_option( 'fue_email_subscriptions_endpoint', 'email-subscriptions' );
	$endpoints     = array(
		(string) get_option( 'fue_unsubscribe_endpoint', 'unsubscribe' ),
		'email-unsubscribe',
		$subscriptions,
		'my-account/' . $subscriptions,
		(string) get_option( 'fue_email_preferences_endpoint', 'email-preferences' ),
	);

	foreach ( $endpoints as $endpoint ) {
		if ( '' !== $endpoint && isset( $wp->query_vars[ $endpoint ] ) ) {
			return true;
		}
	}

	return false;
}

add_action( 'dynamic_sidebar', 'blueline_note_quotes_llama_widget' );
/**
 * Quotes Llama enqueues its base stylesheet on every page; keep it only where
 * its widget renders. A widget rendered before the head prints (the theme
 * renders the sidebar early to know whether the page has one) is seen by the
 * dequeue pass and kept in <head>; one rendered later is enqueued here and
 * WordPress prints it with the late styles.
 *
 * @param array $widget The registered widget about to render.
 * @return void
 */
function blueline_note_quotes_llama_widget( $widget ): void {
	$callback = is_array( $widget ) ? ( $widget['callback'][0] ?? null ) : null;

	if ( ! is_object( $callback ) || 'widgetquotesllama' !== ( $callback->id_base ?? '' ) ) {
		return;
	}

	blueline_quotes_llama_widget_rendered( true );
	wp_enqueue_style( 'quotes-llama-css-style' );
}

/**
 * Whether a Quotes Llama widget has rendered on this request.
 *
 * @param bool $mark Pass true to record that one has.
 * @return bool
 */
function blueline_quotes_llama_widget_rendered( bool $mark = false ): bool {
	static $rendered = false;

	if ( $mark ) {
		$rendered = true;
	}

	return $rendered;
}

add_action( 'wp_print_scripts', 'blueline_move_jquery_to_footer', 1000 );
/**
 * Move jQuery out of <head>, where it blocks the first paint, whenever no
 * blocking head script needs it.
 *
 * Deferred and async head scripts run after the document is parsed wherever
 * they sit, so they move to the footer group with it; WordPress then pulls
 * jQuery (and anything else) back into <head> on its own if a blocking head
 * script still depends on it, so a page that genuinely needs it early keeps
 * it there. blueline_print_footer_jquery() prints it first thing in the
 * footer, ahead of plugins' inline wp_footer scripts that call jQuery()
 * directly. Filter `blueline_jquery_in_footer` to false to keep it in <head>.
 *
 * @return void
 */
function blueline_move_jquery_to_footer(): void {
	// Theme templates only: wp-login.php and embeds print their head scripts
	// without wp_head() and never call wp_footer().
	if ( is_admin() || ! did_action( 'wp_head' ) || ! apply_filters( 'blueline_jquery_in_footer', true ) ) {
		return;
	}

	$scripts = wp_scripts();

	foreach ( blueline_script_dependency_closure( $scripts->registered, $scripts->queue ) as $handle ) {
		if ( in_array( $scripts->get_data( $handle, 'strategy' ), array( 'defer', 'async' ), true )
			&& ! $scripts->get_data( $handle, 'group' ) ) {
			$scripts->add_data( $handle, 'group', 1 );
		}
	}

	foreach ( array( 'jquery', 'jquery-core', 'jquery-migrate' ) as $handle ) {
		$scripts->add_data( $handle, 'group', 1 );
	}
}

/**
 * Every handle in $queue plus everything they depend on, recursively.
 *
 * @param array<string, object> $registered Handle => object with a `deps` array (_WP_Dependency).
 * @param string[]              $queue      Enqueued handles.
 * @return string[]
 */
function blueline_script_dependency_closure( array $registered, array $queue ): array {
	$seen  = array();
	$stack = $queue;

	while ( $stack ) {
		$handle = array_pop( $stack );

		if ( isset( $seen[ $handle ] ) || ! isset( $registered[ $handle ] ) ) {
			continue;
		}

		$seen[ $handle ] = true;

		foreach ( (array) $registered[ $handle ]->deps as $dep ) {
			$stack[] = $dep;
		}
	}

	return array_keys( $seen );
}

add_action( 'wp_footer', 'blueline_print_footer_jquery', 1 );
/**
 * Print jQuery at the very start of the footer when it was moved out of
 * <head>: SportsPress's header-sponsor relocation is an inline
 * `jQuery( document ).ready(...)` printed on wp_footer (priority 10), before
 * WordPress prints the footer scripts at priority 20.
 *
 * @return void
 */
function blueline_print_footer_jquery(): void {
	if ( is_admin() ) {
		return;
	}

	$scripts = wp_scripts();

	if ( ! $scripts->query( 'jquery', 'enqueued' ) || $scripts->query( 'jquery', 'done' ) ) {
		return;
	}

	$scripts->do_items( array( 'jquery' ) );
}

add_action( 'init', 'blueline_disable_emoji_detection' );
/**
 * Skip WordPress's emoji detection script and its image styles on the front
 * end: every browser this site supports draws emoji itself, and the
 * fallback swapped some for unsized images from s.w.org.
 *
 * @return void
 */
function blueline_disable_emoji_detection(): void {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
}

add_action( 'wp', 'blueline_unhook_plugin_theme_color' );
/**
 * The theme prints the one `theme-color` meta tag (header.php), from the
 * header's own brand colour. The Browser Theme Color plugin prints a second,
 * fixed one; browsers use the first, so the plugin's setting did nothing but
 * confuse. Its tags are dropped here rather than leaving two answers.
 *
 * @return void
 */
function blueline_unhook_plugin_theme_color(): void {
	global $wp_filter;

	if ( is_admin() || ! isset( $wp_filter['wp_head'] ) ) {
		return;
	}

	foreach ( blueline_hooked_methods( $wp_filter['wp_head']->callbacks, 'Browser_Theme_Color', 'output_theme_color_meta' ) as $hooked ) {
		remove_action( 'wp_head', $hooked['callback'], $hooked['priority'] );
	}
}

/**
 * The callbacks in a hook's priority => callbacks table that are $method on
 * an instance of $class_name: how to unhook a plugin that never exposes its
 * instance.
 *
 * @param array  $callbacks  WP_Hook::$callbacks shape: priority => id => array( 'function' => callable ).
 * @param string $class_name Class the callback object must be an instance of.
 * @param string $method     Method name.
 * @return array<int, array{callback: array, priority: int}>
 */
function blueline_hooked_methods( array $callbacks, string $class_name, string $method ): array {
	$found = array();

	foreach ( $callbacks as $priority => $entries ) {
		foreach ( (array) $entries as $entry ) {
			$function = $entry['function'] ?? null;

			if ( is_array( $function ) && isset( $function[0], $function[1] )
				&& $function[0] instanceof $class_name && $method === $function[1] ) {
				$found[] = array(
					'callback' => $function,
					'priority' => (int) $priority,
				);
			}
		}
	}

	return $found;
}

add_filter( 'get_custom_logo_image_attributes', 'blueline_custom_logo_fetch_priority' );
/**
 * Stop the header logo claiming `fetchpriority="high"`. WordPress gives it to
 * the first large image it sees, which is always the logo, and the logo is
 * never the page's largest paint; without this the page's real hero image
 * cannot get it.
 *
 * @param array $attr Custom logo <img> attributes.
 * @return array
 */
function blueline_custom_logo_fetch_priority( $attr ) {
	$attr                  = (array) $attr;
	$attr['fetchpriority'] = false;

	return $attr;
}

/**
 * `<img>` attributes for the first, above-the-fold image of a singular
 * template (featured image, team crest): fetched first and never lazy.
 *
 * @param string $sizes The `sizes` value matching the image's CSS box.
 * @return array<string, string>
 */
function blueline_hero_image_attributes( string $sizes ): array {
	return array(
		'sizes'         => $sizes,
		'fetchpriority' => 'high',
		'loading'       => 'eager',
	);
}

/**
 * Attributes for a page's or post's featured image (content-page.php,
 * content-single.php), which spans the content column: the viewport less
 * .bl-container's 24px gutters, up to the 1200px container. WordPress's
 * default assumed the full viewport, so phones fetched the 1024px file.
 *
 * @return array<string, string>
 */
function blueline_entry_thumbnail_attributes(): array {
	return blueline_hero_image_attributes( '(max-width: 1200px) calc(100vw - 48px), 1152px' );
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
