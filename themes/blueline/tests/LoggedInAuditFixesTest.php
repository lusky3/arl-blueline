<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/admin-assets.php';

/**
 * Regressions from the logged-in audit that a unit test can pin.
 */
final class LoggedInAuditFixesTest extends TestCase {

	/**
	 * The Account & Billing dropdown is an absolutely positioned panel: opening it by default on a
	 * billing page floated it over the page's own table. The template must not render `open`.
	 */
	public function test_the_billing_dropdown_is_never_rendered_open(): void {
		$source = (string) file_get_contents( __DIR__ . '/../woocommerce/myaccount/navigation.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test.

		$this->assertDoesNotMatchRegularExpression( "/<details[^>]*<\\?php[^>]*' open'/", $source );
		$this->assertStringContainsString( 'bl-account-nav__billing', $source );
	}

	/**
	 * The "How Occasions work" summary meets the WCAG 2.5.8 24px minimum target size.
	 */
	public function test_the_occasions_summary_meets_the_target_size_minimum(): void {
		$css = blueline_settings_occasions_styles();

		$this->assertStringContainsString( '.bl-occasions__help summary', $css );
		$this->assertStringContainsString( 'min-height:24px', $css );
	}
}
