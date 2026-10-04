<?php
/**
 * Unit tests for `wp blueline-core ownership report|apply`.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';
require_once dirname( __DIR__, 3 ) . '/themes/blueline/tests/cli-stubs.php';
require_once __DIR__ . '/../includes/player-link/ownership.php';
require_once __DIR__ . '/../includes/player-link/class-blueline-core-ownership-command.php';

/**
 * Covers the ownership helpers and Blueline_Core_Ownership_Command.
 */
final class OwnershipCommandTest extends TestCase {

	/**
	 * Fake $wpdb for the report query.
	 *
	 * @var Blueline_Core_Test_Wpdb
	 */
	private Blueline_Core_Test_Wpdb $wpdb;

	/**
	 * Reset stores, CLI log, recorded updates; install a fake $wpdb.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_test_cli_log']                = array();
		$GLOBALS['bl_core_test_updated_posts']     = array();
		$GLOBALS['bl_core_test_update_post_error'] = null;
		$GLOBALS['bl_core_test_kses_removed']      = 0;

		$this->wpdb      = new Blueline_Core_Test_Wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->seed_users();
	}

	/**
	 * Remove the fake $wpdb and recorded state.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['bl_core_test_updated_posts'], $GLOBALS['bl_core_test_update_post_error'], $GLOBALS['bl_core_test_kses_removed'] );
	}

	/**
	 * Users 5 and 7 hold the Player role, 6 is a plain customer, 99 does not exist.
	 */
	private function seed_users(): void {
		$state = &blueline_test_state();

		$state['post_types'] = array( 'sp_player' );
		$state['users'][5]   = (object) array(
			'roles'        => array( 'customer', 'sp_player' ),
			'user_login'   => 'alice',
			'display_name' => 'Player 100',
		);
		$state['users'][6]   = (object) array(
			'roles'        => array( 'customer' ),
			'user_login'   => 'bob',
			'display_name' => 'Bob Builder',
		);
		$state['users'][7]   = (object) array(
			'roles'        => array( 'sp_player' ),
			'user_login'   => 'carol',
			'display_name' => 'Someone Else',
		);
	}

	/**
	 * Register an sp_player post linked to $sp_user and authored by $author.
	 *
	 * @param int    $player_id Post ID.
	 * @param int    $sp_user   Linked user ('' meta when 0).
	 * @param int    $author    post_author.
	 * @param string $type      Post type.
	 */
	private function seed_player( int $player_id, int $sp_user, int $author, string $type = 'sp_player' ): void {
		$state = &blueline_test_state();

		$state['posts'][ $player_id ]                = array(
			'type'   => $type,
			'status' => 'publish',
			'author' => $author,
			'title'  => 'Player ' . $player_id,
		);
		$state['post_meta'][ $player_id ]['sp_user'] = $sp_user ? (string) $sp_user : '';
	}

	/**
	 * Logged CLI messages of one type.
	 *
	 * @param string $type log, success, warning or error.
	 * @return string[]
	 */
	private function messages( string $type ): array {
		$out = array();
		foreach ( $GLOBALS['bl_test_cli_log'] as $entry ) {
			if ( $type === $entry['type'] ) {
				$out[] = $entry['message'];
			}
		}
		return $out;
	}

	/**
	 * One report row from the fake query.
	 *
	 * @param int        $id      Player ID.
	 * @param int        $author  post_author.
	 * @param int|string $sp_user sp_user meta value.
	 * @return object
	 */
	private function report_row( int $id, int $author, $sp_user ): object {
		return (object) array(
			'ID'          => (string) $id,
			'post_title'  => 'Player ' . $id,
			'post_author' => (string) $author,
			'post_date'   => '2026-01-02 03:04:05',
			'sp_user'     => (string) $sp_user,
		);
	}

	/**
	 * Every combination of role, authorship and user existence maps to one category.
	 */
	public function test_category_covers_every_combination(): void {
		$this->assertSame( 'verified', blueline_core_ownership_category( 5, 5, true, true ) );
		$this->assertSame( 'role_not_author', blueline_core_ownership_category( 5, 1, true, true ) );
		$this->assertSame( 'author_not_role', blueline_core_ownership_category( 6, 6, true, false ) );
		$this->assertSame( 'name_claim', blueline_core_ownership_category( 6, 1, true, false ) );
		$this->assertSame( 'missing_user', blueline_core_ownership_category( 99, 99, false, true ) );
	}

	/**
	 * IDs parse to distinct positive integers; anything else is refused.
	 */
	public function test_parse_ids_accepts_only_explicit_positive_ids(): void {
		$this->assertSame( array( 101, 102 ), blueline_core_ownership_parse_ids( ' 101, 102,101 ,' ) );
		$this->assertSame( 'no_ids', blueline_core_ownership_parse_ids( '' )->get_error_code() );
		$this->assertSame( 'no_ids', blueline_core_ownership_parse_ids( ' , ' )->get_error_code() );
		$this->assertSame( 'bad_id', blueline_core_ownership_parse_ids( '101,abc' )->get_error_code() );
		$this->assertSame( 'bad_id', blueline_core_ownership_parse_ids( '0' )->get_error_code() );
		$this->assertSame( 'bad_id', blueline_core_ownership_parse_ids( '-3' )->get_error_code() );
		$this->assertSame( 'bad_id', blueline_core_ownership_parse_ids( 'all' )->get_error_code() );
	}

	/**
	 * The report lists only role-but-not-author rows and counts every category.
	 */
	public function test_report_lists_role_not_author_rows_and_counts_categories(): void {
		$this->wpdb->results = array(
			$this->report_row( 100, 1, 5 ),
			$this->report_row( 101, 7, 7 ),
			$this->report_row( 102, 6, 6 ),
			$this->report_row( 103, 1, 6 ),
			$this->report_row( 104, 1, 99 ),
			$this->report_row( 105, 1, '' ),
			$this->report_row( 106, 2, 7 ),
		);

		( new Blueline_Core_Ownership_Command() )->report( array(), array() );

		$log = $this->messages( 'log' );
		$this->assertSame( "player_id\tplayer\tsp_user\tuser_login\tpost_author\tpost_date\tname_score\tflags", $log[0] );
		$this->assertSame( "100\tPlayer 100\t5\talice\t1\t2026-01-02 03:04:05\t1\t", $log[1], 'name_score is 1 where the linked user\'s name matches the title' );
		$this->assertSame( "106\tPlayer 106\t7\tcarol\t2\t2026-01-02 03:04:05\t0\t", $log[2], 'name_score is 0 where it does not' );
		$this->assertSame( '', $log[3] );
		$this->assertContains( 'verified         1', $log );
		$this->assertContains( 'role_not_author  2', $log );
		$this->assertContains( 'author_not_role  1', $log );
		$this->assertContains( 'name_claim       1', $log );
		$this->assertContains( 'missing_user     1', $log );
		$this->assertContains( 'linked players   6', $log );
		$this->assertSame( array( 'sp_player', 'sp_user' ), $this->wpdb->prepared_args );
		$this->assertSame( array(), $GLOBALS['bl_core_test_updated_posts'], 'report is read-only' );
	}

	/**
	 * CSV output is quoted and carries no summary lines.
	 */
	public function test_report_csv_is_quoted_and_pipeable(): void {
		$this->wpdb->results                = array( $this->report_row( 100, 1, 5 ) );
		$this->wpdb->results[0]->post_title = 'Smith, "Jo"';

		( new Blueline_Core_Ownership_Command() )->report( array(), array( 'format' => 'csv' ) );

		$this->assertSame(
			array(
				'"player_id","player","sp_user","user_login","post_author","post_date","name_score","flags"',
				'"100","Smith, ""Jo""","5","alice","1","2026-01-02 03:04:05","0",""',
			),
			$this->messages( 'log' )
		);
	}

	/**
	 * An unknown format is refused.
	 */
	public function test_report_refuses_an_unknown_format(): void {
		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		( new Blueline_Core_Ownership_Command() )->report( array(), array( 'format' => 'json' ) );
	}

	/**
	 * Apply without --ids refuses before touching anything (no bulk mode).
	 */
	public function test_apply_refuses_without_ids(): void {
		$this->seed_player( 100, 5, 1 );

		try {
			( new Blueline_Core_Ownership_Command() )->apply( array(), array( 'yes' => true ) );
			$this->fail( 'apply without --ids must stop.' );
		} catch ( Blueline_Test_Cli_Exit_Exception $e ) {
			$this->assertStringContainsString( 'no bulk mode', strtolower( $e->getMessage() ) );
		}

		$this->assertSame( array(), $GLOBALS['bl_core_test_updated_posts'] );
	}

	/**
	 * Without --yes nothing is written; ineligible ids are skipped with a reason.
	 */
	public function test_apply_is_a_dry_run_by_default(): void {
		$this->seed_player( 100, 5, 1 );
		$this->seed_player( 101, 7, 7 );
		$this->seed_player( 104, 99, 1 );

		( new Blueline_Core_Ownership_Command() )->apply( array(), array( 'ids' => '100,101,104' ) );

		$this->assertSame( array( 'Would set player 100 post_author 1 -> 5 (previous author would be saved in _blueline_prev_author).' ), $this->messages( 'log' ) );
		$this->assertSame(
			array(
				'Player 101 skipped: already authored by user 7.',
				'Player 104 skipped: linked user 99 does not exist.',
			),
			$this->messages( 'warning' )
		);
		$this->assertStringContainsString( 'Dry run: 1 player(s) would change', $this->messages( 'success' )[0] );
		$this->assertSame( array(), $GLOBALS['bl_core_test_updated_posts'] );
		$this->assertSame( 1, blueline_test_state()['posts'][100]['author'] );
		$this->assertSame( 0, $GLOBALS['bl_core_test_kses_removed'] );
	}

	/**
	 * With --yes only eligible players get post_author = sp_user; sp_user itself is untouched.
	 */
	public function test_apply_with_yes_sets_post_author_for_eligible_players_only(): void {
		$this->seed_player( 100, 5, 1 );
		$this->seed_player( 102, 6, 1 );
		$this->seed_player( 103, 0, 1 );
		$this->seed_player( 110, 5, 1, 'post' );

		( new Blueline_Core_Ownership_Command() )->apply(
			array(),
			array(
				'ids' => '100,102,103,110',
				'yes' => true,
			)
		);

		$state = blueline_test_state();
		$this->assertSame(
			array(
				array(
					'ID'          => 100,
					'post_author' => 5,
				),
			),
			$GLOBALS['bl_core_test_updated_posts']
		);
		$this->assertSame( 5, $state['posts'][100]['author'] );
		$this->assertSame( '5', $state['post_meta'][100]['sp_user'] );
		$this->assertSame( 1, $state['post_meta'][100]['_blueline_prev_author'], 'the replaced author is recorded for undo' );
		$this->assertStringContainsString( '_blueline_prev_author', $this->messages( 'log' )[0], 'and the apply output says so' );
		$this->assertArrayNotHasKey( '_blueline_prev_author', $state['post_meta'][102] ?? array() );
		$this->assertSame( 1, $GLOBALS['bl_core_test_kses_removed'], 'kses must not rewrite post_content on the re-save' );
		$this->assertSame( 1, $state['posts'][102]['author'], 'linked user without the Player role is never made owner' );
		$this->assertSame(
			array(
				'Player 102 skipped: linked user 6 lacks the Player role.',
				'Player 103 skipped: no linked user (sp_user).',
				'Player 110 skipped: not an sp_player post.',
			),
			$this->messages( 'warning' )
		);
		$this->assertSame( array( '1 player(s) updated.' ), $this->messages( 'success' ) );
	}

	/**
	 * A failed update is reported and not counted.
	 */
	public function test_apply_reports_a_failed_update(): void {
		$this->seed_player( 100, 5, 1 );
		$GLOBALS['bl_core_test_update_post_error'] = new WP_Error( 'db', 'Database down' );

		( new Blueline_Core_Ownership_Command() )->apply(
			array(),
			array(
				'ids' => '100',
				'yes' => true,
			)
		);

		$this->assertSame( array( 'Player 100: update failed (Database down).' ), $this->messages( 'warning' ) );
		$this->assertSame( array( '0 player(s) updated.' ), $this->messages( 'success' ) );
	}

	/**
	 * A failed update does not record a previous author.
	 */
	public function test_a_failed_update_records_no_previous_author(): void {
		$this->seed_player( 100, 5, 1 );
		$GLOBALS['bl_core_test_update_post_error'] = new WP_Error( 'db', 'Database down' );

		( new Blueline_Core_Ownership_Command() )->apply(
			array(),
			array(
				'ids' => '100',
				'yes' => true,
			)
		);

		$this->assertArrayNotHasKey( '_blueline_prev_author', blueline_test_state()['post_meta'][100] );
	}

	/**
	 * Apply refuses to hand a player to a user who already owns a different one.
	 */
	public function test_plan_refuses_when_the_linked_user_already_owns_another_player(): void {
		$this->seed_player( 100, 5, 1 );
		$this->seed_player( 300, 0, 5 ); // User 5 is the verified owner of player 300.

		$plan = blueline_core_ownership_plan( 100 );

		$this->assertFalse( $plan['ok'] );
		$this->assertSame( 'linked user 5 already owns a different player (300)', $plan['reason'] );

		( new Blueline_Core_Ownership_Command() )->apply(
			array(),
			array(
				'ids' => '100',
				'yes' => true,
			)
		);

		$this->assertSame( array(), $GLOBALS['bl_core_test_updated_posts'] );
		$this->assertSame( array( 'Player 100 skipped: linked user 5 already owns a different player (300).' ), $this->messages( 'warning' ) );
	}

	/**
	 * Apply refuses to overwrite an author who is a Player-role user.
	 */
	public function test_plan_refuses_when_the_current_author_is_a_player_role_user(): void {
		$this->seed_player( 100, 5, 7 ); // post_author 7 (carol) holds the Player role.

		$plan = blueline_core_ownership_plan( 100 );

		$this->assertFalse( $plan['ok'] );
		$this->assertSame( 'current author 7 is an existing user with the Player role', $plan['reason'] );
	}

	/**
	 * An author who is a non-Player user (or no user at all) is still replaceable.
	 */
	public function test_plan_allows_replacing_a_non_player_author(): void {
		$this->seed_player( 100, 5, 6 ); // bob, a plain customer.
		$this->seed_player( 101, 5, 0 );
		$this->seed_player( 102, 5, 1 );

		$this->assertTrue( blueline_core_ownership_plan( 100 )['ok'] );
		$this->assertTrue( blueline_core_ownership_plan( 101 )['ok'] );
		$this->assertTrue( blueline_core_ownership_plan( 102 )['ok'] );
	}

	/**
	 * The report shows the risk flags and name score for rows apply would refuse.
	 */
	public function test_report_flags_risky_rows_and_shows_name_scores(): void {
		$this->seed_player( 300, 0, 5 ); // User 5 already owns player 300.
		$this->wpdb->results = array(
			$this->report_row( 100, 1, 5 ), // Owns another player.
			$this->report_row( 101, 7, 5 ), // Author 7 holds the Player role.
		);

		( new Blueline_Core_Ownership_Command() )->report( array(), array() );

		$log = $this->messages( 'log' );
		$this->assertSame( "100\tPlayer 100\t5\talice\t1\t2026-01-02 03:04:05\t1\towns_other_player", $log[1] );
		$this->assertSame( "101\tPlayer 101\t5\talice\t7\t2026-01-02 03:04:05\t0.5\towns_other_player author_is_player", $log[2] );
	}

	/**
	 * Spreadsheet formula leaders are neutralised; ordinary cells are untouched.
	 */
	public function test_csv_cells_that_look_like_formulas_are_prefixed(): void {
		$this->assertSame(
			"\"'=1+1\",\"'+SUM(A1)\",\"'-2\",\"'@cmd\",\"'\tx\",\"'\rx\",\"a=b\",\"5\",\"\"",
			blueline_core_ownership_csv_line( array( '=1+1', '+SUM(A1)', '-2', '@cmd', "\tx", "\rx", 'a=b', 5, '' ) )
		);
	}

	/**
	 * A hostile player title cannot reach the CSV as a live formula.
	 */
	public function test_report_csv_neutralises_a_formula_in_the_player_title(): void {
		$this->wpdb->results                = array( $this->report_row( 100, 1, 5 ) );
		$this->wpdb->results[0]->post_title = '=HYPERLINK("http://evil.test","x")';

		( new Blueline_Core_Ownership_Command() )->report( array(), array( 'format' => 'csv' ) );

		$this->assertStringContainsString( '"\'=HYPERLINK(""http://evil.test"",""x"")"', $this->messages( 'log' )[1] );
	}

	/**
	 * Unlink is a dry run unless --yes and removes only the sp_user row of a plain name claim.
	 */
	public function test_unlink_dry_run_then_apply_removes_a_name_claim(): void {
		$this->seed_player( 100, 6, 1 ); // bob: no Player role, not the author.

		( new Blueline_Core_Ownership_Command() )->unlink( array(), array( 'ids' => '100' ) );

		$this->assertSame( array( 'Would unlink player 100 from user 6 (name_claim).' ), $this->messages( 'log' ) );
		$this->assertStringContainsString( 'Dry run: 1 player(s) would be unlinked', $this->messages( 'success' )[0] );
		$this->assertSame( '6', blueline_test_state()['post_meta'][100]['sp_user'], 'a dry run writes nothing' );

		$GLOBALS['bl_test_cli_log'] = array();

		( new Blueline_Core_Ownership_Command() )->unlink(
			array(),
			array(
				'ids' => '100',
				'yes' => true,
			)
		);

		$state = blueline_test_state();
		$this->assertArrayNotHasKey( 'sp_user', $state['post_meta'][100] );
		$this->assertSame( 1, $state['posts'][100]['author'], 'post_author is never touched' );
		$this->assertStringContainsString( 'Unlinked player 100 from user 6', $this->messages( 'log' )[0] );
		$this->assertStringContainsString( 'wp post meta add 100 sp_user 6', $this->messages( 'log' )[0] );
		$this->assertSame( array( '1 player(s) unlinked.' ), $this->messages( 'success' ) );
	}

	/**
	 * Unlink never removes a verified owner, a Player-role link or the author's own link.
	 */
	public function test_unlink_refuses_anything_but_a_plain_name_claim(): void {
		$this->seed_player( 100, 5, 5 ); // verified.
		$this->seed_player( 101, 5, 1 ); // role_not_author.
		$this->seed_player( 102, 6, 6 ); // author_not_role.
		$this->seed_player( 103, 0, 1 ); // no link.
		$this->seed_player( 110, 6, 1, 'post' );

		( new Blueline_Core_Ownership_Command() )->unlink(
			array(),
			array(
				'ids' => '100,101,102,103,110',
				'yes' => true,
			)
		);

		$state = blueline_test_state();
		$this->assertSame( '5', $state['post_meta'][100]['sp_user'] );
		$this->assertSame( '5', $state['post_meta'][101]['sp_user'] );
		$this->assertSame( '6', $state['post_meta'][102]['sp_user'] );
		$this->assertSame(
			array(
				'Player 100 skipped: linked user 5 is not a plain name claim (verified).',
				'Player 101 skipped: linked user 5 is not a plain name claim (role_not_author).',
				'Player 102 skipped: linked user 6 is not a plain name claim (author_not_role).',
				'Player 103 skipped: no linked user (sp_user).',
				'Player 110 skipped: not an sp_player post.',
			),
			$this->messages( 'warning' )
		);
		$this->assertSame( array( '0 player(s) unlinked.' ), $this->messages( 'success' ) );
	}

	/**
	 * A link to a user that no longer exists can be cleared.
	 */
	public function test_unlink_clears_a_dangling_link(): void {
		$this->seed_player( 104, 99, 1 );

		( new Blueline_Core_Ownership_Command() )->unlink(
			array(),
			array(
				'ids' => '104',
				'yes' => true,
			)
		);

		$this->assertArrayNotHasKey( 'sp_user', blueline_test_state()['post_meta'][104] );
	}

	/**
	 * Unlink refuses without --ids, like apply (no bulk mode).
	 */
	public function test_unlink_refuses_without_ids(): void {
		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		( new Blueline_Core_Ownership_Command() )->unlink( array(), array( 'yes' => true ) );
	}

	/**
	 * The command is registered under `blueline-core ownership`.
	 */
	public function test_command_is_registered(): void {
		$this->assertSame( 'Blueline_Core_Ownership_Command', $GLOBALS['bl_test_cli_commands']['blueline-core ownership'] ?? null );
	}
}
