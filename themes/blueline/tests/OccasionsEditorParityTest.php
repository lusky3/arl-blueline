<?php
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
require_once __DIR__ . '/../inc/settings/acknowledgements.php';
require_once __DIR__ . '/../inc/settings/validation.php';
require_once __DIR__ . '/../inc/enqueue.php';
require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/occasions.php';

/**
 * Covers blueline_occasion_editor_styles(): design spec §5/§7.9's
 * `block_editor_settings_all` mechanism, selector
 * ':root, .editor-styles-wrapper', emitting only --bl-occasion-accent.
 */
final class OccasionsEditorParityTest extends TestCase {

	/**
	 * Reset test state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * A force_on occasion with a valid, PASSING accent (never the empty
	 * default -- so the appended CSS's value is provably the occasion's
	 * own, not a coincidence).
	 *
	 * @param string $accent The accent color for the test occasion.
	 * @return array<string, mixed>
	 */
	private function occasion_with_accent( string $accent ): array {
		return array(
			'id'     => 'test-occasion',
			'label'  => 'Test Occasion',
			'type'   => 'decorative',
			'window' => array(
				'start_md' => '01-01',
				'end_md'   => '12-31',
			),
			'accent' => $accent,
			'motif'  => 'none',
			'line'   => '',
			'mode'   => 'force_on',
		);
	}

	/**
	 * Asserts nothing is added when no occasion is active -- the token's
	 * own CSS default already applies, so there is nothing to override.
	 */
	public function test_no_change_when_no_occasion_is_active(): void {
		$settings = array( 'styles' => array( array( 'css' => 'body{color:red}' ) ) );

		$this->assertSame( $settings, blueline_occasion_editor_styles( $settings ) );
	}

	/**
	 * Asserts a missing `styles` key is also left untouched when nothing
	 * is active, rather than this filter inventing the key.
	 */
	public function test_no_styles_key_invented_when_no_occasion_is_active(): void {
		$this->assertSame( array(), blueline_occasion_editor_styles( array() ) );
	}

	/**
	 * Asserts an active occasion appends exactly the documented CSS shape:
	 * selector ':root, .editor-styles-wrapper', only --bl-occasion-accent,
	 * the occasion's own resolved accent value, and '__unstableType' =>
	 * 'theme'.
	 */
	public function test_appends_the_resolved_accent_for_the_active_occasion(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'test-occasion' => $this->occasion_with_accent( '#ffd700' ) ) ) );

		// Sanity-check the fixture actually resolves before trusting the
		// filter's own assertion below.
		$resolved = blueline_resolve_active_occasion();
		$this->assertNotNull( $resolved );
		$this->assertSame( '#ffd700', $resolved['resolved_accent'] );

		$result = blueline_occasion_editor_styles( array() );

		$this->assertCount( 1, $result['styles'] );
		$this->assertSame( 'theme', $result['styles'][0]['__unstableType'] );
		$this->assertSame(
			':root, .editor-styles-wrapper { --bl-occasion-accent: #ffd700; }',
			$result['styles'][0]['css']
		);
	}

	/**
	 * Asserts an existing `styles` list is appended to, not replaced.
	 */
	public function test_preserves_existing_styles_entries(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'test-occasion' => $this->occasion_with_accent( '#ffd700' ) ) ) );

		$result = blueline_occasion_editor_styles( array( 'styles' => array( array( 'css' => 'body{color:red}' ) ) ) );

		$this->assertCount( 2, $result['styles'] );
		$this->assertSame( 'body{color:red}', $result['styles'][0]['css'] );
		$this->assertStringContainsString( '--bl-occasion-accent: #ffd700', $result['styles'][1]['css'] );
	}
}
