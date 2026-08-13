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
	 * Reset the in-memory option store before each test so none of them can
	 * leak state into another.
	 */
	protected function setUp(): void {
		blueline_test_reset_options();
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
}
