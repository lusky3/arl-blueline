<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/player-link.php';

final class PlayerLinkTest extends TestCase {

	public function test_normalize_strips_case_accents_and_punctuation(): void {
		$this->assertSame( 'jean luc picard', blueline_normalize_name( 'Jean-Luc  PICARD' ) );
		$this->assertSame( 'renee cote', blueline_normalize_name( 'Renée Côté' ) );
		$this->assertSame( 'oconnor', blueline_normalize_name( "O'Connor" ) );
	}

	public function test_identical_names_score_one(): void {
		$this->assertSame( 1.0, blueline_name_match_score( 'Cody Lusk', 'cody lusk' ) );
	}

	public function test_unrelated_names_score_low(): void {
		$this->assertLessThan( 0.5, blueline_name_match_score( 'Cody Lusk', 'Wayne Gretzky' ) );
	}

	public function test_middle_name_still_matches_well(): void {
		$this->assertGreaterThanOrEqual( 0.8, blueline_name_match_score( 'Cody James Lusk', 'Cody Lusk' ) );
	}

	public function test_reversed_order_still_matches(): void {
		$this->assertGreaterThanOrEqual( 0.8, blueline_name_match_score( 'Lusk Cody', 'Cody Lusk' ) );
	}

	public function test_empty_input_scores_zero(): void {
		$this->assertSame( 0.0, blueline_name_match_score( '', 'Cody Lusk' ) );
	}

	public function test_claim_pool_current_term_at_or_above_ratio_is_not_sparse(): void {
		// 90/524 was the real, sparse case that motivated this function --
		// half that gap closed (262/524 = 50%) is the boundary, not sparse.
		$this->assertFalse( blueline_is_claim_pool_sparse( 262, 524 ) );
	}

	public function test_claim_pool_current_term_below_ratio_is_sparse(): void {
		// The real staging case (90/524 =~ 17%) this function was added for.
		$this->assertTrue( blueline_is_claim_pool_sparse( 90, 524 ) );
	}

	public function test_claim_pool_zero_current_is_sparse_even_with_no_previous(): void {
		$this->assertTrue( blueline_is_claim_pool_sparse( 0, 0 ) );
	}

	public function test_claim_pool_no_previous_term_takes_current_at_face_value(): void {
		// Nothing to compare against (e.g. the very first season ever
		// tracked) -- a non-empty current term is not "sparse" by definition
		// here; there is no larger prior roster it could be falling short of.
		$this->assertFalse( blueline_is_claim_pool_sparse( 5, 0 ) );
	}

	public function test_claim_pool_equal_rosters_are_not_sparse(): void {
		$this->assertFalse( blueline_is_claim_pool_sparse( 524, 524 ) );
	}

	public function test_season_slug_session_letter_recognises_winter_and_summer(): void {
		$this->assertSame( 'w', blueline_season_slug_session_letter( 'w2026-27' ) );
		$this->assertSame( 's', blueline_season_slug_session_letter( 's2026' ) );
		$this->assertSame( 'w', blueline_season_slug_session_letter( 'W2025-26' ) ); // Case-insensitive.
	}

	public function test_season_slug_session_letter_rejects_year_first_slug(): void {
		// The exact failure mode review flagged: a future "2026-winter"
		// slugging convention must not be classified by a bare first
		// character (which would read as session "2" and silently re-merge
		// Winter/Summer for every term sharing that leading digit).
		$this->assertNull( blueline_season_slug_session_letter( '2026-winter' ) );
	}

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
}
