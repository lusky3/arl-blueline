<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/site-health.php';

/**
 * Covers the wp-admin notice shown when Blueline Core is not active.
 */
final class CorePluginNoticeTest extends TestCase {

	/**
	 * A loaded plugin needs no notice.
	 */
	public function test_loaded_plugin_has_no_model(): void {
		$installed = array( 'blueline-core/blueline-core.php' => array() );

		$this->assertNull( blueline_core_plugin_notice_model( true, $installed ) );
	}

	/**
	 * Installed but not loaded: inactive, carrying the plugin file to activate.
	 */
	public function test_installed_but_not_loaded_is_inactive(): void {
		$installed = array(
			'akismet/akismet.php'             => array(),
			'blueline-core/blueline-core.php' => array(),
		);

		$this->assertSame(
			array(
				'state' => 'inactive',
				'file'  => 'blueline-core/blueline-core.php',
			),
			blueline_core_plugin_notice_model( false, $installed )
		);
	}

	/**
	 * A renamed folder still counts as installed.
	 */
	public function test_renamed_folder_is_still_detected(): void {
		$model = blueline_core_plugin_notice_model( false, array( 'blueline-core-0.1.0/blueline-core.php' => array() ) );

		$this->assertSame( 'inactive', $model['state'] );
		$this->assertSame( 'blueline-core-0.1.0/blueline-core.php', $model['file'] );
	}

	/**
	 * Not installed at all: missing, with no file.
	 */
	public function test_absent_plugin_is_missing(): void {
		$model = blueline_core_plugin_notice_model( false, array( 'akismet/akismet.php' => array() ) );

		$this->assertSame( 'missing', $model['state'] );
		$this->assertSame( '', $model['file'] );
	}

	/**
	 * Another plugin whose file merely resembles the name is not it.
	 */
	public function test_lookalike_file_is_not_matched(): void {
		$model = blueline_core_plugin_notice_model( false, array( 'x/not-blueline-core.php' => array() ) );

		$this->assertSame( 'missing', $model['state'] );
	}

	/**
	 * Nothing to say renders nothing.
	 */
	public function test_html_is_empty_without_a_model(): void {
		$this->assertSame( '', blueline_core_plugin_notice_html( null, 'a', 'b' ) );
	}

	/**
	 * Inactive: says so and offers the activation link.
	 */
	public function test_inactive_html_offers_activation(): void {
		$html = blueline_core_plugin_notice_html(
			array(
				'state' => 'inactive',
				'file'  => 'blueline-core/blueline-core.php',
			),
			'https://example.test/activate',
			'https://example.test/upload'
		);

		$this->assertStringContainsString( 'notice notice-warning', $html );
		$this->assertStringContainsString( 'installed but not active', $html );
		$this->assertStringContainsString( 'href="https://example.test/activate"', $html );
		$this->assertStringNotContainsString( 'example.test/upload', $html );
	}

	/**
	 * Missing: says so and points at the upload screen.
	 */
	public function test_missing_html_offers_upload(): void {
		$html = blueline_core_plugin_notice_html(
			array(
				'state' => 'missing',
				'file'  => '',
			),
			'https://example.test/activate',
			'https://example.test/upload'
		);

		$this->assertStringContainsString( 'is not installed', $html );
		$this->assertStringContainsString( 'href="https://example.test/upload"', $html );
		$this->assertStringNotContainsString( 'example.test/activate', $html );
	}

	/**
	 * Registered on admin_notices at file scope.
	 */
	public function test_notice_is_hooked_on_admin_notices(): void {
		$this->assertMatchesRegularExpression( "/^add_action\\( 'admin_notices', 'blueline_render_core_plugin_notice' \\);/m", (string) file_get_contents( __DIR__ . '/../inc/settings/site-health.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test; wp_remote_get() is for HTTP.
	}
}
