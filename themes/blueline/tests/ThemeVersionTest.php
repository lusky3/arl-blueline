<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Pins the version sources the release workflow and updater depend on.
 */
final class ThemeVersionTest extends TestCase {

	/**
	 * Read a theme file.
	 *
	 * @param string $name File name relative to the theme root.
	 * @return string
	 */
	private function theme_file( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/../' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test; wp_remote_get() is for HTTP.
	}

	/**
	 * The style.css Version header, or ''.
	 *
	 * @return string
	 */
	private function style_version(): string {
		return 1 === preg_match( '/^Version:\s*(\S+)\s*$/m', $this->theme_file( 'style.css' ), $m ) ? $m[1] : '';
	}

	/**
	 * BLUELINE_VERSION and the style.css header must not drift.
	 */
	public function test_functions_constant_matches_style_header(): void {
		$style = $this->style_version();

		$this->assertNotSame( '', $style, 'premise: style.css Version found' );
		$this->assertSame( 1, preg_match( "/define\\(\\s*'BLUELINE_VERSION',\\s*'([^']+)'/", $this->theme_file( 'functions.php' ), $m ), 'premise: constant found' );
		$this->assertSame( $style, $m[1] );
	}

	/**
	 * The header that routes update checks to GitHub (and away from wordpress.org).
	 */
	public function test_style_header_declares_update_uri(): void {
		$this->assertMatchesRegularExpression(
			'~^Update URI:\s*https://github\.com/lusky3/rookiehockey-blueline\s*$~m',
			$this->theme_file( 'style.css' )
		);
	}

	/**
	 * The changelog keeps an Unreleased section and names the current version.
	 */
	public function test_changelog_has_unreleased_and_current_version_headings(): void {
		$changelog = $this->theme_file( 'CHANGELOG.md' );

		$this->assertStringContainsString( '## [Unreleased]', $changelog );
		$this->assertMatchesRegularExpression( '/^## \[?' . preg_quote( $this->style_version(), '/' ) . '\]?(\s|$)/m', $changelog );
	}
}
