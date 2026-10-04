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
		blueline_forget_claim_pool_memo();
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
		unset( $GLOBALS['bl_core_test_referer'], $GLOBALS['wpdb'] );
	}

	/**
	 * Make user 5 the logged-in account named $name, and offer $pool
	 * (player_id => post_title) as the claim pool through the fake $wpdb title
	 * fetch and the pool short-circuit filter.
	 *
	 * @param string             $name Account (billing) name.
	 * @param array<int, string> $pool Pool players.
	 */
	private function seed_claim( string $name, array $pool ): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 5;
		$state['post_types']      = array( 'sp_player' );
		$state['users'][5]        = (object) array(
			'roles'        => array( 'customer' ),
			'display_name' => $name,
		);
		$state['user_meta'][5]    = array(
			'billing_first_name' => (string) strtok( $name, ' ' ),
			'billing_last_name'  => trim( (string) strstr( $name, ' ' ) ),
		);

		$rows = array();
		foreach ( $pool as $player_id => $title ) {
			$state['posts'][ $player_id ] = array(
				'type'   => 'sp_player',
				'status' => 'publish',
				'author' => 0,
			);
			$rows[]                       = (object) array(
				'ID'         => (string) $player_id,
				'post_title' => $title,
			);
		}

		$wpdb            = new Blueline_Core_Test_Wpdb();
		$wpdb->results   = $rows;
		$GLOBALS['wpdb'] = $wpdb;

		add_filter(
			'blueline_pre_claim_pool_player_ids',
			static fn() => array_keys( $pool )
		);
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

	/**
	 * A genuine name match is linked and the user is sent back with blueline_claim=linked.
	 */
	public function test_a_matching_claim_links_and_redirects_with_linked(): void {
		$this->seed_claim( 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$this->post_claim( 100 );

		$this->assertSame( 'https://example.test/account/?blueline_claim=linked', $this->redirect_target() );
		$this->assertSame( array( 5 ), get_post_meta( 100, 'sp_user', false ) );
		$this->assertSame( 100, blueline_get_linked_player_id( 5 ) );
	}

	/**
	 * The user is sent back to wherever wp_get_referer() says they came from.
	 */
	public function test_the_redirect_returns_to_the_validated_referer(): void {
		$GLOBALS['bl_core_test_referer'] = 'https://example.test/team/mammoth/';
		$this->seed_claim( 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$this->post_claim( 100 );

		$this->assertSame( 'https://example.test/team/mammoth/?blueline_claim=linked', $this->redirect_target() );
	}

	/**
	 * An absent or off-site referer makes wp_get_referer() false (it validates the host),
	 * so the handler falls back to the account page, never to the supplied URL.
	 */
	public function test_a_missing_or_off_site_referer_falls_back_to_the_account_page(): void {
		unset( $GLOBALS['bl_core_test_referer'] );
		$this->seed_claim( 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$this->post_claim( 100 );

		// The account page when WooCommerce is present (a stub may or may not define it in this process), else the home page.
		$fallback = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );

		$this->assertSame( $fallback . '?blueline_claim=linked', $this->redirect_target() );    }

	/**
	 * A player somebody else holds by the time the link is written surfaces as already_linked.
	 */
	public function test_already_linked_reaches_the_redirect(): void {
		$this->seed_claim( 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$state                   = &blueline_test_state();
		$state['post_meta'][100] = array( 'sp_user' => '7' ); // Claimed after the candidate list was built.
		$this->post_claim( 100 );

		$this->assertSame( 'https://example.test/account/?blueline_claim=already_linked', $this->redirect_target() );
		$this->assertSame( array( '7' ), get_post_meta( 100, 'sp_user', false ), 'the existing owner is untouched' );
	}

	/**
	 * An account that already holds a different player gets user_already_linked.
	 */
	public function test_user_already_linked_reaches_the_redirect(): void {
		$this->seed_claim( 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$state                   = &blueline_test_state();
		$state['posts'][200]     = array(
			'type'   => 'sp_player',
			'status' => 'publish',
		);
		$state['post_meta'][200] = array( 'sp_user' => '5' );
		$this->post_claim( 100 );

		$this->assertSame( 'https://example.test/account/?blueline_claim=user_already_linked', $this->redirect_target() );
		$this->assertSame( '', get_post_meta( 100, 'sp_user', true ) );
	}

	/**
	 * A name padded with tokens so it contains several players' names is not a match for any of them.
	 */
	public function test_a_padded_account_name_cannot_claim_a_contained_player(): void {
		$this->seed_claim( 'John Mike Dave Smith Brown Jones', array( 100 => 'John Smith' ) );
		$this->post_claim( 100 );

		$this->assertSame( 'https://example.test/account/?blueline_claim=invalid', $this->redirect_target() );
		$this->assertSame( '', get_post_meta( 100, 'sp_user', true ) );
	}
}
