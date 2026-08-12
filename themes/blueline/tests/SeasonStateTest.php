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

	/* ---------------------------------------------- P1 finding 4: split predicates */

	/**
	 * The registration-open predicate mirrors the enum's own product check
	 * exactly -- it is the same fact, just askable independently.
	 */
	public function test_registration_open_predicate_matches_has_purchasable_product(): void {
		$this->assertTrue( blueline_decide_registration_open( $this->signals( array( 'has_purchasable_product' => true ) ) ) );
		$this->assertFalse( blueline_decide_registration_open( $this->signals() ) );
	}

	/**
	 * The core of P1 finding 4: registration being open and games being
	 * played must be independently true or false of each other -- neither
	 * predicate may read the other's signal.
	 */
	public function test_registration_open_and_is_playing_are_independent(): void {
		$selling_and_playing = $this->signals(
			array(
				'has_purchasable_product' => true,
				'upcoming_events'         => 12,
				'recent_events'           => 5,
			)
		);

		$this->assertTrue( blueline_decide_registration_open( $selling_and_playing ) );
		$this->assertTrue( blueline_decide_is_playing( $selling_and_playing ) );
		// The five-state enum can only ever report one of these at a time --
		// confirming that collapse here documents exactly why a caller that
		// needs both facts (the homepage layout) must not branch on this.
		$this->assertSame( 'registration_open', blueline_decide_season_state( $selling_and_playing ) );
	}

	/**
	 * Games recently played is enough to count as "playing", even with the
	 * next game more than 14 days out.
	 */
	public function test_is_playing_true_when_games_recently_played(): void {
		$this->assertTrue(
			blueline_decide_is_playing(
				$this->signals(
					array(
						'upcoming_events'    => 5,
						'days_to_next_event' => 30,
						'recent_events'      => 3,
					)
				)
			)
		);
	}

	/**
	 * An imminent next game (<=14 days) counts as "playing" even with no
	 * games recently played yet -- covers the season's very first week.
	 */
	public function test_is_playing_true_when_next_game_is_imminent(): void {
		$this->assertTrue(
			blueline_decide_is_playing(
				$this->signals(
					array(
						'upcoming_events'    => 5,
						'days_to_next_event' => 10,
						'recent_events'      => 0,
					)
				)
			)
		);
	}

	/**
	 * Games scheduled far off with none recently played (preseason) is not
	 * "playing" -- there is no next-game question an existing player needs
	 * answered yet.
	 */
	public function test_is_playing_false_when_preseason(): void {
		$this->assertFalse(
			blueline_decide_is_playing(
				$this->signals(
					array(
						'upcoming_events'    => 20,
						'days_to_next_event' => 40,
						'recent_events'      => 0,
					)
				)
			)
		);
	}

	/**
	 * No upcoming games at all is never "playing", regardless of any other
	 * signal.
	 */
	public function test_is_playing_false_when_no_upcoming_events(): void {
		$this->assertFalse( blueline_decide_is_playing( $this->signals( array( 'recent_events' => 10 ) ) ) );
	}
}
