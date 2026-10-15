<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable.
/**
 * Stand-ins for what the schedule template (sportspress/event-list.php) reads from SportsPress and core,
 * so the template itself can be rendered.
 *
 * Defining SP() and SP_Calendar makes the template (and anything else that branches on function_exists( 'SP' ))
 * treat SportsPress as active, so this is loaded ONLY inside #[RunInSeparateProcess] tests.
 *
 * The games come from `$GLOBALS['bl_test_sp_calendar_events']`: post-like objects (ID, post_date, post_content,
 * post_status). Each game's teams, status and start live in blueline_test_state() as for any other test
 * (`sp_team` rows, `sp_status`, a `post_date`), and a game's recorded score in
 * `$GLOBALS['bl_test_sp_main_results'][ <event id> ]` as `team id => score`.
 *
 * @package blueline
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- test-only stub bundle, same trade-off as tests/bootstrap.php.

$GLOBALS['bl_test_sp_calendar_events'] = array();
$GLOBALS['bl_test_sp_main_results']    = array();

/**
 * The SportsPress singleton: only plugin_path() is read, to locate the stock template it defers to.
 */
class Blueline_Test_SP {

	/**
	 * Where SportsPress would keep its own templates.
	 *
	 * @return string
	 */
	public function plugin_path() {
		return sys_get_temp_dir() . '/blueline-test-no-sportspress';
	}
}

if ( ! function_exists( 'SP' ) ) {
	/**
	 * The SportsPress singleton.
	 *
	 * @return Blueline_Test_SP
	 */
	function SP() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- SportsPress's own name.
		return new Blueline_Test_SP();
	}
}

/**
 * SP_Calendar reduced to the properties the template sets and the two it reads (columns, caption), with
 * data() answering the scripted games.
 */
class SP_Calendar { // phpcs:ignore Generic.Classes.OpeningBraceSameLine,PEAR.NamingConventions.ValidClassName.Invalid -- SportsPress's own class name.

	/**
	 * Columns shown; an empty list means all of them, as in SportsPress.
	 *
	 * @var array
	 */
	public $columns = array();

	/**
	 * The calendar's caption.
	 *
	 * @var string
	 */
	public $caption = '';

	/**
	 * Status filter ('default' leaves it alone).
	 *
	 * @var string
	 */
	public $status = 'default';

	/**
	 * Sort order.
	 *
	 * @var string
	 */
	public $order = 'ASC';

	/**
	 * Constructor.
	 *
	 * @param int|null $id Calendar post ID.
	 */
	public function __construct( $id = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with SportsPress.
		$this->status = $GLOBALS['bl_test_sp_calendar_status'] ?? 'default';
	}

	/**
	 * The scripted games.
	 *
	 * @return object[]
	 */
	public function data() {
		return $GLOBALS['bl_test_sp_calendar_events'];
	}
}

if ( ! function_exists( 'sp_column_active' ) ) {
	/**
	 * Whether a column is shown: an empty list means every column.
	 *
	 * @param array|null $columns Active columns.
	 * @param string     $key     Column key.
	 * @return bool
	 */
	function sp_column_active( $columns = null, $key = null ) {
		return empty( $columns ) || in_array( $key, (array) $columns, true );
	}
}

if ( ! function_exists( 'sp_get_main_results' ) ) {
	/**
	 * A game's recorded score as `team id => score`.
	 *
	 * @param object $event A game from SP_Calendar::data().
	 * @return array<int, string>
	 */
	function sp_get_main_results( $event ) {
		return $GLOBALS['bl_test_sp_main_results'][ (int) $event->ID ] ?? array();
	}
}

if ( ! function_exists( 'sp_team_short_name' ) ) {
	/**
	 * A team's display name.
	 *
	 * @param int $team_id Team post ID.
	 * @return string
	 */
	function sp_team_short_name( $team_id ) {
		return get_the_title( $team_id );
	}
}

if ( ! function_exists( 'sp_array_value' ) ) {
	/**
	 * SportsPress's array lookup with a default.
	 *
	 * @param array  $arr     Array.
	 * @param string $key     Key.
	 * @param mixed  $fallback Value when the key is absent.
	 * @return mixed
	 */
	function sp_array_value( $arr, $key, $fallback = null ) {
		return is_array( $arr ) && isset( $arr[ $key ] ) ? $arr[ $key ] : $fallback;
	}
}

if ( ! function_exists( 'get_post_time' ) ) {
	/**
	 * A game's start, in site-local time, formatted as asked.
	 *
	 * @param string $format    Date format.
	 * @param bool   $gmt       Unused.
	 * @param object $post      A game.
	 * @param bool   $translate Unused.
	 * @return string
	 */
	function get_post_time( $format = 'U', $gmt = false, $post = null, $translate = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature parity with core.
		return ( new DateTimeImmutable( $post->post_date ) )->format( $format );
	}
}

if ( ! function_exists( 'mysql2date' ) ) {
	/**
	 * A MySQL date in the format asked.
	 *
	 * @param string $format    Date format.
	 * @param string $date      MySQL date.
	 * @param bool   $translate Unused.
	 * @return string
	 */
	function mysql2date( $format, $date, $translate = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature parity with core.
		return ( new DateTimeImmutable( $date ) )->format( $format );
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	/**
	 * Core returns false for a post with no terms in the taxonomy; no game here has any.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return false
	 */
	function get_the_terms( $post_id, $taxonomy ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature parity with core.
		return false;
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	/**
	 * Core's wp_list_pluck() for a list of objects.
	 *
	 * @param array  $items Objects.
	 * @param string $field Property to take.
	 * @return array
	 */
	function wp_list_pluck( $items, $field ) {
		return array_map( static fn( $item ) => is_object( $item ) ? $item->$field : $item[ $field ], $items );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Core's trailingslashit().
	 *
	 * @param string $value Path.
	 * @return string
	 */
	function trailingslashit( $value ) {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	/**
	 * Core's remove_query_arg() against a fixed page URL: the schedule's own page, with the argument gone.
	 *
	 * @param string|string[] $key The argument(s) to remove.
	 * @param string|false    $url Unused.
	 * @return string
	 */
	function remove_query_arg( $key, $url = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature parity with core.
		return 'https://example.test/schedule/';
	}
}

if ( ! function_exists( 'esc_html_x' ) ) {
	/**
	 * Core's esc_html_x(), without translation.
	 *
	 * @param string $text    Text.
	 * @param string $context Context for translators.
	 * @param string $domain  Text domain.
	 * @return string
	 */
	function esc_html_x( $text, $context, $domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with core.
		return esc_html( (string) apply_filters( 'gettext_with_context', $text, $text, $context, (string) $domain ) );
	}
}
