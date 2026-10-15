<?php
/**
 * The one formatter for a game's date, time and score (inc/template-tags.php):
 * the site's own time format ("6:45 pm", never a hard-coded "6:45pm"), the
 * year only for a game outside the current year, "Time TBD" for an unsettled
 * time, and an en dash between scores.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/account/dashboard.php';

/**
 * See this file's own docblock.
 */
final class EventDateFormatTest extends TestCase {

	/**
	 * Reset the fake WordPress state, pinning "now" to Oct 14, 2026 (site time).
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
		blueline_test_reset_options();

		$state        = &blueline_test_state();
		$state['now'] = ( new DateTimeImmutable( '2026-10-14 12:00:00', new DateTimeZone( 'America/Toronto' ) ) )->getTimestamp();
	}

	/**
	 * Register game $id starting at a site-local time.
	 *
	 * @param int    $id        Post ID.
	 * @param string $post_date Site-local start, 'Y-m-d H:i:s'.
	 * @param string $sp_status SportsPress's sp_status value.
	 */
	private function seed_game( int $id, string $post_date, string $sp_status = '' ): void {
		$state                     = &blueline_test_state();
		$state['posts'][ $id ]     = array(
			'status'    => 'publish',
			'type'      => 'sp_event',
			'post_date' => $post_date,
		);
		$state['post_meta'][ $id ] = array( 'sp_status' => $sp_status );
	}

	/**
	 * The year appears only for a game outside the current year.
	 */
	public function test_the_date_format_adds_the_year_only_for_other_years(): void {
		$this->assertSame( 'D, M j', blueline_event_date_format( 2026, 2026 ) );
		$this->assertSame( 'D, M j, Y', blueline_event_date_format( 2020, 2026 ) );
		$this->assertSame( 'D, M j, Y', blueline_event_date_format( 2027, 2026 ) );
	}

	/**
	 * A game this year reads "Fri, Oct 16 at 6:45 pm" in the site's own time
	 * format; one from an old season carries its year.
	 */
	public function test_when_reads_date_at_time_in_the_site_time_format(): void {
		update_option( 'time_format', 'g:i a' );
		$this->seed_game( 501, '2026-10-16 18:45:00' );
		$this->seed_game( 502, '2024-03-08 14:00:00' );

		$this->assertSame( 'Fri, Oct 16 at 6:45 pm', blueline_event_when( 501 ) );
		$this->assertSame( 'Fri, Mar 8, 2024 at 2:00 pm', blueline_event_when( 502 ) );
	}

	/**
	 * The time follows Settings > General, whatever it is.
	 */
	public function test_the_time_follows_the_site_setting(): void {
		update_option( 'time_format', 'H:i' );
		$this->seed_game( 501, '2026-10-16 18:45:00' );

		$this->assertSame( '18:45', blueline_event_time( 501 ) );
		$this->assertSame( 'Fri, Oct 16 at 18:45', blueline_event_when( 501 ) );
	}

	/**
	 * A game whose time is not settled never shows a made-up time, whether
	 * the caller says so or the game's own status does.
	 */
	public function test_a_tbd_game_says_time_tbd(): void {
		$this->seed_game( 501, '2026-10-16 00:00:00', 'tbd' );

		$this->assertSame( 'Fri, Oct 16 · Time TBD', blueline_event_when( 501 ) );
		$this->assertSame( 'Fri, Oct 16 · Time TBD', blueline_event_when( 501, true ) );
		$this->assertSame( 'Fri, Oct 16 · Time TBD', blueline_event_when_text( 'Fri, Oct 16', '' ) );
	}

	/**
	 * My Account and the next-game widget read through the same formatter.
	 */
	public function test_the_account_phrases_use_the_same_formatter(): void {
		update_option( 'time_format', 'g:i a' );
		$this->seed_game( 501, '2026-10-16 18:45:00' );

		$event = array(
			'event_id' => 501,
			'status'   => '',
		);

		$this->assertSame( 'Fri, Oct 16 at 6:45 pm', blueline_account_event_when( $event ) );
		$this->assertSame( 'Fri 6:45 pm', blueline_account_event_when_short( $event ) );

		$event['status'] = 'tbd';
		$this->assertSame( 'Fri, Oct 16 · Time TBD', blueline_account_event_when( $event ) );
	}

	/**
	 * The homepage hero's next-game line uses it too, not its own "6:45pm".
	 */
	public function test_the_homepage_next_game_line_uses_the_formatter(): void {
		require_once __DIR__ . '/../inc/season-state.php';
		require_once __DIR__ . '/../inc/homepage-modules.php';

		update_option( 'time_format', 'g:i a' );
		$this->seed_game( 501, '2026-10-16 18:45:00' );

		$this->assertSame( 'Next game: Fri, Oct 16 at 6:45 pm', blueline_homepage_next_event_line( 501 ) );
	}

	/**
	 * Scores are joined with an en dash.
	 */
	public function test_scores_join_with_an_en_dash(): void {
		$this->assertSame( '3–0', blueline_score_line( array( 3, 0 ) ) );
		$this->assertSame(
			'10–2',
			blueline_score_line(
				array(
					10 => '10',
					11 => '2',
				)
			)
		);
	}
}
