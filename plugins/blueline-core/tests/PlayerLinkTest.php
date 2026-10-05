<?php
/**
 * Unit tests.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';

/**
 * Unit tests.
 */
final class PlayerLinkTest extends TestCase {

	use Blueline_Assert_WP_Error;

	/**
	 * Reset the fake-WordPress state (and player-link.php's request-scoped
	 * linked-player cache) before every test, so the stateful cases below
	 * cannot leak into each other or into the pure ones.
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
	 * @param int    $player_id Post ID.
	 * @param string $status    Post status.
	 * @param int    $author    post_author (0 for none).
	 */
	private function seed_player_post( int $player_id, string $status = 'publish', int $author = 0 ): void {
		$state                        = &blueline_test_state();
		$state['post_types']          = array( 'sp_player' );
		$state['posts'][ $player_id ] = array(
			'type'   => 'sp_player',
			'status' => $status,
			'author' => $author,
		);
	}

	/**
	 * Make $user_id a plain account named $name and offer exactly the $pool players (id => title)
	 * as eligible claim-pool members, which the real pool query then finds.
	 *
	 * @param int                $user_id Account ID (also the current user).
	 * @param string             $name    The account's billing name.
	 * @param array<int, string> $pool    player_id => post_title.
	 */
	private function seed_claim_pool( int $user_id, string $name, array $pool ): void {
		blueline_core_test_seed_claim_pool( $user_id, $name, $pool );
	}

	/**
	 * Test case.
	 */
	public function test_normalize_strips_case_accents_and_punctuation(): void {
		$this->assertSame( 'jean luc picard', blueline_normalize_name( 'Jean-Luc  PICARD' ) );
		$this->assertSame( 'renee cote', blueline_normalize_name( 'Renée Côté' ) );
		$this->assertSame( 'oconnor', blueline_normalize_name( "O'Connor" ) );
	}

	/**
	 * An accent is dropped, never turned into a separator: iconv(//TRANSLIT) made "Müller" into "m uller".
	 */
	public function test_accented_letters_never_split_a_name_into_two_tokens(): void {
		$this->assertSame( 'muller', blueline_normalize_name( 'Müller' ) );
		$this->assertSame( 'cote', blueline_normalize_name( 'Côté' ) );
		$this->assertSame( 'oystein lukasz', blueline_normalize_name( 'Øystein Łukasz' ) );
		$this->assertSame( array( 'jose', 'muller' ), blueline_name_tokens( 'José Müller' ) );
	}

	/**
	 * A player entered with accents and an account typed without them (or the reverse) are the same person.
	 */
	public function test_an_accented_name_matches_its_plain_spelling(): void {
		$this->assertSame( 1.0, blueline_name_match_score( 'José Müller', 'Jose Muller' ) );
		$this->assertSame( 1.0, blueline_name_match_score( 'Renée Côté', 'Renee Cote' ) );
		$this->assertTrue( blueline_name_pair_is_specific_enough( 'José Müller', 'Jose Muller' ), 'Accents must not count as extra tokens in the padding gate.' );
	}

	/**
	 * No locale-dependent transliteration may return: its result changes with the server's LANG,
	 * which unit tests (run in whatever locale the developer has) cannot see.
	 */
	public function test_name_matching_does_not_use_locale_dependent_iconv(): void {
		$source = (string) file_get_contents( __DIR__ . '/../includes/player-link/name-match.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local plugin file in a unit test.

		$this->assertDoesNotMatchRegularExpression( '/\biconv\s*\(/', $source );
	}

	/**
	 * Test case.
	 */
	public function test_identical_names_score_one(): void {
		$this->assertSame( 1.0, blueline_name_match_score( 'Cody Lusk', 'cody lusk' ) );
	}

	/**
	 * Test case.
	 */
	public function test_unrelated_names_score_low(): void {
		$this->assertLessThan( 0.5, blueline_name_match_score( 'Cody Lusk', 'Wayne Gretzky' ) );
	}

	/**
	 * Test case.
	 */
	public function test_middle_name_still_matches_well(): void {
		$this->assertGreaterThanOrEqual( 0.8, blueline_name_match_score( 'Cody James Lusk', 'Cody Lusk' ) );
	}

	/**
	 * Test case.
	 */
	public function test_reversed_order_still_matches(): void {
		$this->assertGreaterThanOrEqual( 0.8, blueline_name_match_score( 'Lusk Cody', 'Cody Lusk' ) );
	}

	/**
	 * Test case.
	 */
	public function test_empty_input_scores_zero(): void {
		$this->assertSame( 0.0, blueline_name_match_score( '', 'Cody Lusk' ) );
	}

	/**
	 * Test case.
	 */
	public function test_claim_pool_current_term_at_or_above_ratio_is_not_sparse(): void {
		// 90/524 was the real, sparse case that motivated this function --
		// half that gap closed (262/524 = 50%) is the boundary, not sparse.
		$this->assertFalse( blueline_is_claim_pool_sparse( 262, 524 ) );
	}

	/**
	 * Test case.
	 */
	public function test_claim_pool_current_term_below_ratio_is_sparse(): void {
		// The real staging case (90/524 =~ 17%) this function was added for.
		$this->assertTrue( blueline_is_claim_pool_sparse( 90, 524 ) );
	}

	/**
	 * Test case.
	 */
	public function test_claim_pool_zero_current_is_sparse_even_with_no_previous(): void {
		$this->assertTrue( blueline_is_claim_pool_sparse( 0, 0 ) );
	}

	/**
	 * Test case.
	 */
	public function test_claim_pool_no_previous_term_takes_current_at_face_value(): void {
		// Nothing to compare against (e.g. the very first season ever
		// tracked) -- a non-empty current term is not "sparse" by definition
		// here; there is no larger prior roster it could be falling short of.
		$this->assertFalse( blueline_is_claim_pool_sparse( 5, 0 ) );
	}

	/**
	 * Test case.
	 */
	public function test_claim_pool_equal_rosters_are_not_sparse(): void {
		$this->assertFalse( blueline_is_claim_pool_sparse( 524, 524 ) );
	}

	/**
	 * Test case.
	 */
	public function test_season_slug_session_letter_recognises_winter_and_summer(): void {
		$this->assertSame( 'w', blueline_season_slug_session_letter( 'w2026-27' ) );
		$this->assertSame( 's', blueline_season_slug_session_letter( 's2026' ) );
		$this->assertSame( 'w', blueline_season_slug_session_letter( 'W2025-26' ) ); // Case-insensitive.
	}

	/**
	 * Test case.
	 */
	public function test_season_slug_session_letter_rejects_year_first_slug(): void {
		// A "2026-winter" convention must not be classified by its bare first character
		// (session "2"), which would silently re-merge Winter and Summer.
		$this->assertNull( blueline_season_slug_session_letter( '2026-winter' ) );
	}

	/**
	 * Test case.
	 */
	public function test_resolve_pool_normal_current_term_selection(): void {
		// Not sparse (300/524 > the 0.5 ratio) -- current term alone is
		// representative enough on its own, no fallback needed.
		$terms  = array(
			array(
				'term_id' => 674,
				'slug'    => 'w2026-27',
			),
			array(
				'term_id' => 654,
				'slug'    => 'w2025-26',
			),
		);
		$counts = array(
			674 => 300,
			654 => 524,
		);

		$result = blueline_resolve_claim_pool_term_ids(
			$terms,
			static function ( $term_id ) use ( $counts ) {
				return $counts[ $term_id ] ?? 0;
			}
		);

		$this->assertSame( array( 674 ), $result );
	}

	/**
	 * Test case.
	 */
	public function test_resolve_pool_sparse_fallback_is_additive_and_stays_same_session(): void {
		// The real staging shape: newest (674, Winter) is sparse against its
		// own previous Winter term (654), but a Summer term (666) sits
		// between them by term_id. The result must be BOTH Winter terms
		// (additive, so a first-timer tagged only with 674 is still
		// reachable) and must NEVER include 666, however large its count.
		$terms  = array(
			array(
				'term_id' => 674,
				'slug'    => 'w2026-27',
			),
			array(
				'term_id' => 666,
				'slug'    => 's2026',
			),
			array(
				'term_id' => 654,
				'slug'    => 'w2025-26',
			),
		);
		$counts = array(
			674 => 90,
			666 => 9999,
			654 => 524,
		);

		$result = blueline_resolve_claim_pool_term_ids(
			$terms,
			static function ( $term_id ) use ( $counts ) {
				return $counts[ $term_id ] ?? 0;
			}
		);

		$this->assertSame( array( 674, 654 ), $result );
		$this->assertNotContains( 666, $result );
	}

	/**
	 * Test case.
	 */
	public function test_resolve_pool_nonconforming_anchor_returns_empty(): void {
		// The newest term's own slug can't be classified into a session --
		// no reliable anchor to scope by, so this must not guess by shifting
		// to whatever comes next; it degrades to an empty pool instead.
		$terms = array(
			array(
				'term_id' => 999,
				'slug'    => '2026-winter',
			),
			array(
				'term_id' => 654,
				'slug'    => 'w2025-26',
			),
		);

		$result = blueline_resolve_claim_pool_term_ids(
			$terms,
			static function () {
				return 999999; // Even a huge count must not rescue an unclassifiable anchor.
			}
		);

		$this->assertSame( array(), $result );
	}

	/**
	 * Test case.
	 */
	public function test_resolve_pool_skips_nonconforming_non_anchor_term(): void {
		// A non-conforming term that is NOT the anchor is simply excluded --
		// never guessed into the current session, however large its count.
		$terms  = array(
			array(
				'term_id' => 674,
				'slug'    => 'w2026-27',
			),
			array(
				'term_id' => 999,
				'slug'    => '2026-winter',
			),
			array(
				'term_id' => 654,
				'slug'    => 'w2025-26',
			),
		);
		$counts = array(
			674 => 90,
			999 => 9999,
			654 => 524,
		);

		$result = blueline_resolve_claim_pool_term_ids(
			$terms,
			static function ( $term_id ) use ( $counts ) {
				return $counts[ $term_id ] ?? 0;
			}
		);

		$this->assertSame( array( 674, 654 ), $result );
	}

	/**
	 * Test case.
	 */
	public function test_resolve_pool_no_members_anywhere_returns_current_alone(): void {
		// Documented failure mode: nothing in this session has any members
		// at all -- return the (empty) current term rather than nothing,
		// the honest answer, not a crash.
		$terms = array(
			array(
				'term_id' => 674,
				'slug'    => 'w2026-27',
			),
			array(
				'term_id' => 654,
				'slug'    => 'w2025-26',
			),
		);

		$result = blueline_resolve_claim_pool_term_ids(
			$terms,
			static function () {
				return 0;
			}
		);

		$this->assertSame( array( 674 ), $result );
	}

	/**
	 * Test case.
	 */
	public function test_resolve_pool_single_term_with_no_previous_is_not_sparse(): void {
		$terms = array(
			array(
				'term_id' => 100,
				'slug'    => 'w2020-21',
			),
		);

		$result = blueline_resolve_claim_pool_term_ids(
			$terms,
			static function () {
				return 5;
			}
		);

		$this->assertSame( array( 100 ), $result );
	}

	/**
	 * Test case.
	 */
	public function test_resolve_pool_empty_terms_returns_empty(): void {
		$this->assertSame(
			array(),
			blueline_resolve_claim_pool_term_ids(
				array(),
				static function () {
					return 0;
				}
			)
		);
	}

	// -----------------------------------------------------------------------
	// Candidate gate -- the single-token identity-squatting hole.
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_matcher_still_scores_a_subset_name_at_one(): void {
		// NOT a bug report -- a pin. blueline_name_match_score()'s formula is
		// plan-mandated and deliberately unchanged: it divides by the SMALLER
		// token set so "Cody James Lusk" still matches "Cody Lusk". The
		// consequence is that any strict subset scores exactly 1.0, which is
		// why the fix for the identity-squatting hole lives in the candidate
		// GATE below, not in this function. If this assertion ever starts
		// failing, someone changed the matcher, and the six verbatim tests
		// above are no longer describing the shipped behaviour.
		$this->assertSame( 1.0, blueline_name_match_score( 'Matthew', 'Matthew Zielinski' ) );
		$this->assertSame( 1.0, blueline_name_match_score( 'Smith', 'John Smith' ) );
	}

	/**
	 * Test case.
	 */
	public function test_single_token_name_is_never_specific_enough(): void {
		$this->assertFalse( blueline_name_pair_is_specific_enough( 'Matthew', 'Matthew Zielinski' ) );
		$this->assertFalse( blueline_name_pair_is_specific_enough( 'Matthew Zielinski', 'Matthew' ) );
		$this->assertFalse( blueline_name_pair_is_specific_enough( 'Smith', 'John Smith' ) );
		// A repeated token is still ONE distinct token -- "Smith Smith" must
		// not buy its way past the gate by padding the raw token list.
		$this->assertFalse( blueline_name_pair_is_specific_enough( 'Smith Smith', 'John Smith' ) );
		// Punctuation-only / empty names are not names.
		$this->assertFalse( blueline_name_pair_is_specific_enough( '', 'John Smith' ) );
		$this->assertFalse( blueline_name_pair_is_specific_enough( '---', 'John Smith' ) );
	}

	/**
	 * Test case.
	 */
	public function test_two_token_names_are_specific_enough(): void {
		$this->assertTrue( blueline_name_pair_is_specific_enough( 'Cody Lusk', 'Cody Lusk' ) );
		$this->assertTrue( blueline_name_pair_is_specific_enough( 'Cody James Lusk', 'Cody Lusk' ) );
		$this->assertTrue( blueline_name_pair_is_specific_enough( 'Lusk Cody', 'Cody Lusk' ) );
	}

	/**
	 * Test case.
	 */
	public function test_single_token_account_name_produces_no_candidates(): void {
		// The attack, end to end at the gate: a user sets billing_last_name to
		// '' and billing_first_name to a common given name. Every one of these
		// players scores a perfect 1.0 against "Matthew" -- and none may be
		// offered.
		$candidates = blueline_score_player_candidates(
			'Matthew',
			array(
				101 => 'Matthew Zielinski',
				102 => 'Matthew Brown',
				103 => 'Matthew',
			)
		);

		$this->assertSame( array(), $candidates );
	}

	/**
	 * Test case.
	 */
	public function test_single_token_player_title_produces_no_candidates(): void {
		// The gate is symmetric: a player post titled with one token is just
		// as unidentifying as an account named with one, and a full-named
		// account must not be offered it either.
		$this->assertSame(
			array(),
			blueline_score_player_candidates( 'Cody Lusk', array( 101 => 'Cody' ) )
		);
	}

	/**
	 * Test case.
	 */
	public function test_genuine_two_token_match_still_produces_a_candidate(): void {
		$candidates = blueline_score_player_candidates(
			'Cody James Lusk',
			array(
				101 => 'Wayne Gretzky',
				102 => 'Cody Lusk',
				103 => '',
			)
		);

		$this->assertCount( 1, $candidates );
		$this->assertSame( 102, $candidates[0]['player_id'] );
		$this->assertSame( 'Cody Lusk', $candidates[0]['name'] );
		$this->assertGreaterThanOrEqual( BLUELINE_MATCH_THRESHOLD, $candidates[0]['score'] );
	}

	/**
	 * Test case.
	 */
	public function test_candidates_are_sorted_best_first(): void {
		// Long compound names, because they are the only way to land a score
		// strictly BETWEEN the threshold and 1.0: the score's denominator is
		// the smaller token set, so a 7-token name missing one token scores
		// 6/7 = 0.8571. Shorter near-misses fall straight through 0.85 to 0.5.
		// The weaker match is listed FIRST in the input, so a sort that failed
		// to run (or ran backwards) would be caught rather than accidentally
		// agreeing with insertion order.
		$candidates = blueline_score_player_candidates(
			'Ana Maria Jose Luis Carmen Rosa Diaz',
			array(
				101 => 'Ana Maria Jose Luis Carmen Rosa Silva',
				102 => 'Ana Maria Jose Luis Carmen Rosa Diaz',
			)
		);

		$this->assertCount( 2, $candidates );
		$this->assertSame( 102, $candidates[0]['player_id'] );
		$this->assertSame( 101, $candidates[1]['player_id'] );
		$this->assertGreaterThan( $candidates[1]['score'], $candidates[0]['score'] );
	}

	/**
	 * Test case.
	 */
	public function test_find_player_candidates_short_circuits_on_a_single_token_name(): void {
		// The same gate, reached through the real entry point the claim card
		// and the backfill script both call. A single-token billing name must
		// return an empty list WITHOUT ever querying the candidate pool -- the
		// stub get_posts() would otherwise be reached and this suite has no
		// $wpdb to fetch titles with, so an exception here would itself be the
		// failure signal.
		$state                    = &blueline_test_state();
		$state['post_types']      = array( 'sp_player' );
		$state['current_user_id'] = 5;
		$state['users'][5]        = (object) array( 'display_name' => 'Matthew' );
		$state['user_meta'][5]    = array(
			'billing_first_name' => 'Matthew',
			'billing_last_name'  => '',
		);

		$this->assertSame( array(), blueline_find_player_candidates( 5 ) );
	}

	/**
	 * Test case.
	 */
	public function test_find_player_candidates_is_empty_without_a_name_at_all(): void {
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_player' );
		$state['users'][6]   = (object) array( 'display_name' => '' );

		$this->assertSame( array(), blueline_find_player_candidates( 6 ) );
	}

	// -----------------------------------------------------------------------
	// blueline_link_player_to_user() -- the three invariants that are the
	// entire safety argument for the identity write, plus the success path.
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_link_refuses_when_acting_for_someone_else_without_edit_users(): void {
		$state                    = &blueline_test_state();
		$state['post_types']      = array( 'sp_player' );
		$state['current_user_id'] = 99; // Not the target user, and holds no caps.

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ), 'a refused link must write nothing' );
	}

	/**
	 * Test case.
	 */
	public function test_link_refuses_a_player_already_claimed_by_another_user(): void {
		$state                    = &blueline_test_state();
		$state['post_types']      = array( 'sp_player' );
		$state['current_user_id'] = 5;
		$state['post_meta'][100]  = array( BLUELINE_PLAYER_USER_META => 7 );
		$this->seed_player_post( 100 );

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'already_linked', $result->get_error_code() );
		$this->assertSame( 7, get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ), 'the existing owner must not be repointed' );
	}

	/**
	 * Test case.
	 */
	public function test_link_refuses_a_second_player_for_an_already_linked_user(): void {
		$state                    = &blueline_test_state();
		$state['post_types']      = array( 'sp_player' );
		$state['current_user_id'] = 5;
		$state['post_meta'][200]  = array( BLUELINE_PLAYER_USER_META => 5 ); // User 5's existing player.
		$this->seed_player_post( 100 );
		$this->seed_player_post( 200 );

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_already_linked', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ), 'the second player must stay unclaimed' );
	}

	/**
	 * Test case.
	 */
	public function test_link_writes_the_meta_and_busts_the_cache_for_the_user_themselves(): void {
		$this->seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );

		// Warm the request-scoped cache with the pre-link answer, so the
		// assertion below proves blueline_forget_linked_player_cache() ran --
		// a stale cache here is the exact bug that accessor exists to prevent.
		$this->assertNull( blueline_get_linked_player_id( 5 ) );

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertTrue( $result );
		$this->assertSame( 5, get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ) );
		$this->assertSame( 100, blueline_get_linked_player_id( 5 ) );
	}

	/**
	 * Test case.
	 */
	public function test_link_is_allowed_for_an_admin_acting_on_another_users_behalf(): void {
		// The backfill script's write path: a WP-CLI run under --user=<admin>.
		$state                    = &blueline_test_state();
		$state['post_types']      = array( 'sp_player' );
		$state['current_user_id'] = 9;
		$state['caps']            = array( 'edit_users' => true );
		$this->seed_player_post( 100 );

		$this->assertTrue( blueline_link_player_to_user( 100, 5 ) );
		$this->assertSame( 5, get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ) );
	}

	/**
	 * Test case.
	 */
	public function test_relinking_the_same_player_to_the_same_user_is_a_no_op_success(): void {
		// Idempotence: the backfill script is documented as safe to re-run,
		// and a double-submitted claim form must not error either.
		$state                    = &blueline_test_state();
		$state['post_types']      = array( 'sp_player' );
		$state['current_user_id'] = 5;
		$state['post_meta'][100]  = array( BLUELINE_PLAYER_USER_META => 5 );
		$this->seed_player_post( 100 );

		$this->assertTrue( blueline_link_player_to_user( 100, 5 ) );
		$this->assertSame( 100, blueline_get_linked_player_id( 5 ) );
	}

	// -----------------------------------------------------------------------
	// Name-claim eligibility and verified ownership.
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_a_plain_account_with_no_owned_player_may_claim_by_name(): void {
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_player' );
		$state['users'][5]   = (object) array( 'roles' => array( 'customer' ) );

		$this->assertTrue( blueline_user_can_claim_by_name( 5 ) );
	}

	/**
	 * Test case.
	 */
	public function test_a_player_role_account_may_not_claim_by_name(): void {
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_player' );
		$state['users'][5]   = (object) array( 'roles' => array( 'customer', 'sp_player' ) );

		$this->assertFalse( blueline_user_can_claim_by_name( 5 ) );
	}

	/**
	 * Test case.
	 */
	public function test_an_owner_of_any_player_record_may_not_claim_by_name(): void {
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_player' );
		$state['users'][5]   = (object) array( 'roles' => array( 'customer' ) );
		$state['posts'][300] = array(
			'type'   => 'sp_player',
			'author' => 5,
		);

		$this->assertFalse( blueline_user_can_claim_by_name( 5 ) );
	}

	/**
	 * Test case.
	 */
	public function test_ineligible_account_gets_no_candidates_and_cannot_link(): void {
		$state                    = &blueline_test_state();
		$state['post_types']      = array( 'sp_player' );
		$state['current_user_id'] = 5;
		$state['users'][5]        = (object) array(
			'roles'        => array( 'sp_player' ),
			'display_name' => 'Matthew Smith',
		);
		$this->seed_player_post( 100 );

		$this->assertSame( array(), blueline_find_player_candidates( 5 ) );

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_eligible', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ) );
	}

	/**
	 * Test case.
	 */
	public function test_admin_may_still_link_a_player_role_account(): void {
		$state                    = &blueline_test_state();
		$state['post_types']      = array( 'sp_player' );
		$state['current_user_id'] = 9;
		$state['caps']            = array( 'edit_users' => true );
		$state['users'][5]        = (object) array( 'roles' => array( 'sp_player' ) );
		$this->seed_player_post( 100 );

		$this->assertTrue( blueline_link_player_to_user( 100, 5 ) );
	}

	/**
	 * Test case.
	 */
	public function test_verified_owner_needs_both_player_role_and_post_author(): void {
		$state                   = &blueline_test_state();
		$state['post_types']     = array( 'sp_player' );
		$state['post_meta'][100] = array( BLUELINE_PLAYER_USER_META => 5 );
		$state['posts'][100]     = array(
			'type'   => 'sp_player',
			'author' => 5,
		);

		$state['users'][5] = (object) array( 'roles' => array( 'sp_player' ) );
		$this->assertTrue( blueline_user_is_verified_player_owner( 5 ) );

		$state['users'][5] = (object) array( 'roles' => array( 'customer' ) );
		$this->assertFalse( blueline_user_is_verified_player_owner( 5 ), 'Owner without the Player role is not verified.' );

		$state['users'][5]             = (object) array( 'roles' => array( 'sp_player' ) );
		$state['posts'][100]['author'] = 17;
		$this->assertFalse( blueline_user_is_verified_player_owner( 5 ), 'A name-claimed link (sp_user only) is not verified.' );
	}

	/**
	 * Test case.
	 */
	public function test_unlinked_account_is_never_a_verified_owner(): void {
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_player' );
		$state['users'][5]   = (object) array( 'roles' => array( 'sp_player' ) );

		$this->assertFalse( blueline_user_is_verified_player_owner( 5 ) );
	}

	// -----------------------------------------------------------------------
	// The name gate bounds BOTH sides, so a padded account name cannot
	// borrow every two-token player whose tokens it happens to contain.
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_padded_account_name_does_not_match_players_it_merely_contains(): void {
		$padded = 'John Mike Dave Smith Brown Jones';

		// The matcher alone still scores each of these a perfect 1.0 (pinned above) ...
		$this->assertSame( 1.0, blueline_name_match_score( $padded, 'John Smith' ) );

		// ... so the gate is what stops them.
		$this->assertSame(
			array(),
			blueline_score_player_candidates(
				$padded,
				array(
					101 => 'John Smith',
					102 => 'Mike Brown',
					103 => 'Dave Jones',
					104 => 'John Brown',
					105 => 'Jane Doe',
				)
			)
		);
	}

	/**
	 * Test case.
	 */
	public function test_gate_bounds_the_player_side_too(): void {
		// A short account name against a player title padded with extra tokens.
		$this->assertFalse( blueline_name_pair_is_specific_enough( 'John Smith', 'John Mike Dave Smith Brown Jones' ) );
		$this->assertFalse( blueline_name_pair_is_specific_enough( 'John Smith', 'John Michael Robert Smith' ) );
	}

	/**
	 * Test case.
	 */
	public function test_gate_still_allows_reordering_and_one_middle_name(): void {
		$this->assertTrue( blueline_name_pair_is_specific_enough( 'Cody Lusk', 'Lusk Cody' ) );
		$this->assertTrue( blueline_name_pair_is_specific_enough( 'John Michael Smith', 'John Smith' ) );
		$this->assertTrue( blueline_name_pair_is_specific_enough( 'John Smith', 'John Michael Smith' ) );

		$candidates = blueline_score_player_candidates(
			'Lusk Cody',
			array( 101 => 'Cody Lusk' )
		);
		$this->assertCount( 1, $candidates );

		$candidates = blueline_score_player_candidates(
			'John Michael Smith',
			array( 101 => 'John Smith' )
		);
		$this->assertCount( 1, $candidates );
	}

	// -----------------------------------------------------------------------
	// The linked-player lookup is deterministic and status-agnostic.
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_a_user_linked_to_an_unpublished_player_still_resolves_to_it(): void {
		$this->seed_player_post( 100, 'draft' );
		$this->seed_player_post( 101, 'private' );
		$state                   = &blueline_test_state();
		$state['post_meta'][100] = array( BLUELINE_PLAYER_USER_META => '5' );
		$state['post_meta'][101] = array( BLUELINE_PLAYER_USER_META => '6' );

		$this->assertSame( 100, blueline_get_linked_player_id( 5 ) );
		$this->assertSame( 101, blueline_get_linked_player_id( 6 ) );
	}

	/**
	 * Test case.
	 */
	public function test_with_several_linked_players_the_one_the_user_owns_wins(): void {
		$this->seed_player_post( 100, 'publish', 1 ); // Legacy name claim, newest-looking ID is irrelevant.
		$this->seed_player_post( 200, 'publish', 5 ); // The registration-created record the user owns.
		$state                   = &blueline_test_state();
		$state['post_meta'][100] = array( BLUELINE_PLAYER_USER_META => '5' );
		$state['post_meta'][200] = array( BLUELINE_PLAYER_USER_META => '5' );

		$this->assertSame( 200, blueline_get_linked_player_id( 5 ) );
	}

	/**
	 * Test case.
	 */
	public function test_with_several_linked_players_and_none_owned_the_lowest_id_wins(): void {
		$this->seed_player_post( 300 );
		$this->seed_player_post( 100 );
		$this->seed_player_post( 200 );
		$state                   = &blueline_test_state();
		$state['post_meta'][300] = array( BLUELINE_PLAYER_USER_META => '5' );
		$state['post_meta'][100] = array( BLUELINE_PLAYER_USER_META => '5' );
		$state['post_meta'][200] = array( BLUELINE_PLAYER_USER_META => '5' );

		$this->assertSame( array( 100, 200, 300 ), blueline_query_linked_player_ids( 5 ) );
		$this->assertSame( 100, blueline_get_linked_player_id( 5 ) );
	}

	/**
	 * A user linked to a draft player cannot slip a second link past the guard.
	 */
	public function test_a_draft_link_still_blocks_a_second_link(): void {
		$this->seed_claim_pool( 5, 'Cody Lusk', array( 101 => 'Cody Lusk' ) );
		$this->seed_player_post( 100, 'draft' );
		$state                   = &blueline_test_state();
		$state['post_meta'][100] = array( BLUELINE_PLAYER_USER_META => '5' );

		$result = blueline_link_player_to_user( 101, 5 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_already_linked', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// blueline_link_player_to_user() enforces the claim rules itself.
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_link_refuses_a_post_that_is_not_a_player(): void {
		$state                    = &blueline_test_state();
		$state['post_types']      = array( 'sp_player' );
		$state['current_user_id'] = 5;
		$state['posts'][100]      = array(
			'type'   => 'post',
			'status' => 'publish',
		);

		$result = blueline_link_player_to_user( 100, 5 );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid', $result->get_error_code() );
		$this->assertArrayNotHasKey( BLUELINE_PLAYER_USER_META, blueline_test_state()['post_meta'][100] ?? array() );

		$unknown = blueline_link_player_to_user( 999, 5 );
		$this->assertWPError( $unknown );
		$this->assertSame( 'invalid', $unknown->get_error_code(), 'a post that does not exist is not a player' );
	}

	/**
	 * Test case.
	 */
	public function test_link_refuses_an_unpublished_player_for_a_non_admin_but_not_for_an_admin(): void {
		$this->seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$this->seed_player_post( 100, 'draft' );

		$result = blueline_link_player_to_user( 100, 5 );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid', $result->get_error_code() );

		$state                    = &blueline_test_state();
		$state['current_user_id'] = 9;
		$state['caps']            = array( 'edit_users' => true );

		$this->assertTrue( blueline_link_player_to_user( 100, 5 ), 'the league may link a draft record' );
	}

	/**
	 * Test case.
	 */
	public function test_link_refuses_a_player_outside_the_users_candidate_list(): void {
		$this->seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$this->seed_player_post( 101 ); // A real, published, unlinked player, but not a name match.

		$result = blueline_link_player_to_user( 101, 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( 101, BLUELINE_PLAYER_USER_META, true ) );
	}

	/**
	 * Test case.
	 */
	public function test_link_refuses_a_candidate_whose_name_does_not_clear_the_gate(): void {
		// Even if the pool offers the player, a padded account name has no candidates.
		$this->seed_claim_pool( 5, 'John Mike Dave Smith Brown Jones', array( 100 => 'John Smith' ) );

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ) );
	}

	// -----------------------------------------------------------------------
	// The write is conditional and the invariant is re-checked after it.
	// -----------------------------------------------------------------------

	/**
	 * Another account links the same player between this call's read and its write.
	 */
	public function test_a_concurrent_claim_of_the_same_player_cannot_produce_two_owners(): void {
		$this->seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );

		$GLOBALS['bl_core_test_before_add_post_meta'] = static function ( $post_id, $key ) {
			add_post_meta( $post_id, $key, 7, true ); // The other request wins the race.
		};

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'already_linked', $result->get_error_code() );
		$this->assertSame( array( 7 ), get_post_meta( 100, BLUELINE_PLAYER_USER_META, false ), 'exactly one row, the winner\'s' );
		$this->assertNull( blueline_get_linked_player_id( 5 ) );
	}

	/**
	 * Another writer replaces the row between this call's write and its re-check.
	 */
	public function test_a_foreign_owner_after_the_write_is_reported_and_never_deleted(): void {
		$this->seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );

		$GLOBALS['bl_core_test_after_add_post_meta'] = static function ( $post_id, $key ) {
			$state                                  = &blueline_test_state();
			$state['post_meta'][ $post_id ][ $key ] = 7;
		};

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'already_linked', $result->get_error_code() );
		$this->assertSame( array( 7 ), get_post_meta( 100, BLUELINE_PLAYER_USER_META, false ), 'the other writer\'s row survives' );
		$this->assertNull( blueline_get_linked_player_id( 5 ) );
	}

	/**
	 * Several sp_user rows on one player (the shape a lost race leaves) are all seen,
	 * so a claim against them is refused rather than silently adding a third owner.
	 */
	public function test_duplicate_owner_rows_are_all_seen_and_block_a_claim(): void {
		$this->seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$state                   = &blueline_test_state();
		$state['post_meta'][100] = array( BLUELINE_PLAYER_USER_META => new Blueline_Test_Meta_Rows( array( '7', '8', '7', '0' ) ) );

		$this->assertSame( array( 7, 8 ), blueline_player_linked_user_ids( 100 ) );

		$result = blueline_link_player_to_user( 100, 5 );
		$this->assertWPError( $result );
		$this->assertSame( 'already_linked', $result->get_error_code() );
	}

	/**
	 * The user links a second player between this call's read and its write.
	 */
	public function test_a_concurrent_second_player_for_the_same_user_is_rolled_back(): void {
		$this->seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$this->seed_player_post( 200 );

		$GLOBALS['bl_core_test_after_add_post_meta'] = static function () {
			$state                   = &blueline_test_state();
			$state['post_meta'][200] = array( BLUELINE_PLAYER_USER_META => '5' );
		};

		$result = blueline_link_player_to_user( 100, 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'user_already_linked', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( 100, BLUELINE_PLAYER_USER_META, true ), 'the row this call wrote is removed' );
		$this->assertSame( 200, blueline_get_linked_player_id( 5 ), 'the earlier link is untouched' );
	}

	/**
	 * A '' / '0' sp_user row is the "unclaimed" shape; it is taken over, not treated as an owner.
	 */
	public function test_a_placeholder_sp_user_row_is_taken_over(): void {
		$this->seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$state = &blueline_test_state();
		$state['post_meta'][100][ BLUELINE_PLAYER_USER_META ] = '0';

		$this->assertTrue( blueline_link_player_to_user( 100, 5 ) );
		$this->assertSame( array( 5 ), get_post_meta( 100, BLUELINE_PLAYER_USER_META, false ) );
	}

	/**
	 * A normal claim writes exactly one row, through the unique add.
	 */
	public function test_a_normal_claim_writes_exactly_one_row(): void {
		$this->seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );

		$this->assertTrue( blueline_link_player_to_user( 100, 5 ) );
		$this->assertSame( array( 5 ), get_post_meta( 100, BLUELINE_PLAYER_USER_META, false ) );
		$this->assertFalse( add_post_meta( 100, BLUELINE_PLAYER_USER_META, 6, true ), 'a unique add cannot create a second row' );
	}

	// -----------------------------------------------------------------------
	// The claim pool's season terms and linked-player lookups are memoised per request.
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_claim_pool_season_terms_are_memoised_until_the_cache_is_flushed(): void {
		$group = blueline_player_link_cache_group();

		$this->assertSame( array(), blueline_claim_pool_season_term_ids() ); // sp_season is not registered here.
		wp_cache_get( 'claim_pool_season_term_ids', $group, false, $found );
		$this->assertTrue( $found, 'the empty answer is memoised too' );

		wp_cache_set( 'claim_pool_season_term_ids', array( 7, 8 ), $group ); // Prove the second call does not re-resolve.
		$this->assertSame( array( 7, 8 ), blueline_claim_pool_season_term_ids() );

		wp_cache_flush();

		wp_cache_get( 'claim_pool_season_term_ids', $group, false, $found );
		$this->assertFalse( $found );
		$this->assertSame( array(), blueline_claim_pool_season_term_ids() );
	}

	/**
	 * The module's memos live in a non-persistent cache group, so a persistent object cache can
	 * never serve a stale link or pool across requests.
	 */
	public function test_request_memos_use_a_non_persistent_cache_group(): void {
		$group = blueline_player_link_cache_group();

		$this->assertContains( $group, $GLOBALS['bl_core_test_non_persistent_groups'] );
	}

	/**
	 * Linking busts the cached "no link" answer, and the next lookup re-queries.
	 */
	public function test_linked_player_lookup_is_memoised_and_forgotten(): void {
		$this->seed_player_post( 100 );
		$state                   = &blueline_test_state();
		$state['post_meta'][100] = array( BLUELINE_PLAYER_USER_META => '5' );

		$this->assertSame( 100, blueline_get_linked_player_id( 5 ) );

		unset( $state['post_meta'][100] ); // The memo, not the store, answers now.
		$this->assertSame( 100, blueline_get_linked_player_id( 5 ) );

		blueline_forget_linked_player_cache( 5 );

		$this->assertNull( blueline_get_linked_player_id( 5 ) );
		$this->assertNull( blueline_get_linked_player_id( 5 ), 'a null answer is memoised too' );
	}
}
