<?php
/**
 * SportsPress integration loader: body classes, sidebar logic, the header
 * sponsors filter, the horizontal-scroll wrapper for SP's own data tables,
 * the future-status fix for venue archives, and the small hero/teaser
 * renderers consumed by sportspress/*.php -- split by concern into
 * inc/sportspress/*.php.
 *
 * Every SportsPress touchpoint in those parts is guarded with
 * function_exists() / class_exists() / post_type_exists() / taxonomy_exists()
 * so the theme never fatals with SportsPress deactivated.
 *
 * Kept as the single entry point so functions.php and the tests that
 * require this file keep loading the whole integration.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/sportspress/core.php';
require_once __DIR__ . '/sportspress/nav-options.php';
require_once __DIR__ . '/sportspress/table-scroll.php';
require_once __DIR__ . '/sportspress/calendar.php';
require_once __DIR__ . '/sportspress/heroes.php';
require_once __DIR__ . '/sportspress/venues.php';
require_once __DIR__ . '/sportspress/default-team-logo.php';
require_once __DIR__ . '/sportspress/player-list.php';
