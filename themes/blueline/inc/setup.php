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

add_filter( 'show_admin_bar', 'blueline_hide_admin_bar_for_players' );
/**
 * Hide the WordPress/SportsPress admin toolbar for anyone without
 * `manage_options` -- the same capability every admin surface this theme
 * ships (inc/settings/page.php's own settings page, its AJAX/POST
 * handlers, etc.) is already gated behind.
 *
 * Live-site review: a plain player-role account signed in to /account saw
 * the full wp-admin toolbar, including a direct "ARL Settings" link
 * (/wp-admin/admin.php?page=sportspress) and an "Admin Notices" item --
 * neither of which a player can do anything useful with, and both of which
 * make the account look more privileged than it is (and, for the settings
 * link, invite a curious click into an admin screen that will simply
 * refuse them once inc/settings/page.php's own `current_user_can(
 * 'manage_options' )` check runs). Removing the toolbar link is a UI fix,
 * not the security boundary -- that boundary is (and must remain) the
 * server-side capability check on the settings page/handlers themselves,
 * which this filter does not touch and does not need to: a hidden link to
 * a still-guarded page is simply tidier, not less safe than a visible one.
 *
 * Registered directly at file scope, the same way this file's other
 * WordPress-core hooks are (e.g. the widgets_init registration below):
 * `show_admin_bar()` core itself exposes is just a thin wrapper around
 * `add_filter( 'show_admin_bar', ... )`, and the actual current_user_can()
 * check inside this callback only runs later, when core applies the filter
 * to decide whether to render the bar (well after the current user is
 * resolved) -- registering it early costs nothing.
 *
 * @param bool $show Core's own default answer.
 * @return bool
 */
function blueline_hide_admin_bar_for_players( $show ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	return $show;
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
