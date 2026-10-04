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

/*
 * Same "persistent overlay chrome, not in-flow content" placement as the
 * renderer above (the team flyout moved to header.php for tab order) -- prints nothing unless blueline_resolve_active_
 * occasion() (inc/occasions.php) currently resolves one AND its motif has
 * an effect defined (blueline_render_occasion_effects()'s own guard).
 */
blueline_render_occasion_effects();
wp_footer();
?>
</body>
</html>
