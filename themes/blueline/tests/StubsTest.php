<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Unit tests.
 */
final class StubsTest extends TestCase {
	/**
	 * Test case.
	 */
	public function test_harness_boots(): void {
		$this->assertTrue( defined( 'ABSPATH' ) );
		$this->assertSame( 'a&amp;b', esc_html( 'a&b' ) );
	}
}
