<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/player-link.php';

final class PlayerLinkTest extends TestCase {

	public function test_normalize_strips_case_accents_and_punctuation(): void {
		$this->assertSame( 'jean luc picard', blueline_normalize_name( "Jean-Luc  PICARD" ) );
		$this->assertSame( 'renee cote',      blueline_normalize_name( 'Renée Côté' ) );
		$this->assertSame( 'oconnor',         blueline_normalize_name( "O'Connor" ) );
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
}
