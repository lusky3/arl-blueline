<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/next-game-member-scope.php';

/**
 * The per-member scope the next-game scripts add to their localStorage keys,
 * so one member's dismissal or "seen" state on a shared browser never applies
 * to the next member who signs in there (FE-01).
 */
final class NextGameMemberScopeTest extends TestCase {

	/**
	 * Two members get different scopes; the same member always gets the same one.
	 */
	public function test_scope_is_per_member_and_stable(): void {
		$this->assertNotSame( blueline_next_game_member_scope( 5 ), blueline_next_game_member_scope( 6 ) );
		$this->assertSame( blueline_next_game_member_scope( 5 ), blueline_next_game_member_scope( 5 ) );
	}

	/**
	 * It is a short opaque hash, not the user id or anything that names the member.
	 */
	public function test_scope_is_a_short_opaque_hash(): void {
		$scope = blueline_next_game_member_scope( 5 );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $scope );
		$this->assertSame( substr( wp_hash( 'blueline-next-game:5' ), 0, 16 ), $scope );
	}

	/**
	 * A guest has no scope.
	 */
	public function test_guest_has_no_scope(): void {
		$this->assertSame( '', blueline_next_game_member_scope( 0 ) );
	}

	/**
	 * The attribute the widget, account card and Preferences button all carry.
	 */
	public function test_attribute_carries_the_current_members_scope(): void {
		blueline_test_reset_state();
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 5;

		$this->assertSame(
			'data-bl-member="' . blueline_next_game_member_scope( 5 ) . '"',
			blueline_next_game_member_attribute()
		);
	}
}
