<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable.
/**
 * SportsPress and blueline-core stand-ins for rendering the theme's real
 * sportspress/league-table.php (StandingsViewerRowsTest).
 *
 * Loaded ONLY inside #[RunInSeparateProcess] tests: defining SP_League_Table
 * or the plugin's blueline_current_user_team_ids() in the shared process
 * would change what every other test sees (CorePluginAbsentTest asserts that
 * function is absent there).
 *
 * @package blueline
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- test-only stub bundle, same trade-off as tests/bootstrap.php.

/*
 * Rows SP_League_Table::data() returns, keyed by team id, with SportsPress's
 * own header row at index 0. Tests replace this before rendering.
 */
$GLOBALS['bl_test_league_table_data'] = array();

// The team ids blueline_current_user_team_ids() reports for the current viewer.
$GLOBALS['bl_test_viewer_team_ids'] = array();

/**
 * Stand-in for SportsPress's league table: data() returns the scripted rows,
 * header row included, as the real class does. Aliased to SP_League_Table
 * below rather than declared under that name, since
 * player-schedule-stubs.php declares its own (headerless) one.
 */
class Blueline_Test_Standings_League_Table {

	/**
	 * Table caption (the stock template's fallback title).
	 *
	 * @var string
	 */
	public $caption = '';

	/**
	 * Mirrors the real property the template forwards into its cache key.
	 *
	 * @var mixed
	 */
	public $show_published_events = null;

	/**
	 * Mirrors the real property the template forwards into its cache key.
	 *
	 * @var mixed
	 */
	public $show_future_events = null;

	/**
	 * Constructor.
	 *
	 * @param int $id sp_table post ID (unused: the rows are scripted).
	 */
	public function __construct( $id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with SportsPress.
	}

	/**
	 * Header row at index 0, then one row per team id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function data() {
		return $GLOBALS['bl_test_league_table_data'];
	}
}

class_alias( 'Blueline_Test_Standings_League_Table', 'SP_League_Table' );

/**
 * Stand-in for SportsPress's sp_array_value().
 *
 * @param array  $arr           Array to read.
 * @param string $key           Key.
 * @param mixed  $default_value Fallback.
 * @return mixed
 */
function sp_array_value( $arr = array(), $key = 0, $default_value = null ) {
	return isset( $arr[ $key ] ) ? $arr[ $key ] : $default_value;
}

/**
 * Stand-in for SportsPress's sp_get_post_mode(): a team table.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function sp_get_post_mode( $post_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with SportsPress.
	return 'team';
}

/**
 * Stand-in for core's get_the_ID() (the template's default table id).
 *
 * @return int
 */
function get_the_ID() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- core's own name.
	return 0;
}

/**
 * Stand-in for core's is_preview().
 *
 * @return bool
 */
function is_preview() {
	return false;
}

if ( ! function_exists( 'get_post_permalink' ) ) {
	/**
	 * Stand-in for core's get_post_permalink().
	 *
	 * @param int $post Post ID.
	 * @return string
	 */
	function get_post_permalink( $post = 0 ) {
		return 'https://example.test/team/' . (int) $post . '/';
	}
}

/**
 * Stand-in for core's wp_kses_allowed_html( 'post' ): the tags and global
 * attributes the league table prints.
 *
 * @param string $context Context (ignored).
 * @return array
 */
function wp_kses_allowed_html( $context = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP core.
	return array_fill_keys(
		array( 'a', 'abbr', 'caption', 'img', 'span', 'table', 'tbody', 'td', 'th', 'thead', 'tr' ),
		array()
	);
}

/**
 * Stand-in for the blueline-core plugin's blueline_current_user_team_ids().
 *
 * @return int[]
 */
function blueline_current_user_team_ids(): array {
	return $GLOBALS['bl_test_viewer_team_ids'];
}
