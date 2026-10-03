<?php
/**
 * Theme setup: add_theme_support, nav menus, widget areas.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', 'blueline_setup' );
/**
 * Register theme supports and nav menu locations.
 */
function blueline_setup() {
	load_theme_textdomain( 'blueline', BLUELINE_DIR . '/languages' );

	add_theme_support( 'sportspress' );          // REQUIRED for SP template resolution.
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );

	/*
	 * The size band photography is rendered at, never the uploaded original.
	 *
	 * Without this, the control panel becomes a way to put a multi-megabyte
	 * phone photograph into the hero of the site's most-visited page: the
	 * media library holds originals up to 2560px, and a background-image has
	 * no srcset to save anyone. The theme's own shipped photographs are 720px
	 * WebP for exactly this reason, and an admin-chosen one has no business
	 * being larger.
	 *
	 * Hard-cropped, so every photograph arrives at the same aspect ratio the
	 * band is designed around rather than being letterboxed by background-size:
	 * cover in a way the alignment control then has to fight.
	 */
	add_image_size( 'blueline-band', 960, 540, true );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );

	/*
	 * Required so the header can call has_custom_logo() / the_custom_logo()
	 * (Task 4). `height`/`width` are only the recommended-size hint the
	 * Customizer's media picker shows an admin, not an enforced crop --
	 * flex-height/flex-width (both true) mean WordPress never forces the
	 * uploaded image to this ratio, and header.css's own
	 * `.custom-logo-link img { height: var(--bl-header-logo); width: auto; }`
	 * (fixed height, auto width) already renders whatever aspect ratio the
	 * real uploaded image has, unenforced by this hint either way.
	 *
	 * Reported live: "We use a 1:1 logo" -- the league's real logo asset is
	 * square, not the 3:1 (240x80) this hint originally suggested. Matching
	 * it to a real square size (80x80, the header's own resting
	 * --bl-header-logo height, so the hint and the rendered size agree)
	 * keeps the Customizer's own guidance honest for what admins actually
	 * upload here, even though the rendered header was already correct for
	 * a square image before this change.
	 */
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 80,
			'width'       => 80,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	// The supported mechanism for matching the block editor's preview to the
	// front end: WordPress reads this file's contents server-side and injects
	// them exclusively inside the editor canvas iframe's .editor-styles-wrapper
	// -- never into the surrounding wp-admin document -- regardless of the
	// selectors the CSS happens to use. See assets/src/css/editor.css for why
	// its :root token block also duplicates onto .editor-styles-wrapper.
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/dist/editor.css' );

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'blueline' ),
			'utility' => __( 'Utility Menu (account links, header actions)', 'blueline' ),
			'footer'  => __( 'Footer Menu', 'blueline' ),
		)
	);
}

add_filter( 'get_custom_logo_image_attributes', 'blueline_custom_logo_sizes', 10, 2 );
/**
 * PERF-07: give the header logo a `sizes` matching its rendered box
 * (header.css: height var(--bl-header-logo), 80px, 60px below 1100px; width
 * auto) instead of core's "(max-width: 512px) 100vw, 512px", which made
 * browsers fetch the full 512px source for an 80px mark.
 *
 * @param array $attr          Image attributes.
 * @param int   $attachment_id Logo attachment ID.
 * @return array
 */
function blueline_custom_logo_sizes( $attr, $attachment_id ) {
	$attr  = is_array( $attr ) ? $attr : array();
	$src   = wp_get_attachment_image_src( (int) $attachment_id, 'full' );
	$ratio = ( is_array( $src ) && ! empty( $src[1] ) && ! empty( $src[2] ) ) ? $src[1] / $src[2] : 1;

	$attr['sizes'] = sprintf( '(max-width: 1099.98px) %dpx, %dpx', (int) ceil( 60 * $ratio ), (int) ceil( 80 * $ratio ) );

	return $attr;
}

add_action( 'widgets_init', 'blueline_widgets_init' );
/**
 * Register widget areas. IDs must match the existing rookie-child
 * assignment (sidebar-1, footer-1..footer-4) or live widgets are orphaned.
 */
function blueline_widgets_init() {
	register_sidebar(
		array(
			'name'          => __( 'Sidebar', 'blueline' ),
			'id'            => 'sidebar-1',
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);

	for ( $i = 1; $i <= 4; $i++ ) {
		register_sidebar(
			array(
				/* translators: %d: footer widget area number. */
				'name'          => sprintf( __( 'Footer %d', 'blueline' ), $i ),
				'id'            => 'footer-' . $i,
				'before_widget' => '<section id="%1$s" class="widget %2$s">',
				'after_widget'  => '</section>',
				'before_title'  => '<h2 class="widget-title">',
				'after_title'   => '</h2>',
			)
		);
	}
}

/**
 * How many widgets currently sit in a registered widget area, straight from
 * core's own sidebar/widget assignment store -- not the sidebar's
 * REGISTRATION above (which persists regardless of any section toggle), but
 * what an admin actually put in it. Lives here rather than in
 * inc/settings/sections.php because it knows nothing about sections or
 * toggles, only about the widget store this file owns the registration
 * side of; blueline_section_widget_warning() (inc/settings/sections.php)
 * is this function's only caller, and stays in sections.php since IT is the
 * one that knows which section maps to which area.
 *
 * Calls wp_get_sidebars_widgets() despite core's own docblock marking it
 * `@access private` (wp-includes/widgets.php): there is no public
 * alternative that reports widget ASSIGNMENTS without also rendering them
 * (dynamic_sidebar() prints markup; is_active_sidebar() reports only
 * true/false, never a count). Not independently re-verified against core's
 * own source as part of this change -- flagging that rather than dressing
 * it up as more thoroughly checked than it is.
 *
 * Not special-cased against the `wp_inactive_widgets` bucket
 * wp_get_sidebars_widgets() also returns (core's holding pen for widgets
 * assigned to no sidebar, not a real registered area): $area here is always
 * a real sidebar id, since blueline_section_widget_warning()'s own `$areas`
 * map only ever names one, so nothing currently calls this with
 * `wp_inactive_widgets` to say the wrong thing about.
 *
 * @param string $area A registered sidebar/widget-area id (e.g. 'footer-2').
 * @return int
 */
function blueline_active_widget_count( string $area ): int {
	$sidebars_widgets = wp_get_sidebars_widgets();

	return isset( $sidebars_widgets[ $area ] ) ? count( $sidebars_widgets[ $area ] ) : 0;
}
