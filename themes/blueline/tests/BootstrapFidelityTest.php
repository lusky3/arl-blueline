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
