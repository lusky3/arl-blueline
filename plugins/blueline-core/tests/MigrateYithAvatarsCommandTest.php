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
	 * The command is registered under `blueline-core migrate-yith-avatars`.
	 */
	public function test_command_is_registered(): void {
		$this->assertSame( 'Blueline_Core_Migrate_Yith_Avatars_Command', $GLOBALS['bl_test_cli_commands']['blueline-core migrate-yith-avatars'] ?? null );
	}
}
