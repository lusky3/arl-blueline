<?php
/**
 * Guards tests/bootstrap.php's option-lifecycle stubs (update_option(),
 * add_option()) against silently diverging from real WordPress core.
 *
 * WHY THIS EXISTS: over ten prior tasks, this stub accumulated SIX separate
 * bugs, each shipping a fully green test suite over something untrue about
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
 *   5. sanitize_option_{$option} dispatched with only 2 args, ($value,
 *      $option), where core's real sanitize_option() (wp-includes/
 *      formatting.php) dispatches with 3: ($value, $option,
 *      $original_value).
 *   6. Three generic action hooks -- 'update_option', 'updated_option',
 *      'add_option' -- fire unconditionally in real core alongside their
 *      option-specific counterparts, but were not fired by this stub at
 *      all.
 *
 * Bugs 1-4 were found and fixed across earlier tasks; building THIS guard
 * is what surfaced 5 and 6, which were fixed directly rather than recorded
 * as tolerated exceptions (see tests/fixtures/wp-core-option-contract.json's
 * `known_gaps`, deliberately empty) -- a documented-but-unfixed gap is the
 * same bet as an undocumented one, just with better bookkeeping.
 *
 * Every one of those was a guess about core that nobody checked against
 * core's actual source -- despite that source being available. This test
 * class closes that gap with two structurally different jobs:
 *
 * JOB 1 -- test_stub_matches_committed_contract() and its data provider,
 * plus test_original_value_is_captured_before_any_sanitize_filter_mutates_it(),
 * ALWAYS RUN, no external dependency: replay the recorded sequences in
 * tests/fixtures/wp-core-option-contract.json's `sequences` section against
 * the live update_option()/add_option() stubs in this test run, and assert
 * the observed hook-dispatch sequence and each hook's argument order match
 * exactly. This is the regression guard -- it is what would have failed on
 * any of bugs 1-4 and 6 above, and does fail the moment the stub is edited
 * to reintroduce one (verified directly while building this test: swapping
 * the generic pre_update_option filter's argument order back to bug #2's
 * shape in tests/bootstrap.php made it fail, naming the exact discrepancy,
 * before the swap was reverted -- see this task's report for that and the
 * equivalent demonstration for bugs 5 and 6). Bug 5 specifically needs its
 * own dedicated test: every recorder used by the scenario-driven test is an
 * identity function, so $original_value and $value are indistinguishable in
 * every assertion it makes -- test_original_value_is_captured_before_any_
 * sanitize_filter_mutates_it() closes that blind spot with a genuinely
 * mutating callback (see its own docblock).
 *
 * JOB 2 -- test_fixture_matches_a_live_wp_core_checkout(), ONLY RUNS WHEN
 * AN ORACLE IS EXPLICITLY CONFIGURED (BLUELINE_WP_CORE_INCLUDES_DIR set to
 * a wp-includes directory -- there is no implicit fallback default; see
 * that method's docblock for why): re-extracts the true hook contract from
 * a real WordPress core checkout (via tests/tools/wp-core-option-contract-
 * extractor.php) and asserts it still matches this fixture's `source_truth`
 * section exactly -- but ONLY after first confirming the oracle's own
 * declared WordPress version matches this fixture's recorded
 * `source.wp_version`; a version MISMATCH fails the test outright, in
 * either direction, rather than silently comparing data from the wrong
 * version and calling it a pass. This catches the fixture itself going
 * stale after a WordPress core version bump -- including the specific
 * failure mode of an oracle that was refreshed without anyone re-running
 * this test, or a fixture nobody updated after staging moved. Skipping this
 * job when no oracle is configured is acceptable ONLY because job 1 above
 * always runs regardless -- a silently-skipped test is exactly the failure
 * mode this whole exercise exists to eliminate, and job 1 carries that
 * weight unconditionally.
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
			'option'         => $option_name,
			'value'          => self::SCENARIO_NEW_VALUE,
			// The value as it arrived at whichever function dispatched
			// sanitize_option_{$option} -- in every scenario here nothing
			// upstream mutates the value (all recorders pass it through
			// unchanged), so original_value is always the same concrete
			// value as 'value', for both the update_option()-side and the
			// add_option()-side dispatch on a first write.
			'original_value' => self::SCENARIO_NEW_VALUE,
			'old_value'      => 'update_option_subsequent_write' === $scenario_key ? self::SCENARIO_OLD_VALUE : false,
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
	 * JOB 2 (runs only when an oracle is EXPLICITLY configured): re-extracts
	 * the true option-lifecycle hook contract from a live WordPress core
	 * checkout and asserts it still matches this fixture's `source_truth`
	 * section exactly -- but only once the oracle's own declared version
	 * (read from its wp-includes/version.php) is confirmed to match this
	 * fixture's recorded `source.wp_version`. A version MISMATCH fails this
	 * test outright, in EITHER direction -- never a skip, never a silent
	 * pass -- because `source.wp_version` is a provenance claim ("this is
	 * what was verified"), and a mismatch means that claim is no longer
	 * verifiably true of whatever was just checked, independent of whether
	 * the underlying structural data happens to still agree. An oracle
	 * OLDER than the fixture can no longer stand in for the version the
	 * fixture claims; an oracle NEWER than the fixture means core has moved
	 * and the fixture needs regenerating -- a real finding about drift, not
	 * a misconfiguration to wave through just because this particular pair
	 * of versions happens to extract identically.
	 *
	 * There is deliberately NO implicit fallback oracle path (earlier
	 * versions of this test defaulted to a hardcoded local checkout when
	 * BLUELINE_WP_CORE_INCLUDES_DIR was unset). An unreviewed default that
	 * everyone's test run silently trusts without anyone having chosen it
	 * for THIS run is exactly the failure mode this job exists to close --
	 * gap (1) in this task's own history. Requiring an explicit environment
	 * variable means whoever configures an oracle (a developer's local
	 * checkout, or a future CI step that rsyncs staging's live core) is
	 * making a conscious choice this test can then hold to account, rather
	 * than inheriting a stale default nobody re-examined.
	 *
	 * Skipping this test when no oracle is configured at all is acceptable
	 * only because job 1 above always runs unconditionally and carries the
	 * actual regression-guard weight; this job's sole purpose is to prevent
	 * the fixture itself from silently drifting from a real, current
	 * WordPress core, and it cannot do that without someone pointing it at
	 * one.
	 *
	 * @return void
	 */
	public function test_fixture_matches_a_live_wp_core_checkout(): void {
		$oracle_dir = getenv( 'BLUELINE_WP_CORE_INCLUDES_DIR' );

		if ( false === $oracle_dir || '' === $oracle_dir ) {
			$this->markTestSkipped(
				'no oracle configured -- set BLUELINE_WP_CORE_INCLUDES_DIR to a wp-includes directory to run this ' .
				'staleness check. Skipping here is acceptable ONLY because test_stub_matches_committed_contract() ' .
				'above always runs unconditionally and requires no oracle -- see this class\'s docblock. There is ' .
				'deliberately no implicit default path to fall back to (see this method\'s own docblock for why).'
			);
			return;
		}

		if ( ! is_dir( $oracle_dir ) || ! is_readable( $oracle_dir . '/option.php' ) ) {
			$this->markTestSkipped(
				"BLUELINE_WP_CORE_INCLUDES_DIR ('{$oracle_dir}') does not look like a wp-includes directory " .
				'(option.php not found or unreadable) -- skipping this staleness check.'
			);
			return;
		}

		$fixture_version = self::$fixture['source']['wp_version'] ?? null;
		$this->assertIsString( $fixture_version, 'fixture is missing source.wp_version' );

		try {
			$oracle_version = blueline_wpcc_read_wp_core_version( $oracle_dir );
		} catch ( RuntimeException $e ) {
			$this->fail(
				"could not determine the oracle's own WordPress version from '{$oracle_dir}': {$e->getMessage()}"
			);
			return;
		}

		if ( $oracle_version !== $fixture_version ) {
			if ( version_compare( $oracle_version, $fixture_version, '>' ) ) {
				$message = "the oracle at '{$oracle_dir}' reports WordPress {$oracle_version}, NEWER than this " .
					"fixture's recorded source.wp_version ({$fixture_version}) -- core has moved since this " .
					'fixture was last verified. This is a real finding, not a misconfiguration: regenerate the ' .
					"fixture with tests/tools/generate-wp-core-option-contract.php against '{$oracle_dir}', " .
					'review sequences/known_gaps for anything the newer version changes, and update ' .
					"source.wp_version/extracted_date to {$oracle_version} -- do this even if the structural " .
					"data turns out unchanged, because the fixture's version claim must describe what was " .
					'actually last checked, not merely what still happens to match.';
			} else {
				$message = "the oracle at '{$oracle_dir}' reports WordPress {$oracle_version}, OLDER than this " .
					"fixture's recorded source.wp_version ({$fixture_version}) -- this checkout can no longer " .
					'stand in for the version this fixture claims to have verified. Point ' .
					"BLUELINE_WP_CORE_INCLUDES_DIR at a checkout of WordPress {$fixture_version} or newer " .
					"(staging is the preferred oracle -- see this fixture's source.oracle for how to reach it), " .
					"or, if {$oracle_version} is now deliberately the reference version, regenerate the fixture " .
					'from it and update source.wp_version/extracted_date accordingly.';
			}

			$this->fail( $message );
			return;
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

	/**
	 * Canary for the $original_value argument specifically (bug 5): every
	 * recorder used by test_stub_matches_committed_contract() is an
	 * identity function that returns whatever it received unchanged, so
	 * $original_value and $value are indistinguishable in every assertion
	 * that test makes -- a regression that captured $original_value from
	 * the value AFTER some upstream mutation (rather than snapshotting it
	 * BEFORE any sanitize_option_{$option} callback runs, as
	 * update_option()/add_option() are now written to do) would sail
	 * through that test unnoticed, since value === original_value in every
	 * case it exercises.
	 *
	 * This test registers a genuinely MUTATING callback on
	 * sanitize_option_{$option} at a lower priority (runs first) than a
	 * second, purely-recording callback (runs second) on the same hook and
	 * option. This stub's apply_filters() fixes $original_value once, as
	 * whatever the THIRD argument was to this specific apply_filters()
	 * call -- it is not re-derived per callback in the chain -- so the
	 * recording callback necessarily observes $value already transformed by
	 * the mutating callback, while $original_value must still be the raw
	 * value update_option() was called with, if and only if
	 * update_option() snapshotted it before calling apply_filters() at all.
	 * A regression that instead captured $original_value from the
	 * already-sanitized $value (e.g. reading it back out after the filter
	 * dispatch, or reusing a stale variable) would make this recording
	 * callback observe $original_value === $value (both the mutated
	 * string) instead of them differing -- which is exactly what this test
	 * asserts does NOT happen.
	 *
	 * Uses a subsequent-write call (an option seeded directly into the
	 * store, bypassing hooks) rather than a first-ever write, so
	 * sanitize_option_{$option} fires exactly once -- a first write would
	 * fire it a second time inside the add_option() delegation, with its
	 * OWN freshly-captured original_value (the already-mutated value
	 * update_option() passed in), which would only add noise to this
	 * specific assertion, not additional coverage; that double-dispatch
	 * nuance is already covered by the 'update_option_first_write' scenario
	 * in test_stub_matches_committed_contract().
	 *
	 * @return void
	 */
	public function test_original_value_is_captured_before_any_sanitize_filter_mutates_it(): void {
		$option = 'bl_contract_original_value_canary';

		$GLOBALS['bl_test_options'][ $option ] = 'seed';

		$observed = array();

		add_filter(
			"sanitize_option_{$option}",
			static fn( $value ) => $value . '-mutated',
			5,
			1
		);

		add_filter(
			"sanitize_option_{$option}",
			static function ( $value, $option_name, $original_value ) use ( &$observed ) {
				$observed[] = array(
					'value'          => $value,
					'original_value' => $original_value,
				);
				return $value;
			},
			10,
			3
		);

		update_option( $option, 'raw' );

		$this->assertCount(
			1,
			$observed,
			'the recording callback must run exactly once for a subsequent write'
		);
		$this->assertNotSame(
			$observed[0]['original_value'],
			$observed[0]['value'],
			'original_value must differ from value once an earlier-priority sanitize_option_{$option} callback ' .
			'has mutated it -- if they are equal here, original_value is being re-derived from the mutated ' .
			'value rather than snapshotted before any filter ran'
		);
		$this->assertSame(
			'raw',
			$observed[0]['original_value'],
			'original_value must be the raw value update_option() was called with, unaffected by any ' .
			'sanitize_option_{$option} callback'
		);
		$this->assertSame(
			'raw-mutated',
			$observed[0]['value'],
			"value must reflect the earlier-priority callback's mutation by the time the recording callback runs"
		);
	}
}
