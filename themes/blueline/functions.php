<?php
/**
 * Blueline theme bootstrap.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

// Bump on every asset-affecting change: enqueued CSS/JS/style.css use this
// as their query-string version, and staging/production both serve them
// with long, immutable cache lifetimes (see inc/enqueue.php).
define( 'BLUELINE_VERSION', '1.0.1' );
define( 'BLUELINE_DIR', get_template_directory() );
define( 'BLUELINE_URI', get_template_directory_uri() );

require_once BLUELINE_DIR . '/inc/setup.php';
require_once BLUELINE_DIR . '/inc/enqueue.php';
require_once BLUELINE_DIR . '/inc/template-tags.php';
