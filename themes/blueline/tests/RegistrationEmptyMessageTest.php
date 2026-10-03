<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/account/dashboard.php';
require_once __DIR__ . '/../inc/template-tags.php';

/**
 * Live-site review: "No registration found for the current season yet."
 * showed even for a fully rostered, linked player -- real "did my payment
 * go through?" anxiety for someone who simply registered by an
 * offline/manual method and has no ONLINE order for
 * blueline_get_user_registration_status() to find. Covers
 * blueline_registration_empty_message() (the pure copy choice) and its
 * wiring into blueline_account_render_registration() via the new
 * $player_id parameter. blueline_get_user_registration_status() itself is
 * untouched -- these tests never register the `product_cat` taxonomy, so
 * it returns null exactly as it would for a real "no online order" result,
 * without needing WooCommerce/order fixtures wired in.
 */
final class RegistrationEmptyMessageTest extends TestCase {

	/**
	 * Reset every in-memory store this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Seed a player whose sp_current_team resolves to a real, published team.
	 *
	 * @param int $player_id Player post id.
	 * @param int $team_id   Team post id to publish.
	 */
	private function seed_rostered_player( int $player_id, int $team_id ): void {
		$state                            = &blueline_test_state();
		$state['post_types']              = array( 'sp_player', 'sp_team' );
		$state['posts'][ $team_id ]       = array(
			'status' => 'publish',
			'type'   => 'sp_team',
		);
		$state['post_meta'][ $player_id ] = array(
			'sp_current_team' => new Blueline_Test_Meta_Rows( array( (string) $team_id ) ),
		);
	}

	/**
	 * Test case.
	 */
	public function test_message_for_a_rostered_player_is_softened(): void {
		$this->assertSame(
			'No online order found for the current season. If you registered a different way, you’re all set — contact us if anything looks wrong.',
			blueline_registration_empty_message( true )
		);
	}

	/**
	 * Test case.
	 */
	public function test_message_for_an_unrostered_player_is_unchanged(): void {
		$this->assertSame(
			'No registration found for the current season yet.',
			blueline_registration_empty_message( false )
		);
	}

	/**
	 * Wiring proof: a linked player with a real current team gets the
	 * softened copy from the actual renderer, not just from the pure
	 * function in isolation.
	 */
	public function test_renderer_softens_the_copy_for_a_rostered_linked_player(): void {
		$this->seed_rostered_player( 66, 115100 );

		ob_start();
		blueline_account_render_registration( 1, 66 );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'If you registered a different way', $html );
		$this->assertStringNotContainsString( 'No registration found for the current season yet.', $html );
	}

	/**
	 * Wiring proof, the other direction: with no linked player at all (the
	 * dashboard's own call site when blueline_get_linked_player_id()
	 * returns null), the original, unambiguous copy is unchanged.
	 */
	public function test_renderer_keeps_the_original_copy_with_no_linked_player(): void {
		ob_start();
		blueline_account_render_registration( 1, null );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'No registration found for the current season yet.', $html );
		$this->assertStringNotContainsString( 'If you registered a different way', $html );
	}

	/**
	 * A linked player with NO current team (e.g. never rostered, or only a
	 * stale historical one that resolved to nothing published) is not
	 * "rostered" for this purpose -- the original copy stays.
	 */
	public function test_renderer_keeps_the_original_copy_for_a_linked_but_unrostered_player(): void {
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_player' );

		ob_start();
		blueline_account_render_registration( 1, 999 );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'No registration found for the current season yet.', $html );
		$this->assertStringNotContainsString( 'If you registered a different way', $html );
	}
}
