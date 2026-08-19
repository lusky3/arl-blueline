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
