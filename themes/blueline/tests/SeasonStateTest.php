<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/season-state.php';

/**
 * Unit tests.
 */
final class SeasonStateTest extends TestCase {

	/**
	 * Builds a full signals array for blueline_decide_season_state(), with
	 * per-test overrides layered on top of an "everything off" baseline.
	 *
	 * @param array $over Signal keys to override.
	 * @return array
	 */
	private function signals( array $over = array() ): array {
		return array_merge(
			array(
				'has_purchasable_product' => false,
				'upcoming_events'         => 0,
				'days_to_next_event'      => null,
				'has_playoff_events'      => false,
				'recent_events'           => 0,
			),
			$over
		);
	}

	/**
	 * Test case.
	 */
	public function test_purchasable_product_means_registration_open(): void {
		$this->assertSame(
			'registration_open',
			blueline_decide_season_state( $this->signals( array( 'has_purchasable_product' => true ) ) )
		);
	}

	/**
	 * Test case.
	 */
	public function test_registration_open_wins_over_upcoming_events(): void {
		$this->assertSame(
			'registration_open',
			blueline_decide_season_state(
				$this->signals(
					array(
						'has_purchasable_product' => true,
						'upcoming_events'         => 12,
						'days_to_next_event'      => 3,
					)
				)
			)
		);
	}

	/**
	 * Test case.
	 */
	public function test_playoffs_detected(): void {
		$this->assertSame(
			'playoffs',
			blueline_decide_season_state(
				$this->signals(
					array(
						'upcoming_events'    => 4,
						'days_to_next_event' => 2,
						'has_playoff_events' => true,
						'recent_events'      => 30,
					)
				)
			)
		);
	}

	/**
	 * Test case.
	 */
	public function test_upcoming_far_off_with_no_recent_games_is_preseason(): void {
		$this->assertSame(
			'preseason',
			blueline_decide_season_state(
				$this->signals(
					array(
						'upcoming_events'    => 40,
						'days_to_next_event' => 21,
						'recent_events'      => 0,
					)
				)
			)
		);
	}

	/**
	 * Test case.
	 */
	public function test_games_running_is_in_season(): void {
		$this->assertSame(
			'in_season',
			blueline_decide_season_state(
				$this->signals(
					array(
						'upcoming_events'    => 40,
						'days_to_next_event' => 3,
						'recent_events'      => 15,
					)
				)
			)
		);
	}

	/**
	 * Test case.
	 */
	public function test_nothing_scheduled_is_offseason(): void {
		$this->assertSame( 'offseason', blueline_decide_season_state( $this->signals() ) );
	}
}
