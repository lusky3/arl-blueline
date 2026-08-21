<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/cli-stubs.php'; // wp_json_encode(), used by blueline_settings_inputs_hash().
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/acknowledgements.php';
require_once __DIR__ . '/../inc/settings/validation.php';
require_once __DIR__ . '/../inc/enqueue.php'; // blueline_stylesheet_version(), which blueline_settings_inputs_hash() calls.
require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/occasions.php';

/**
 * Covers the pure window/precedence helpers and blueline_resolve_active_occasion()
 * itself, including the AA-override integration (design spec §4.5).
 *
 * Seeds `blueline_settings( 'occasions' )` via a direct update_option()
 * call with NO `_tab` -- exactly tests/SettingsLinksTest.php's own
 * precedent for "resolver-only" tests -- deliberately WITHOUT requiring
 * inc/settings/page.php, so the value is stored exactly as given, never
 * run through blueline_sanitize_occasions() (Task 1's own test file
 * already covers that pipeline).
 */
final class OccasionsResolverTest extends TestCase {

	/**
	 * Resets the fake option store and CLI-stub state before every test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * A minimal, valid occasion fixture, reused across several tests.
	 *
	 * @param array<string, mixed> $overrides Keys to override.
	 * @return array<string, mixed>
	 */
	private function occasion( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'     => 'test-occasion',
				'label'  => 'Test Occasion',
				'type'   => 'decorative',
				'window' => array(
					'start_md' => '01-01',
					'end_md'   => '12-31',
				),
				'accent' => '',
				'motif'  => 'none',
				'line'   => '',
				'mode'   => 'auto',
			),
			$overrides
		);
	}

	/* ---------------------------------------------------------- today_md */

	/**
	 * Asserts today's calendar date is computed in SITE timezone, not UTC
	 * -- a timestamp just after UTC midnight on the 2nd is still the 1st
	 * in a timezone west of UTC.
	 */
	public function test_today_md_uses_site_timezone(): void {
		$state             = &blueline_test_state();
		$state['timezone'] = 'America/Vancouver'; // UTC-8/-7.

		// 2026-03-02 00:30 UTC -- still 2026-03-01 evening in Vancouver.
		$now = ( new DateTimeImmutable( '2026-03-02T00:30:00+00:00' ) )->getTimestamp();

		$this->assertSame( '03-01', blueline_occasion_today_md( $now ) );
	}

	/* ------------------------------------------------------ window_contains */

	/**
	 * Asserts a normal (non-wrapping) window is inclusive of both bounds
	 * and excludes a date just outside either.
	 */
	public function test_window_contains_normal_range_is_inclusive(): void {
		$this->assertTrue( blueline_occasion_window_contains( '12-01', '12-26', '12-01' ) );
		$this->assertTrue( blueline_occasion_window_contains( '12-01', '12-26', '12-26' ) );
		$this->assertTrue( blueline_occasion_window_contains( '12-01', '12-26', '12-15' ) );
		$this->assertFalse( blueline_occasion_window_contains( '12-01', '12-26', '11-30' ) );
		$this->assertFalse( blueline_occasion_window_contains( '12-01', '12-26', '12-27' ) );
	}

	/**
	 * Asserts a single-day window (start === end) matches exactly that
	 * one day.
	 */
	public function test_window_contains_single_day(): void {
		$this->assertTrue( blueline_occasion_window_contains( '07-01', '07-01', '07-01' ) );
		$this->assertFalse( blueline_occasion_window_contains( '07-01', '07-01', '07-02' ) );
	}

	/**
	 * Asserts a year-boundary-crossing window (New Year's own
	 * 12-27..01-02) matches both sides of the wraparound and excludes a
	 * date in between.
	 */
	public function test_window_contains_year_boundary_wraparound(): void {
		$this->assertTrue( blueline_occasion_window_contains( '12-27', '01-02', '12-27' ) );
		$this->assertTrue( blueline_occasion_window_contains( '12-27', '01-02', '12-31' ) );
		$this->assertTrue( blueline_occasion_window_contains( '12-27', '01-02', '01-01' ) );
		$this->assertTrue( blueline_occasion_window_contains( '12-27', '01-02', '01-02' ) );
		$this->assertFalse( blueline_occasion_window_contains( '12-27', '01-02', '06-15' ) );
		$this->assertFalse( blueline_occasion_window_contains( '12-27', '01-02', '12-26' ) );
		$this->assertFalse( blueline_occasion_window_contains( '12-27', '01-02', '01-03' ) );
	}

	/* --------------------------------------------------------- compare */

	/**
	 * Asserts force_on beats auto, regardless of type or window.
	 */
	public function test_compare_force_on_beats_auto(): void {
		$forced = $this->occasion(
			array(
				'id'   => 'a',
				'mode' => 'force_on',
			)
		);
		$auto   = $this->occasion(
			array(
				'id'   => 'b',
				'mode' => 'auto',
				'type' => 'commemorative',
			)
		);

		$ranked = array( $auto, $forced );
		usort( $ranked, 'blueline_occasion_compare' );

		$this->assertSame( 'a', $ranked[0]['id'] );
	}

	/**
	 * Asserts commemorative beats decorative among occasions of the same
	 * mode tier.
	 */
	public function test_compare_commemorative_beats_decorative(): void {
		$commemorative = $this->occasion(
			array(
				'id'   => 'a',
				'type' => 'commemorative',
			)
		);
		$decorative    = $this->occasion(
			array(
				'id'   => 'b',
				'type' => 'decorative',
			)
		);

		$ranked = array( $decorative, $commemorative );
		usort( $ranked, 'blueline_occasion_compare' );

		$this->assertSame( 'a', $ranked[0]['id'] );
	}

	/**
	 * Asserts, among occasions tied on mode tier and type, the earliest
	 * start_md wins.
	 */
	public function test_compare_earliest_start_wins(): void {
		$later   = $this->occasion(
			array(
				'id'     => 'a',
				'window' => array(
					'start_md' => '12-01',
					'end_md'   => '12-26',
				),
			)
		);
		$earlier = $this->occasion(
			array(
				'id'     => 'b',
				'window' => array(
					'start_md' => '07-01',
					'end_md'   => '07-01',
				),
			)
		);

		$ranked = array( $later, $earlier );
		usort( $ranked, 'blueline_occasion_compare' );

		$this->assertSame( 'b', $ranked[0]['id'] );
	}

	/**
	 * Asserts, among occasions tied on every other criterion, id ascending
	 * is the final, deterministic tiebreak.
	 */
	public function test_compare_id_is_the_final_tiebreak(): void {
		$z = $this->occasion( array( 'id' => 'zebra' ) );
		$a = $this->occasion( array( 'id' => 'alpha' ) );

		$ranked = array( $z, $a );
		usort( $ranked, 'blueline_occasion_compare' );

		$this->assertSame( 'alpha', $ranked[0]['id'] );
	}

	/* ------------------------------------------------ resolve_active_occasion */

	/**
	 * Asserts nothing stored resolves to null.
	 */
	public function test_resolver_returns_null_when_nothing_stored(): void {
		$this->assertNull( blueline_resolve_active_occasion( time() ) );
	}

	/**
	 * Asserts an auto-mode occasion whose window does not cover today
	 * resolves to null.
	 */
	public function test_resolver_returns_null_when_no_window_matches(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'canada-day' => $this->occasion(
						array(
							'id'     => 'canada-day',
							'window' => array(
								'start_md' => '07-01',
								'end_md'   => '07-01',
							),
						)
					),
				),
			)
		);

		$now = ( new DateTimeImmutable( '2026-03-15 12:00:00', wp_timezone() ) )->getTimestamp();

		$this->assertNull( blueline_resolve_active_occasion( $now ) );
	}

	/**
	 * Asserts an auto-mode occasion whose window DOES cover today
	 * resolves, with its own accent ('' here) resolved to the real
	 * default.
	 */
	public function test_resolver_returns_a_matching_auto_occasion(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'canada-day' => $this->occasion(
						array(
							'id'     => 'canada-day',
							'window' => array(
								'start_md' => '07-01',
								'end_md'   => '07-01',
							),
						)
					),
				),
			)
		);

		$now      = ( new DateTimeImmutable( '2026-07-01 12:00:00', wp_timezone() ) )->getTimestamp();
		$resolved = blueline_resolve_active_occasion( $now );

		$this->assertNotNull( $resolved );
		$this->assertSame( 'canada-day', $resolved['id'] );
		$this->assertSame( blueline_occasion_accent_default(), $resolved['resolved_accent'] );
	}

	/**
	 * Asserts force_on activates regardless of the window.
	 */
	public function test_resolver_force_on_ignores_the_window(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'canada-day' => $this->occasion(
						array(
							'id'     => 'canada-day',
							'mode'   => 'force_on',
							'window' => array(
								'start_md' => '07-01',
								'end_md'   => '07-01',
							),
						)
					),
				),
			)
		);

		$now = ( new DateTimeImmutable( '2026-03-15 12:00:00', wp_timezone() ) )->getTimestamp();

		$this->assertSame( 'canada-day', blueline_resolve_active_occasion( $now )['id'] );
	}

	/**
	 * Asserts force_off is never eligible, even during its own window.
	 */
	public function test_resolver_force_off_is_never_eligible(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'canada-day' => $this->occasion(
						array(
							'id'     => 'canada-day',
							'mode'   => 'force_off',
							'window' => array(
								'start_md' => '07-01',
								'end_md'   => '07-01',
							),
						)
					),
				),
			)
		);

		$now = ( new DateTimeImmutable( '2026-07-01 12:00:00', wp_timezone() ) )->getTimestamp();

		$this->assertNull( blueline_resolve_active_occasion( $now ) );
	}

	/**
	 * Asserts that, of two occasions both matching today's window, the
	 * commemorative one wins -- design spec §5/§7.6's "suppresses any
	 * decorative occasion".
	 */
	public function test_resolver_commemorative_beats_decorative_when_both_match(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'decorative-one'    => $this->occasion(
						array(
							'id'     => 'decorative-one',
							'type'   => 'decorative',
							'window' => array(
								'start_md' => '01-01',
								'end_md'   => '12-31',
							),
						)
					),
					'commemorative-one' => $this->occasion(
						array(
							'id'     => 'commemorative-one',
							'type'   => 'commemorative',
							'window' => array(
								'start_md' => '01-01',
								'end_md'   => '12-31',
							),
						)
					),
				),
			)
		);

		$now = ( new DateTimeImmutable( '2026-07-01 12:00:00', wp_timezone() ) )->getTimestamp();

		$this->assertSame( 'commemorative-one', blueline_resolve_active_occasion( $now )['id'] );
	}

	/**
	 * The fail-closed case (design spec §4.5): an occasion whose accent
	 * fails contrast against BLUELINE_TOKEN_INK, with NO acknowledgement
	 * on record, must not activate.
	 */
	public function test_resolver_falls_closed_on_an_unacknowledged_failing_accent(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'failing' => $this->occasion(
						array(
							'id'     => 'failing',
							'mode'   => 'force_on',
							'accent' => '#274a63',
						)
					),
				),
			)
		);

		// Sanity-check the fixture's premise before trusting the assertion below.
		$this->assertLessThan( blueline_contrast_threshold( 'body' ), blueline_contrast_ratio( BLUELINE_TOKEN_INK, '#274a63' ) );

		$this->assertNull( blueline_resolve_active_occasion( time() ) );
	}

	/**
	 * The fail-open case (design spec §4.5): the identical failing accent,
	 * but with a live acknowledgement on record whose rule id, value, and
	 * inputs hash all match current reality -- must activate.
	 */
	public function test_resolver_falls_open_on_an_acknowledged_failing_accent(): void {
		$hash             = blueline_settings_inputs_hash();
		$acknowledgements = blueline_record_acknowledgement(
			array(),
			'occasion:failing',
			'ink-on-occasion-accent',
			'#274a63',
			blueline_contrast_ratio( BLUELINE_TOKEN_INK, '#274a63' ),
			$hash,
			1
		);

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions'           => array(
					'failing' => $this->occasion(
						array(
							'id'     => 'failing',
							'mode'   => 'force_on',
							'accent' => '#274a63',
						)
					),
				),
				'aa_acknowledgements' => $acknowledgements,
			)
		);

		$resolved = blueline_resolve_active_occasion( time() );

		$this->assertNotNull( $resolved );
		$this->assertSame( 'failing', $resolved['id'] );
		$this->assertSame( '#274a63', $resolved['resolved_accent'] );
	}

	/**
	 * Asserts an unacknowledged failing candidate is skipped, falling
	 * through to the next-best candidate, rather than the whole resolution
	 * returning null just because the FIRST candidate failed.
	 */
	public function test_resolver_falls_through_to_the_next_candidate_past_an_unacknowledged_failure(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					// Ranks first (commemorative beats decorative) but fails contrast, unacknowledged.
					'failing-commemorative' => $this->occasion(
						array(
							'id'     => 'failing-commemorative',
							'mode'   => 'force_on',
							'type'   => 'commemorative',
							'accent' => '#274a63',
						)
					),
					// Ranks second, but passes outright.
					'passing-decorative'    => $this->occasion(
						array(
							'id'   => 'passing-decorative',
							'mode' => 'force_on',
							'type' => 'decorative',
						)
					),
				),
			)
		);

		$resolved = blueline_resolve_active_occasion( time() );

		$this->assertNotNull( $resolved );
		$this->assertSame( 'passing-decorative', $resolved['id'] );
	}

	/**
	 * Asserts blueline_resolve_active_occasion()'s own source never calls
	 * blueline_occasion_presets() -- design spec §5's second ruling: the
	 * preset catalog has no bearing on what is actually live.
	 */
	public function test_resolver_source_never_reads_the_preset_catalog(): void {
		$source = file_get_contents( __DIR__ . '/../inc/occasions.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		if ( ! preg_match( '/function blueline_resolve_active_occasion\([^)]*\)\s*:\s*\?array\s*\{/', $source, $start, PREG_OFFSET_CAPTURE ) ) {
			$this->fail( 'could not locate blueline_resolve_active_occasion()\'s definition in inc/occasions.php' );
		}

		$offset = $start[0][1];
		$length = strlen( $source );
		$depth  = 0;
		$body   = '';

		for ( $i = $offset; $i < $length; $i++ ) {
			$char = $source[ $i ];

			if ( '{' === $char ) {
				++$depth;
			}
			if ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					$body = substr( $source, $offset, $i - $offset + 1 );
					break;
				}
			}
		}

		$this->assertNotSame( '', $body, 'failed to extract the function body' );
		$this->assertStringNotContainsString( 'blueline_occasion_presets', $body );
	}
}
