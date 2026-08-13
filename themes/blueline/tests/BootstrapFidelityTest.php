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
	 * pre_update_option -> write -> update_option_{$option}.
	 *
	 * This is the ORDER, not just presence -- a callback on each hook
	 * appends its own name to a shared array, so a future edit to the
	 * update_option() stub that fires these in the wrong sequence (or drops
	 * one) fails this test even though each hook still individually "fires".
	 * That distinction is exactly what let this stub previously fire only
	 * sanitize_option_* and never pre_update_option_*, silently, since a
	 * presence-only test could not have told the difference.
	 */
	public function test_update_option_dispatches_hooks_in_core_order(): void {
		$order = array();

		add_filter(
			'sanitize_option_bl_order',
			static function ( $value ) use ( &$order ) {
				$order[] = 'sanitize_option_bl_order';
				return $value;
			}
		);
		add_filter(
			'pre_update_option_bl_order',
			static function ( $value ) use ( &$order ) {
				$order[] = 'pre_update_option_bl_order';
				return $value;
			}
		);
		add_filter(
			'pre_update_option',
			static function ( $value ) use ( &$order ) {
				$order[] = 'pre_update_option';
				return $value;
			}
		);
		add_action(
			'update_option_bl_order',
			static function () use ( &$order ) {
				$order[] = 'update_option_bl_order';
			}
		);

		update_option( 'bl_order', 'x' );

		$this->assertSame(
			array(
				'sanitize_option_bl_order',
				'pre_update_option_bl_order',
				'pre_update_option',
				'update_option_bl_order',
			),
			$order
		);
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
	 * Test case: registers a filter that the NEXT test method in this class
	 * (below) must not see. Deliberately registers first and asserts the
	 * filter fires here, so a reader can tell this test genuinely added the
	 * hook rather than the assertion coincidentally matching an empty
	 * filter chain.
	 *
	 * Relies on PHPUnit's default declaration-order execution -- this
	 * project's phpunit.xml sets no --order-by, so these two methods run in
	 * the order they appear in this file.
	 */
	public function test_a_filter_registered_here_fires_within_this_test(): void {
		add_filter( 'bl_test_leak', static fn( $v ) => $v . '-leaked' );

		$this->assertSame( 'x-leaked', apply_filters( 'bl_test_leak', 'x' ) );
	}

	/**
	 * Test case: proves the previous test's 'bl_test_leak' registration did
	 * not survive into this test's setUp(). Without blueline_test_reset()
	 * (or blueline_test_reset_hooks()) running between tests, this would
	 * fail with 'x-leaked' instead of 'x'.
	 */
	public function test_a_filter_registered_in_a_previous_test_does_not_leak_here(): void {
		$this->assertSame( 'x', apply_filters( 'bl_test_leak', 'x' ) );
	}

	/**
	 * Test case: the same isolation as the pair above, but self-contained
	 * in one test rather than depending on method execution order, so this
	 * assertion holds regardless of how the suite is ordered.
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
