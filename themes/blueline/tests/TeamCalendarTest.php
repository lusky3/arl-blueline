<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Unit tests for the team calendar subscribe URLs.
 */
final class TeamCalendarTest extends TestCase {

	/**
	 * Reset state and register the taxonomy/post types the resolver checks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
		$state               = &blueline_test_state();
		$state['post_types'] = array( 'sp_calendar', 'sp_team' );
	}

	/**
	 * Register a calendar post pointing at a team.
	 *
	 * @param int    $id      Calendar post id.
	 * @param int    $team_id Team it belongs to.
	 * @param string $slug    Permalink slug.
	 * @param string $status  Post status.
	 * @return void
	 */
	private function calendar( int $id, int $team_id, string $slug, string $status = 'publish' ): void {
		$state                     = &blueline_test_state();
		$state['posts'][ $id ]     = array(
			'status'    => $status,
			'permalink' => 'https://example.test/calendar/' . $slug,
			'type'      => 'sp_calendar',
			'title'     => $slug,
		);
		$state['post_meta'][ $id ] = array( 'sp_team' => (string) $team_id );
	}

	/**
	 * The webcal form is the feed URL under a scheme that means "subscribe",
	 * and the Google form wraps that same webcal URL as its cid.
	 */
	public function test_builds_both_subscribe_forms_from_the_feed(): void {
		$this->calendar( 115101, 115100, 'mammoth-arl' );

		$urls = blueline_team_calendar_urls( 115100 );

		$this->assertNotNull( $urls );
		$this->assertSame( 115101, $urls['calendar_id'] );
		$this->assertSame(
			'webcal://example.test/calendar/mammoth-arl?feed=sp-ical',
			$urls['webcal']
		);
		$this->assertStringStartsWith(
			'https://calendar.google.com/calendar/render?cid=',
			$urls['google']
		);
		$this->assertStringContainsString(
			rawurlencode( 'webcal://example.test/calendar/mammoth-arl?feed=sp-ical' ),
			$urls['google']
		);
	}

	/**
	 * A draft calendar must not be offered: its feed is not public, so the
	 * subscribe link would fail silently inside someone's calendar app.
	 */
	public function test_ignores_an_unpublished_calendar(): void {
		$this->calendar( 115101, 115100, 'mammoth-arl', 'draft' );

		$this->assertNull( blueline_team_calendar_urls( 115100 ) );
	}

	/**
	 * Teams change calendars between seasons and the old ones are kept, so the
	 * newest wins.
	 */
	public function test_prefers_the_newest_calendar_for_a_team(): void {
		$this->calendar( 1500, 115100, 'mammoth-2015' );
		$this->calendar( 115101, 115100, 'mammoth-arl' );

		$urls = blueline_team_calendar_urls( 115100 );

		$this->assertSame( 115101, $urls['calendar_id'] );
	}

	/**
	 * A team with no calendar of its own gets nothing rather than another
	 * team's feed.
	 */
	public function test_returns_null_for_a_team_with_no_calendar(): void {
		$this->calendar( 115101, 115100, 'mammoth-arl' );

		$this->assertNull( blueline_team_calendar_urls( 79 ) );
	}

	/**
	 * Guards the obvious bad inputs.
	 */
	public function test_rejects_a_missing_team(): void {
		$this->assertNull( blueline_team_calendar_urls( 0 ) );
		$this->assertNull( blueline_team_calendar_urls( -5 ) );
	}

	/**
	 * The scheme swap must replace only the leading scheme.
	 */
	public function test_only_the_leading_scheme_is_rewritten(): void {
		$this->calendar( 900, 42, 'https-cup-final' );

		$urls = blueline_team_calendar_urls( 42 );

		$this->assertSame(
			'webcal://example.test/calendar/https-cup-final?feed=sp-ical',
			$urls['webcal']
		);
	}
}
