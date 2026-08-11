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
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );

	// Required so the header can call has_custom_logo() / the_custom_logo() (Task 4).
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 80,
			'width'       => 240,
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
