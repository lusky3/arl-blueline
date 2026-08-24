<?php
/**
 * The stub environment must be faithful enough that a passing test means
 * something. These assertions all failed against the original stubs.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Pins the bootstrap's filter dispatch, option store, and wp_kses fidelity.
 */
final class BootstrapFidelityTest extends TestCase {

	/**
	 * Reset both in-memory stores before each test so none of them can leak
	 * state into another -- including the hook a test method below
	 * registers under 'bl_test_leak', which the next test method in this
	 * class must not see.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Test case.
	 */
	public function test_apply_filters_runs_registered_callbacks(): void {
		add_filter( 'bl_test_hook', static fn( $v ) => $v . '-filtered' );

		$this->assertSame( 'x-filtered', apply_filters( 'bl_test_hook', 'x' ) );
	}

	/**
	 * Test case.
	 */
	public function test_filters_run_in_priority_order(): void {
		add_filter( 'bl_test_order', static fn( $v ) => $v . 'b', 20 );
		add_filter( 'bl_test_order', static fn( $v ) => $v . 'a', 10 );

		$this->assertSame( 'ab', apply_filters( 'bl_test_order', '' ) );
	}

	/**
	 * Test case.
	 */
	public function test_apply_filters_passes_extra_arguments(): void {
		add_filter( 'bl_test_args', static fn( $v, $extra ) => $v . $extra, 10, 2 );

		$this->assertSame( 'xy', apply_filters( 'bl_test_args', 'x', 'y' ) );
	}

	/**
	 * Test case.
	 */
	public function test_options_round_trip(): void {
		$this->assertFalse( get_option( 'bl_missing' ) );
		$this->assertSame( 'fallback', get_option( 'bl_missing', 'fallback' ) );

		update_option( 'bl_thing', array( 'a' => 1 ) );
		$this->assertSame( array( 'a' => 1 ), get_option( 'bl_thing' ) );

		delete_option( 'bl_thing' );
		$this->assertFalse( get_option( 'bl_thing' ) );
	}

	/**
	 * Test case.
	 */
	public function test_update_option_runs_the_sanitize_filter(): void {
		add_filter( 'sanitize_option_bl_guarded', static fn( $v ) => strtoupper( (string) $v ) );
		update_option( 'bl_guarded', 'quiet' );

		$this->assertSame( 'QUIET', get_option( 'bl_guarded' ) );
	}

	/**
	 * Pins update_option()'s hook dispatch to core's documented order:
	 * sanitize_option_{$option} -> pre_update_option_{$option} ->
	 * pre_update_option -> write -> update_option_{$option}. Also pins each
	 * hook's ARGUMENTS, not just its position in the sequence.
	 *
	 * The argument assertion is the point of this test, not a bonus: the
	 * two pre_update_option* filters do not share an argument order in
	 * core -- pre_update_option_{$option} is ( $value, $old_value, $option )
	 * but the generic pre_update_option is ( $value, $option, $old_value ),
	 * $option and $old_value swapped. A stub that fires both hooks in the
	 * right SEQUENCE but with the generic hook's arguments transposed would
	 * pass an order-only version of this test while still being wrong in a
	 * way a real callback (e.g. one written against core's docs and reused
	 * on the wrong hook) would silently misread. Asserting each hook's
	 * arguments individually is what closes that gap.
	 *
	 * Seeds an old value first so $old_value and $option are never the same
	 * string as $value, which would let a transposed-argument bug hide
	 * behind coincidentally-equal assertions.
	 */
	public function test_update_option_dispatches_hooks_in_core_order(): void {
		update_option( 'bl_order', 'was' );

		$calls = array();

		add_filter(
			'sanitize_option_bl_order',
			static function ( $value, $option ) use ( &$calls ) {
				$calls[] = array( 'sanitize_option_bl_order', array( $value, $option ) );
				return $value;
			},
			10,
			2
		);
		add_filter(
			'pre_update_option_bl_order',
			static function ( $value, $old_value, $option ) use ( &$calls ) {
				$calls[] = array( 'pre_update_option_bl_order', array( $value, $old_value, $option ) );
				return $value;
			},
			10,
			3
		);
		add_filter(
			'pre_update_option',
			static function ( $value, $option, $old_value ) use ( &$calls ) {
				$calls[] = array( 'pre_update_option', array( $value, $option, $old_value ) );
				return $value;
			},
			10,
			3
		);
		add_action(
			'update_option_bl_order',
			static function ( $old_value, $new_value, $option ) use ( &$calls ) {
				$calls[] = array( 'update_option_bl_order', array( $old_value, $new_value, $option ) );
			},
			10,
			3
		);

		update_option( 'bl_order', 'x' );

		$this->assertSame(
			array(
				'sanitize_option_bl_order',
				'pre_update_option_bl_order',
				'pre_update_option',
				'update_option_bl_order',
			),
			array_column( $calls, 0 ),
			'hook dispatch order'
		);

		$this->assertSame(
			array( 'x', 'bl_order' ),
			$calls[0][1],
			'sanitize_option_{$option} must receive ( $value, $option )'
		);
		$this->assertSame(
			array( 'x', 'was', 'bl_order' ),
			$calls[1][1],
			'pre_update_option_{$option} must receive ( $value, $old_value, $option )'
		);
		$this->assertSame(
			array( 'x', 'bl_order', 'was' ),
			$calls[2][1],
			'the GENERIC pre_update_option filter must receive ( $value, $option, $old_value ) -- $option and ' .
			'$old_value are swapped relative to the option-specific hook above; this is a well-known WordPress footgun'
		);
		$this->assertSame(
			array( 'was', 'x', 'bl_order' ),
			$calls[3][1],
			'update_option_{$option} must fire with ( $old_value, $new_value, $option )'
		);
	}

	/**
	 * Matches core's short-circuit: writing the exact value an option
	 * already holds must not perform a write, must not fire
	 * update_option_{$option}, and must report false -- not the true an
	 * unconditional-write stub would report regardless of whether anything
	 * actually changed.
	 */
	public function test_update_option_short_circuits_when_the_value_is_unchanged(): void {
		update_option( 'bl_same', 'value' );

		$fired = false;
		add_action(
			'update_option_bl_same',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$result = update_option( 'bl_same', 'value' );

		$this->assertFalse( $result, 'update_option() must report false when nothing changed' );
		$this->assertFalse( $fired, 'update_option_{$option} must not fire when nothing changed' );
		$this->assertSame( 'value', get_option( 'bl_same' ) );
	}

	/**
	 * The pre_update_option_{$option} filter must receive the value
	 * CURRENTLY in storage, not the value this same call is
	 * sanitizing/about to write -- that is the entire reason a cross-tab
	 * settings merge lives on this hook rather than sanitize_option_*.
	 */
	public function test_pre_update_option_receives_old_value_as_currently_stored(): void {
		update_option( 'bl_prev', 'first' );

		$captured_old = null;
		add_filter(
			'pre_update_option_bl_prev',
			static function ( $new_value, $old_value ) use ( &$captured_old ) {
				$captured_old = $old_value;
				return $new_value;
			},
			10,
			2
		);

		update_option( 'bl_prev', 'second' );

		$this->assertSame( 'first', $captured_old );
	}

	/**
	 * The update_option_{$option} action fires with ($old_value,
	 * $new_value, $option), in that order -- matching core, and the
	 * opposite order from a careless reading of pre_update_option_*'s
	 * ($new_value, $old_value) signature.
	 */
	public function test_update_option_action_receives_old_then_new_value(): void {
		update_option( 'bl_watched', 'before' );

		$captured = array();
		add_action(
			'update_option_bl_watched',
			static function ( $old_value, $new_value ) use ( &$captured ) {
				$captured = array( $old_value, $new_value );
			},
			10,
			2
		);

		update_option( 'bl_watched', 'after' );

		$this->assertSame( array( 'before', 'after' ), $captured );
	}

	/**
	 * Pins the asymmetry a task-6 code review caught this stub getting
	 * wrong: core's real update_option() does NOT fire
	 * update_option_{$option} on the very FIRST write to an option that
	 * does not exist yet -- it delegates internally to add_option(), which
	 * fires add_option_{$option} (and added_option) instead. Only a
	 * SUBSEQUENT write, to an option that already exists, fires
	 * update_option_{$option}. A callback hooked only to
	 * update_option_{$option} silently never runs on that first save --
	 * exactly the gap inc/settings/cache.php's purge trigger originally had
	 * (see its blueline_flush_page_cache_on_first_save()).
	 */
	public function test_update_option_fires_add_option_hook_on_first_write_only(): void {
		$calls = array();

		add_action(
			'add_option_bl_first',
			static function ( $option, $value ) use ( &$calls ) {
				$calls[] = array( 'add_option_bl_first', $option, $value );
			},
			10,
			2
		);
		add_action(
			'update_option_bl_first',
			static function ( $old_value, $new_value, $option ) use ( &$calls ) {
				$calls[] = array( 'update_option_bl_first', $old_value, $new_value, $option );
			},
			10,
			3
		);

		update_option( 'bl_first', 'one' ); // First-ever write: the option does not exist yet.
		update_option( 'bl_first', 'two' ); // Second write: the option already exists.

		$this->assertSame(
			array(
				array( 'add_option_bl_first', 'bl_first', 'one' ),
				array( 'update_option_bl_first', 'one', 'two', 'bl_first' ),
			),
			$calls,
			'the first write must fire add_option_{$option}, never update_option_{$option}; only the second write may fire the latter'
		);
	}

	/**
	 * Task-7 fix-round-5 finding, pinned directly: on a genuine first-ever
	 * write, update_option() delegates to add_option() (see the test
	 * above) -- a REAL re-entrant call, not merely firing the same hooks
	 * inline. Because add_option() is a fully independent function that
	 * unconditionally re-applies sanitize_option_{$option} to whatever
	 * value it is given, that filter runs TWICE on a first write: once
	 * inside update_option() itself (on the raw input), and again inside
	 * add_option() (on the value update_option()'s own
	 * pre_update_option_{$option}/pre_update_option filters already
	 * produced). Those two pre_update_option* filters -- where a cross-tab
	 * merge such as blueline_settings_merge() lives -- run only ONCE, here,
	 * strictly BEFORE the delegation; add_option()'s own write path has no
	 * equivalent filter at all. A sanitize callback that unconditionally
	 * re-adds bookkeeping to every value it returns (e.g.
	 * inc/settings/page.php's `_posted_fields`) will therefore have that
	 * bookkeeping restored by the second, unmerged pass and persisted
	 * verbatim on a first write -- a real, if cosmetic and self-healing
	 * (the next save's merge strips it again), fact about core itself, not
	 * a defect in this stub to paper over. See
	 * SettingsStoreTest::test_merge_never_persists_the_posted_fields_key_itself()
	 * and SettingsCliCommandTest::test_import_never_honours_posted_fields_or_tab_from_a_file()
	 * for where this exact quirk now surfaces in this suite, and both
	 * tests' own docblocks for why they seed the option first rather than
	 * "fixing" it.
	 */
	public function test_update_option_first_write_sanitizes_twice_but_merges_once(): void {
		$sanitize_calls          = array();
		$pre_update_option_calls = array();

		add_filter(
			'sanitize_option_bl_first_double',
			static function ( $value ) use ( &$sanitize_calls ) {
				$sanitize_calls[] = $value;
				return $value;
			}
		);
		add_filter(
			'pre_update_option_bl_first_double',
			static function ( $value, $old_value ) use ( &$pre_update_option_calls ) {
				$pre_update_option_calls[] = array( $value, $old_value );
				return $value;
			},
			10,
			2
		);

		update_option( 'bl_first_double', 'x' ); // First-ever write: the option does not exist yet.

		$this->assertCount(
			2,
			$sanitize_calls,
			'sanitize_option_{$option} must fire twice on a first-ever write: once in update_option(), once again inside the add_option() it delegates to'
		);
		$this->assertCount(
			1,
			$pre_update_option_calls,
			'pre_update_option_{$option} (where a cross-tab merge lives) must fire only ONCE on a first-ever write -- add_option()\'s own write path has no equivalent filter'
		);
		$this->assertSame(
			'x',
			$sanitize_calls[0],
			'the first sanitize pass receives the raw input'
		);
		$this->assertSame(
			'x',
			$sanitize_calls[1],
			'the second sanitize pass (inside add_option()) receives whatever pre_update_option_{$option} already produced -- here unchanged, but this is the exact seam a merge-then-resanitize callback can lose bookkeeping through'
		);
	}

	/**
	 * Test case: add_option() must run sanitize_option_{$option}
	 * unconditionally, exactly as core does.
	 */
	public function test_add_option_runs_the_sanitize_filter(): void {
		add_filter( 'sanitize_option_bl_added', static fn( $v ) => strtoupper( (string) $v ) );

		add_option( 'bl_added', 'quiet' );

		$this->assertSame( 'QUIET', get_option( 'bl_added' ) );
	}

	/**
	 * Test case: add_option() must not overwrite an option that already
	 * exists, matching core's "add if absent" contract.
	 */
	public function test_add_option_returns_false_when_option_already_exists(): void {
		update_option( 'bl_exists', 'first' );

		$this->assertFalse( add_option( 'bl_exists', 'second' ) );
		$this->assertSame( 'first', get_option( 'bl_exists' ), 'add_option must not overwrite an existing value' );
	}

	/**
	 * Test case: the transient stubs round-trip over their own in-memory
	 * store, independent of the options store above.
	 */
	public function test_transients_round_trip(): void {
		$this->assertFalse( get_transient( 'bl_missing_transient' ) );

		set_transient( 'bl_thing', 'value', 60 );
		$this->assertSame( 'value', get_transient( 'bl_thing' ) );

		delete_transient( 'bl_thing' );
		$this->assertFalse( get_transient( 'bl_thing' ) );
	}

	/**
	 * Test case: wp_cache_add() only succeeds once per group:key pair until
	 * released -- the "add if absent" semantics a migration lock depends on.
	 */
	public function test_wp_cache_add_locks_until_released(): void {
		$this->assertTrue( wp_cache_add( 'bl_lock', 1, 'bl_group', 60 ) );
		$this->assertFalse(
			wp_cache_add( 'bl_lock', 1, 'bl_group', 60 ),
			'a second wp_cache_add() for the same key must fail while the first holds the lock'
		);

		wp_cache_delete( 'bl_lock', 'bl_group' );

		$this->assertTrue(
			wp_cache_add( 'bl_lock', 1, 'bl_group', 60 ),
			'releasing the lock must allow it to be re-acquired'
		);
	}

	/**
	 * Test case: proves a filter registration does not survive a hook reset,
	 * self-contained in one test rather than depending on method execution
	 * order, so this assertion holds regardless of how the suite is ordered.
	 */
	public function test_reset_hooks_removes_a_registration_added_since_the_baseline(): void {
		add_filter( 'bl_test_leak_direct', static fn( $v ) => $v . '-leaked' );
		$this->assertSame( 'x-leaked', apply_filters( 'bl_test_leak_direct', 'x' ) );

		blueline_test_reset_hooks();

		$this->assertSame( 'x', apply_filters( 'bl_test_leak_direct', 'x' ) );
	}

	/**
	 * Test case.
	 */
	public function test_wp_kses_post_keeps_allowed_markup(): void {
		$this->assertSame( '<strong>hi</strong>', wp_kses_post( '<strong>hi</strong>' ) );
	}

	/**
	 * Test case.
	 */
	public function test_wp_kses_post_strips_scripts(): void {
		$this->assertSame( 'hi', wp_kses_post( '<script>evil()</script>hi' ) );
	}

	/**
	 * Test case: the tag-name match must be case-insensitive, same as core.
	 */
	public function test_wp_kses_post_strips_uppercase_script_tags(): void {
		$this->assertSame( 'hi', wp_kses_post( '<SCRIPT>evil()</SCRIPT>hi' ) );
	}

	/**
	 * Test case: attributes on the opening tag must not defeat the match.
	 */
	public function test_wp_kses_post_strips_script_tags_with_attributes(): void {
		$this->assertSame(
			'hi',
			wp_kses_post( '<script type="text/javascript" src="evil.js">bad()</script>hi' ) // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- this is an attacker-input string literal being fed to wp_kses_post() for sanitization, not a real <script> tag being output.
		);
	}

	/**
	 * Test case: an empty (but properly closed) attribute-bearing script
	 * tag must still be removed in full, not left behind as an empty pair.
	 */
	public function test_wp_kses_post_strips_attribute_only_script_tags(): void {
		$this->assertSame( 'hi', wp_kses_post( '<script src="x.js"></script>hi' ) ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- attacker-input string literal fed to wp_kses_post(), not a real <script> tag being output.
	}

	/**
	 * Test case: a <script> nested inside another <script> is invalid HTML,
	 * but nothing stops an attacker from sending it. A single non-repeating
	 * pass would match the outer opening tag against the FIRST closing tag
	 * it finds (the inner one), stripping "<script>a<script>b</script>" and
	 * leaving the inner element's own trailing text ("c") behind as plain,
	 * attacker-controlled output. Repeating the removal until the string
	 * stops changing must not leak that fragment.
	 */
	public function test_wp_kses_post_strips_nested_script_tags(): void {
		$this->assertSame( 'hi', wp_kses_post( '<script>a<script>b</script>c</script>hi' ) );
	}

	/**
	 * Test case: an unterminated <script> tag has no closing tag for the
	 * main removal pass to match against, so it would otherwise survive
	 * untouched. Under-stripping here is the dangerous failure mode for a
	 * security-flavoured stub, so everything from the opening tag onward
	 * must be removed.
	 */
	public function test_wp_kses_post_strips_unterminated_script_tag(): void {
		$this->assertSame( '', wp_kses_post( '<script>alert(1)hi-no-close' ) );
	}
}
