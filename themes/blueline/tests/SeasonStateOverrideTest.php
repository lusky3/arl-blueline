<?php
/**
 * Covers Task 7 of the P1b-panel-completion plan: the season-state
 * break-glass -- an admin-set override of the computed season state, with a
 * mandatory expiry.
 *
 * ## The trap this file is written against
 *
 * Before this task, blueline_season_state() took NO parameters. PHP does not
 * error when a userland function is called with extra arguments -- it
 * discards them silently. So the obvious test,
 * `blueline_season_state( strtotime( '2026-08-15' ) )`, would have RUN, the
 * timestamp would have gone nowhere, and the assertion would have been made
 * against whatever the real clock produced. Green, and meaningless.
 *
 * Two of the tests below exist specifically so that cannot be true again,
 * because they fail if `$now` is accepted and then ignored:
 *
 *  - test_the_now_parameter_reaches_the_data_layer_and_bypasses_the_cache()
 *    is the strongest one. The 15-minute transient lives inside
 *    blueline_season_state_data(), not the wrapper, so "was the transient
 *    consulted?" is a direct, observable answer to "did `$now` get that far?".
 *    Seed the transient with a state the live signals could not produce, and
 *    a cached read returns it while a `$now` read must not.
 *  - test_an_override_expires_between_two_timestamps() proves the same for
 *    the override's own expiry: one stored override, two timestamps, two
 *    different answers. Neither answer is the one the real clock gives.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/sanitize.php'; // The `choices` guard the override dropdown rests on.
require_once __DIR__ . '/../inc/announcement.php'; // blueline_site_timestamp(), which the expiry is resolved through.
require_once __DIR__ . '/../inc/season-state.php';

/**
 * Covers blueline_season_state_override(), the `?int $now` threading through
 * blueline_season_state()/blueline_season_state_data(), and the persistent
 * admin notice.
 */
final class SeasonStateOverrideTest extends TestCase {

	/**
	 * Reset the option store, the transient store, the hook store and the
	 * fake-post store before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Store an override.
	 *
	 * @param string $state One of the five season states, or anything else
	 *                      to exercise the unknown-state path.
	 * @param string $until Y-m-d expiry, or '' for none.
	 * @return void
	 */
	private function set_override( string $state, string $until ): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'season_state_override'       => $state,
				'season_state_override_until' => $until,
			)
		);
	}

	/**
	 * With nothing stored, nothing is overridden -- and the computed state
	 * with every signal absent is 'offseason'.
	 */
	public function test_no_override_falls_through_to_the_computed_state(): void {
		$this->assertSame( '', blueline_season_state_override( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
		$this->assertSame( 'offseason', blueline_season_state( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
	}

	/**
	 * A valid, unexpired override replaces the computed state.
	 */
	public function test_an_override_replaces_the_computed_state(): void {
		$this->set_override( 'playoffs', '2026-12-31' );

		$this->assertSame( 'playoffs', blueline_season_state( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
	}

	/**
	 * ANTI-VACUITY TEST (expiry half). One stored override, two timestamps,
	 * two different answers -- neither of which is what the real clock would
	 * give for both. If `$now` were accepted and discarded, both calls would
	 * return the same thing and this fails.
	 */
	public function test_an_override_expires_between_two_timestamps(): void {
		$this->set_override( 'playoffs', '2026-12-31' );

		$this->assertSame(
			'playoffs',
			blueline_season_state( strtotime( '2026-12-31T12:00:00-05:00' ) ),
			'still inside the expiry day'
		);
		$this->assertSame(
			'offseason',
			blueline_season_state( strtotime( '2027-01-01T12:00:00-05:00' ) ),
			'the day after the expiry, the override is gone'
		);
	}

	/**
	 * The expiry day is inclusive and runs to the end of that day in site
	 * time -- an admin who typed "until the 31st" gets the whole 31st.
	 */
	public function test_the_expiry_day_is_inclusive_in_site_time(): void {
		$this->set_override( 'playoffs', '2026-12-31' );

		$this->assertSame(
			'playoffs',
			blueline_season_state( (int) blueline_site_timestamp( '2026-12-31 23:59:59' ) )
		);
		$this->assertSame(
			'offseason',
			blueline_season_state( (int) blueline_site_timestamp( '2027-01-01 00:00:00' ) )
		);
	}

	/**
	 * An override past its expiry is ignored rather than honoured.
	 */
	public function test_an_expired_override_is_ignored(): void {
		$this->set_override( 'playoffs', '2026-01-01' );

		$this->assertNotSame( 'playoffs', blueline_season_state( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
	}

	/**
	 * The expiry is MANDATORY. An override with no end date is the failure
	 * mode this whole design exists to prevent: nobody remembers it is set,
	 * and it silently becomes the site's permanent state.
	 */
	public function test_an_override_without_an_expiry_is_ignored(): void {
		$this->set_override( 'playoffs', '' );

		$this->assertNotSame( 'playoffs', blueline_season_state( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
	}

	/**
	 * An expiry the parser cannot make sense of is not a valid expiry, so
	 * the override is ignored. This is the OPPOSITE direction to the
	 * announcement banner's unparseable bound (which reads as absent, leaving
	 * the window open) and deliberately so: an absent expiry here means
	 * "ignore the override", which is the safe direction for a control whose
	 * whole risk is outlasting its usefulness.
	 */
	public function test_an_unparseable_expiry_is_ignored(): void {
		/*
		 * Seeded past the sanitizer. The `date` branch refuses a malformed
		 * expiry on every update_option() write, so seeding one through
		 * set_override() left `''` in storage and made this a duplicate of
		 * test_an_override_without_an_expiry_is_ignored(). While it was
		 * inert, the guard below could be inverted -- making an UNPARSEABLE
		 * EXPIRY PERMANENT, the exact failure this control's mandatory
		 * expiry exists to prevent -- with the whole suite green.
		 */
		blueline_test_seed_option_bypassing_sanitizer(
			BLUELINE_SETTINGS_OPTION,
			array(
				'season_state_override'       => 'playoffs',
				'season_state_override_until' => 'sometime next year',
			)
		);

		// Premise: the malformed expiry really is in storage, alongside a
		// state that would otherwise be honoured.
		$this->assertSame( 'playoffs', blueline_settings( 'season_state_override' ) );
		$this->assertSame( 'sometime next year', blueline_settings( 'season_state_override_until' ) );

		$this->assertSame( '', blueline_season_state_override( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
		$this->assertSame( 'offseason', blueline_season_state( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
	}

	/**
	 * And the save path refuses the same expiry outright, so the two guards
	 * cover different routes in rather than duplicating each other.
	 */
	public function test_a_malformed_expiry_cannot_be_stored_through_the_save_path(): void {
		$this->set_override( 'playoffs', 'sometime next year' );

		// The field is rejected outright and the previously-stored value
		// kept -- here, the un-set default, since setUp() starts every test
		// with an empty option store (see blueline_settings_defaults()).
		$this->assertSame( '', blueline_settings( 'season_state_override_until' ) );
	}

	/**
	 * A state outside the five known ones is ignored -- this theme never
	 * invents a sixth (the hero's own whitelist,
	 * inc/homepage-modules.php's blueline_render_hero(), says the same).
	 *
	 * Seeded past the sanitizer on purpose. An off-list value CANNOT be
	 * stored through update_option() any more -- the `choices` guard added
	 * in Task 7's fix round refuses it and keeps the previous value -- so a
	 * version of this test using set_override() asserts against a value that
	 * was never stored, and passes with the read clamp deleted. It did
	 * exactly that for one commit. `wp db import` and a direct database edit
	 * are the routes that remain, and that is what this seeds.
	 */
	public function test_an_unknown_state_already_in_storage_is_ignored(): void {
		blueline_test_seed_option_bypassing_sanitizer(
			BLUELINE_SETTINGS_OPTION,
			array(
				'season_state_override'       => 'world_cup',
				'season_state_override_until' => '2036-12-31',
			)
		);

		// Premise check: the corrupt value really is in storage, so this
		// test cannot pass merely because the seed silently failed.
		$this->assertSame( 'world_cup', blueline_settings( 'season_state_override' ) );

		$this->assertSame( '', blueline_season_state_override( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
		$this->assertSame( 'offseason', blueline_season_state( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
	}

	/**
	 * The other half of the same guarantee, and the reason the sanitizer
	 * cannot be the only defence: the save path refuses an off-list value
	 * outright, so the two guards cover different routes in rather than
	 * duplicating each other.
	 */
	public function test_an_unknown_state_cannot_be_stored_through_the_save_path_at_all(): void {
		$this->set_override( 'world_cup', '2036-12-31' );

		// The field is rejected outright and the previously-stored value
		// kept -- here, the un-set default, since setUp() starts every test
		// with an empty option store (see blueline_settings_defaults()).
		$this->assertSame(
			'',
			blueline_settings( 'season_state_override' ),
			'update_option() runs the sanitizer, so an off-list value must never reach storage'
		);
	}

	/**
	 * Every one of the five known states is actually accepted -- otherwise a
	 * typo in the whitelist would make this control silently useless for one
	 * of them, and only the 'playoffs' tests above would notice.
	 */
	public function test_every_known_state_is_accepted_as_an_override(): void {
		foreach ( BLUELINE_SEASON_STATES as $state ) {
			$this->set_override( $state, '2026-12-31' );

			$this->assertSame(
				$state,
				blueline_season_state( strtotime( '2026-08-15T12:00:00+00:00' ) ),
				"'$state' is a known season state but was not accepted as an override"
			);
		}
	}

	/**
	 * There are exactly five, and these five.
	 */
	public function test_the_known_states_are_the_five_the_rest_of_the_theme_uses(): void {
		$this->assertSame(
			array( 'registration_open', 'preseason', 'in_season', 'playoffs', 'offseason' ),
			BLUELINE_SEASON_STATES
		);
	}

	/**
	 * THE DRIFT PIN between the domain enum and the panel's dropdown. The
	 * schema's `choices` list is what an admin can pick from and what the
	 * sanitizer accepts; BLUELINE_SEASON_STATES is what
	 * blueline_season_state_override() will actually honour. A value in one
	 * and not the other is either an option that saves and then does nothing,
	 * or a state the panel cannot reach.
	 *
	 * Pinned by a test rather than by having defaults.php read the constant
	 * directly: inc/settings/defaults.php is otherwise self-contained data,
	 * and pointing it at inc/season-state.php's constant would couple the
	 * schema to the season logic's load order for no gain a red build here
	 * doesn't already provide.
	 */
	public function test_the_override_dropdown_offers_exactly_the_honoured_states(): void {
		$choices = blueline_settings_schema()['season_state_override']['choices'];

		$this->assertSame(
			'',
			array_key_first( $choices ),
			'the empty "no override" option must come first, so the default reads as no override'
		);

		$offered = array_values( array_filter( array_map( 'strval', array_keys( $choices ) ), static fn( $key ) => '' !== $key ) );

		$this->assertSame( BLUELINE_SEASON_STATES, $offered );
	}

	/**
	 * I2: a near-miss typed into the break-glass is REFUSED at save, not
	 * quietly stored and then ignored on read. The silent version of this
	 * was the worst failure mode in the feature: "Settings saved.", no
	 * change to the site, and no admin notice either, because the notice
	 * only renders while the override reads back as a real state.
	 */
	public function test_a_mistyped_override_is_refused_at_save_rather_than_silently_ignored(): void {
		$result = blueline_sanitize_field( 'playofs', blueline_settings_schema()['season_state_override'] );

		$this->assertTrue( is_wp_error( $result ), 'a typo in an emergency control must not save cleanly' );
	}

	/**
	 * And the legitimate values still save, including the empty "no
	 * override" option -- a guard that refused those would be worse than no
	 * guard.
	 */
	public function test_every_offered_override_value_saves(): void {
		$field = blueline_settings_schema()['season_state_override'];

		foreach ( array_keys( $field['choices'] ) as $choice ) {
			$this->assertSame( (string) $choice, blueline_sanitize_field( (string) $choice, $field ) );
		}
	}

	/**
	 * A developer filter still wins over an admin override. Filters are the
	 * last word by convention throughout this theme, so the override is
	 * applied BEFORE `blueline_season_state` fires, not after it.
	 */
	public function test_a_filter_still_wins_over_an_override(): void {
		$this->set_override( 'playoffs', '2026-12-31' );
		add_filter( 'blueline_season_state', static fn() => 'preseason' );

		$this->assertSame( 'preseason', blueline_season_state( strtotime( '2026-08-15T12:00:00+00:00' ) ) );
	}

	/**
	 * ANTI-VACUITY TEST (threading half), and the strongest one in this file.
	 *
	 * The 15-minute transient lives inside blueline_season_state_data(), not
	 * inside the wrapper, so whether it was consulted is a direct answer to
	 * "did `$now` reach the data layer?". A cached read hands back the seeded
	 * state; a `$now` read must recompute from live signals instead, which
	 * with no product catalogue and no sp_event post type is 'offseason'.
	 *
	 * If `$now` were dropped at blueline_season_state() -- accepted by the
	 * signature and never passed on -- the second assertion would read the
	 * transient like the first and return 'playoffs'. Confirmed by stubbing
	 * the parameter out and watching exactly that happen.
	 */
	public function test_the_now_parameter_reaches_the_data_layer_and_bypasses_the_cache(): void {
		set_transient(
			'blueline_season_state',
			array(
				'state'                => 'playoffs',
				'product_id'           => null,
				'next_event_id'        => null,
				'is_registration_open' => false,
				'is_playing'           => true,
			),
			900
		);

		$this->assertSame(
			'playoffs',
			blueline_season_state(),
			'a plain read is served from the transient'
		);
		$this->assertSame(
			'offseason',
			blueline_season_state( strtotime( '2026-08-15T12:00:00+00:00' ) ),
			'a read at an explicit timestamp must not be served a value computed at some other moment'
		);
	}

	/**
	 * The bypass runs both ways: a `$now` read must not WRITE the transient
	 * either, or one test-shaped read would poison the cache every real
	 * request reads afterwards. And the transient is never keyed by `$now`,
	 * which would be an unbounded set of cache keys.
	 */
	public function test_a_read_at_an_explicit_timestamp_writes_no_cache(): void {
		blueline_season_state_data( strtotime( '2026-08-15T12:00:00+00:00' ) );

		$this->assertFalse( get_transient( 'blueline_season_state' ) );
		$this->assertSame( array(), $GLOBALS['bl_test_transients'], 'no transient of any name may be written' );
	}

	/**
	 * The `$now` branch of the moment the queries are built from: an
	 * injected timestamp becomes the site-local `Y-m-d H:i:s` string the
	 * date_query bounds are expressed in, NOT its UTC rendering, since
	 * post_date is stored as site-local wall-clock.
	 *
	 * This covers the half of the threading the transient-bypass test cannot
	 * reach. The queries themselves stay untested here: exercising them would
	 * need a WP_Query stub written from memory against `date_query`
	 * semantics nobody in this worktree can check against core's own source,
	 * and a guessed stub is how this project's option-lifecycle stubs shipped
	 * six green-but-untrue assumptions (see tests/WpCoreContractTest.php).
	 */
	public function test_the_query_moment_is_built_from_the_injected_timestamp(): void {
		list( $mysql, $timestamp ) = blueline_season_state_moment( strtotime( '2026-08-15T04:00:00+00:00' ) );

		$this->assertSame( '2026-08-15 00:00:00', $mysql, 'the bound is site-local, not UTC' );

		/*
		 * NOT the injected instant. The returned timestamp is the site-local
		 * wall clock read back by strtotime(), matching what the null branch
		 * produces from current_time( 'mysql' ) -- see Ruling M and
		 * test_both_branches_of_the_query_moment_agree() below. An earlier
		 * version of this assertion pinned the raw instant, which is exactly
		 * the bug: it made the two branches disagree by the site's offset
		 * and called that correct.
		 */
		$this->assertSame( (int) strtotime( $mysql ), $timestamp );
	}

	/**
	 * THE BRANCH-PARITY PIN (Ruling M, Task 7 fix round). Both branches of
	 * blueline_season_state_moment() must produce the identical pair for the
	 * identical instant -- not just the same `$now_mysql`, the same
	 * `$now_ts` too.
	 *
	 * The first version of this function got that wrong: `$now_mysql` was
	 * site-local in both branches, but the `$now` branch handed back the raw
	 * Unix instant as `$now_ts` while the null branch handed back
	 * `strtotime( current_time( 'mysql' ) )` -- a site-local wall clock read
	 * as UTC. Two consumers assume the null branch's frame (the 30-day
	 * `after` bound, and the days-to-next-event subtraction), so the two
	 * branches silently disagreed by the site's offset, which is enough for
	 * `ceil( ... / DAY_IN_SECONDS ) <= 14` in blueline_decide_is_playing()
	 * to flip in_season and preseason around a boundary. Only reachable via
	 * `$now`, so nothing shipped broken -- but the docblock claimed the
	 * branches matched, and one derived bound did not.
	 *
	 * This assertion is deliberately whole-array rather than field-by-field:
	 * a third value added to the tuple later is covered the day it appears.
	 */
	public function test_both_branches_of_the_query_moment_agree(): void {
		$instant = strtotime( '2026-08-15T04:00:00+00:00' );

		$state        = &blueline_test_state();
		$state['now'] = $instant;

		$this->assertSame(
			blueline_season_state_moment(),
			blueline_season_state_moment( $instant ),
			'the clock branch and the injected branch must describe the same moment identically'
		);
	}

	/**
	 * The consumer that made the divergence above matter: the recent-events
	 * query pairs a `gmdate()`-formatted `after` bound, built from
	 * `$now_ts`, with a site-local `before` bound built from `$now_mysql`.
	 * For that pairing to mean "the last 30 days", `$now_ts` has to be in
	 * the same frame `$now_mysql` is expressed in.
	 */
	public function test_the_thirty_day_window_is_exactly_thirty_days_in_the_bounds_own_frame(): void {
		list( $mysql, $timestamp ) = blueline_season_state_moment( strtotime( '2026-08-15T04:00:00+00:00' ) );

		$this->assertSame( '2026-08-15 00:00:00', $mysql );
		$this->assertSame(
			'2026-07-16 00:00:00',
			gmdate( 'Y-m-d H:i:s', $timestamp - 30 * DAY_IN_SECONDS ),
			'the after bound must land exactly 30 days before the before bound, same wall clock'
		);
	}

	/**
	 * And it MOVES with the timestamp -- the assertion that would fail if the
	 * parameter were ignored here and the clock read instead.
	 */
	public function test_the_query_moment_moves_with_the_timestamp(): void {
		list( $earlier ) = blueline_season_state_moment( strtotime( '2026-08-15T12:00:00+00:00' ) );
		list( $later )   = blueline_season_state_moment( strtotime( '2026-12-01T12:00:00+00:00' ) );

		$this->assertSame( '2026-08-15 08:00:00', $earlier );
		$this->assertSame( '2026-12-01 07:00:00', $later, 'and follows the site zone across a DST boundary' );
	}

	/**
	 * A plain read still caches, exactly as before this task -- the bypass
	 * is scoped to the explicit-timestamp path and changes nothing about
	 * ordinary front-end traffic.
	 */
	public function test_a_plain_read_still_populates_the_cache(): void {
		blueline_season_state_data();

		$cached = get_transient( 'blueline_season_state' );

		$this->assertIsArray( $cached );
		$this->assertSame( 'offseason', $cached['state'] );
	}

	/**
	 * The notice is what stops an override being forgotten, so it renders on
	 * every admin screen while one is active, and names both the forced
	 * state and the date it lifts.
	 */
	public function test_the_admin_notice_names_the_forced_state_and_its_expiry(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;

		$this->set_override( 'playoffs', '2036-12-31' );

		ob_start();
		blueline_render_season_state_override_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Playoffs', $html );
		$this->assertStringContainsString( '2036-12-31', $html );
	}

	/**
	 * A `<section>`, never a `<div>` -- `notice notice-warning` matches every
	 * substring the declutter plugin behind NoticeDivGuardTest strips.
	 */
	public function test_the_admin_notice_is_a_section_not_a_div(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;

		$this->set_override( 'playoffs', '2036-12-31' );

		$html = $this->render_notice();

		$this->assertStringContainsString( '<section class="notice notice-warning', $html );
		$this->assertStringNotContainsString( '<div', $html );
	}

	/**
	 * Render the notice and hand back its markup.
	 *
	 * @return string
	 */
	private function render_notice(): string {
		ob_start();
		blueline_render_season_state_override_notice();

		return (string) ob_get_clean();
	}

	/**
	 * No override, no notice.
	 */
	public function test_no_notice_without_an_active_override(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;

		$this->assertSame( '', trim( $this->render_notice() ) );
	}

	/**
	 * An expired override produces no notice either: it is already being
	 * ignored, so a warning about it would be describing behaviour that is
	 * not happening.
	 */
	public function test_no_notice_for_an_expired_override(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;

		$this->set_override( 'playoffs', '2020-01-01' );

		$this->assertSame( '', trim( $this->render_notice() ) );
	}

	/**
	 * A user who cannot reach the settings panel is not shown an instruction
	 * they have no ability to act on -- the same capability gate
	 * inc/settings/cache.php's own persistent notice uses.
	 */
	public function test_no_notice_without_manage_options(): void {
		$this->set_override( 'playoffs', '2036-12-31' );

		$this->assertSame( '', trim( $this->render_notice() ) );
	}

	/**
	 * The notice is actually wired to `admin_notices`. Without this the
	 * function above could be correct and never called -- which is the
	 * whole failure mode the override's expiry exists to prevent.
	 */
	public function test_the_notice_is_registered_on_admin_notices(): void {
		$registered = array();

		foreach ( $GLOBALS['bl_test_hooks']['admin_notices'] ?? array() as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$registered[] = $entry['cb'];
			}
		}

		$this->assertContains( 'blueline_render_season_state_override_notice', $registered );
	}

	/**
	 * Every state has a human label for the notice to name it by -- an
	 * unlabelled state would print a raw key like `in_season` to an admin.
	 */
	public function test_every_state_has_a_human_label(): void {
		foreach ( BLUELINE_SEASON_STATES as $state ) {
			$label = blueline_season_state_label( $state );

			$this->assertNotSame( '', trim( $label ), "$state has no label" );
			$this->assertStringNotContainsString( '_', $label, "$state's label is still the raw key" );
		}
	}
}
