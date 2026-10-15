<?php
/**
 * Keeps the go-live checklist's module check in docs/OPERATIONS.md in step with includes/modules.php.
 *
 * The checklist names every module explicitly (rather than reading blueline_core_modules()) so an
 * outdated plugin on production, which would list fewer modules itself, still shows up as "no".
 * That only works while the doc's list is complete.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers the module list in docs/OPERATIONS.md.
 */
final class ModuleDocsTest extends TestCase {

	/**
	 * The checklist line names exactly the modules modules.php loads, in the same order.
	 */
	public function test_operations_checklist_lists_every_module(): void {
		$doc = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/OPERATIONS.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a repo file in a test.

		$this->assertSame( 1, preg_match_all( '/foreach\(\[([^\]]*)\] as \$m\)\{echo \$m," ",blueline_core_module_loaded\(\$m\)/', $doc, $matches ), 'Exactly one module check in docs/OPERATIONS.md.' );

		preg_match_all( '/"([a-z0-9-]+)"/', $matches[1][0], $listed );

		$modules = require dirname( __DIR__ ) . '/includes/modules.php';

		$this->assertSame( array_keys( $modules ), $listed[1] );
	}
}
