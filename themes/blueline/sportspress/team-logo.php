<?php
/**
 * Theme override of SportsPress's "Logo" team-summary component
 * (sportspress_output_team_logo(), hooked ahead of the team's own Profile
 * content whenever sportspress_team_show_logo is "yes", the default).
 *
 * Intentionally empty: single-team.php's own hero (blueline_sp_team_hero(),
 * inc/sportspress.php) already prints the team's crest, once, at the very
 * top of the page -- this stock component duplicated it a second time,
 * full-size, directly above the roster. Reported live as "team pages show
 * 2 team logos." There is no sportspress_team_show_logo admin toggle
 * exposed in this theme's own settings (it is a raw wp_options row SportsPress
 * itself reads, `inc/settings/defaults.php` never touches it), so suppressing
 * it here -- the same override-a-stock-template mechanism this theme already
 * uses for league-table.php, team-events.php, and team-lists.php -- keeps the
 * fix in version control instead of a one-off database edit nothing else in
 * this repo would know about.
 *
 * Overrides SportsPress templates/team-logo.php, core template version 1.4 as of
 * SportsPress Pro 2.7.29; re-check this override when that version changes.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;
