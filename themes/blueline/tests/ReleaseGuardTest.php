<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/release/lib.php';
require_once __DIR__ . '/../inc/updater-rules.php';

/**
 * Covers the release guard and manifest builder (scripts/release/lib.php).
 */
final class ReleaseGuardTest extends TestCase {

	/**
	 * A style.css header block.
	 *
	 * @param string $version Version header.
	 * @return string
	 */
	private function style( string $version ): string {
		return "/*\nTheme Name: Blueline\nVersion: {$version}\nRequires at least: 6.9\nTested up to: 7.1\nRequires PHP: 8.3\n*/\n";
	}

	/**
	 * A functions.php fragment.
	 *
	 * @param string $version Constant value.
	 * @return string
	 */
	private function functions( string $version ): string {
		return "<?php\ndefine( 'BLUELINE_VERSION', '{$version}' );\n";
	}

	/**
	 * Everything agrees.
	 */
	public function test_guard_passes_when_all_agree(): void {
		$this->assertSame(
			array(),
			blueline_release_guard( 'v1.2.0-rc.1', $this->style( '1.2.0-rc.1' ), $this->functions( '1.2.0-rc.1' ), "## 1.2.0-rc.1\n- x\n" )
		);
	}

	/**
	 * Tag and style.css disagree.
	 */
	public function test_guard_fails_on_tag_style_mismatch(): void {
		$errors = blueline_release_guard( 'v1.2.0', $this->style( '1.1.0' ), $this->functions( '1.1.0' ), "## 1.2.0\n- x\n" );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'style.css Version', $errors[0] );
	}

	/**
	 * The constant drifted from style.css.
	 */
	public function test_guard_fails_on_constant_mismatch(): void {
		$errors = blueline_release_guard( 'v1.2.0', $this->style( '1.2.0' ), $this->functions( '1.0.1' ), "## 1.2.0\n- x\n" );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'BLUELINE_VERSION', $errors[0] );
	}

	/**
	 * No changelog entry at all.
	 */
	public function test_guard_fails_on_missing_changelog(): void {
		$errors = blueline_release_guard( 'v1.2.0', $this->style( '1.2.0' ), $this->functions( '1.2.0' ), "## [Unreleased]\n- x\n" );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'CHANGELOG', $errors[0] );
	}

	/**
	 * A heading with nothing under it is not an entry.
	 */
	public function test_guard_fails_on_empty_changelog_section(): void {
		$errors = blueline_release_guard( 'v1.2.0', $this->style( '1.2.0' ), $this->functions( '1.2.0' ), "## 1.2.0\n\n## 1.1.0\n- x\n" );

		$this->assertCount( 1, $errors );
	}

	/**
	 * Tags outside the grammar.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function bad_tags(): array {
		return array(
			'two parts'      => array( 'v1.2' ),
			'uppercase V'    => array( 'V1.2.0' ),
			'no v'           => array( '1.2.0' ),
			'build metadata' => array( 'v1.2.0+b' ),
			'dangling dash'  => array( 'v1.2.0-' ),
		);
	}

	/**
	 * Bad tags.
	 *
	 * @param string $tag Tag.
	 */
	#[DataProvider( 'bad_tags' )]
	public function test_guard_fails_on_bad_tag( string $tag ): void {
		$errors = blueline_release_guard( $tag, $this->style( '1.2.0' ), $this->functions( '1.2.0' ), "## 1.2.0\n- x\n" );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'not v', $errors[0] );
	}

	/**
	 * The script's tag grammar and the theme's must not drift apart.
	 */
	public function test_tag_grammar_matches_the_theme_updater(): void {
		$samples = array( 'v1.2.3', 'v1.2.3-rc.1', 'V1.2.3', '1.2.3', 'v1.2', 'v1.2.3+b', 'v1.2.3-', 'v01.2.3', '', 'v1.2.3-rc..1' );

		foreach ( $samples as $tag ) {
			$this->assertSame( blueline_updater_parse_tag( $tag ), blueline_release_parse_tag( $tag ), $tag );
		}
	}

	/**
	 * Heading styles.
	 */
	public function test_changelog_section_heading_styles(): void {
		$md = "# Changelog\n\n## [Unreleased]\n- u\n\n## [1.2.0] - 2026-10-02\n### Added\n- a\n\n## 1.1.0\n- b\n\n## 1.0.0-rc.1 - 2026-01-01\n- c\n";

		$this->assertSame( "### Added\n- a", blueline_release_changelog_section( $md, '1.2.0' ) );
		$this->assertSame( '- b', blueline_release_changelog_section( $md, '1.1.0' ) );
		$this->assertSame( '- c', blueline_release_changelog_section( $md, '1.0.0-rc.1' ) );
		$this->assertNull( blueline_release_changelog_section( $md, '1.0.0' ), 'prefix of another heading' );
		$this->assertNull( blueline_release_changelog_section( "## [Unreleased]\n- u\n", '1.2.0' ), 'Unreleased is not a version' );
	}

	/**
	 * Requirements come from style.css, not from constants in the script.
	 */
	public function test_manifest_shape_and_header_sourced_requirements(): void {
		$manifest = blueline_release_manifest( '1.2.0', $this->style( '1.2.0' ), 'blueline-1.2.0.zip', str_repeat( 'a', 64 ), '- x' );

		$this->assertSame(
			array( 'version', 'requires_wp', 'requires_php', 'tested_wp', 'zip', 'sha256', 'changelog' ),
			array_keys( $manifest )
		);
		$this->assertSame( '6.9', $manifest['requires_wp'] );
		$this->assertSame( '8.3', $manifest['requires_php'] );
		$this->assertSame( '7.1', $manifest['tested_wp'] );
	}

	/**
	 * Producer and consumer cannot drift.
	 */
	public function test_manifest_json_round_trips_through_theme_validator(): void {
		$manifest = blueline_release_manifest( '1.2.0-rc.1', $this->style( '1.2.0-rc.1' ), 'blueline-1.2.0-rc.1.zip', hash( 'sha256', 'x' ), '- x' );
		$decoded  = json_decode( (string) json_encode( $manifest ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- mirrors scripts/release/build-manifest.php, which has no WordPress.

		$this->assertNotNull( blueline_updater_validate_manifest( $decoded, '1.2.0-rc.1' ) );
	}
}
