<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

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
}
