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
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';

/**
 * Covers blueline_settings_inputs_hash(): a single hash that changes if,
 * and only if, style.css's own last-edit time or contrast-rules.json's
 * rules/thresholds change.
 */
final class SettingsInputsHashTest extends TestCase {

	/**
	 * Reset the options store before each test -- needed starting with
	 * this task's own blueline_validated_against() tests; harmless for
	 * this file's pre-existing fixture-file-based tests, which never
	 * touch the options store at all.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Writes a minimal fixture contrast-rules.json and returns its path.
	 *
	 * @param array $data Decoded content to encode.
	 * @return string Path to the fixture file.
	 */
	private function write_fixture( array $data ): string {
		$path = sys_get_temp_dir() . '/blueline-rules-' . uniqid( '', true ) . '.json';
		file_put_contents( $path, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture.
		return $path;
	}

	/**
	 * Asserts the same inputs (style.css unchanged, same rules file
	 * content) always hash identically.
	 */
	public function test_stable_for_identical_inputs(): void {
		$path = $this->write_fixture(
			array(
				'rules'      => array(
					array(
						'id'  => 'a',
						'fg'  => '--x',
						'bg'  => '--y',
						'min' => 4.5,
					),
				),
				'thresholds' => array(
					'body'  => 4.5,
					'large' => 3.0,
				),
			)
		);

		try {
			$this->assertSame(
				blueline_settings_inputs_hash( $path ),
				blueline_settings_inputs_hash( $path )
			);
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts changing contrast-rules.json's "rules" changes the hash.
	 */
	public function test_changes_when_rules_change(): void {
		$before = $this->write_fixture(
			array(
				'rules'      => array(
					array(
						'id'  => 'a',
						'fg'  => '--x',
						'bg'  => '--y',
						'min' => 4.5,
					),
				),
				'thresholds' => array(
					'body'  => 4.5,
					'large' => 3.0,
				),
			)
		);
		$after  = $this->write_fixture(
			array(
				'rules'      => array(
					array(
						'id'  => 'a',
						'fg'  => '--x',
						'bg'  => '--y',
						'min' => 7.0,
					),
				),
				'thresholds' => array(
					'body'  => 4.5,
					'large' => 3.0,
				),
			)
		);

		try {
			$this->assertNotSame(
				blueline_settings_inputs_hash( $before ),
				blueline_settings_inputs_hash( $after )
			);
		} finally {
			unlink( $before ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
			unlink( $after ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts changing contrast-rules.json's "thresholds" changes the
	 * hash.
	 */
	public function test_changes_when_thresholds_change(): void {
		$before = $this->write_fixture(
			array(
				'rules'      => array(),
				'thresholds' => array(
					'body'  => 4.5,
					'large' => 3.0,
				),
			)
		);
		$after  = $this->write_fixture(
			array(
				'rules'      => array(),
				'thresholds' => array(
					'body'  => 7.0,
					'large' => 3.0,
				),
			)
		);

		try {
			$this->assertNotSame(
				blueline_settings_inputs_hash( $before ),
				blueline_settings_inputs_hash( $after )
			);
		} finally {
			unlink( $before ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
			unlink( $after ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts editing an unrelated top-level key (e.g. "$comment") does
	 * NOT change the hash -- only "rules" and "thresholds" are hashed, per
	 * the design spec's §4.3, so a comment-only edit does not invalidate
	 * every live acknowledgement.
	 */
	public function test_unrelated_top_level_keys_do_not_change_the_hash(): void {
		$without_comment = $this->write_fixture(
			array(
				'rules'      => array(),
				'thresholds' => array(
					'body'  => 4.5,
					'large' => 3.0,
				),
			)
		);
		$with_comment    = $this->write_fixture(
			array(
				'$comment'   => 'a documentation-only edit',
				'version'    => 2,
				'rules'      => array(),
				'thresholds' => array(
					'body'  => 4.5,
					'large' => 3.0,
				),
			)
		);

		try {
			$this->assertSame(
				blueline_settings_inputs_hash( $without_comment ),
				blueline_settings_inputs_hash( $with_comment )
			);
		} finally {
			unlink( $without_comment ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
			unlink( $with_comment ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts a missing or malformed contrast-rules.json degrades to
	 * hashing an empty rules/thresholds set rather than fataling.
	 */
	public function test_missing_rules_file_does_not_fatal(): void {
		$hash = blueline_settings_inputs_hash( '/nonexistent/contrast-rules.json' );

		$this->assertIsString( $hash );
		$this->assertNotSame( '', $hash );
	}

	/**
	 * Asserts calling with no arguments at all (the real production
	 * contract) resolves against the real, committed
	 * tools/contrast-rules.json without fataling.
	 */
	public function test_real_no_argument_call_does_not_fatal(): void {
		$hash = blueline_settings_inputs_hash();

		$this->assertIsString( $hash );
		$this->assertNotSame( '', $hash );
	}

	/* -------------------------------------------------- validated_against */

	/**
	 * Covers blueline_validated_against() (design spec §6.5's
	 * storage-shape ruling): a fresh install (nothing stored at all)
	 * reads as ''.
	 */
	public function test_validated_against_defaults_to_empty_string(): void {
		$this->assertSame( '', blueline_validated_against() );
	}

	/**
	 * Reads back whatever was actually stored.
	 */
	public function test_validated_against_reads_the_stored_value(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( '_validated_against' => 'abc123hash' ) );

		$this->assertSame( 'abc123hash', blueline_validated_against() );
	}

	/**
	 * A malformed stored value (not a string) reads back as '' rather
	 * than being trusted verbatim -- matching
	 * blueline_stored_acknowledgements()'s own defensive posture for the
	 * same shape of bad data.
	 */
	public function test_validated_against_repairs_a_non_string_stored_value(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( '_validated_against' => array( 'not' => 'a string' ) ) );

		$this->assertSame( '', blueline_validated_against() );
	}
}
