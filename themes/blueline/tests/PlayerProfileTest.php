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
}
