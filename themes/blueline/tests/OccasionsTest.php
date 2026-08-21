<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/occasions.php';

/**
 * Covers blueline_occasion_accent_default()'s narrow, one-hop resolution
 * of --bl-occasion-accent's declared default against a real or fixture
 * style.css.
 */
final class OccasionsTest extends TestCase {

	/**
	 * Writes a minimal fixture stylesheet and returns its path.
	 *
	 * @param string $css Fixture CSS content.
	 * @return string Path to the fixture file.
	 */
	private function write_fixture( string $css ): string {
		$path = sys_get_temp_dir() . '/blueline-occasions-' . uniqid( '', true ) . '.css';
		file_put_contents( $path, $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture.
		return $path;
	}

	/**
	 * Asserts the real, committed style.css resolves to --bl-ice's real
	 * literal value.
	 */
	public function test_resolves_the_real_stylesheet(): void {
		$this->assertSame( '#74c0e1', blueline_occasion_accent_default() );
	}

	/**
	 * Asserts a minimal fixture with a different --bl-ice value resolves
	 * correctly, proving this reads the referenced token's OWN value
	 * rather than a hardcoded literal.
	 */
	public function test_resolves_a_fixture_with_a_different_ice_value(): void {
		$path = $this->write_fixture(
			":root {\n\t--bl-ice: #123456;\n\t--bl-occasion-accent: var(--bl-ice);\n}"
		);

		try {
			$this->assertSame( '#123456', blueline_occasion_accent_default( $path ) );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts a mention of --bl-occasion-accent inside a comment is not
	 * mistaken for the real declaration -- the same regression
	 * tools/lib/css-tokens.mjs's own test suite guards against.
	 */
	public function test_ignores_a_mention_inside_a_comment(): void {
		$path = $this->write_fixture(
			"/* --bl-occasion-accent: var(--bl-danger); */\n:root {\n\t--bl-ice: #abcdef;\n\t--bl-occasion-accent: var(--bl-ice);\n}"
		);

		try {
			$this->assertSame( '#abcdef', blueline_occasion_accent_default( $path ) );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts a missing stylesheet returns '' rather than fataling.
	 */
	public function test_returns_empty_string_when_file_missing(): void {
		$this->assertSame( '', blueline_occasion_accent_default( '/nonexistent/style.css' ) );
	}

	/**
	 * Asserts a stylesheet with no :root rule at all returns '' rather
	 * than fataling.
	 */
	public function test_returns_empty_string_when_no_root_rule(): void {
		$path = $this->write_fixture( 'body { color: red; }' );

		try {
			$this->assertSame( '', blueline_occasion_accent_default( $path ) );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts a --bl-occasion-accent declared as a literal (not a single
	 * var() reference) returns '' rather than guessing.
	 */
	public function test_returns_empty_string_when_not_declared_as_a_var_reference(): void {
		$path = $this->write_fixture( ":root {\n\t--bl-occasion-accent: #74c0e1;\n}" );

		try {
			$this->assertSame( '', blueline_occasion_accent_default( $path ) );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts a referenced token that is not itself a plain hex literal
	 * (here, undeclared entirely) returns '' rather than guessing.
	 */
	public function test_returns_empty_string_when_referenced_token_is_not_a_hex_literal(): void {
		$path = $this->write_fixture( ":root {\n\t--bl-occasion-accent: var(--bl-undeclared);\n}" );

		try {
			$this->assertSame( '', blueline_occasion_accent_default( $path ) );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
		}
	}

	/**
	 * Asserts the read-failure trace hook never fatals and is safe to call
	 * repeatedly.
	 */
	public function test_read_failure_hook_never_fatals(): void {
		blueline_occasion_accent_default_read_failure( '/nonexistent/style.css', 'missing or unreadable' );
		blueline_occasion_accent_default_read_failure( '/nonexistent/style.css', 'missing or unreadable' );

		$this->addToAssertionCount( 1 );
	}
}
