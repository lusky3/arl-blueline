<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/admin-assets.php';
require_once __DIR__ . '/../inc/account/dashboard.php';

/**
 * Regressions from the logged-in audit that a unit test can pin.
 */
final class LoggedInAuditFixesTest extends TestCase {

	/**
	 * Render the My Account navigation template on one endpoint.
	 *
	 * @param string $current Endpoint the visitor is on.
	 * @return string
	 */
	private function render_account_nav( string $current ): string {
		require_once __DIR__ . '/fixtures/account-nav-stubs.php';
		$GLOBALS['bl_test_account_nav_current'] = $current;

		ob_start();
		include __DIR__ . '/../woocommerce/myaccount/navigation.php';

		return (string) ob_get_clean();
	}

	/**
	 * The Account & Billing dropdown is an absolutely positioned panel: opening it by default on a
	 * billing page floated it over the page's own table. It renders collapsed on every endpoint,
	 * including a billing one, and holds exactly the billing tabs.
	 */
	#[RunInSeparateProcess]
	public function test_the_billing_dropdown_is_never_rendered_open(): void {
		foreach ( array( 'dashboard', 'orders', 'edit-address' ) as $current ) {
			$html = $this->render_account_nav( $current );

			$this->assertSame( 1, preg_match( '#<details\b([^>]*)>(.*?)</details>#s', $html, $details ), "$current: the dropdown is rendered" );
			$this->assertDoesNotMatchRegularExpression( '/\bopen\b/', $details[1], "$current: the <details> tag carries no open attribute" );
			$this->assertStringContainsString( 'Account &amp; Billing', $details[2] );

			foreach ( array( 'orders', 'edit-address', 'refund-requests' ) as $endpoint ) {
				$this->assertStringContainsString( '/account/' . $endpoint . '/', $details[2], "$current: $endpoint is inside the dropdown" );
			}
			$this->assertStringNotContainsString( '/account/my-team/', $details[2] );
			$this->assertStringContainsString( '/account/my-team/', $html );
		}
	}

	/**
	 * Log out is the last item, after the billing dropdown and set apart by its
	 * own class, never between the pills; the dropdown's summary carries a
	 * chevron that marks it as one.
	 */
	#[RunInSeparateProcess]
	public function test_log_out_comes_last_and_the_dropdown_has_an_indicator(): void {
		$html = $this->render_account_nav( 'dashboard' );

		$this->assertSame( 1, substr_count( $html, '/account/customer-logout/' ) );
		$this->assertGreaterThan( strpos( $html, '</details>' ), strpos( $html, '/account/customer-logout/' ) );
		$this->assertMatchesRegularExpression( '#<li class="[^"]*\bbl-account-nav__logout\b[^"]*">\s*<a href="https://example.test/account/customer-logout/">\s*Log out\s*</a>\s*</li>\s*</ul>#', $html );
		$this->assertMatchesRegularExpression( '#<summary>\s*Account &amp; Billing\s*<svg class="bl-account-nav__chevron"[^>]*aria-hidden="true"#', $html );
	}

	/**
	 * A page's heading uses the label of the tab that leads to it, whatever
	 * the tab was renamed to; an endpoint with no tab keeps its own title.
	 */
	#[RunInSeparateProcess]
	public function test_the_page_heading_matches_its_tab_label(): void {
		require_once __DIR__ . '/fixtures/account-nav-stubs.php';

		$this->assertSame( 'Orders', blueline_account_endpoint_title_from_nav( 'My Registrations', 'orders' ) );
		$this->assertSame( 'Refund requests', blueline_account_endpoint_title_from_nav( 'Refunds', 'refund-requests' ) );
		$this->assertSame( 'View order', blueline_account_endpoint_title_from_nav( 'View order', 'view-order' ) );
	}

	/**
	 * On a billing page the collapsed dropdown still says it holds the current page.
	 */
	#[RunInSeparateProcess]
	public function test_the_collapsed_dropdown_marks_itself_current_on_a_billing_page(): void {
		$html = $this->render_account_nav( 'edit-address' );

		$this->assertStringContainsString( '<details class="bl-account-nav__billing is-active">', $html );
		$this->assertStringContainsString( '<summary aria-current="true">', $html );

		$html = $this->render_account_nav( 'my-team' );

		$this->assertStringContainsString( '<details class="bl-account-nav__billing">', $html );
		$this->assertStringNotContainsString( 'aria-current="true"', $html );
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
