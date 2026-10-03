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
			'roles'      => array( 'customer', 'sp_player' ),
			'user_login' => 'alice',
		);
		$state['users'][6]   = (object) array(
			'roles'      => array( 'customer' ),
			'user_login' => 'bob',
		);
		$state['users'][7]   = (object) array(
			'roles'      => array( 'sp_player' ),
			'user_login' => 'carol',
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
		$this->assertSame( "player_id\tplayer\tsp_user\tuser_login\tpost_author", $log[0] );
		$this->assertSame( "100\tPlayer 100\t5\talice\t1", $log[1] );
		$this->assertSame( "106\tPlayer 106\t7\tcarol\t2", $log[2] );
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
				'"player_id","player","sp_user","user_login","post_author"',
				'"100","Smith, ""Jo""","5","alice","1"',
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

		$this->assertSame( array( 'Would set player 100 post_author 1 -> 5.' ), $this->messages( 'log' ) );
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
	 * The command is registered under `blueline-core ownership`.
	 */
	public function test_command_is_registered(): void {
		$this->assertSame( 'Blueline_Core_Ownership_Command', $GLOBALS['bl_test_cli_commands']['blueline-core ownership'] ?? null );
	}
}
