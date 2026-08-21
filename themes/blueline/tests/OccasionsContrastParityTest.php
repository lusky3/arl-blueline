<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/team-colors.php';

/**
 * Design spec §5.1's fourth ruling: assets/src/js/settings-occasions.js
 * carries a small, DELIBERATE duplicate of blueline_contrast_ratio()'s
 * math (never an import of tools/lib/contrast.mjs, which is dev/build
 * tooling, not a runtime asset), and that duplicate must be proven to
 * agree with the PHP original at a fixed set of hex pairs -- "required,
 * not optional" per that ruling.
 *
 * This is the PHP half of a two-file, shared-fixture parity guard: this
 * exact table of pairs and expected ratios is pinned again,
 * independently, on the JS side in
 * assets/src/js/settings-occasions.test.mjs (see that file's own
 * comment). Both tables were produced from the SAME computation, run
 * once and confirmed to agree to 6 decimal places before either was
 * written down -- so a future edit to either formula that silently
 * drifts from the other fails ITS OWN half of this shared table,
 * without either file ever having to import, shell out to, or
 * otherwise depend on the other language at runtime.
 */
final class OccasionsContrastParityTest extends TestCase {

	/**
	 * The exact same seven hex pairs and expected ratios as
	 * assets/src/js/settings-occasions.test.mjs's own PARITY_FIXTURE.
	 * Keep both in sync by hand if this ever changes -- there is no
	 * automated link between the two files, by design (see this
	 * class's own docblock).
	 *
	 * @return array<int, array{0:string, 1:string, 2:float}>
	 */
	private function fixture(): array {
		return array(
			array( '#132343', '#ffffff', 15.565337 ),
			array( '#132343', '#000000', 1.349152 ),
			array( '#132343', '#132343', 1.0 ),
			array( '#132343', '#274a63', 1.664712 ),
			array( '#132343', '#f7fbfc', 14.942248 ),
			array( '#132343', '#c8102e', 2.645692 ),
			array( '#132343', '#ffd700', 11.097474 ),
		);
	}

	/**
	 * Asserts blueline_contrast_ratio() matches the shared fixture, to
	 * within a small floating-point delta.
	 */
	public function test_php_contrast_ratio_matches_the_shared_parity_fixture(): void {
		foreach ( $this->fixture() as $pair ) {
			list( $a, $b, $expected ) = $pair;
			$this->assertEqualsWithDelta(
				$expected,
				blueline_contrast_ratio( $a, $b ),
				0.0005,
				"$a vs $b"
			);
		}
	}
}
