<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see inc/template-tags.php's identical disable.
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/player-profile.php';

/**
 * Covers Player Profile's pure per-field display logic.
 */
final class PlayerProfileTest extends TestCase {

	/**
	 * An empty stored value (get_user_meta()'s own "not set" return) must
	 * show a "not provided" fallback, not a blank value next to its label.
	 */
	public function test_empty_value_falls_back_to_not_provided(): void {
		$this->assertSame( 'Not provided', blueline_player_profile_field_display( '' ) );
	}

	/**
	 * A real stored value passes through unchanged.
	 */
	public function test_real_value_passes_through_unchanged(): void {
		$this->assertSame( '4 - Beginner – Intermediate', blueline_player_profile_field_display( '4 - Beginner – Intermediate' ) );
	}

	/**
	 * The exact six fields this tab reads, and their labels -- documented
	 * here as a single source of truth so a future edit can't silently
	 * drop or relabel one without a test noticing.
	 */
	public function test_registration_fields_are_the_expected_six(): void {
		$fields = blueline_player_profile_registration_fields();

		$this->assertSame(
			array(
				'arl_division'          => 'Skill level',
				'arl_position'          => 'Position',
				'arl_jerseysize'        => 'Jersey size',
				'arl_gender'            => 'Gender',
				'arl_emergency_contact' => 'Emergency contact',
				'arl_emergency_number'  => 'Emergency contact number',
			),
			$fields
		);
	}
}
