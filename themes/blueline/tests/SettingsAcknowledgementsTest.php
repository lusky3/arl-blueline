<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/cli-stubs.php';
require_once __DIR__ . '/../inc/enqueue.php';
require_once __DIR__ . '/../inc/settings/validation.php';
require_once __DIR__ . '/../inc/settings/acknowledgements.php';

/**
 * Covers aa_acknowledgements storage: blueline_sanitize_acknowledgements()'s
 * shape validation.
 */
final class SettingsAcknowledgementsTest extends TestCase {

	/**
	 * A well-formed entry, reused across several tests.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_entry(): array {
		return array(
			'rule_id'     => 'ink-on-occasion-accent',
			'ratio'       => 3.2,
			'user_id'     => 7,
			'date'        => 1700000000,
			'inputs_hash' => 'abc123',
			'scope'       => 'occasion:canada-day',
		);
	}

	/**
	 * Asserts a non-array value sanitizes to an empty map.
	 */
	public function test_non_array_value_sanitizes_to_empty(): void {
		foreach ( array( null, 'not-an-array', 42, false ) as $bad ) {
			$this->assertSame( array(), blueline_sanitize_acknowledgements( $bad ) );
		}
	}

	/**
	 * Asserts a well-formed entry survives, keyed by its own scope.
	 */
	public function test_a_well_formed_entry_survives(): void {
		$clean = blueline_sanitize_acknowledgements(
			array( 'occasion:canada-day' => $this->valid_entry() )
		);

		$this->assertSame( $this->valid_entry(), $clean['occasion:canada-day'] );
	}

	/**
	 * Asserts an entry missing a required key is dropped rather than
	 * corrupting the whole read.
	 */
	public function test_an_entry_missing_a_required_key_is_dropped(): void {
		$entry = $this->valid_entry();
		unset( $entry['inputs_hash'] );

		$clean = blueline_sanitize_acknowledgements( array( 'occasion:canada-day' => $entry ) );

		$this->assertArrayNotHasKey( 'occasion:canada-day', $clean );
	}

	/**
	 * Asserts an entry whose own `scope` field disagrees with its map key
	 * is dropped rather than trusted.
	 */
	public function test_an_entry_whose_scope_field_disagrees_with_its_key_is_dropped(): void {
		$entry          = $this->valid_entry();
		$entry['scope'] = 'occasion:remembrance-day';

		$clean = blueline_sanitize_acknowledgements( array( 'occasion:canada-day' => $entry ) );

		$this->assertArrayNotHasKey( 'occasion:canada-day', $clean );
	}

	/**
	 * Asserts a non-array row value (not a whole entry) is dropped.
	 */
	public function test_a_non_array_row_is_dropped(): void {
		$clean = blueline_sanitize_acknowledgements( array( 'occasion:canada-day' => 'not-an-array' ) );

		$this->assertSame( array(), $clean );
	}

	/**
	 * Asserts a valid entry and an invalid one in the same map are handled
	 * independently: the valid one survives, the invalid one is dropped.
	 */
	public function test_one_bad_entry_does_not_take_down_a_good_one(): void {
		$bad = $this->valid_entry();
		unset( $bad['ratio'] );

		$clean = blueline_sanitize_acknowledgements(
			array(
				'occasion:canada-day'      => $this->valid_entry(),
				'occasion:remembrance-day' => $bad,
			)
		);

		$this->assertArrayHasKey( 'occasion:canada-day', $clean );
		$this->assertArrayNotHasKey( 'occasion:remembrance-day', $clean );
	}

	/**
	 * Asserts numeric-looking string values for ratio/user_id/date are
	 * cast to their real types rather than rejected -- a value round-
	 * tripped through JSON (import/export) may arrive this way.
	 */
	public function test_numeric_strings_are_cast_to_the_right_type(): void {
		$entry            = $this->valid_entry();
		$entry['ratio']   = '3.2';
		$entry['user_id'] = '7';
		$entry['date']    = '1700000000';

		$clean = blueline_sanitize_acknowledgements( array( 'occasion:canada-day' => $entry ) );

		$this->assertSame( 3.2, $clean['occasion:canada-day']['ratio'] );
		$this->assertSame( 7, $clean['occasion:canada-day']['user_id'] );
		$this->assertSame( 1700000000, $clean['occasion:canada-day']['date'] );
	}

	/* -------------------------------------------------- record/invalidate */

	/**
	 * Asserts recording an acknowledgement adds an entry keyed by its
	 * scope, with the given fields and a `date` set from the real clock.
	 */
	public function test_record_adds_an_entry_keyed_by_scope(): void {
		$before = time();

		$acknowledgements = blueline_record_acknowledgement(
			array(),
			'occasion:canada-day',
			'ink-on-occasion-accent',
			3.2,
			'abc123',
			7
		);

		$after = time();

		$entry = $acknowledgements['occasion:canada-day'];
		$this->assertSame( 'ink-on-occasion-accent', $entry['rule_id'] );
		$this->assertSame( 3.2, $entry['ratio'] );
		$this->assertSame( 7, $entry['user_id'] );
		$this->assertSame( 'abc123', $entry['inputs_hash'] );
		$this->assertSame( 'occasion:canada-day', $entry['scope'] );
		$this->assertGreaterThanOrEqual( $before, $entry['date'] );
		$this->assertLessThanOrEqual( $after, $entry['date'] );
	}

	/**
	 * Asserts recording an acknowledgement for a scope that already has
	 * one REPLACES it rather than accumulating history -- only one entry
	 * is ever live per scope.
	 */
	public function test_record_replaces_an_existing_entry_for_the_same_scope(): void {
		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'rule-a', 3.0, 'hash-1', 1 );
		$acknowledgements = blueline_record_acknowledgement( $acknowledgements, 'occasion:canada-day', 'rule-b', 4.0, 'hash-2', 2 );

		$this->assertCount( 1, $acknowledgements );
		$this->assertSame( 'rule-b', $acknowledgements['occasion:canada-day']['rule_id'] );
		$this->assertSame( 2, $acknowledgements['occasion:canada-day']['user_id'] );
	}

	/**
	 * Asserts recording an acknowledgement leaves an unrelated scope's
	 * entry untouched.
	 */
	public function test_record_does_not_disturb_a_different_scope(): void {
		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'rule-a', 3.0, 'hash-1', 1 );
		$acknowledgements = blueline_record_acknowledgement( $acknowledgements, 'occasion:remembrance-day', 'rule-b', 4.0, 'hash-2', 2 );

		$this->assertSame( 'rule-a', $acknowledgements['occasion:canada-day']['rule_id'] );
		$this->assertSame( 'rule-b', $acknowledgements['occasion:remembrance-day']['rule_id'] );
	}

	/**
	 * Asserts invalidating removes exactly the named scope's entry.
	 */
	public function test_invalidate_removes_only_the_named_scope(): void {
		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'rule-a', 3.0, 'hash-1', 1 );
		$acknowledgements = blueline_record_acknowledgement( $acknowledgements, 'occasion:remembrance-day', 'rule-b', 4.0, 'hash-2', 2 );

		$acknowledgements = blueline_invalidate_acknowledgement( $acknowledgements, 'occasion:canada-day' );

		$this->assertArrayNotHasKey( 'occasion:canada-day', $acknowledgements );
		$this->assertArrayHasKey( 'occasion:remembrance-day', $acknowledgements );
	}

	/**
	 * Asserts invalidating a scope with no entry is a harmless no-op.
	 */
	public function test_invalidate_is_a_no_op_for_an_unknown_scope(): void {
		$this->assertSame( array(), blueline_invalidate_acknowledgement( array(), 'occasion:canada-day' ) );
	}

	/* -------------------------------------------------------------- covers */

	/**
	 * Asserts a matching rule id, ratio, and inputs hash all together
	 * cover the scope.
	 */
	public function test_covers_true_when_rule_ratio_and_hash_all_match(): void {
		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1', 7 );

		$this->assertTrue(
			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1' )
		);
	}

	/**
	 * Asserts no entry for the scope at all answers false.
	 */
	public function test_covers_false_when_no_entry_exists_for_the_scope(): void {
		$this->assertFalse(
			blueline_acknowledgement_covers( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1' )
		);
	}

	/**
	 * Asserts a stale inputs hash (style.css or contrast-rules.json
	 * changed since the acknowledgement) answers false, even though the
	 * rule id and ratio still match -- per the design spec's §4.5, this is
	 * "unacknowledged", not an error.
	 */
	public function test_covers_false_when_inputs_hash_is_stale(): void {
		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-old', 7 );

		$this->assertFalse(
			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-new' )
		);
	}

	/**
	 * Asserts a different rule id answers false, even with the same ratio
	 * and hash.
	 */
	public function test_covers_false_when_rule_id_differs(): void {
		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1', 7 );

		$this->assertFalse(
			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'paper-not-text-on-occasion-accent', 3.2, 'hash-1' )
		);
	}

	/**
	 * Asserts a changed ratio (the admin edited the value since
	 * acknowledging) answers false, even with the same rule id and hash.
	 */
	public function test_covers_false_when_ratio_differs(): void {
		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1', 7 );

		$this->assertFalse(
			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'ink-on-occasion-accent', 5.0, 'hash-1' )
		);
	}

	/**
	 * Asserts a floating-point ratio that is equal within a tiny tolerance
	 * still covers -- the stored value and the freshly-computed value are
	 * two independent float computations, never assumed bit-identical.
	 */
	public function test_covers_true_within_a_small_float_tolerance(): void {
		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1', 7 );

		$this->assertTrue(
			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'ink-on-occasion-accent', 3.2 + 1.0e-9, 'hash-1' )
		);
	}

	/**
	 * Integration-style: uses the real blueline_settings_inputs_hash()
	 * (Task 4) to prove the mechanism composes with it exactly as Phase
	 * 2.1's resolver will -- record with the real current hash, then check
	 * coverage against that same real current hash.
	 */
	public function test_composes_with_the_real_inputs_hash(): void {
		$current_hash     = blueline_settings_inputs_hash();
		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, $current_hash, 7 );

		$this->assertTrue(
			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, $current_hash )
		);
	}
}
