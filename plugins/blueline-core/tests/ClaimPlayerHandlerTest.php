<?php
/**
 * Unit tests for the admin_post_blueline_claim_player handler.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';

/**
 * Covers blueline_handle_claim_player_submission()'s guards (SEC-01).
 */
final class ClaimPlayerHandlerTest extends TestCase {

	/**
	 * Reset stores and the request superglobals.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$_POST                           = array();
		$_REQUEST                        = array();
		$GLOBALS['bl_core_test_referer'] = 'https://example.test/account/';
	}

	/**
	 * Clear the superglobals and referer.
	 */
	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['bl_core_test_referer'] );
	}

	/**
	 * Post a claim for $player_id with a valid nonce.
	 *
	 * @param int $player_id Claimed player.
	 */
	private function post_claim( int $player_id ): void {
		$_POST['player_id']    = (string) $player_id;
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'blueline_claim_player' );
		$_REQUEST['player_id'] = (string) $player_id;
	}

	/**
	 * Run the handler and return where it redirected.
	 *
	 * @return string
	 */
	private function redirect_target(): string {
		try {
			blueline_handle_claim_player_submission();
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			return $e->location;
		}

		$this->fail( 'The handler must redirect.' );
	}

	/**
	 * Logged-out requests die before anything else.
	 */
	public function test_logged_out_request_dies(): void {
		$this->expectException( Blueline_Test_WP_Die_Exception::class );

		blueline_handle_claim_player_submission();
	}

	/**
	 * A missing or wrong nonce dies.
	 */
	public function test_bad_nonce_dies(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 5;
		$_REQUEST['_wpnonce']     = 'forged';

		$this->expectException( Blueline_Test_WP_Die_Exception::class );

		blueline_handle_claim_player_submission();
	}

	/**
	 * A Player-role account cannot claim by name: invalid, and sp_user is never written.
	 */
	public function test_player_role_account_cannot_claim_by_name(): void {
		$state                       = &blueline_test_state();
		$state['current_user_id']    = 5;
		$state['post_types']         = array( 'sp_player' );
		$state['users'][5]           = (object) array(
			'roles'        => array( 'sp_player' ),
			'display_name' => 'Cody Lusk',
		);
		$state['post_meta'][100]     = array( 'sp_current_team' => '7' );
		$state['posts'][100]['type'] = 'sp_player';
		$this->post_claim( 100 );

		$this->assertSame( 'https://example.test/account/?blueline_claim=invalid', $this->redirect_target() );
		$this->assertArrayNotHasKey( 'sp_user', blueline_test_state()['post_meta'][100] );
	}

	/**
	 * A player id outside the account's own candidate list is invalid.
	 */
	public function test_claim_outside_the_candidate_list_is_invalid(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 6;
		$state['users'][6]        = (object) array(
			'roles'        => array( 'customer' ),
			'display_name' => 'Matthew',
		);
		$this->post_claim( 100 );

		$this->assertSame( 'https://example.test/account/?blueline_claim=invalid', $this->redirect_target() );
	}
}
