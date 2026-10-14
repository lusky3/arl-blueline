<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable.
/**
 * WordPress/SportsPress stand-ins for the account schedule, team-table and
 * single-game calendar tests (PlayerScheduleTest).
 *
 * Loaded ONLY inside #[RunInSeparateProcess] tests, for the same reason as
 * season-state-signal-stubs.php (which this file builds on): defining
 * WP_Query, WP_Term or SP_League_Table in the shared process would change
 * what every other test sees, since theme code branches on class_exists().
 *
 * @package blueline
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- test-only stub bundle, same trade-off as tests/bootstrap.php.

require_once __DIR__ . '/season-state-signal-stubs.php';

$GLOBALS['bl_test_team_records'] = array();

/**
 * Core's term class, which blueline_venue_label() checks get_term() returned.
 */
class WP_Term {

	/**
	 * Term ID.
	 *
	 * @var int
	 */
	public $term_id = 0;

	/**
	 * Taxonomy.
	 *
	 * @var string
	 */
	public $taxonomy = '';

	/**
	 * Name.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Slug.
	 *
	 * @var string
	 */
	public $slug = '';

	/**
	 * Parent term ID.
	 *
	 * @var int
	 */
	public $parent = 0;

	/**
	 * Copy the fields of a term registered with blueline_test_register_term().
	 *
	 * @param object $term Registered term.
	 */
	public function __construct( $term ) {
		foreach ( array( 'term_id', 'taxonomy', 'name', 'slug', 'parent' ) as $field ) {
			$this->$field = $term->$field;
		}
	}
}

/**
 * Stand-in for SportsPress's league table: like the real class, its rows come
 * from the table's own `sp_team` meta rows (records seeded per team).
 */
class SP_League_Table {

	/**
	 * Table post ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Constructor.
	 *
	 * @param int $id sp_table post ID.
	 */
	public function __construct( $id ) {
		$this->id = (int) $id;
	}

	/**
	 * Rows keyed by team id, for the teams this table lists.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function data() {
		$rows = array();

		foreach ( (array) get_post_meta( $this->id, 'sp_team', false ) as $team_id ) {
			$team_id = (int) $team_id;

			if ( $team_id > 0 && isset( $GLOBALS['bl_test_team_records'][ $team_id ] ) ) {
				$rows[ $team_id ] = $GLOBALS['bl_test_team_records'][ $team_id ];
			}
		}

		return $rows;
	}
}

/**
 * Stand-in for WooCommerce's is_account_page().
 *
 * @return bool
 */
function is_account_page() {
	return ! empty( $GLOBALS['bl_test_is_account_page'] );
}

/**
 * Stand-in for WooCommerce's wc_get_page_permalink().
 *
 * @param string $page Page key, e.g. 'myaccount'.
 * @return string
 */
function wc_get_page_permalink( $page ) {
	return 'myaccount' === $page ? 'https://example.test/account/' : 'https://example.test/';
}

/**
 * Stand-in for get_gmt_from_date(): the site zone is America/Toronto in these
 * tests, and every event starts at the get_the_time() stub's fixed 7:00 PM on
 * 2026-10-16 -- 23:00 UTC.
 *
 * @param string $date Local date (ignored).
 * @return string
 */
function get_gmt_from_date( $date ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with core.
	return '2026-10-16 23:00:00';
}
