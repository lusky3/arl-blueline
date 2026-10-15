<?php
/**
 * My Account address forms without a Country field.
 *
 * WooCommerce saves an address from My Account > Addresses only when the form posts
 * `{type}_country`: WC_Form_Handler::save_address() returns early, with no message, when it is
 * missing. The site's Checkout Field Editor configuration disables the billing Country and State
 * fields (checkout is unaffected; it saves through another path), so the billing address form
 * reloaded on every save and stored nothing, for every role.
 *
 * So when an address form's field set has no country field of its own, the form carries the
 * customer's stored country (or the store's) in a hidden input, and a submission that still lacks
 * it (a form rendered before this) gets it just before WooCommerce's handler runs. A form that
 * shows a Country field is never touched. The country only feeds WooCommerce's own checks
 * (postcode and phone formats); with no country field in the set, nothing new is stored.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure: the country to send with a $type address form whose field set has no country field, or ''
 * when nothing should be added.
 *
 * @param string $type    Address type: billing or shipping.
 * @param array  $fields  The form's fields, keyed by field name (WC_Countries::get_address_fields()).
 * @param string $stored  The customer's stored country for this address type ('' for none).
 * @param string $base    The store's base country.
 * @return string A two-letter country code, or ''.
 */
function blueline_core_address_fallback_country( string $type, array $fields, string $stored, string $base ): string {
	if ( ! in_array( $type, array( 'billing', 'shipping' ), true ) || isset( $fields[ $type . '_country' ] ) ) {
		return '';
	}

	/**
	 * Filters the country sent with a My Account address form that has no Country field of its
	 * own. Return '' to send none (WooCommerce then does not save that form).
	 *
	 * @param string $country The customer's stored country for this address, else the store's.
	 * @param string $type    Address type: billing or shipping.
	 */
	$country = apply_filters( 'blueline_core_address_fallback_country', '' !== $stored ? $stored : $base, $type );
	$country = is_string( $country ) ? strtoupper( trim( $country ) ) : '';

	return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
}

/**
 * The fallback country for the current user's $type address form, read from WooCommerce.
 *
 * @param string $type Address type: billing or shipping.
 * @return string A two-letter country code, or ''.
 */
function blueline_core_current_address_fallback_country( string $type ): string {
	$user_id = get_current_user_id();

	if ( $user_id <= 0 || ! function_exists( 'WC' ) || ! is_object( WC()->countries ?? null ) ) {
		return '';
	}

	$countries = WC()->countries;
	$stored    = (string) get_user_meta( $user_id, $type . '_country', true );
	$base      = (string) $countries->get_base_country();

	// The same field set WooCommerce renders and validates the form with.
	$fields = $countries->get_address_fields( '' !== $stored ? $stored : $base, $type . '_' );

	return blueline_core_address_fallback_country( $type, is_array( $fields ) ? $fields : array(), $stored, $base );
}

/**
 * Print the hidden country input inside a $type edit-address form, when the form needs one.
 *
 * @param string $type Address type: billing or shipping.
 * @return void
 */
function blueline_core_print_address_country_input( string $type ): void {
	$country = blueline_core_current_address_fallback_country( $type );

	if ( '' === $country ) {
		return;
	}

	printf( '<input type="hidden" name="%1$s" value="%2$s" />' . "\n", esc_attr( $type . '_country' ), esc_attr( $country ) );
}

add_action( 'woocommerce_after_edit_address_form_billing', 'blueline_core_print_billing_country_input' );
/**
 * The billing form's hidden country input.
 *
 * @return void
 */
function blueline_core_print_billing_country_input(): void {
	blueline_core_print_address_country_input( 'billing' );
}

add_action( 'woocommerce_after_edit_address_form_shipping', 'blueline_core_print_shipping_country_input' );
/**
 * The shipping form's hidden country input (only printed if a configuration removes that field too).
 *
 * @return void
 */
function blueline_core_print_shipping_country_input(): void {
	blueline_core_print_address_country_input( 'shipping' );
}

// WooCommerce's WC_Form_Handler::save_address() runs on template_redirect at priority 10.
add_action( 'template_redirect', 'blueline_core_fill_address_country', 9 );
/**
 * Give an edit-address submission that has no country the fallback one, before WooCommerce's
 * handler reads it. Nothing is saved here; the handler still checks the nonce, the user and
 * every field.
 *
 * @return void
 */
function blueline_core_fill_address_country(): void {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only routing checks; WooCommerce's handler, which this feeds, verifies the nonce.
	if ( 'POST' !== $method || ! isset( $_POST['action'] ) || 'edit_address' !== $_POST['action'] ) {
		return;
	}

	global $wp;

	$slug = isset( $wp->query_vars['edit-address'] ) ? sanitize_title( (string) $wp->query_vars['edit-address'] ) : 'billing';
	$type = function_exists( 'wc_edit_address_i18n' ) ? (string) wc_edit_address_i18n( $slug, true ) : $slug;

	if ( isset( $_POST[ $type . '_country' ] ) ) {
		return;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$country = blueline_core_current_address_fallback_country( $type );

	if ( '' === $country ) {
		return;
	}

	$_POST[ $type . '_country' ]    = $country;
	$_REQUEST[ $type . '_country' ] = $country;
}
