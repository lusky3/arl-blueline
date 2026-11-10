<?php
/**
 * Unit tests: a self-service name claim is stored under blueline-core's own key, never in the
 * shared sp_user key that other league plugins trust as identity (audit PLG-01).
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';

/**
 * Covers where blueline_link_player_to_user() writes and how a claim-key link is read back.
 */
final class NameClaimKeyTest extends TestCase {

	use Blueline_Assert_WP_Error;

	/**
	 * Reset the fake-WordPress state and the request memos.
	 */
	protected function setUp(): void {
		parent::setUp();
		blueline_test_reset();
		blueline_test_reset_state();
		unset( $GLOBALS['bl_core_test_before_add_post_meta'], $GLOBALS['bl_core_test_after_add_post_meta'] );
	}

	/**
	 * Drop the fake $wpdb and any simulated concurrent writer.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['bl_core_test_before_add_post_meta'], $GLOBALS['bl_core_test_after_add_post_meta'] );
		parent::tearDown();
	}

	/**
	 * Register an sp_player post in the fake store.
	 *
	 * @param int $player_id Post ID.
	 * @param int $author    post_author.
	 */
	private function seed_player_post( int $player_id, int $author = 0 ): void {
		$state                        = &blueline_test_state();
		$state['post_types']          = array( 'sp_player' );
		$state['posts'][ $player_id ] = array(
			'type'   => 'sp_player',
			'status' => 'publish',
			'author' => $author,
		);
	}

	/**
	 * The claim key is blueline-core's own, protected (underscore) post meta key.
	 */
	public function test_the_claim_key_is_a_protected_plugin_key(): void {
		$this->assertSame( '_blueline_name_claim_user', BLUELINE_PLAYER_CLAIM_META );
		$this->assertNotSame( BLUELINE_PLAYER_USER_META, BLUELINE_PLAYER_CLAIM_META );
	}

	/**
	 * A self-service claim writes the claim key, leaves sp_user alone and still resolves the link.
	 */
	public function test_a_name_claim_writes_the_claim_key_and_never_sp_user(): void {
		blueline_core_test_seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );

		$this->assertTrue( blueline_link_player_to_user( 100, 5 ) );

		$this->assertSame( '', get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ), 'sp_user is what other plugins trust; a name claim must not write it' );
		$this->assertSame( array( 5 ), get_post_meta( 100, BLUELINE_PLAYER_CLAIM_META, false ) );
		$this->assertSame( 100, blueline_get_linked_player_id( 5 ), 'the claimant still sees their (view-only) player' );
		$this->assertFalse( blueline_user_is_verified_player_owner( 5 ) );
	}

	/**
	 * A league write (edit_users) is still a real sp_user link.
	 */
	public function test_a_league_link_still_writes_sp_user(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 9;
		$state['caps']            = array( 'edit_users' => true );
		$this->seed_player_post( 100 );

		$this->assertTrue( blueline_link_player_to_user( 100, 5 ) );

		$this->assertSame( 5, get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ) );
		$this->assertSame( '', get_post_meta( 100, BLUELINE_PLAYER_CLAIM_META, true ) );
	}

	/**
	 * An existing sp_user link (registration, the league, or a claim made before this change) keeps working.
	 */
	public function test_an_existing_sp_user_link_still_resolves(): void {
		$this->seed_player_post( 100 );
		blueline_test_state()['post_meta'][100] = array( BLUELINE_PLAYER_USER_META => '5' );

		$this->assertSame( 100, blueline_get_linked_player_id( 5 ) );
		$this->assertSame( array( 5 ), blueline_player_linked_user_ids( 100 ) );
	}

	/**
	 * A player claimed through the claim key is taken: another account cannot claim it too.
	 */
	public function test_a_claim_key_link_blocks_a_second_claimant(): void {
		blueline_core_test_seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		blueline_test_state()['post_meta'][100][ BLUELINE_PLAYER_CLAIM_META ] = '7';

		$this->assertSame( array( 7 ), blueline_player_linked_user_ids( 100 ) );

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'already_linked', $result->get_error_code() );
		$this->assertSame( array(), blueline_find_player_candidates( 5 ), 'the claim pool no longer offers it either' );
		$this->assertSame( '7', get_post_meta( 100, BLUELINE_PLAYER_CLAIM_META, true ) );
	}

	/**
	 * A claimant with a claim-key link cannot pick up a second player.
	 */
	public function test_a_claim_key_link_counts_as_the_users_player(): void {
		blueline_core_test_seed_claim_pool( 5, 'Cody Lusk', array( 101 => 'Cody Lusk' ) );
		$this->seed_player_post( 100 );
		blueline_test_state()['post_meta'][100] = array( BLUELINE_PLAYER_CLAIM_META => '5' );

		$result = blueline_link_player_to_user( 101, 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'user_already_linked', $result->get_error_code() );
	}

	/**
	 * Once the real member is linked through sp_user (registration or the league), an older name
	 * claim on the same player no longer resolves for the claimant.
	 */
	public function test_an_sp_user_link_supersedes_another_users_name_claim(): void {
		$this->seed_player_post( 100, 7 );
		blueline_test_state()['post_meta'][100] = array(
			BLUELINE_PLAYER_CLAIM_META => '5',
			BLUELINE_PLAYER_USER_META  => '7',
		);

		$this->assertNull( blueline_get_linked_player_id( 5 ) );
		$this->assertSame( 100, blueline_get_linked_player_id( 7 ) );
	}

	/**
	 * A claim and an sp_user link to the same user on the same player resolve once.
	 */
	public function test_a_claim_and_sp_user_for_the_same_user_resolve_to_one_player(): void {
		$this->seed_player_post( 100 );
		blueline_test_state()['post_meta'][100] = array(
			BLUELINE_PLAYER_CLAIM_META => '5',
			BLUELINE_PLAYER_USER_META  => '5',
		);

		$this->assertSame( array( 100 ), blueline_query_linked_player_ids( 5 ) );
		$this->assertSame( array( 5 ), blueline_player_linked_user_ids( 100 ) );
	}

	/**
	 * A registration that links the player to someone else between the claim's write and its
	 * re-check rolls the claim back.
	 */
	public function test_a_concurrent_sp_user_link_rolls_the_claim_back(): void {
		blueline_core_test_seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );

		$GLOBALS['bl_core_test_after_add_post_meta'] = static function ( $post_id ) {
			blueline_test_state()['post_meta'][ $post_id ][ BLUELINE_PLAYER_USER_META ] = '7';
		};

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'already_linked', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( 100, BLUELINE_PLAYER_CLAIM_META, true ), 'the claim row this call wrote is removed' );
		$this->assertSame( '7', get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ), 'the registration row survives' );
	}
}
