<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/site-health.php';

/**
 * Covers the "Blueline Core plugin is active" Site Health test.
 */
final class CorePluginSiteHealthTest extends TestCase {

	/**
	 * Missing plugin: a recommendation that says what is missing.
	 */
	public function test_inactive_plugin_is_a_recommendation(): void {
		$result = blueline_site_health_core_plugin_result( false );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertSame( 'Blueline Core plugin is not active', $result['label'] );
		$this->assertSame( 'blueline_core_plugin', $result['test'] );
		$this->assertStringStartsWith( '<p>', $result['description'] );
		$this->assertStringContainsString( 'mail wrapper, SEO tags and privacy hardening', $result['description'] );
	}

	/**
	 * Active plugin: good.
	 */
	public function test_active_plugin_is_good(): void {
		$result = blueline_site_health_core_plugin_result( true );

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'blue', $result['badge']['color'] );
		$this->assertSame( 'Blueline Core plugin is active', $result['label'] );
	}

	/**
	 * Loaded but older than the theme needs: a recommendation that names both
	 * versions and says how to update (the plugin has no update source).
	 */
	public function test_an_outdated_plugin_is_a_recommendation(): void {
		$result = blueline_site_health_core_plugin_result( true, '0.0.9' );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertSame( 'Blueline Core plugin is out of date', $result['label'] );
		$this->assertStringContainsString( '0.0.9', $result['description'] );
		$this->assertStringContainsString( BLUELINE_CORE_MIN_VERSION, $result['description'] );
		$this->assertStringContainsString( 'Replace current with uploaded', $result['description'] );
	}

	/**
	 * Exactly the minimum, and newer, are fine; so is a version we cannot read.
	 */
	public function test_the_minimum_and_newer_versions_are_good(): void {
		$this->assertSame( 'good', blueline_site_health_core_plugin_result( true, BLUELINE_CORE_MIN_VERSION )['status'] );
		$this->assertSame( 'good', blueline_site_health_core_plugin_result( true, '99.0.0' )['status'] );
		$this->assertSame( 'good', blueline_site_health_core_plugin_result( true, '' )['status'] );
	}

	/**
	 * Version comparison is numeric, not alphabetical: 0.1.10 is newer than 0.1.9.
	 */
	public function test_outdated_check_compares_versions_numerically(): void {
		$this->assertTrue( blueline_core_plugin_is_outdated( '0.1.0' ) );
		$this->assertFalse( blueline_core_plugin_is_outdated( BLUELINE_CORE_MIN_VERSION ) );
		$this->assertFalse( blueline_core_plugin_is_outdated( '0.1.10' ) );
		$this->assertFalse( blueline_core_plugin_is_outdated( '' ) );
	}

	/**
	 * A missing plugin stays "not active", never "out of date".
	 */
	public function test_an_inactive_plugin_is_not_reported_as_outdated(): void {
		$this->assertSame( 'Blueline Core plugin is not active', blueline_site_health_core_plugin_result( false, '0.0.1' )['label'] );
	}

	/**
	 * The theme must never demand a plugin newer than the one in this repository,
	 * or a release cut from it would warn about a version nobody can install.
	 */
	public function test_the_minimum_is_not_newer_than_the_plugin_in_the_repo(): void {
		$plugin = __DIR__ . '/../../../plugins/blueline-core/blueline-core.php';

		if ( ! is_readable( $plugin ) ) {
			$this->markTestSkipped( 'The plugin is not alongside the theme.' );
		}

		$this->assertSame( 1, preg_match( "/define\( 'BLUELINE_CORE_VERSION', '([^']+)' \)/", (string) file_get_contents( $plugin ), $m ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin source in a unit test; wp_remote_get() is for HTTP.
		$this->assertTrue( version_compare( BLUELINE_CORE_MIN_VERSION, $m[1], '<=' ), 'BLUELINE_CORE_MIN_VERSION must not exceed the plugin version in the repository.' );
	}

	/**
	 * The theme suite never loads the plugin, so the live run reports it missing.
	 */
	public function test_run_detects_the_plugin_constant(): void {
		$this->assertFalse( defined( 'BLUELINE_CORE_VERSION' ) );
		$this->assertSame( 'recommended', blueline_site_health_run_core_plugin_test()['status'] );
	}

	/**
	 * Registered as a direct test at file scope, keeping existing tests.
	 */
	public function test_registered_as_a_direct_test(): void {
		$tests = blueline_site_health_register_core_plugin_test( array( 'direct' => array( 'existing' => array() ) ) );

		$this->assertArrayHasKey( 'existing', $tests['direct'] );
		$this->assertSame( 'blueline_site_health_run_core_plugin_test', $tests['direct']['blueline_core_plugin']['test'] );
		$this->assertMatchesRegularExpression( "/^add_filter\\( 'site_status_tests', 'blueline_site_health_register_core_plugin_test' \\);/m", (string) file_get_contents( __DIR__ . '/../inc/settings/site-health.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test; wp_remote_get() is for HTTP.
	}
}
