<?php
/**
 * blueline theme bootstrap.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

define( 'BLUELINE_VERSION', '1.0.0' );
define( 'BLUELINE_DIR', get_template_directory() );
define( 'BLUELINE_URI', get_template_directory_uri() );

require_once BLUELINE_DIR . '/inc/setup.php';
require_once BLUELINE_DIR . '/inc/enqueue.php';
require_once BLUELINE_DIR . '/inc/template-tags.php';
