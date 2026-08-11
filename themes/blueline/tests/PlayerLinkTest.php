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
}
