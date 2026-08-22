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
require_once __DIR__ . '/../inc/announcement.php';
require_once __DIR__ . '/../inc/occasions.php';

/**
 * Covers blueline_occasion_suppress_urgent_announcement(): design spec
 * §5/§7.6's "suppresses ... the announcement banner's urgent styling"
 * for the duration of a commemorative occasion.
 */
final class OccasionsAnnouncementTest extends TestCase {

	/**
	 * Reset both stores before each test: the option store (the settings
	 * the suppression callback reads) and the fake-post store.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * A force_on occasion of the given type, so window matching is not a
	 * variable in these tests.
	 *
	 * @param string $type 'decorative' or 'commemorative'.
	 * @return array<string, mixed>
	 */
	private function occasion( string $type ): array {
		return array(
			'id'     => 'test-occasion',
			'label'  => 'Test Occasion',
			'type'   => $type,
			'window' => array(
				'start_md' => '01-01',
				'end_md'   => '12-31',
			),
			'accent' => '',
			'motif'  => 'none',
			'line'   => '',
			'mode'   => 'force_on',
		);
	}

	/**
	 * Asserts 'info' is never touched -- only 'urgent' is ever subject to
	 * this clamp.
	 */
	public function test_info_is_never_suppressed(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'announcement_severity' => 'info',
				'occasions'             => array( 'test-occasion' => $this->occasion( 'commemorative' ) ),
			)
		);

		$this->assertSame( 'info', blueline_announcement_severity() );
	}

	/**
	 * Asserts 'urgent' is suppressed to 'info' while a commemorative
	 * occasion is the currently resolved-active one.
	 */
	public function test_urgent_is_suppressed_while_a_commemorative_occasion_is_active(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'announcement_severity' => 'urgent',
				'occasions'             => array( 'test-occasion' => $this->occasion( 'commemorative' ) ),
			)
		);

		$this->assertSame( 'info', blueline_announcement_severity() );
	}

	/**
	 * Asserts 'urgent' is left alone when no occasion is active at all.
	 */
	public function test_urgent_is_not_suppressed_when_no_occasion_is_active(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'announcement_severity' => 'urgent' ) );

		$this->assertSame( 'urgent', blueline_announcement_severity() );
	}

	/**
	 * Asserts 'urgent' is left alone when the active occasion is
	 * decorative, not commemorative -- the suppression is specific to the
	 * commemorative type, not "any active occasion".
	 */
	public function test_urgent_is_not_suppressed_by_a_decorative_occasion(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'announcement_severity' => 'urgent',
				'occasions'             => array( 'test-occasion' => $this->occasion( 'decorative' ) ),
			)
		);

		$this->assertSame( 'urgent', blueline_announcement_severity() );
	}
}
