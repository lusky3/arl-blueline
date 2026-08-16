<?php
/**
 * Covers Task 6 of the P1b-panel-completion plan: the site-wide
 * announcement banner -- its date window, its severity clamp, the site-time
 * timestamp helper both of those rest on, and the markup itself.
 *
 * The window is the part worth testing hardest. Every assertion here passes
 * an explicit `$now` rather than relying on the clock, so a test asserting
 * "this window has passed" keeps meaning that after the date it was written
 * on -- and blueline_announcement_visible()'s own `?int $now` parameter is
 * genuinely honoured rather than accepted and discarded (a whole class of
 * silently-vacuous test this plan has already been bitten by; see
 * tests/SeasonStateOverrideTest.php's own docblock for the sibling case).
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/announcement.php';

/**
 * Covers blueline_site_timestamp(), blueline_announcement_visible(),
 * blueline_announcement_severity() and blueline_render_announcement().
 */
final class AnnouncementTest extends TestCase {

	/**
	 * Reset both stores before each test: the option store (the settings the
	 * banner reads) and the fake-post store (the page an announcement links
	 * to).
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Store an announcement, layering per-test values over an "off"
	 * baseline so each test only states what it actually cares about.
	 *
	 * @param array $over Settings keys to override.
	 * @return void
	 */
	private function set_announcement( array $over = array() ): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array_merge(
				array(
					'announcement_text'     => '',
					'announcement_link'     => 0,
					'announcement_from'     => '',
					'announcement_to'       => '',
					'announcement_severity' => 'info',
				),
				$over
			)
		);
	}

	/**
	 * A timestamp is resolved in SITE time, not UTC: the stub site zone is
	 * America/Toronto (tests/bootstrap.php's wp_timezone()), so midnight on
	 * a summer date there is 04:00 UTC, four hours later than the same wall
	 * clock read as UTC would be.
	 */
	public function test_a_timestamp_is_resolved_in_site_time_not_utc(): void {
		$this->assertSame(
			(int) strtotime( '2026-08-15T04:00:00+00:00' ),
			blueline_site_timestamp( '2026-08-15 00:00:00' )
		);
	}

	/**
	 * Proves the helper actually reads wp_timezone() rather than hardcoding
	 * one zone: the same wall-clock string resolves to a different instant
	 * once the site's configured zone changes.
	 */
	public function test_a_timestamp_follows_the_sites_configured_zone(): void {
		$state             = &blueline_test_state();
		$state['timezone'] = 'UTC';

		$this->assertSame(
			(int) strtotime( '2026-08-15T00:00:00+00:00' ),
			blueline_site_timestamp( '2026-08-15 00:00:00' )
		);
	}

	/**
	 * Unparseable input is null, never a silently-wrong instant.
	 */
	public function test_an_unparseable_datetime_is_null(): void {
		$this->assertNull( blueline_site_timestamp( 'the third of never' ) );
	}

	/**
	 * The empty string is null too, and this is not incidental: PHP's own
	 * date parser reads '' as "now", so an unguarded empty bound would
	 * resolve to the current instant and quietly make every window
	 * half-closed at today's date.
	 */
	public function test_an_empty_datetime_is_null_rather_than_now(): void {
		$this->assertNull( blueline_site_timestamp( '' ) );
		$this->assertNull( blueline_site_timestamp( '   ' ) );
	}

	/**
	 * Nothing typed in the text field means no banner, whatever the rest of
	 * the fields say.
	 */
	public function test_no_text_means_no_banner(): void {
		$this->set_announcement(
			array(
				'announcement_from' => '',
				'announcement_to'   => '',
			)
		);

		$this->assertFalse( blueline_announcement_visible( strtotime( '2026-08-15' ) ) );
	}

	/**
	 * Whitespace is not text.
	 */
	public function test_whitespace_only_text_means_no_banner(): void {
		$this->set_announcement( array( 'announcement_text' => "   \n\t " ) );

		$this->assertFalse( blueline_announcement_visible( strtotime( '2026-08-15' ) ) );
	}

	/**
	 * A window that has not opened yet.
	 */
	public function test_a_window_in_the_future_is_not_yet_visible(): void {
		$this->set_announcement(
			array(
				'announcement_text' => 'Ice is out Friday',
				'announcement_from' => '2026-09-01',
				'announcement_to'   => '2026-09-30',
			)
		);

		$this->assertFalse( blueline_announcement_visible( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
	}

	/**
	 * A window that has closed.
	 */
	public function test_a_window_that_has_passed_is_no_longer_visible(): void {
		$this->set_announcement(
			array(
				'announcement_text' => 'Ice is out Friday',
				'announcement_from' => '2026-09-01',
				'announcement_to'   => '2026-09-30',
			)
		);

		$this->assertFalse( blueline_announcement_visible( strtotime( '2026-10-02T12:00:00+00:00' ) ) );
	}

	/**
	 * Inside the window.
	 */
	public function test_a_window_that_is_open_is_visible(): void {
		$this->set_announcement(
			array(
				'announcement_text' => 'Ice is out Friday',
				'announcement_from' => '2026-09-01',
				'announcement_to'   => '2026-09-30',
			)
		);

		$this->assertTrue( blueline_announcement_visible( strtotime( '2026-09-15T12:00:00+00:00' ) ) );
	}

	/**
	 * Both bounds are inclusive whole days in SITE time: an admin who typed
	 * "1 September to 30 September" means the banner is up for the whole of
	 * the 1st and the whole of the 30th where the league plays, not up to
	 * midnight UTC on either.
	 */
	public function test_both_bounds_are_inclusive_whole_site_days(): void {
		$this->set_announcement(
			array(
				'announcement_text' => 'Ice is out Friday',
				'announcement_from' => '2026-09-01',
				'announcement_to'   => '2026-09-30',
			)
		);

		$this->assertTrue(
			blueline_announcement_visible( blueline_site_timestamp( '2026-09-01 00:00:00' ) ),
			'the first moment of the opening day is inside the window'
		);
		$this->assertFalse(
			blueline_announcement_visible( blueline_site_timestamp( '2026-08-31 23:59:59' ) ),
			'the last moment of the day before is outside it'
		);
		$this->assertTrue(
			blueline_announcement_visible( blueline_site_timestamp( '2026-09-30 23:59:59' ) ),
			'the last moment of the closing day is still inside the window'
		);
		$this->assertFalse(
			blueline_announcement_visible( blueline_site_timestamp( '2026-10-01 00:00:00' ) ),
			'the first moment of the next day is outside it'
		);
	}

	/**
	 * No bounds at all means the banner stays up until an admin clears the
	 * text.
	 */
	public function test_an_open_ended_window_stays_visible(): void {
		$this->set_announcement( array( 'announcement_text' => 'Ice is out Friday' ) );

		$this->assertTrue( blueline_announcement_visible( strtotime( '2030-01-01T12:00:00+00:00' ) ) );
	}

	/**
	 * A stored bound the parser cannot make sense of -- which the `date`
	 * sanitizer refuses to store, so it can only arrive by a direct DB edit
	 * or an import -- reads as ABSENT, leaving that end of the window open,
	 * rather than hiding the banner. See blueline_announcement_visible()'s
	 * own docblock for why that direction was chosen.
	 */
	public function test_an_unparseable_stored_bound_is_treated_as_absent(): void {
		$this->set_announcement(
			array(
				'announcement_text' => 'Ice is out Friday',
				'announcement_from' => 'next tuesday-ish',
				'announcement_to'   => 'whenever',
			)
		);

		$this->assertTrue( blueline_announcement_visible( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
	}

	/**
	 * One unparseable bound leaves only that end open; the other is still
	 * enforced.
	 */
	public function test_one_unparseable_bound_leaves_the_other_enforced(): void {
		$this->set_announcement(
			array(
				'announcement_text' => 'Ice is out Friday',
				'announcement_from' => 'garbage',
				'announcement_to'   => '2026-09-30',
			)
		);

		$this->assertTrue( blueline_announcement_visible( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
		$this->assertFalse( blueline_announcement_visible( strtotime( '2026-10-02T12:00:00+00:00' ) ) );
	}

	/**
	 * Severity is clamped to the two the stylesheet actually knows about.
	 * The value reaches a class attribute, so an unrecognised one must
	 * resolve to a known modifier rather than being echoed through.
	 */
	public function test_severity_is_clamped_to_the_two_known_values(): void {
		$this->set_announcement( array( 'announcement_severity' => 'urgent' ) );
		$this->assertSame( 'urgent', blueline_announcement_severity() );

		$this->set_announcement( array( 'announcement_severity' => 'info' ) );
		$this->assertSame( 'info', blueline_announcement_severity() );
	}

	/**
	 * The clamp's actual job: an unrecognised tone ALREADY IN STORAGE
	 * resolves to a known modifier rather than being echoed into a class
	 * attribute.
	 *
	 * Seeded past the sanitizer, because since Task 7's fix round the
	 * `choices` guard means an off-list tone can no longer be stored through
	 * update_option() at all -- it is refused and the previous value kept.
	 * A version of this using set_announcement() therefore asserted against
	 * a value that was never stored and passed with the clamp deleted, which
	 * is exactly what it did for one commit. Import and direct database
	 * edits are the routes that remain.
	 *
	 * @dataProvider provide_unrecognised_severities
	 *
	 * @param string $stored The corrupt value sitting in the option row.
	 */
	#[DataProvider( 'provide_unrecognised_severities' )]
	public function test_an_unrecognised_stored_severity_falls_back_to_info( string $stored ): void {
		blueline_test_seed_option_bypassing_sanitizer(
			BLUELINE_SETTINGS_OPTION,
			array( 'announcement_severity' => $stored )
		);

		// Premise check: the corrupt value really is in storage.
		$this->assertSame( $stored, blueline_settings( 'announcement_severity' ) );

		$this->assertSame( 'info', blueline_announcement_severity() );
	}

	/**
	 * Shapes an import or a hand-edited row can plausibly leave behind.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function provide_unrecognised_severities(): array {
		return array(
			'an invented tone'       => array( 'apocalyptic' ),
			'empty'                  => array( '' ),
			'right word, wrong case' => array( 'URGENT' ),
			'stray whitespace'       => array( ' urgent ' ),
		);
	}

	/**
	 * And the save path refuses the same values outright, so the two guards
	 * cover different routes in rather than duplicating each other.
	 */
	public function test_an_unrecognised_severity_cannot_be_stored_through_the_save_path(): void {
		$this->set_announcement( array( 'announcement_severity' => 'apocalyptic' ) );

		$this->assertNotSame( 'apocalyptic', blueline_settings( 'announcement_severity' ) );
	}

	/**
	 * The drift pin between the tone dropdown and the read-time clamp: the
	 * schema's `choices` are what an admin can pick and what the sanitizer
	 * accepts, BLUELINE_ANNOUNCEMENT_SEVERITIES is what
	 * blueline_announcement_severity() will honour and what the stylesheet
	 * has a `bl-announce--{severity}` rule for. A value in one and not the
	 * other is either an unstyled banner or an unreachable tone.
	 */
	public function test_the_tone_dropdown_offers_exactly_the_clamped_severities(): void {
		$choices = blueline_settings_schema()['announcement_severity']['choices'];

		$this->assertSame(
			BLUELINE_ANNOUNCEMENT_SEVERITIES,
			array_map( 'strval', array_keys( $choices ) )
		);
	}

	/**
	 * Render the banner and hand back its markup.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		blueline_render_announcement();

		return (string) ob_get_clean();
	}

	/**
	 * An invisible banner prints nothing at all -- not an empty container.
	 */
	public function test_an_invisible_banner_prints_nothing(): void {
		$this->set_announcement();

		$this->assertSame( '', trim( $this->render() ) );
	}

	/**
	 * The banner is a `<section>`, never a `<div>`. NoticeDivGuardTest
	 * enforces this across the whole theme for its own (admin-side) reason;
	 * this asserts the front-end instance directly, since the severity
	 * modifier `bl-announce--info` contains one of that guard's five
	 * forbidden substrings.
	 */
	public function test_the_banner_is_a_section_carrying_its_severity_modifier(): void {
		$this->set_announcement( array( 'announcement_text' => 'Ice is out Friday' ) );

		$html = $this->render();

		$this->assertStringContainsString( '<section', $html );
		$this->assertStringContainsString( 'class="bl-announce bl-announce--info"', $html );
		$this->assertStringNotContainsString( '<div class="bl-announce', $html );
	}

	/**
	 * The text is escaped at the point of echo, not trusted.
	 */
	public function test_the_text_is_escaped(): void {
		$this->set_announcement( array( 'announcement_text' => 'Ice & <script>alert(1)</script>' ) );

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Ice &amp;', $html );
	}

	/**
	 * The banner carries the text's own hash, which is what makes a
	 * dismissal specific to the announcement that was dismissed: editing the
	 * text changes the hash, so the new one is not already-dismissed for
	 * anyone.
	 */
	public function test_the_banner_carries_a_hash_of_its_own_text(): void {
		$this->set_announcement( array( 'announcement_text' => 'Ice is out Friday' ) );
		$first = $this->render();

		$this->set_announcement( array( 'announcement_text' => 'Ice is back Monday' ) );
		$second = $this->render();

		$this->assertMatchesRegularExpression( '/data-bl-announce="[0-9a-f]{8}"/', $first );
		$this->assertMatchesRegularExpression( '/data-bl-announce="[0-9a-f]{8}"/', $second );

		preg_match( '/data-bl-announce="([0-9a-f]{8})"/', $first, $a );
		preg_match( '/data-bl-announce="([0-9a-f]{8})"/', $second, $b );

		$this->assertNotSame( $a[1], $b[1], 'editing the announcement must change its hash' );
	}

	/**
	 * The dismiss control is a real `<button>` (keyboard reachable, and not
	 * a link to nowhere) with an accessible name -- the glyph itself is
	 * hidden from assistive technology.
	 */
	public function test_the_dismiss_control_is_a_labelled_button(): void {
		$this->set_announcement( array( 'announcement_text' => 'Ice is out Friday' ) );

		$html = $this->render();

		$this->assertStringContainsString( '<button', $html );
		$this->assertStringContainsString( 'type="button"', $html );
		$this->assertStringContainsString( 'aria-label="Dismiss this announcement"', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}

	/**
	 * A published page chosen in the panel turns the announcement text into
	 * a link to it.
	 */
	public function test_a_published_linked_page_makes_the_text_a_link(): void {
		blueline_test_register_post( 77, 'publish', 'https://example.test/ice-out' );
		$this->set_announcement(
			array(
				'announcement_text' => 'Ice is out Friday',
				'announcement_link' => 77,
			)
		);

		$this->assertStringContainsString( 'href="https://example.test/ice-out"', $this->render() );
	}

	/**
	 * A trashed (or draft, or deleted) linked page is NOT linked to: the
	 * same trap blueline_resolve_link() exists for -- get_permalink() hands
	 * back a plausible URL for a page a visitor can no longer reach. Here
	 * the announcement simply loses its link rather than falling back to a
	 * built-in path, because an announcement has no natural destination to
	 * fall back to.
	 */
	public function test_a_trashed_linked_page_is_not_linked(): void {
		blueline_test_register_post( 78, 'trash', 'https://example.test/gone' );
		$this->set_announcement(
			array(
				'announcement_text' => 'Ice is out Friday',
				'announcement_link' => 78,
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( 'https://example.test/gone', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringContainsString( 'Ice is out Friday', $html );
	}

	/**
	 * No page chosen means no link, and specifically not a link to the site
	 * root.
	 */
	public function test_no_linked_page_means_no_link(): void {
		$this->set_announcement( array( 'announcement_text' => 'Ice is out Friday' ) );

		$this->assertStringNotContainsString( '<a ', $this->render() );
	}

	/**
	 * The banner opts into `.bl-container` on an inner element rather than
	 * being constrained itself -- the hero's own pattern, and what lets the
	 * coloured strip run full-bleed while its text still lines up with
	 * everything else on the page.
	 */
	public function test_the_banner_is_full_bleed_with_a_contained_inner(): void {
		$this->set_announcement( array( 'announcement_text' => 'Ice is out Friday' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'class="bl-container bl-announce__inner"', $html );
		$this->assertStringNotContainsString( 'class="bl-announce bl-container', $html );
	}
}
