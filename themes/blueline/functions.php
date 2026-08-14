<?php
/**
 * Blueline theme bootstrap.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

// Fallback cache-busting version only. Real asset versions are derived
// automatically in inc/enqueue.php: built CSS/JS read the content hash
// webpack writes to their `*.asset.php` companion file on every build,
// and style.css (hand-edited outside the build) uses its own filemtime().
// This constant is used only if one of those mechanisms is unavailable
// (e.g. a missing/broken assets/dist/*.asset.php) — it does not need to
// be bumped by hand when assets change, and staging/production both
// serve assets with long, immutable cache lifetimes (see inc/enqueue.php)
// specifically because that per-asset versioning makes a manual bump
// unnecessary.
define( 'BLUELINE_VERSION', '1.0.1' );
define( 'BLUELINE_DIR', get_template_directory() );
define( 'BLUELINE_URI', get_template_directory_uri() );

require_once BLUELINE_DIR . '/inc/settings/defaults.php';
require_once BLUELINE_DIR . '/inc/settings/store.php';
require_once BLUELINE_DIR . '/inc/settings/links.php';
require_once BLUELINE_DIR . '/inc/setup.php';
require_once BLUELINE_DIR . '/inc/enqueue.php';
require_once BLUELINE_DIR . '/inc/template-tags.php';
require_once BLUELINE_DIR . '/inc/season-state.php';
require_once BLUELINE_DIR . '/inc/homepage-modules.php';
require_once BLUELINE_DIR . '/inc/team-colors.php';
require_once BLUELINE_DIR . '/inc/sportspress.php';
require_once BLUELINE_DIR . '/inc/woocommerce.php';
require_once BLUELINE_DIR . '/inc/account/endpoints.php';
require_once BLUELINE_DIR . '/inc/account/player-link.php';
require_once BLUELINE_DIR . '/inc/account/player-data.php';
require_once BLUELINE_DIR . '/inc/account/dashboard.php';
require_once BLUELINE_DIR . '/inc/account/avatars.php';
