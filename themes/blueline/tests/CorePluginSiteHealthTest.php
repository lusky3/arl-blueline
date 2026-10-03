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
