<?php
/**
 * Stand-ins for the two WooCommerce helpers woocommerce/checkout/terms.php calls.
 *
 * @package blueline
 */

if ( ! function_exists( 'wc_terms_and_conditions_checkbox_enabled' ) ) {
	/**
	 * Terms checkbox is on.
	 *
	 * @return bool
	 */
	function wc_terms_and_conditions_checkbox_enabled() {
		return true;
	}
}

if ( ! function_exists( 'wc_terms_and_conditions_checkbox_text' ) ) {
	/**
	 * Checkbox label text.
	 *
	 * @return void
	 */
	function wc_terms_and_conditions_checkbox_text() {
		echo 'I have read and agree to the terms';
	}
}
