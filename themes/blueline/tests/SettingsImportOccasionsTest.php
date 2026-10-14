<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why.
/**
 * The export carries `occasions`; the import has to take it back.
 *
 * `occasions` is a reserved key rather than a schema field, so the generic
 * allow-list walk used to report it as "not a recognised setting" and leave it
 * out of the preview, even though the export itself contained it. These cases
 * pin the round trip: export from a site that has occasions, import on an
 * empty one, and the same occasions arrive.
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
require_once __DIR__ . '/../inc/settings/validation.php';
require_once __DIR__ . '/../inc/enqueue.php';
require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/occasions.php';
require_once __DIR__ . '/../inc/settings/page.php';
require_once __DIR__ . '/../inc/settings/import-export-handlers.php';

/**
 * See this file's own docblock.
 */
final class SettingsImportOccasionsTest extends TestCase {

	/**
	 * Reset the in-memory stores between cases.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * A well-formed occasion.
	 *
	 * @return array<string, mixed>
	 */
	private function occasion(): array {
		return array(
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
			'mode'   => 'auto',
		);
	}

	/**
	 * Export from a site that has an occasion, import on an empty store: the
	 * occasion arrives, byte for byte, through the real write path.
	 */
	public function test_export_then_import_on_an_empty_store_restores_occasions(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'canada-day' => $this->occasion() ) ) );
		$exported = blueline_settings_export_json();

		$this->assertStringContainsString( 'canada-day', $exported, 'the export must carry the occasion' );

		blueline_test_reset();
		blueline_test_reset_state();
		$this->assertSame( array(), blueline_settings( 'occasions' ), 'precondition: the new site has no occasions' );

		$state = blueline_settings_import_preview_state( $exported );

		$this->assertSame( array(), $state['errors'] );
		$this->assertSame( array(), $state['dropped'], 'occasions is importable, not a dropped key' );
		$this->assertSame( 'changed', $state['diff']['occasions']['status'] );
		$this->assertSame( array( 'canada-day' => $this->occasion() ), $state['diff']['occasions']['to'] );

		update_option( BLUELINE_SETTINGS_OPTION, $state['prepared'] );

		$this->assertSame( array( 'canada-day' => $this->occasion() ), blueline_settings( 'occasions' ) );
	}

	/**
	 * A malformed occasion in the file refuses the whole import (all or
	 * nothing, like every other rejected field) instead of silently shrinking
	 * the list.
	 */
	public function test_an_invalid_occasion_refuses_the_import(): void {
		$bad          = $this->occasion();
		$bad['label'] = '';

		$state = blueline_settings_import_preview_state( (string) wp_json_encode( array( 'occasions' => array( 'canada-day' => $bad ) ) ) );

		$this->assertNotSame( array(), $state['errors'] );
		$this->assertNull( $state['diff'] );
	}

	/**
	 * `occasions` that is not a list at all is refused, not coerced to empty
	 * (which would wipe the stored occasions).
	 */
	public function test_a_non_list_occasions_value_is_refused(): void {
		$state = blueline_settings_import_preview_state( '{"occasions":"nope"}' );

		$this->assertNotSame( array(), $state['errors'] );
		$this->assertNull( $state['diff'] );
	}

	/**
	 * Other unknown keys are still reported as dropped.
	 */
	public function test_other_unknown_keys_are_still_dropped(): void {
		$state = blueline_settings_import_preview_state( '{"occasions":{},"not_a_setting":1}' );

		$this->assertSame( array( 'not_a_setting' ), $state['dropped'] );
	}
}
