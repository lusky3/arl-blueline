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
 * to match this site's header/footer background, which -- since the
 * 2026.2 "Confident Minimal" repaint -- IS one of the themed surfaces
 * that follows the light/dark/system toggle (--bl-content-bg-raised in
 * style.css's :root block), not a fixed navy band. So this meta tag has
 * to follow the same toggle: a logged-in visitor with an explicit
 * preference gets a single value matching what the server is about to
 * render (blueline_get_theme_preference(), inc/account/theme-preference.php);
 * everyone else (guests, and a logged-in "system" preference) gets both
 * light and dark values behind their own `media` attribute, so the
 * browser itself picks the one matching prefers-color-scheme -- exactly
 * mirroring style.css's own two-guard pattern for every other themed
 * token. The two hex values are --bl-content-bg-raised's light/dark
 * values; see style.css's :root and :root[data-theme="dark"] blocks.
 */
$blueline_theme_color_pref = 'system';
if ( is_user_logged_in() && function_exists( 'blueline_get_theme_preference' ) ) {
	$blueline_theme_color_pref = blueline_get_theme_preference( get_current_user_id() );
}
?>
<?php if ( 'light' === $blueline_theme_color_pref ) : ?>
<meta name="theme-color" content="#E7EBEE">
<?php elseif ( 'dark' === $blueline_theme_color_pref ) : ?>
<meta name="theme-color" content="#1A2028">
<?php else : ?>
<meta name="theme-color" content="#E7EBEE" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1A2028" media="(prefers-color-scheme: dark)">
<?php endif; ?>
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="page" class="bl-site">
	<?php blueline_site_header(); ?>
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
