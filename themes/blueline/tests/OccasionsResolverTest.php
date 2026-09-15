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
 * precedent for "resolver-only" tests.
 *
 * That update_option() call IS still run through
 * blueline_sanitize_occasions(), and this file NOT requiring
 * inc/settings/page.php does not change that: page.php registers
 * blueline_settings_sanitize_callback() on `sanitize_option_{$option}` at
 * FILE SCOPE, 20+ other test files require page.php, and PHPUnit loads
 * every test file into one process before any test runs -- so that filter
 * is live here regardless. tests/OccasionsCronTest.php's own
 * auto_occasion() docblock records the same landmine from the other side.
 *
 * The practical consequence: a fixture blueline_sanitize_occasions() would
 * reject is not stored malformed, it is DROPPED, and the test would then be
 * asserting against an empty `occasions` array instead of the fixture it
 * meant to seed. So a test that genuinely needs invalid STORED data --
 * modelling a hand-edited row, a restored dump or a migration script,
 * which is exactly what the resolver's own defensive reads exist for --
 * writes straight into $GLOBALS['bl_test_options'], the established
 * bypass-everything pattern (tests/SettingsSchemaNoticeTest.php,
 * tests/SettingsMergeProgrammaticWriteTest.php). Every fixture written
 * here via update_option() is sanitizer-valid on purpose.
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
					// A neutral id (not 'canada-day'/'remembrance-day'): those two
					// now have their own blueline_occasion_themed_accent_default()
					// override, which this test is not exercising -- it wants the
					// GENERIC blueline_occasion_accent_default() fallback below.
					'test-occasion' => $this->occasion(
						array(
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
		$this->assertSame( 'test-occasion', $resolved['id'] );
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
	 * Asserts a force_on occasion whose stored `window` was corrupted to a
	 * non-array scalar by an out-of-band write (a hand-edited row, a
	 * restored dump) still resolves cleanly instead of feeding the scalar
	 * into blueline_occasion_compare()'s array reads unnormalized.
	 * blueline_sanitize_occasions() would never write this shape itself --
	 * see this file's own class docblock -- so the fixture bypasses it via
	 * $GLOBALS['bl_test_options'] directly.
	 */
	public function test_resolver_force_on_survives_malformed_stored_window(): void {
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array(
			'occasions' => array(
				'canada-day' => $this->occasion(
					array(
						'id'     => 'canada-day',
						'mode'   => 'force_on',
						'window' => 'not-an-array',
					)
				),
			),
		);

		$now = ( new DateTimeImmutable( '2026-03-15 12:00:00', wp_timezone() ) )->getTimestamp();

		$resolved = blueline_resolve_active_occasion( $now );

		$this->assertSame( 'canada-day', $resolved['id'] );
		$this->assertSame(
			array(
				'start_md' => '',
				'end_md'   => '',
			),
			$resolved['window']
		);
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
	 * Seeds a stored `accent` that blueline_sanitize_occasions() rejects on
	 * write, and asserts the resolver treats it as unresolvable (skip,
	 * never fatal) rather than crashing or handing garbage to contrast
	 * math. blueline_settings() does not sanitize on read
	 * (inc/settings/store.php's own docblock: a stored value can be invalid
	 * from a hand-edited row or a migration script), which is exactly why
	 * the resolver itself must guard against this.
	 *
	 * Seeded straight into the in-memory option store, not through
	 * update_option(): the write-side sanitizer is live in this process
	 * (see this class's own docblock) and would DROP this entry outright,
	 * leaving the assertion below passing against an empty `occasions`
	 * array and proving nothing about the resolver at all.
	 */
	public function test_resolver_skips_a_candidate_with_an_unsanitizable_stored_accent(): void {
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array(
			'occasions' => array(
				'bad-accent' => $this->occasion(
					array(
						'id'     => 'bad-accent',
						'mode'   => 'force_on',
						'accent' => 'not-a-colour',
					)
				),
			),
		);

		$this->assertNull( blueline_resolve_active_occasion( time() ) );
	}

	/**
	 * The same unsanitizable stored `accent` as above, but with a
	 * second, valid candidate available -- asserts the resolver falls
	 * through to it rather than the whole resolution returning null just
	 * because the first (higher-precedence) candidate's stored accent was
	 * garbage. Seeded the same bypass-everything way, for the same reason.
	 */
	public function test_resolver_falls_through_past_an_unsanitizable_stored_accent_to_the_next_candidate(): void {
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array(
			'occasions' => array(
				// Ranks first (commemorative beats decorative), but its
				// stored accent is not a real hex colour at all.
				'bad-accent-commemorative' => $this->occasion(
					array(
						'id'     => 'bad-accent-commemorative',
						'mode'   => 'force_on',
						'type'   => 'commemorative',
						'accent' => 'not-a-colour',
					)
				),
				// Ranks second, but passes outright.
				'passing-decorative'       => $this->occasion(
					array(
						'id'   => 'passing-decorative',
						'mode' => 'force_on',
						'type' => 'decorative',
					)
				),
			),
		);

		$resolved = blueline_resolve_active_occasion( time() );

		$this->assertNotNull( $resolved );
		$this->assertSame( 'passing-decorative', $resolved['id'] );
	}

	/**
	 * The window counterpart to the two `accent` tests above: a stored
	 * `start_md` that blueline_sanitize_occasions() rejects on write must
	 * make the candidate INELIGIBLE, never permanently active.
	 *
	 * Unvalidated, `start_md => ''` with `end_md => '12-31'` takes
	 * blueline_occasion_window_contains()'s non-wrapping branch (`'' <=
	 * '12-31'`) and evaluates `$today >= '' && $today <= '12-31'` -- true
	 * for EVERY possible $today, so the occasion would theme the whole site
	 * every day of the year until somebody noticed and repaired the row by
	 * hand. `occasions` is a reserved settings key rather than a schema
	 * field, so blueline_settings_repair()'s schema-field walk never
	 * revalidates it: nothing else would ever take it back out.
	 *
	 * Asserted on two dates months apart, deliberately: "not active" on one
	 * arbitrary date could be luck, "not active" on either side of the year
	 * cannot.
	 *
	 * Seeded straight into the in-memory option store for the same reason
	 * as the `accent` tests above -- the live write-side sanitizer would
	 * drop this entry, and the assertions would then pass against an empty
	 * `occasions` array whether or not the resolver guarded anything.
	 */
	public function test_resolver_skips_a_candidate_with_an_invalid_stored_window_bound(): void {
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array(
			'occasions' => array(
				'broken-window' => $this->occasion(
					array(
						'id'     => 'broken-window',
						'mode'   => 'auto',
						'window' => array(
							'start_md' => '',
							'end_md'   => '12-31',
						),
					)
				),
			),
		);

		$this->assertNull(
			blueline_resolve_active_occasion( ( new DateTimeImmutable( '2026-03-15 12:00:00', wp_timezone() ) )->getTimestamp() )
		);
		$this->assertNull(
			blueline_resolve_active_occasion( ( new DateTimeImmutable( '2026-11-02 12:00:00', wp_timezone() ) )->getTimestamp() )
		);
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

	/* ---------------------------------------- front_end_styles */

	/**
	 * Asserts no inline style is added when no occasion is active -- the
	 * static `--bl-occasion-accent: var(--bl-ice);` default in style.css
	 * already covers this case.
	 */
	public function test_front_end_styles_adds_nothing_when_no_occasion_active(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array() ) );

		blueline_occasion_front_end_styles();

		$this->assertSame( array(), blueline_test_state()['inline_styles'] );
	}

	/**
	 * Asserts an active occasion's resolved accent is added as a real
	 * `:root{--bl-occasion-accent:...}` inline override on the
	 * `blueline-tokens` handle -- the mechanism design spec §7.2's named
	 * consumers (the CTA ribbon fill, the signature band, the motif) were
	 * missing entirely before this.
	 */
	public function test_front_end_styles_adds_the_resolved_accent(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					// Empty accent resolves to blueline_occasion_accent_default()
					// -- the real style.css default, which passes contrast --
					// same fixture shape test_resolver_force_on_ignores_the_window()
					// above already relies on. A neutral id (not 'canada-day'/
					// 'remembrance-day'): those two now have their own
					// blueline_occasion_themed_accent_default() override, which
					// this test is not exercising.
					'test-occasion' => $this->occasion(
						array(
							'mode' => 'force_on',
						)
					),
				),
			)
		);

		blueline_occasion_front_end_styles();

		$this->assertSame(
			array( array( 'blueline-tokens', ':root{--bl-occasion-accent:' . blueline_occasion_accent_default() . ';}' ) ),
			blueline_test_state()['inline_styles']
		);
	}

	/* ---------------------------------------- header motif + line */

	/**
	 * Asserts nothing renders when no occasion is active.
	 */
	public function test_render_header_occasion_motif_renders_nothing_when_no_occasion_active(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array() ) );

		ob_start();
		blueline_render_header_occasion_motif();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Asserts nothing renders for an active occasion whose motif is 'none'
	 * -- the wrapper element itself must not appear empty in the markup.
	 */
	public function test_render_header_occasion_motif_renders_nothing_for_motif_none(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'canada-day' => $this->occasion(
						array(
							'id'    => 'canada-day',
							'mode'  => 'force_on',
							'motif' => 'none',
						)
					),
				),
			)
		);

		ob_start();
		blueline_render_header_occasion_motif();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Asserts the active occasion's real motif renders inside the wrapper,
	 * dispatched through the pre-existing blueline_render_occasion_motif()
	 * that had no caller anywhere in the theme before this.
	 */
	public function test_render_header_occasion_motif_renders_the_active_motif(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'canada-day' => $this->occasion(
						array(
							'id'    => 'canada-day',
							'mode'  => 'force_on',
							'motif' => 'maple-leaf',
						)
					),
				),
			)
		);

		ob_start();
		blueline_render_header_occasion_motif();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'bl-header__occasion-motif', $output );
		$this->assertStringContainsString( 'bl-occasion-motif--maple-leaf', $output );
	}

	/**
	 * Asserts blueline_active_occasion_line() returns '' with nothing
	 * active, and the stored `line` when an occasion is active.
	 */
	public function test_active_occasion_line(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array() ) );
		$this->assertSame( '', blueline_active_occasion_line() );

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'canada-day' => $this->occasion(
						array(
							'id'   => 'canada-day',
							'mode' => 'force_on',
							'line' => 'Happy Canada Day, ARL!',
						)
					),
				),
			)
		);

		$this->assertSame( 'Happy Canada Day, ARL!', blueline_active_occasion_line() );
	}
}
