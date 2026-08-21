<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/cli-stubs.php';
require_once __DIR__ . '/../inc/settings/tokens.php';

/**
 * Covers blueline_token_manifest()'s defensive read of tools/tokens.json
 * and blueline_token_is_tunable()'s lookup against it.
 */
final class SettingsTokensTest extends TestCase {

	/**
	 * Asserts a missing manifest file falls back to an empty manifest
	 * rather than fataling.
	 */
	public function test_missing_file_returns_empty_manifest(): void {
		$this->assertSame( array(), blueline_token_manifest( '/nonexistent/tokens.json' ) );
	}

	/**
	 * Asserts malformed JSON falls back to an empty manifest rather than
	 * fataling.
	 */
	public function test_malformed_json_returns_empty_manifest(): void {
		$path = sys_get_temp_dir() . '/blueline-tokens-malformed-' . uniqid( '', true ) . '.json';
		file_put_contents( $path, '{ not valid json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture.

		try {
			$this->assertSame( array(), blueline_token_manifest( $path ) );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts a file missing the top-level "tokens" object falls back to
	 * an empty manifest rather than fataling.
	 */
	public function test_missing_tokens_key_returns_empty_manifest(): void {
		$path = sys_get_temp_dir() . '/blueline-tokens-notokens-' . uniqid( '', true ) . '.json';
		file_put_contents( $path, wp_json_encode( array( 'version' => 1 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture.

		try {
			$this->assertSame( array(), blueline_token_manifest( $path ) );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts a well-formed manifest parses every entry's five keys, and
	 * that a malformed individual entry (not an array) is dropped rather
	 * than corrupting the whole read.
	 */
	public function test_parses_valid_entries_and_drops_a_malformed_one(): void {
		$path = sys_get_temp_dir() . '/blueline-tokens-valid-' . uniqid( '', true ) . '.json';
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture.
			$path,
			wp_json_encode(
				array(
					'tokens' => array(
						'--bl-ink'             => array(
							'type'    => 'color',
							'group'   => 'brand',
							'tier'    => 'brand',
							'bounds'  => null,
							'tunable' => false,
						),
						'--bl-occasion-accent' => array(
							'type'    => 'color',
							'group'   => 'occasion',
							'tier'    => 'occasion',
							'bounds'  => array( 'contrast_rules' => array( 'ink-on-occasion-accent' ) ),
							'tunable' => true,
						),
						'--bl-broken'          => 'not an array',
					),
				)
			)
		);

		try {
			$manifest = blueline_token_manifest( $path );

			$this->assertArrayNotHasKey( '--bl-broken', $manifest );
			$this->assertFalse( $manifest['--bl-ink']['tunable'] );
			$this->assertTrue( $manifest['--bl-occasion-accent']['tunable'] );
			$this->assertSame(
				array( 'contrast_rules' => array( 'ink-on-occasion-accent' ) ),
				$manifest['--bl-occasion-accent']['bounds']
			);
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts blueline_token_is_tunable() reads the manifest correctly for
	 * a tunable token, a declared non-tunable token, and an unknown name.
	 */
	public function test_is_tunable_reads_the_real_manifest(): void {
		$this->assertTrue( blueline_token_is_tunable( '--bl-occasion-accent' ) );
		$this->assertFalse( blueline_token_is_tunable( '--bl-ink' ) );
		$this->assertFalse( blueline_token_is_tunable( '--bl-does-not-exist' ) );
	}

	/**
	 * Asserts the real, committed tools/tokens.json marks exactly one
	 * token tunable.
	 */
	public function test_real_manifest_has_exactly_one_tunable_token(): void {
		$manifest = blueline_token_manifest();
		$tunable  = array_filter( $manifest, static fn( $entry ) => $entry['tunable'] );

		$this->assertCount( 1, $tunable );
		$this->assertArrayHasKey( '--bl-occasion-accent', $tunable );
	}

	/**
	 * Asserts the read-failure trace hook never fatals and is safe to call
	 * repeatedly.
	 */
	public function test_read_failure_hook_never_fatals(): void {
		blueline_token_manifest_read_failure( '/nonexistent/tokens.json', 'missing or unreadable' );
		blueline_token_manifest_read_failure( '/nonexistent/tokens.json', 'missing or unreadable' );

		$this->addToAssertionCount( 1 );
	}
}
