<?php
/**
 * The sidebar containing the "Sidebar" widget area.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_active_sidebar( 'sidebar-1' ) ) {
	return;
}
?>
<aside id="secondary" class="widget-area bl-sidebar" aria-label="<?php esc_attr_e( 'Sidebar', 'blueline' ); ?>">
	<?php dynamic_sidebar( 'sidebar-1' ); ?>
</aside>
