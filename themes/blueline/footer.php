<?php
/**
 * The footer markup, closing out #page opened in header.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

blueline_site_footer();
?>
</div><!-- #page -->
<?php
/*
 * Persistent overlay chrome, not in-flow content -- so it lives here,
 * right before wp_footer(), rather than in header.php alongside
 * blueline_render_announcement() (this theme's only other sitewide
 * element, but an in-flow one for its own, unrelated reasons). Prints
 * nothing unless a signed-in, claimed visitor has a real upcoming game
 * and the floating_next_game section toggle is on
 * (blueline_render_floating_next_game()'s own guard).
 */
blueline_render_floating_next_game();
wp_footer();
?>
</body>
</html>
