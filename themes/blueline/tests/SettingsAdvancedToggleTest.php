<?php
/**
 * Covers `advanced_enabled` -- the spec's "here be dragons" disclosure -- and
 * the one control it currently gates.
 *
 * Two claims are pinned here, and both are claims made in user-facing copy
 * rather than only in code:
 *
 * 1. The toggle actually hides the delete control. Asserted against RENDERED
 *    MARKUP, not against the setting's value, because this branch has a
 *    template behind it and a notice that shipped on this branch rendering a
 *    raw internal key proved that reading the data half tells you nothing
 *    about what an admin sees.
 * 2. An import can never turn it on. The spec requires `advanced_enabled` and
 *    `aa_acknowledgements` be discarded from an import unconditionally,
 *    "otherwise a crafted file arrives pre-excused" -- consent is the one
 *    thing a file cannot assert on an admin's behalf.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/snapshots.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/import.php';
require_once __DIR__ . '/../inc/settings/cache.php';
require_once __DIR__ . '/../inc/settings/delete-data.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/page.php';

/**
 * Pins what the Advanced disclosure hides, and that an import cannot set it.
 *
 * @package blueline
 */
final class SettingsAdvancedToggleTest extends TestCase {

	/**
	 * Reset the in-memory stores between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Capture what blueline_settings_render_delete_all_data() prints.
	 *
	 * @return string Rendered markup.
	 */
	private function render_delete_control(): string {
		ob_start();
		blueline_settings_render_delete_all_data();
		return (string) ob_get_clean();
	}

	/**
	 * A disclosure affordance defaults to disclosing nothing.
	 *
	 * @return void
	 */
	public function test_advanced_is_off_by_default(): void {
		$this->assertFalse( blueline_settings_defaults()['advanced_enabled'] );
	}

	/**
	 * With Advanced off, the delete control renders no markup at all.
	 *
	 * @return void
	 */
	public function test_the_delete_control_is_hidden_while_advanced_is_off(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'advanced_enabled' => false ) );

		$this->assertSame( '', trim( $this->render_delete_control() ) );
	}

	/**
	 * With Advanced on, the form and its confirmation box are rendered.
	 *
	 * @return void
	 */
	public function test_the_delete_control_appears_once_advanced_is_on(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'advanced_enabled' => true ) );

		$html = $this->render_delete_control();

		$this->assertStringContainsString( 'blueline_settings_delete_all_data', $html );
		$this->assertStringContainsString( 'blueline_delete_confirm', $html );
	}

	/**
	 * The copy has to draw the reset/delete distinction, because that is the
	 * single thing an admin is most likely to get wrong, and the recoverable
	 * option is the one they probably wanted.
	 */
	public function test_the_delete_copy_says_the_saved_copies_go_too(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'advanced_enabled' => true ) );

		$html = $this->render_delete_control();

		$this->assertStringContainsString( 'not the same as resetting', $html );
		$this->assertStringContainsString( 'nothing to restore from', $html );
	}

	/**
	 * The spec's own words: an import "discards aa_acknowledgements and
	 * advanced_enabled unconditionally (otherwise a crafted file arrives
	 * pre-excused)".
	 */
	public function test_an_import_cannot_switch_advanced_on(): void {
		$prepared = blueline_settings_import_prepare(
			array(
				'advanced_enabled' => true,
				'footer_heading'   => 'The ARL',
			),
			BLUELINE_SETTINGS_SCHEMA_VERSION
		);

		$this->assertIsArray( $prepared );
		$this->assertArrayNotHasKey( 'advanced_enabled', $prepared );
		// Premise: the payload was otherwise honoured, so the assertion above
		// is about this key rather than about the whole payload being refused.
		$this->assertSame( 'The ARL', $prepared['footer_heading'] );
	}

	/**
	 * The P2 acknowledgement key is discarded by the same guard.
	 *
	 * @return void
	 */
	public function test_an_import_cannot_smuggle_aa_acknowledgements_either(): void {
		$prepared = blueline_settings_import_prepare(
			array(
				'aa_acknowledgements' => array( 'anything' ),
				'footer_heading'      => 'The ARL',
			),
			BLUELINE_SETTINGS_SCHEMA_VERSION
		);

		$this->assertIsArray( $prepared );
		$this->assertArrayNotHasKey( 'aa_acknowledgements', $prepared );
	}

	/**
	 * Switching it off is a disclosure, not a revocation: the toggle hides the
	 * control, and hiding is all it claims to do. If this ever started
	 * behaving like a permission check, the copy on the toggle would become a
	 * lie in the more dangerous direction -- someone would rely on it.
	 */
	public function test_the_help_text_says_it_is_a_warning_not_a_lock(): void {
		$schema = blueline_settings_schema();

		$this->assertStringContainsString( 'not a lock', $schema['advanced_enabled']['help'] );
	}
}
