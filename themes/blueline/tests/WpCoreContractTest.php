<?php
/**
 * Guards tests/bootstrap.php's option-lifecycle stubs (update_option(),
 * add_option()) against silently diverging from real WordPress core.
 *
 * WHY THIS EXISTS: over ten prior tasks, four separate bugs in these stubs
 * each shipped a fully green test suite over something untrue about
 * production --
 *
 *   1. update_option() never fired pre_update_option_{$option} at all.
 *   2. The generic pre_update_option filter fired with the wrong argument
 *      order -- ($value, $old_value, $option) instead of core's real
 *      ($value, $option, $old_value).
 *   3. update_option_{$option} fired even on a first-ever write, where core
 *      instead delegates to add_option().
 *   4. That delegation was faked inline (writing the value and firing
 *      add_option_{$option}/added_option directly) rather than being a
 *      real re-entrant call into add_option(), so core's double
 *      sanitize_option_{$option} dispatch on a first write never happened
 *      in tests.
 *
 * Every one of those was a guess about core that nobody checked against
 * core's actual source -- despite that source being available. This test
 * class closes that gap with two structurally different jobs:
 *
 * JOB 1 -- test_stub_matches_committed_contract() and its data provider,
 * ALWAYS RUNS, no external dependency: replays the recorded sequences in
 * tests/fixtures/wp-core-option-contract.json's `sequences` section against
 * the live update_option()/add_option() stubs in this test run, and asserts
 * the observed hook-dispatch sequence and each hook's argument order match
 * exactly. This is the regression guard -- it is what would have failed on
 * any of the four bugs above, and does fail the moment the stub is edited
 * to reintroduce one. That was verified directly while building this test:
 * temporarily swapping the generic pre_update_option filter's argument order
 * back to bug #2's shape in tests/bootstrap.php made this job fail, naming
 * the exact discrepancy, before the swap was reverted.
 *
 * JOB 2 -- test_fixture_matches_a_live_wp_core_checkout(), ONLY RUNS WHEN
 * AN ORACLE IS PRESENT: re-extracts the true hook contract from a real
 * WordPress core checkout (via tests/tools/wp-core-option-contract-extractor.php)
 * and asserts it still matches this fixture's `source_truth` section
 * exactly. This catches the fixture itself going stale after a WordPress
 * core version bump. Skipping this job when no oracle is configured is
 * acceptable ONLY because job 1 always runs regardless -- a silently-skipped
 * test is exactly the failure mode this whole exercise exists to eliminate,
 * and job 1 carries that weight unconditionally.
 *
 * SCOPE AND LIMITS -- read before trusting this test for more than it
 * claims: this verifies STRUCTURAL fidelity only -- which hooks fire, in
 * what order, with what arguments -- for the option lifecycle
 * (update_option()/add_option()) specifically. It says nothing about
 * BEHAVIOURAL equivalence (e.g. esc_url() not stripping a stray `"`, which
 * this approach cannot reach: the hook contract for esc_url() is trivial --
 * it fires no hooks at all -- the divergence there is in what the function
 * DOES, not what it announces). It also does not model option DEFAULTS
 * (register_setting()'s default value machinery, the default_option_
 * {$option} filter's role in that) at all -- see
 * tests/fixtures/wp-core-option-contract.json's "scope" key.
 *
 * This task discovered ONE further, previously-unknown divergence while
 * building this guard: tests/fixtures/wp-core-option-contract.json's
 * `known_gaps` documents it (real core's sanitize_option_{$option} filter
 * takes 3 args, this stub's inlined version passes only 2; and three
 * generic action hooks -- 'update_option', 'updated_option', 'add_option'
 * -- fire unconditionally in real core but are not fired by this stub at
 * all). Per this task's brief, that finding is deliberately reported here
 * (and in the fixture) rather than silently patched into the stub, so it
 * gets a proper decision. This test class PINS the current, documented gap
 * (asserting the stub's actual, imperfect behaviour) rather than ignoring
 * it, so that behaviour cannot drift further without this test noticing.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/tools/wp-core-option-contract-extractor.php';

/**
 * Structural contract test for the option-lifecycle stubs.
 */
final class WpCoreContractTest extends TestCase {

	/**
	 * Decoded tests/fixtures/wp-core-option-contract.json, loaded once.
	 *
	 * @var array<string,mixed>
	 */
	private static array $fixture;

	/**
	 * Loads and validates the committed fixture once for the whole class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$path = __DIR__ . '/fixtures/wp-core-option-contract.json';
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHPUnit test reading a local, committed fixture file; no WordPress runtime/wp_remote_get() applies here.

		self::assertIsString( $json, "could not read fixture: {$path}" );

		$decoded = json_decode( (string) $json, true );

		self::assertIsArray( $decoded, "fixture is not valid JSON: {$path}" );
		self::assertArrayHasKey( 'sequences', $decoded );
		self::assertArrayHasKey( 'source_truth', $decoded );

		self::$fixture = $decoded;
	}

	/**
	 * Resets the in-memory option store and hook registry before each test
	 * so no scenario can leak state (options or registered callbacks) into
	 * another.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Data provider: one case per scenario key in the fixture's
	 * `sequences` section.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function scenarioProvider(): array {
		return array(
			'update_option() on a brand new option (first-ever write)' => array( 'update_option_first_write' ),
			'update_option() on an option that already exists (subsequent write)' => array( 'update_option_subsequent_write' ),
			'add_option() called directly on a brand new option' => array( 'add_option_direct' ),
		);
	}

	/**
	 * JOB 1 (always runs, no external dependency): for each fixture
	 * scenario, registers a recording callback on every hook the scenario
	 * mentions, performs the matching update_option()/add_option() call(s)
	 * against the live stub, and asserts the observed hook sequence and
	 * each hook's argument order matches the fixture exactly -- including
	 * asserting that hooks the fixture marks `"fires": false` are genuinely
	 * never observed.
	 *
	 * This is the guard that fails if tests/bootstrap.php's update_option()/
	 * add_option() stubs are edited to diverge from this contract -- see
	 * this class's docblock for the four historical bugs it exists to
	 * catch, and this task's report for the break-and-restore demonstration
	 * proving it does.
	 *
	 * @param string $scenario_key Key into the fixture's `sequences` map.
	 * @return void
	 */
	#[DataProvider( 'scenarioProvider' )]
	public function test_stub_matches_committed_contract( string $scenario_key ): void {
		$scenario = self::$fixture['sequences'][ $scenario_key ] ?? null;
		$this->assertIsArray( $scenario, "no such fixture scenario: {$scenario_key}" );

		// A distinct option name per scenario, so dynamic hook names
		// (pre_update_option_{$option}, etc.) never collide across
		// scenarios sharing this method via the data provider.
		$option_name = 'bl_contract_' . $scenario_key;

		$observed        = array();
		$expected        = array();
		$registered_hook = array();

		foreach ( $scenario as $entry ) {
			if ( '$comment' === $entry ) {
				continue;
			}

			$hook_name = str_replace( '{$option}', $option_name, $entry['hook'] );

			// Register exactly one recorder per unique concrete hook name,
			// even though a hook (e.g. sanitize_option_{$option}, which
			// fires twice on a first-ever write -- once in update_option(),
			// once again inside the add_option() it delegates to) may
			// appear more than once in the scenario's own entry list:
			// apply_filters()/do_action() invoke EVERY registered callback
			// on each dispatch, so registering twice would double-count
			// each real firing.
			if ( ! isset( $registered_hook[ $hook_name ] ) ) {
				$registered_hook[ $hook_name ] = true;

				$hook_name_for_closure = $entry['hook'];
				$recorder              = static function ( ...$args ) use ( &$observed, $hook_name_for_closure ) {
					$observed[] = array( $hook_name_for_closure, $args );
					// Filters must return the value they received (the
					// first argument) unchanged, or the stub's own write
					// path breaks.
					return $args[0] ?? null;
				};

				$accepted_args = max( 1, count( $entry['expected_args'] ?? array() ) );

				if ( 'filter' === $entry['kind'] ) {
					add_filter( $hook_name, $recorder, 10, $accepted_args );
				} else {
					add_action( $hook_name, $recorder, 10, $accepted_args );
				}
			}

			if ( false !== ( $entry['fires'] ?? true ) ) {
				$expected[] = array(
					$entry['hook'],
					$this->substituteOptionName( $entry['expected_args'], $option_name, $scenario_key ),
				);
			}
		}

		$this->exerciseScenario( $scenario_key, $option_name );

		$this->assertSame(
			array_column( $expected, 0 ),
			array_column( $observed, 0 ),
			"hook dispatch order for scenario '{$scenario_key}' does not match the committed contract"
		);

		foreach ( $expected as $i => $expected_entry ) {
			$this->assertSame(
				$expected_entry[1],
				$observed[ $i ][1],
				"argument order/values for hook '{$expected_entry[0]}' (position {$i}) in scenario '{$scenario_key}' does not match the committed contract"
			);
		}

		// Also positively assert that every "fires: false" hook in the
		// scenario was genuinely never observed -- pinning the documented
		// gap (see known_gaps) rather than merely not checking it.
		foreach ( $scenario as $entry ) {
			if ( '$comment' === $entry || false !== ( $entry['fires'] ?? true ) ) {
				continue;
			}

			$this->assertNotContains(
				$entry['hook'],
				array_column( $observed, 0 ),
				"hook '{$entry['hook']}' was expected to NEVER fire in scenario '{$scenario_key}' per the committed known_gaps entry, but it fired -- either the stub grew this hook (update the fixture deliberately) or something else is now dispatching it"
			);
		}
	}

	/**
	 * Substitutes the literal placeholder values a fixture's
	 * `expected_args` entries use ('value', 'option', 'old_value') with the
	 * concrete values this test's exerciseScenario() actually writes, so
	 * asserted argument arrays can be compared by value rather than by
	 * hand-decoding which placeholder means what for every hook.
	 *
	 * The 'old_value' placeholder is scenario-dependent: only
	 * 'update_option_subsequent_write' pre-seeds an option that already
	 * exists (to SCENARIO_OLD_VALUE); the first-write and direct-add
	 * scenarios write a genuinely new option, for which core's (and this
	 * stub's) real $old_value is the boolean `false`, not a string.
	 *
	 * @param array<int,string>|null $expected_args Fixture argument-name
	 *                                               placeholders, in order.
	 * @param string                 $option_name    Concrete option name
	 *                                                used for this
	 *                                                scenario.
	 * @param string                 $scenario_key   Fixture scenario key,
	 *                                                to resolve 'old_value'.
	 * @return array<int,mixed>|null Concrete expected argument values, or
	 *                                null if $expected_args was null.
	 */
	private function substituteOptionName( ?array $expected_args, string $option_name, string $scenario_key ): ?array {
		if ( null === $expected_args ) {
			return null;
		}

		$map = array(
			'option'    => $option_name,
			'value'     => self::SCENARIO_NEW_VALUE,
			'old_value' => 'update_option_subsequent_write' === $scenario_key ? self::SCENARIO_OLD_VALUE : false,
		);

		return array_map(
			static fn( string $placeholder ) => $map[ $placeholder ] ?? $placeholder,
			$expected_args
		);
	}

	/**
	 * The "new value" every scenario in this test writes -- shared so
	 * substituteOptionName() can translate the fixture's 'value' argument
	 * placeholder into a concrete comparison value.
	 *
	 * @var string
	 */
	private const SCENARIO_NEW_VALUE = 'contract-new';

	/**
	 * The "old value" pre-seeded for 'update_option_subsequent_write' (the
	 * only scenario in which an option already exists before the observed
	 * call) -- shared so substituteOptionName() can translate the
	 * fixture's 'old_value' argument placeholder for that scenario.
	 *
	 * @var string
	 */
	private const SCENARIO_OLD_VALUE = 'contract-old';

	/**
	 * Performs the actual update_option()/add_option() call(s) a given
	 * scenario models, against the live stub, with recorders already
	 * registered.
	 *
	 * @param string $scenario_key One of the fixture's `sequences` keys.
	 * @param string $option_name  Concrete option name for this run.
	 * @return void
	 */
	private function exerciseScenario( string $scenario_key, string $option_name ): void {
		switch ( $scenario_key ) {
			case 'update_option_first_write':
				update_option( $option_name, self::SCENARIO_NEW_VALUE );
				break;

			case 'update_option_subsequent_write':
				// Seed the option directly in the in-memory store, bypassing
				// update_option()/add_option() entirely, so the seed itself
				// fires none of the hooks this test's recorders (already
				// registered by this point) are watching -- only the
				// write that follows, to an option that now genuinely
				// already exists, is observed and asserted.
				$GLOBALS['bl_test_options'][ $option_name ] = self::SCENARIO_OLD_VALUE;
				update_option( $option_name, self::SCENARIO_NEW_VALUE );
				break;

			case 'add_option_direct':
				add_option( $option_name, self::SCENARIO_NEW_VALUE );
				break;

			default:
				$this->fail( "exerciseScenario() does not know how to run scenario '{$scenario_key}'" );
		}
	}

	/**
	 * JOB 2 (runs only when an oracle is present): re-extracts the true
	 * option-lifecycle hook contract from a live WordPress core checkout
	 * and asserts it still matches this fixture's `source_truth` section
	 * exactly -- catching a STALE fixture after a WordPress core version
	 * bump, which job 1 (which only knows about this fixture, not core
	 * itself) cannot.
	 *
	 * Skipping this test when no oracle is configured is acceptable only
	 * because job 1 above always runs unconditionally and carries the
	 * actual regression-guard weight; this job's sole purpose is to prevent
	 * the fixture itself from silently drifting from a real, current
	 * WordPress core.
	 *
	 * The oracle path is a local WordPress core checkout's wp-includes
	 * directory, configurable via the BLUELINE_WP_CORE_INCLUDES_DIR
	 * environment variable (falling back to /home/cody/arl-local/wp-includes,
	 * this repository's known local checkout) -- pointing that variable at
	 * a nonexistent path is exactly how this task's verification confirmed
	 * job 1 keeps running with no oracle present while this job cleanly
	 * skips.
	 *
	 * @return void
	 */
	public function test_fixture_matches_a_live_wp_core_checkout(): void {
		$oracle_dir = getenv( 'BLUELINE_WP_CORE_INCLUDES_DIR' );
		if ( false === $oracle_dir || '' === $oracle_dir ) {
			$oracle_dir = '/home/cody/arl-local/wp-includes';
		}

		if ( ! is_dir( $oracle_dir ) || ! is_readable( $oracle_dir . '/option.php' ) ) {
			$this->markTestSkipped(
				"no WordPress core oracle available at '{$oracle_dir}' -- set BLUELINE_WP_CORE_INCLUDES_DIR to a " .
				'wp-includes directory to run this staleness check. This test skipping is acceptable ONLY because ' .
				'test_stub_matches_committed_contract() above always runs and requires no oracle -- see this ' .
				"class's docblock."
			);
		}

		try {
			$live_source_truth = blueline_extract_wp_core_option_contract( $oracle_dir );
		} catch ( RuntimeException $e ) {
			$this->fail(
				"live extraction from '{$oracle_dir}' failed: {$e->getMessage()} -- this means core has changed in " .
				'a way tests/tools/wp-core-option-contract-extractor.php was not taught to understand; that is a ' .
				'louder and more useful failure than a silently-stale fixture would be.'
			);
			return;
		}

		$committed_source_truth = self::$fixture['source_truth'];
		unset( $committed_source_truth['$comment'] );

		$this->assertSame(
			$committed_source_truth,
			$live_source_truth,
			"tests/fixtures/wp-core-option-contract.json's source_truth no longer matches '{$oracle_dir}' -- " .
			'regenerate it with tests/tools/generate-wp-core-option-contract.php and review sequences/known_gaps ' .
			'for anything the change affects (see that tool\'s docblock).'
		);
	}
}
