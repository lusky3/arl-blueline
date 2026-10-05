<?php
/**
 * Structure guards for the player-link module's files.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

/**
 * The module is split across files; the loader must reach every one and each must be safe to load.
 */
final class PlayerLinkStructureTest extends TestCase {

	/**
	 * Files the loader loads only under WP-CLI.
	 */
	private const CLI_ONLY = array( 'ownership.php', 'class-blueline-core-ownership-command.php' );

	/**
	 * The module's PHP files, by basename.
	 *
	 * @return string[]
	 */
	private function module_files(): array {
		$files = array_map( 'basename', glob( dirname( __DIR__ ) . '/includes/player-link/*.php' ) ?: array() ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- glob() returns false on error.
		sort( $files );

		return $files;
	}

	/**
	 * The source of one module file.
	 *
	 * @param string $file Basename.
	 * @return string
	 */
	private function source( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/includes/player-link/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file, not a remote URL.
	}

	/**
	 * Every file other than the loader is required by the loader, so a new file cannot be forgotten.
	 */
	public function test_the_loader_requires_every_other_file(): void {
		$loader = $this->source( 'player-link.php' );

		foreach ( $this->module_files() as $file ) {
			if ( 'player-link.php' === $file ) {
				continue;
			}

			$this->assertStringContainsString( "require_once __DIR__ . '/" . $file . "';", $loader, $file . ' is not loaded by player-link.php.' );
		}
	}

	/**
	 * Only the CLI files are loaded conditionally; everything else loads unconditionally.
	 */
	public function test_only_the_cli_files_load_conditionally(): void {
		$loader = $this->source( 'player-link.php' );

		foreach ( $this->module_files() as $file ) {
			if ( 'player-link.php' === $file ) {
				continue;
			}

			$indented = 1 === preg_match( "/^\t+require_once __DIR__ \. '\/" . preg_quote( $file, '/' ) . "';/m", $loader );
			$this->assertSame( in_array( $file, self::CLI_ONLY, true ), $indented, $file );
		}
	}

	/**
	 * Every file refuses a direct request.
	 */
	public function test_every_file_guards_against_direct_access(): void {
		foreach ( $this->module_files() as $file ) {
			$this->assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $this->source( $file ), $file );
		}
	}

	/**
	 * The functions the rest of the plugin, the theme and the backfill script call all exist after one require.
	 */
	public function test_the_public_functions_are_defined_by_the_loader(): void {
		require_once dirname( __DIR__ ) . '/includes/player-link/player-link.php';

		foreach ( array( 'blueline_find_player_candidates', 'blueline_link_player_to_user', 'blueline_get_linked_player_id', 'blueline_forget_linked_player_cache', 'blueline_user_match_name', 'blueline_current_user_player_id', 'blueline_handle_claim_player_submission', 'blueline_name_pair_is_specific_enough' ) as $function ) {
			$this->assertTrue( function_exists( $function ), $function );
		}

		$this->assertSame( 'sp_user', BLUELINE_PLAYER_USER_META );
	}

	/**
	 * Test-only seams stay out of production code.
	 */
	public function test_no_test_only_seams_remain(): void {
		$source = '';
		foreach ( $this->module_files() as $file ) {
			$source .= $this->source( $file );
		}

		foreach ( array( 'blueline_pre_claim_pool_player_ids', 'blueline_forget_claim_pool_memo', 'blueline_claim_pool_memo', 'blueline_linked_player_cache' ) as $seam ) {
			$this->assertStringNotContainsString( $seam, $source, $seam );
		}
	}
}
