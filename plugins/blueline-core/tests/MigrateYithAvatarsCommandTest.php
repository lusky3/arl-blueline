<?php
/**
 * Unit tests for `wp blueline-core migrate-yith-avatars`.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/avatars/avatars.php';
require_once dirname( __DIR__, 3 ) . '/themes/blueline/tests/cli-stubs.php';
require_once __DIR__ . '/../includes/avatars/class-blueline-core-migrate-yith-avatars-command.php';

/**
 * Covers includes/avatars/class-blueline-core-migrate-yith-avatars-command.php.
 */
final class MigrateYithAvatarsCommandTest extends TestCase {

	/**
	 * Users: 3 migratable, 4 corrupt meta, 5 missing attachment, 6 already migrated.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_cli_log'] = array();

		$state = &blueline_test_state();
		foreach ( array( 3, 4, 5, 6 ) as $user_id ) {
			$state['users'][ $user_id ] = (object) array( 'user_login' => 'user' . $user_id );
		}
		$state['users'][7] = (object) array( 'user_login' => 'no-avatar' );
		$state['users'][8] = (object) array( 'user_login' => 'empty-avatar' );

		// A present-but-empty YITH row must not count: exercises the production meta_query's value/compare.
		$state['user_meta'][8]['yith-wcmap-avatar'] = '';

		$state['user_meta'][3]['yith-wcmap-avatar']  = '500';
		$state['user_meta'][4]['yith-wcmap-avatar']  = 'abc';
		$state['user_meta'][5]['yith-wcmap-avatar']  = '600';
		$state['user_meta'][6]['yith-wcmap-avatar']  = '700';
		$state['user_meta'][6]['blueline_avatar_id'] = '700';
		$state['posts'][500]                         = array( 'type' => 'attachment' );
		$state['posts'][700]                         = array( 'type' => 'attachment' );

		$GLOBALS['bl_test_options']['yith_wcmap_users_avatar_ids'] = array(
			2 => 500,
			4 => 700,
			5 => 800,
		);
	}

	/**
	 * Logged lines.
	 *
	 * @return string[]
	 */
	private function log_lines(): array {
		return array_column( $GLOBALS['bl_test_cli_log'], 'message' );
	}

	/**
	 * Log lines containing $needle.
	 *
	 * @param string $needle Substring.
	 * @return string[]
	 */
	private function lines_with( string $needle ): array {
		return array_values( array_filter( $this->log_lines(), static fn( $line ) => str_contains( $line, $needle ) ) );
	}

	/**
	 * Report mode (default) prints every row and writes nothing.
	 */
	public function test_report_mode_writes_nothing_and_reports_every_row(): void {
		( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array() );

		$this->assertSame( '', get_user_meta( 3, 'blueline_avatar_id', true ) );
		$this->assertCount( 1, $this->lines_with( 'REPORT MODE (default)' ) );
		$this->assertStringContainsString( 'would migrate', $this->lines_with( 'user3' )[0] );
		$this->assertStringContainsString( '500 (report only)', $this->lines_with( 'user3' )[0] );
		$this->assertStringContainsString( 'SKIPPED -- yith-wcmap-avatar value is non-numeric/zero: "abc"', $this->lines_with( 'user4' )[0] );
		$this->assertStringContainsString( 'SKIPPED -- attachment 600 no longer exists', $this->lines_with( 'user5' )[0] );
		$this->assertStringContainsString( 'already migrated (no-op)', $this->lines_with( 'user6' )[0] );
		$this->assertSame( array(), $this->lines_with( 'no-avatar' ) );
		$this->assertSame( array(), $this->lines_with( 'empty-avatar' ) );
		$this->assertContains( 'Rows found (real user -> attachment links): 4', $this->log_lines() );
		$this->assertCount( 1, $this->lines_with( 'with no owning user (not migrated -- nothing to attribute them to): 800' ) );
		$this->assertSame( 'yith_wcmap_users_avatar_ids = {"2":500,"4":700,"5":800}', $this->log_lines()[1] );
	}

	/**
	 * --apply without edit_users refuses before reading or printing anything.
	 */
	public function test_apply_without_edit_users_refuses_up_front(): void {
		try {
			( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array( 'apply' => true ) );
			$this->fail( 'Expected a refusal.' );
		} catch ( Blueline_Test_Cli_Exit_Exception $e ) {
			$this->assertStringContainsString( 'edit_users', $e->getMessage() );
		}

		$this->assertCount( 1, $GLOBALS['bl_test_cli_log'] );
		$this->assertSame( '', get_user_meta( 3, 'blueline_avatar_id', true ) );
	}

	/**
	 * --apply copies the link, leaves YITH's records alone and is idempotent.
	 */
	public function test_apply_copies_links_and_is_idempotent(): void {
		blueline_test_state()['caps']['edit_users'] = true;

		( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array( 'apply' => true ) );

		$this->assertSame( 500, get_user_meta( 3, 'blueline_avatar_id', true ) );
		$this->assertSame( '', get_user_meta( 4, 'blueline_avatar_id', true ) );
		$this->assertSame( '', get_user_meta( 5, 'blueline_avatar_id', true ) );
		$this->assertSame( '500', get_user_meta( 3, 'yith-wcmap-avatar', true ) );
		$this->assertSame( array( 2 => 500, 4 => 700, 5 => 800 ), get_option( 'yith_wcmap_users_avatar_ids' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short literal.
		$this->assertStringContainsString( 'migrated', $this->lines_with( 'user3' )[0] );

		$GLOBALS['bl_test_cli_log'] = array();
		( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array( 'apply' => true ) );

		$this->assertStringContainsString( 'already migrated (no-op)', $this->lines_with( 'user3' )[0] );
	}

	/**
	 * Log entries of one type (log, warning, error, success).
	 *
	 * @param string $type Entry type.
	 * @return string[] Messages.
	 */
	private function entries_of_type( string $type ): array {
		$messages = array();
		foreach ( $GLOBALS['bl_test_cli_log'] as $entry ) {
			if ( $type === $entry['type'] ) {
				$messages[] = $entry['message'];
			}
		}

		return $messages;
	}

	/**
	 * Give user 9 a different avatar (attachment 800) than YITH holds (attachment 900).
	 */
	private function seed_conflict(): void {
		$state                                       = &blueline_test_state();
		$state['users'][9]                           = (object) array( 'user_login' => 'conflicted' );
		$state['user_meta'][9]['yith-wcmap-avatar']  = '900';
		$state['user_meta'][9]['blueline_avatar_id'] = '800';
		$state['posts'][800]                         = array( 'type' => 'attachment' );
		$state['posts'][900]                         = array( 'type' => 'attachment' );
		$state['caps']['edit_users']                 = true;
	}

	/**
	 * A different avatar already set is a CONFLICT: reported, kept, and warned about, never overwritten.
	 */
	public function test_existing_different_avatar_is_a_conflict_and_is_not_overwritten(): void {
		$this->seed_conflict();

		( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array( 'apply' => true ) );

		$this->assertSame( '800', get_user_meta( 9, 'blueline_avatar_id', true ) );
		$row = $this->lines_with( 'conflicted' )[0];
		$this->assertStringContainsString( 'CONFLICT -- has 800, YITH has 900; kept (use --force)', $row );
		$this->assertStringNotContainsString( 'migrated', $row );

		$warnings = $this->entries_of_type( 'warning' );
		$this->assertCount( 2, $warnings );
		$this->assertStringContainsString( '1 user(s) already have a different blueline_avatar_id', implode( "\n", $warnings ) );
		$this->assertStringContainsString( 'user IDs: 9', implode( "\n", $warnings ) );
		$this->assertSame( array(), $this->entries_of_type( 'error' ) );
	}

	/**
	 * Report mode also flags the conflict (it does not claim it "would migrate").
	 */
	public function test_report_mode_flags_the_conflict(): void {
		$this->seed_conflict();

		( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array() );

		$this->assertStringContainsString( 'CONFLICT', $this->lines_with( 'conflicted' )[0] );
		$this->assertStringNotContainsString( 'would migrate', $this->lines_with( 'conflicted' )[0] );
		$this->assertSame( '800', get_user_meta( 9, 'blueline_avatar_id', true ) );
	}

	/**
	 * --apply --force overwrites the different avatar, and says so.
	 */
	public function test_force_overwrites_a_conflicting_avatar(): void {
		$this->seed_conflict();

		( new Blueline_Core_Migrate_Yith_Avatars_Command() )(
			array(),
			array(
				'apply' => true,
				'force' => true,
			)
		);

		$this->assertSame( 900, get_user_meta( 9, 'blueline_avatar_id', true ) );
		$this->assertStringContainsString( 'migrated (overwrote 800)', $this->lines_with( 'conflicted' )[0] );
		$this->assertStringNotContainsString( 'already have a different', implode( "\n", $this->entries_of_type( 'warning' ) ) );
	}

	/**
	 * --force without --apply only reports what would be overwritten.
	 */
	public function test_force_without_apply_writes_nothing(): void {
		$this->seed_conflict();

		( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array( 'force' => true ) );

		$this->assertSame( '800', get_user_meta( 9, 'blueline_avatar_id', true ) );
		$this->assertStringContainsString( 'would overwrite 800', $this->lines_with( 'conflicted' )[0] );
		$this->assertStringContainsString( '900 (report only)', $this->lines_with( 'conflicted' )[0] );
	}

	/**
	 * Skipped rows (non-numeric value, missing attachment) end in one warning naming the users, exit 0.
	 */
	public function test_skipped_rows_produce_a_warning(): void {
		( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array() );

		$warnings = $this->entries_of_type( 'warning' );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( '2 row(s) were SKIPPED', $warnings[0] );
		$this->assertStringContainsString( 'user IDs: 4, 5', $warnings[0] );
		$this->assertSame( array(), $this->entries_of_type( 'error' ) );
	}

	/**
	 * A run summary counts every outcome.
	 */
	public function test_summary_counts_every_outcome(): void {
		( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array() );

		$this->assertContains( 'Summary: 1 migrated/would migrate, 1 already migrated, 2 skipped, 0 conflict(s), 0 failed.', $this->log_lines() );
	}

	/**
	 * A clean run (nothing skipped, nothing to migrate) warns about nothing.
	 */
	public function test_clean_run_has_no_warnings(): void {
		$state = &blueline_test_state();
		unset( $state['user_meta'][4], $state['user_meta'][5] );

		( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array() );

		$this->assertSame( array(), $this->entries_of_type( 'warning' ) );
		$this->assertSame( array(), $this->entries_of_type( 'error' ) );
	}

	/**
	 * User 3's meta row refuses writes, as if a plugin vetoed `update_user_metadata`.
	 */
	private function refuse_writes_for_user_3(): void {
		blueline_test_state()['user_meta'][3] = new Blueline_Core_Test_Rejecting_Meta_Row( array( 'yith-wcmap-avatar' => '500' ) );
	}

	/**
	 * A write that does not stick is FAILED (never "migrated"), the report is still
	 * printed in full, and the run ends in WP_CLI::error() so the exit status is non-zero.
	 */
	public function test_rejected_write_is_reported_as_failed_and_errors(): void {
		blueline_test_state()['caps']['edit_users'] = true;
		$this->refuse_writes_for_user_3();

		try {
			( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array( 'apply' => true ) );
			$this->fail( 'Expected the run to end in an error.' );
		} catch ( Blueline_Test_Cli_Exit_Exception $e ) {
			$this->assertStringContainsString( '1 row(s) FAILED', $e->getMessage() );
			$this->assertStringContainsString( 'user IDs: 3', $e->getMessage() );
		}

		$this->assertSame( '', get_user_meta( 3, 'blueline_avatar_id', true ) );

		$row = $this->lines_with( 'user3' )[0];
		$this->assertStringContainsString( 'FAILED -- meta write rejected, nothing changed', $row );
		$this->assertStringNotContainsString( 'migrated', str_replace( 'already migrated', '', $row ) );
		// The report ran to the end before erroring, and the error is the last entry.
		$this->assertContains( 'Rows found (real user -> attachment links): 4', $this->log_lines() );
		$this->assertContains( 'Summary: 0 migrated/would migrate, 1 already migrated, 2 skipped, 0 conflict(s), 1 failed.', $this->log_lines() );
		$this->assertSame( 'error', end( $GLOBALS['bl_test_cli_log'] )['type'] );
	}

	/**
	 * Report mode never writes, so a store that refuses writes cannot make it fail.
	 */
	public function test_report_mode_never_reports_a_failure(): void {
		$this->refuse_writes_for_user_3();

		( new Blueline_Core_Migrate_Yith_Avatars_Command() )( array(), array() );

		$this->assertStringContainsString( 'would migrate', $this->lines_with( 'user3' )[0] );
		$this->assertSame( array(), $this->entries_of_type( 'error' ) );
	}

	/**
	 * The command is registered under `blueline-core migrate-yith-avatars`.
	 */
	public function test_command_is_registered(): void {
		$this->assertSame( 'Blueline_Core_Migrate_Yith_Avatars_Command', $GLOBALS['bl_test_cli_commands']['blueline-core migrate-yith-avatars'] ?? null );
	}
}
