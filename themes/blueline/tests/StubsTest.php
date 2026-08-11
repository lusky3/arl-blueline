<?php
use PHPUnit\Framework\TestCase;

final class StubsTest extends TestCase {
	public function test_harness_boots(): void {
		$this->assertTrue( defined( 'ABSPATH' ) );
		$this->assertSame( 'a&amp;b', esc_html( 'a&b' ) );
	}
}
