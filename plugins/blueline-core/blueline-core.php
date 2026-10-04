<?php
/**
 * Plugin Name:       Blueline Core
 * Plugin URI:        https://github.com/lusky3/rookiehockey-blueline
 * Description:       League functionality for the Blueline theme: player linking and photos, My Account routing, mail wrapper, checkout fields, SEO tags, search and privacy hardening.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.3
 * Author:            Adult Recreational League
 * Author URI:        https://www.rookiehockey.ca
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blueline-core
 * Update URI:        false
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

define( 'BLUELINE_CORE_VERSION', '0.1.0' );
define( 'BLUELINE_CORE_FILE', __FILE__ );
define( 'BLUELINE_CORE_DIR', __DIR__ );

require_once BLUELINE_CORE_DIR . '/includes/boot.php';

register_activation_hook( BLUELINE_CORE_FILE, 'blueline_core_activate' );
register_deactivation_hook( BLUELINE_CORE_FILE, 'blueline_core_deactivate' );

// Priority 1: the theme's functions.php has run, so legacy-theme detection is reliable.
add_action( 'after_setup_theme', 'blueline_core_boot', 1 );
