<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/dashboard.php';

/**
 * P4 finding 6: two claim candidates sharing a name used to render as
 * identical rows with an identical "Yes, that's me" button. These tests
 * cover the pure formatting layer added to distinguish them --
 * blueline_format_candidate_detail()'s visible "Team · Season · #Number"
 * line and blueline_candidate_aria_label()'s matching accessible name --
 * without touching (or needing to stub) blueline_find_player_candidates()'s
 * actual WordPress/SportsPress data reads.
 */
final class AccountClaimDetailTest extends TestCase {

	/**
	 * Test case.
	 */
	public function test_detail_joins_all_three_segments_in_order(): void {
		$this->assertSame(
			'Cherry Pickers · W2026-27 · #14',
			blueline_format_candidate_detail(
				array(
					'team'   => 'Cherry Pickers',
					'season' => 'W2026-27',
					'number' => '14',
				)
			)
		);
	}

	/**
	 * Test case.
	 */
	public function test_detail_omits_missing_segments_without_leaving_stray_separators(): void {
		$this->assertSame(
			'Cherry Pickers · #14',
			blueline_format_candidate_detail(
				array(
					'team'   => 'Cherry Pickers',
					'season' => '',
					'number' => '14',
				)
			)
		);
	}

	/**
	 * Test case.
	 */
	public function test_detail_is_empty_when_every_segment_is_missing(): void {
		$this->assertSame(
			'',
			blueline_format_candidate_detail(
				array(
					'team'   => '',
					'season' => '',
					'number' => '',
				)
			)
		);
	}

	/**
	 * Test case.
	 */
	public function test_detail_tolerates_missing_array_keys(): void {
		// blueline_find_player_candidates() always sets all three keys, but
		// this formatter is defensive against a caller that doesn't.
		$this->assertSame( '', blueline_format_candidate_detail( array() ) );
	}

	/**
	 * Test case.
	 */
	public function test_detail_omits_the_hash_entirely_when_number_is_empty(): void {
		$detail = blueline_format_candidate_detail(
			array(
				'team'   => 'Cherry Pickers',
				'season' => 'W2026-27',
				'number' => '',
			)
		);
		$this->assertSame( 'Cherry Pickers · W2026-27', $detail );
		$this->assertStringNotContainsString( '#', $detail );
	}

	/**
	 * Test case.
	 */
	public function test_aria_label_without_detail_matches_the_original_name_only_copy(): void {
		$this->assertSame(
			'Yes, that’s me — Mike Brown',
			blueline_candidate_aria_label( array( 'name' => 'Mike Brown' ), '' )
		);
	}

	/**
	 * Test case.
	 */
	public function test_aria_label_with_detail_carries_the_same_disambiguation_as_the_visible_row(): void {
		// The whole point of P4 finding 6: a screen-reader user choosing
		// between two "Mike Brown" rows must hear the same distinguishing
		// information a sighted user sees in .bl-account-claim__detail.
		$this->assertSame(
			'Yes, that’s me — Mike Brown, Cherry Pickers · W2026-27 · #14',
			blueline_candidate_aria_label(
				array( 'name' => 'Mike Brown' ),
				'Cherry Pickers · W2026-27 · #14'
			)
		);
	}
}
