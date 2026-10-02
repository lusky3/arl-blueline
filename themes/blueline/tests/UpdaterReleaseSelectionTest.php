<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/updater-rules.php';

/**
 * Covers release selection by channel, tag parsing and manifest validation.
 */
final class UpdaterReleaseSelectionTest extends TestCase {

	/**
	 * Build a GitHub-shaped release with both assets by default.
	 *
	 * @param string               $tag  Tag name.
	 * @param array<string, mixed> $over Field overrides.
	 * @return array<string, mixed>
	 */
	private function release( string $tag, array $over = array() ): array {
		$version = ltrim( $tag, 'v' );
		$api     = 'https://api.github.com/repos/lusky3/rookiehockey-blueline/releases/assets/';

		return array_merge(
			array(
				'tag_name'   => $tag,
				'draft'      => false,
				'prerelease' => false !== strpos( $tag, '-' ),
				'html_url'   => 'https://github.com/lusky3/rookiehockey-blueline/releases/tag/' . $tag,
				'assets'     => array(
					array(
						'name' => 'blueline-' . $version . '.zip',
						'url'  => $api . '1',
					),
					array(
						'name' => 'manifest.json',
						'url'  => $api . '2',
					),
				),
			),
			$over
		);
	}

	/**
	 * The versions of the eligible releases, newest first.
	 *
	 * @param array<int, array<string, mixed>> $releases  Releases.
	 * @param string                           $installed Installed version.
	 * @param string                           $channel   Channel.
	 * @return string[]
	 */
	private function versions( array $releases, string $installed, string $channel ): array {
		return array_column( blueline_updater_eligible_releases( $releases, $installed, $channel ), 'version' );
	}

	/**
	 * Tag grammar.
	 *
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function tags(): array {
		return array(
			'final'          => array( 'v1.2.3', '1.2.3' ),
			'rc'             => array( 'v1.2.3-rc.1', '1.2.3-rc.1' ),
			'uppercase V'    => array( 'V1.2.3', null ),
			'no v'           => array( '1.2.3', null ),
			'two parts'      => array( 'v1.2', null ),
			'build metadata' => array( 'v1.2.3+build', null ),
			'dangling dash'  => array( 'v1.2.3-', null ),
			'leading zero'   => array( 'v01.2.3', null ),
			'empty'          => array( '', null ),
		);
	}

	/**
	 * Tag parsing.
	 *
	 * @param string  $tag    Tag.
	 * @param ?string $expect Expected version.
	 */
	#[DataProvider( 'tags' )]
	public function test_parse_tag_cases( string $tag, ?string $expect ): void {
		$this->assertSame( $expect, blueline_updater_parse_tag( $tag ) );
	}

	/**
	 * A hand-set prerelease flag must not be what keeps rc builds off production.
	 */
	public function test_stable_skips_prerelease_flag_and_rc_suffix(): void {
		$releases = array(
			$this->release( 'v1.1.0-rc.1', array( 'prerelease' => false ) ),
			$this->release( 'v1.0.9', array( 'prerelease' => true ) ),
		);

		$this->assertSame( array(), $this->versions( $releases, '1.0.1', 'stable' ) );
		$this->assertSame( array( '1.1.0-rc.1', '1.0.9' ), $this->versions( $releases, '1.0.1', 'beta' ) );
	}

	/**
	 * Beta takes the newest of either kind.
	 */
	public function test_beta_prefers_newest_of_either(): void {
		$releases = array( $this->release( 'v1.0.9' ), $this->release( 'v1.1.0-rc.1' ), $this->release( 'v1.1.0' ), $this->release( 'v1.1.0-rc.2' ) );

		$this->assertSame( array( '1.1.0', '1.1.0-rc.2', '1.1.0-rc.1', '1.0.9' ), $this->versions( $releases, '1.0.1', 'beta' ) );
		$this->assertSame( array( '1.1.0', '1.0.9' ), $this->versions( $releases, '1.0.1', 'stable' ) );
	}

	/**
	 * Ordering around release candidates.
	 */
	public function test_rc_ordering_and_final_beats_rc(): void {
		$rc2 = $this->release( 'v1.1.0-rc.2' );
		$rc1 = $this->release( 'v1.1.0-rc.1' );

		$this->assertSame( array( '1.1.0-rc.2' ), $this->versions( array( $rc1, $rc2 ), '1.1.0-rc.1', 'beta' ) );
		$this->assertSame( array(), $this->versions( array( $rc1 ), '1.1.0-rc.1', 'beta' ), 'same version is not newer' );
		$this->assertSame( array( '1.1.0-rc.1' ), $this->versions( array( $rc1 ), '1.0.1', 'beta' ) );
		$this->assertSame( array(), $this->versions( array( $rc1 ), '1.1.0', 'beta' ), 'a final install is never offered its own rc' );
		$this->assertSame( array( '1.1.0-rc.10' ), $this->versions( array( $this->release( 'v1.1.0-rc.10' ), $this->release( 'v1.1.0-rc.9' ) ), '1.1.0-rc.9', 'beta' ) );
	}

	/**
	 * Drafts, missing assets and non-newer releases never qualify.
	 */
	public function test_drafts_missing_assets_and_not_newer_are_skipped(): void {
		$no_manifest                         = $this->release( 'v1.3.0' );
		$no_manifest['assets']               = array_slice( $no_manifest['assets'], 0, 1 );
		$no_zip                              = $this->release( 'v1.4.0' );
		$no_zip['assets']                    = array_slice( $no_zip['assets'], 1 );
		$wrong_zip_name                      = $this->release( 'v1.5.0' );
		$wrong_zip_name['assets'][0]['name'] = 'blueline-9.9.9.zip';

		$releases = array(
			$this->release( 'v2.0.0', array( 'draft' => true ) ),
			$no_manifest,
			$no_zip,
			$wrong_zip_name,
			$this->release( 'v1.0.1' ),
			$this->release( 'v1.0.0' ),
			$this->release( 'not-a-tag' ),
			$this->release( 'v1.2.0' ),
		);

		$this->assertSame( array( '1.2.0' ), $this->versions( $releases, '1.0.1', 'stable' ) );
	}

	/**
	 * An asset URL that is not on this repo's API is never offered.
	 */
	public function test_asset_with_foreign_url_is_skipped(): void {
		$release                     = $this->release( 'v1.2.0' );
		$release['assets'][0]['url'] = 'https://api.github.com/repos/lusky3/other/releases/assets/1';

		$this->assertSame( array(), $this->versions( array( $release ), '1.0.1', 'stable' ) );
	}

	/**
	 * The client falls back to the next-older candidate, so all are returned.
	 */
	public function test_returns_multiple_candidates_newest_first(): void {
		$result = blueline_updater_eligible_releases( array( $this->release( 'v1.1.0' ), $this->release( 'v1.2.0' ) ), '1.0.1', 'stable' );

		$this->assertSame( array( '1.2.0', '1.1.0' ), array_column( $result, 'version' ) );
		$this->assertSame( 'blueline-1.2.0.zip', $result[0]['zip_asset']['name'] );
		$this->assertSame( 'manifest.json', $result[0]['manifest_asset']['name'] );
		$this->assertSame( 'v1.2.0', $result[0]['release']['tag_name'] );
	}

	/**
	 * Non-array entries in the API list are ignored.
	 */
	public function test_garbage_entries_are_ignored(): void {
		$releases = array( 'x', null, 5, array( 'tag_name' => array() ), $this->release( 'v1.2.0' ) );

		$this->assertSame( array( '1.2.0' ), $this->versions( $releases, '1.0.1', 'stable' ) );
	}

	/**
	 * A well-formed manifest for version 1.2.0.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest(): array {
		return array(
			'version'      => '1.2.0',
			'requires_wp'  => '6.9',
			'requires_php' => '8.3',
			'tested_wp'    => '7.1',
			'zip'          => 'blueline-1.2.0.zip',
			'sha256'       => str_repeat( 'AB', 32 ),
			'changelog'    => '- x',
		);
	}

	/**
	 * A valid manifest is accepted and its sha256 lower-cased.
	 */
	public function test_validate_manifest_accepts_and_lowercases_sha(): void {
		$result = blueline_updater_validate_manifest( $this->manifest(), '1.2.0' );

		$this->assertIsArray( $result );
		$this->assertSame( str_repeat( 'ab', 32 ), $result['sha256'] );
		$this->assertSame( '6.9', $result['requires_wp'] );
	}

	/**
	 * Broken manifests.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_manifests(): array {
		$good = array(
			'version'      => '1.2.0',
			'requires_wp'  => '6.9',
			'requires_php' => '8.3',
			'tested_wp'    => '7.1',
			'zip'          => 'blueline-1.2.0.zip',
			'sha256'       => str_repeat( 'a', 64 ),
			'changelog'    => '',
		);

		return array(
			'not an array'   => array( 'nope' ),
			'wrong version'  => array( array_merge( $good, array( 'version' => '1.2.1' ) ) ),
			'wrong zip name' => array( array_merge( $good, array( 'zip' => 'blueline-9.9.9.zip' ) ) ),
			'short sha'      => array( array_merge( $good, array( 'sha256' => str_repeat( 'a', 63 ) ) ) ),
			'non-hex sha'    => array( array_merge( $good, array( 'sha256' => str_repeat( 'g', 64 ) ) ) ),
			'missing sha'    => array( array_diff_key( $good, array( 'sha256' => 1 ) ) ),
			'missing zip'    => array( array_diff_key( $good, array( 'zip' => 1 ) ) ),
			'array version'  => array( array_merge( $good, array( 'version' => array( '1.2.0' ) ) ) ),
		);
	}

	/**
	 * Rejections.
	 *
	 * @param mixed $manifest Manifest.
	 */
	#[DataProvider( 'bad_manifests' )]
	public function test_validate_manifest_rejects( $manifest ): void {
		$this->assertNull( blueline_updater_validate_manifest( $manifest, '1.2.0' ) );
	}

	/**
	 * Only an exact "beta" opts in.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function channels(): array {
		return array(
			'beta'    => array( 'beta', 'beta' ),
			'padded'  => array( ' BETA ', 'beta' ),
			'stable'  => array( 'stable', 'stable' ),
			'empty'   => array( '', 'stable' ),
			'null'    => array( null, 'stable' ),
			'unknown' => array( 'nightly', 'stable' ),
			'int'     => array( 123, 'stable' ),
		);
	}

	/**
	 * Channel resolution.
	 *
	 * @param mixed  $configured Configured value.
	 * @param string $expect     Expected channel.
	 */
	#[DataProvider( 'channels' )]
	public function test_resolve_channel( $configured, string $expect ): void {
		$this->assertSame( $expect, blueline_updater_resolve_channel( $configured ) );
	}

	/**
	 * Asset names are bound to the version, never taken from the manifest.
	 */
	public function test_asset_names(): void {
		$this->assertSame(
			array(
				'zip'      => 'blueline-1.2.0-rc.1.zip',
				'manifest' => 'manifest.json',
			),
			blueline_updater_asset_names( '1.2.0-rc.1' )
		);
	}
}
