<?php
/**
 * The sidebar containing the "Sidebar" widget area.
 *
 * On a Page whose own content has 2+ real section headings, this
 * renders a sticky "on this page" jump-nav instead of (well, alongside
 * -- see below) the plain widget rail; every other page keeps the
 * restyled widget rail as before.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

$blueline_has_jump_nav = function_exists( 'blueline_page_needs_jump_nav' ) && blueline_page_needs_jump_nav();
$blueline_has_widgets  = is_active_sidebar( 'sidebar-1' );

if ( ! $blueline_has_jump_nav && ! $blueline_has_widgets ) {
	return;
}

$blueline_sidebar_class = 'widget-area bl-sidebar' . ( $blueline_has_jump_nav ? ' bl-sidebar--jump-nav' : '' );
?>
<aside id="secondary" class="<?php echo esc_attr( $blueline_sidebar_class ); ?>" aria-label="<?php esc_attr_e( 'Sidebar', 'blueline' ); ?>">
	<?php
	if ( $blueline_has_jump_nav ) {
		blueline_render_page_jump_nav();
	}

	if ( $blueline_has_widgets ) {
		dynamic_sidebar( 'sidebar-1' );
	}
	?>
</aside>
