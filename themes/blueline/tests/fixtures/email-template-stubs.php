<?php
/**
 * Stand-ins for the WooCommerce helpers the email templates call, so a template's own markup can be rendered.
 *
 * @package blueline
 */

if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
	/**
	 * My Account endpoint URL on a fixed test host.
	 *
	 * @param string $endpoint Endpoint slug.
	 * @return string
	 */
	function wc_get_account_endpoint_url( $endpoint ) {
		return 'https://example.test/account/' . $endpoint . '/';
	}
}

// The templates' `use Automattic\WooCommerce\Utilities\FeaturesUtil;` needs a class to resolve to.
require_once __DIR__ . '/class-featuresutil.php';
