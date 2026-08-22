<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/cli-stubs.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/snapshots.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/import.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/acknowledgements.php';
require_once __DIR__ . '/../inc/enqueue.php'; // blueline_stylesheet_version(), which blueline_settings_inputs_hash() calls.
require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/settings/validation.php';
require_once __DIR__ . '/../inc/occasions.php';
require_once __DIR__ . '/../inc/settings/page.php';

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

require_once __DIR__ . '/../inc/cli/settings-command.php';

/**
 * Covers `wp blueline settings occasions list|enable|disable|force`
 * (Blueline_Settings_Command::occasions(), inc/cli/settings-command.php)
 * -- design spec §6.5's WP-CLI-shape ruling: one subcommand, one action
 * positional argument, and the enable/auto, disable/force_off,
 * force/force_on verb-to-mode mapping.
 */
final class OccasionsCliCommandTest extends TestCase {

	/**
	 * Reset every in-memory store and this test's own CLI log before each
	 * test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_cli_log'] = array();
	}

	/**
	 * Grant the in-memory current user `manage_options`.
	 */
	private function grant_manage_options(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;
	}

	/**
	 * Every message the CLI recorded, joined.
	 *
	 * @return string
	 */
	private function cli_output(): string {
		return implode( "\n", array_column( $GLOBALS['bl_test_cli_log'], 'message' ) );
	}

	/**
	 * `list` on a fresh install (nothing stored) still names every preset
	 * -- and requires no capability at all, matching `export`'s own
	 * read-only precedent.
	 */
	public function test_list_names_every_preset_when_nothing_is_stored(): void {
		( new Blueline_Settings_Command() )->occasions( array( 'list' ), array() );

		$output = $this->cli_output();

		$this->assertStringContainsString( 'canada-day', $output );
		$this->assertStringContainsString( 'remembrance-day', $output );
		$this->assertStringContainsString( 'christmas', $output );
		$this->assertStringContainsString( 'new-year', $output );
	}

	/**
	 * `list` reports a stored occasion's id/label/type/mode, and no
	 * longer lists that same id under "presets not yet stored".
	 */
	public function test_list_reports_a_stored_occasion_and_excludes_it_from_presets(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'canada-day' => array(
						'id'     => 'canada-day',
						'label'  => 'Canada Day',
						'type'   => 'decorative',
						'window' => array(
							'start_md' => '07-01',
							'end_md'   => '07-01',
						),
						'accent' => '',
						'motif'  => 'maple-leaf',
						'line'   => '',
						'mode'   => 'force_on',
					),
				),
			)
		);

		( new Blueline_Settings_Command() )->occasions( array( 'list' ), array() );

		$output = $this->cli_output();

		$this->assertStringContainsString( 'label=Canada Day', $output );
		$this->assertStringContainsString( 'mode=force_on', $output );

		$presets_section = substr( $output, (int) strpos( $output, 'Presets not yet stored' ) );
		$this->assertStringNotContainsString( 'canada-day', $presets_section );
	}

	/**
	 * `enable` refuses without `manage_options`, writing nothing.
	 */
	public function test_enable_refuses_without_manage_options(): void {
		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		try {
			( new Blueline_Settings_Command() )->occasions( array( 'enable', 'canada-day' ), array() );
		} finally {
			$this->assertSame( false, get_option( BLUELINE_SETTINGS_OPTION, false ) );
		}
	}

	/**
	 * `enable` on a preset id not yet stored materializes it with
	 * mode=auto -- the CLI equivalent of the panel's "add from preset".
	 */
	public function test_enable_materializes_a_preset_with_auto_mode(): void {
		$this->grant_manage_options();

		( new Blueline_Settings_Command() )->occasions( array( 'enable', 'canada-day' ), array() );

		$stored = blueline_settings( 'occasions' );

		$this->assertArrayHasKey( 'canada-day', $stored );
		$this->assertSame( 'auto', $stored['canada-day']['mode'] );
		$this->assertSame( 'Canada Day', $stored['canada-day']['label'] );
	}

	/**
	 * `disable` on an already-stored occasion sets force_off, preserving
	 * every other field.
	 */
	public function test_disable_sets_force_off_on_a_stored_occasion(): void {
		$this->grant_manage_options();

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'canada-day' => array(
						'id'     => 'canada-day',
						'label'  => 'Canada Day',
						'type'   => 'decorative',
						'window' => array(
							'start_md' => '07-01',
							'end_md'   => '07-01',
						),
						'accent' => '#ffffff',
						'motif'  => 'maple-leaf',
						'line'   => '',
						'mode'   => 'auto',
					),
				),
			)
		);

		( new Blueline_Settings_Command() )->occasions( array( 'disable', 'canada-day' ), array() );

		$stored = blueline_settings( 'occasions' );

		$this->assertSame( 'force_off', $stored['canada-day']['mode'] );
		$this->assertSame( '#ffffff', $stored['canada-day']['accent'] );
	}

	/**
	 * `force` sets force_on, whether materializing a preset or updating
	 * an existing stored occasion.
	 */
	public function test_force_sets_force_on(): void {
		$this->grant_manage_options();

		( new Blueline_Settings_Command() )->occasions( array( 'force', 'christmas' ), array() );

		$stored = blueline_settings( 'occasions' );

		$this->assertSame( 'force_on', $stored['christmas']['mode'] );
	}

	/**
	 * An id matching neither a stored occasion nor a preset is a
	 * WP_CLI::error(), never a silent no-op.
	 */
	public function test_enable_an_unknown_id_errors(): void {
		$this->grant_manage_options();

		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		( new Blueline_Settings_Command() )->occasions( array( 'enable', 'not-a-real-id' ), array() );
	}

	/**
	 * An unrecognised action (neither list, enable, disable, nor force)
	 * errors.
	 */
	public function test_an_unrecognised_action_errors(): void {
		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		( new Blueline_Settings_Command() )->occasions( array( 'delete', 'canada-day' ), array() );
	}

	/**
	 * `enable` with no id at all (a missing positional argument) errors
	 * rather than fataling on an undefined array key.
	 */
	public function test_enable_with_no_id_errors(): void {
		$this->grant_manage_options();

		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		( new Blueline_Settings_Command() )->occasions( array( 'enable' ), array() );
	}
}
