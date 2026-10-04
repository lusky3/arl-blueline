<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/template-tags.php';

/**
 * A-14: the "blue leaf" device was a teardrop path; it is the maple leaf now.
 */
final class LeafMarkTest extends TestCase {

	/**
	 * Decorative maple-leaf SVG with the caller's class.
	 */
	public function test_leaf_mark_is_a_decorative_maple_leaf(): void {
		ob_start();
		blueline_leaf_mark( 'bl-empty-state__mark' );
		$svg = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="bl-leaf-mark bl-empty-state__mark"', $svg );
		$this->assertStringContainsString( 'aria-hidden="true"', $svg );
		$this->assertStringContainsString( 'viewBox="-2015 -2000 4030 4030"', $svg );
		$this->assertStringNotContainsString( 'M24 2c8', $svg );
	}
}
