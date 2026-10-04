<?php
/**
 * The header markup, up to and including the site header.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
/*
 * Colours the mobile browser's own UI chrome (status bar / address bar)
 * to match this site's, rather than leaving it at the browser's own
 * default (usually plain white). A single, fixed value -- not one that
 * varies with the light/dark/system content-area toggle -- because the
 * thing theme-color is actually matching is the site's own header/nav
 * bar, and that bar is deliberately fixed navy in every theme per the
 * toggle's own design spec (docs/superpowers/specs/2026-08-22-blueline-
 * theme-toggle-design.md §2); a value that changed with the toggle would
 * desync from the fixed header the instant a reader scrolled to it.
 * #0D1729 is --bl-ink-deep, the header/footer's own background -- see
 * style.css's :root block for that token's own definition.
 */
?>
<meta name="theme-color" content="#0D1729">
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php
/*
 * A guest's own light/dark/system choice lives only in their browser
 * (localStorage) -- this site's anonymous-visitor page cache means the
 * server can never know or render it (see inc/account/theme-preference.php's
 * own docblock). This call MUST stay here, before wp_head(): it is a
 * small, blocking inline script that must run before any enqueued
 * stylesheet prints, or a dark-preferring guest sees a flash of the light
 * theme on every page load. Prints nothing for a logged-in visitor, whose
 * data-theme is already rendered server-side, above, via the
 * language_attributes filter.
 */
blueline_render_guest_theme_bootstrap_script();
?>
<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="page" class="bl-site">
	<?php blueline_site_header(); ?>
	<?php
	/*
	 * The team flyout (inc/team-flyout.php). position:fixed, so its DOM spot
	 * only sets tab order: straight after the header, not after the footer
	 * (D-23/B-04). Prints nothing unless the directory's position is 'flyout'.
	 */
	blueline_render_team_flyout();
	?>
	<?php
	/*
	 * Site-wide, and here rather than in a homepage template, because most
	 * arrivals on this site are deep links shared into a team chat -- a
	 * banner only the homepage rendered would miss them. Prints nothing
	 * unless an admin has actually written an announcement and its date
	 * window is open (blueline_announcement_visible()).
	 */
	blueline_render_announcement();
	?>
