<?php
/**
 * Season-aware homepage: hero variants and module rendering.
 *
 * Consumes blueline_season_state()/blueline_season_state_data() (Task 6).
 * Produces the two interface functions template-homepage.php calls:
 * blueline_render_hero( string $state ) and blueline_render_module( string $name ).
 *
 * Split by concern into inc/homepage/: registration-offers.php,
 * season-progress.php, hero.php, modules.php, standings.php, new-here.php
 * and band-photos.php. Kept as the single entry point so functions.php and
 * the tests that require this file keep loading the whole homepage.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/homepage/registration-offers.php';
require_once __DIR__ . '/homepage/season-progress.php';
require_once __DIR__ . '/homepage/hero.php';
require_once __DIR__ . '/homepage/modules.php';
require_once __DIR__ . '/homepage/standings.php';
require_once __DIR__ . '/homepage/new-here.php';
require_once __DIR__ . '/homepage/band-photos.php';
