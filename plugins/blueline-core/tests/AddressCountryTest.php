<?php
/**
 * Unit tests for includes/checkout/address-country.php: the country a My Account address form
 * sends when the site's field configuration removed its Country field.
 *
 * @package blueline-core
 */

// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- the tests read back the request globals the code under test fills.

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/checkout/checkout.php';

/**
 * Covers the address-country fallback.
 */
final class AddressCountryTest extends TestCase {

	/**
	 * The billing field set as the site's Checkout Field Editor configuration leaves it: no
	 * billing_country, no billing_state.
	 */
	private const BILLING_WITHOUT_COUNTRY = array(
		'billing_first_name' => array( 'required' => true ),
		'billing_email'      => array( 'required' => true ),
		'billing_address_1'  => array( 'required' => true ),
		'billing_city'       => array( 'required' => true ),
		'billing_postcode'   => array( 'required' => true ),
	);

	/**
	 * Fresh hooks and state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Drop the request globals this suite sets.
	 */
	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'], $GLOBALS['wp'] );
	}

	/**
	 * A form without a country field gets the customer's stored country, or the store's when they
	 * have none.
	 */
	public function test_a_form_without_a_country_field_gets_the_stored_or_the_store_country(): void {
		$this->assertSame( 'US', blueline_core_address_fallback_country( 'billing', self::BILLING_WITHOUT_COUNTRY, 'US', 'CA' ) );
		$this->assertSame( 'CA', blueline_core_address_fallback_country( 'billing', self::BILLING_WITHOUT_COUNTRY, '', 'CA' ) );
	}

	/**
	 * A form that shows its own country field is never given a second one, and only the two address
	 * types are handled.
	 */
	public function test_a_form_with_its_own_country_field_or_an_unknown_type_is_left_alone(): void {
		$shipping = array(
			'shipping_country'  => array( 'required' => true ),
			'shipping_postcode' => array( 'required' => true ),
		);

		$this->assertSame( '', blueline_core_address_fallback_country( 'shipping', $shipping, '', 'CA' ) );
		$this->assertSame( '', blueline_core_address_fallback_country( 'payment', self::BILLING_WITHOUT_COUNTRY, '', 'CA' ) );
	}

	/**
	 * The country is filterable; '' switches the fallback off, and anything that is not a two-letter
	 * country code is ignored.
	 */
	public function test_the_country_is_filterable_and_validated(): void {
		add_filter(
			'blueline_core_address_fallback_country',
			static function ( $country, $type ) {
				return 'billing' === $type ? 'us' : $country;
			},
			10,
			2
		);
		$this->assertSame( 'US', blueline_core_address_fallback_country( 'billing', self::BILLING_WITHOUT_COUNTRY, '', 'CA' ) );

		add_filter(
			'blueline_core_address_fallback_country',
			static function () {
				return '';
			},
			20
		);
		$this->assertSame( '', blueline_core_address_fallback_country( 'billing', self::BILLING_WITHOUT_COUNTRY, '', 'CA' ) );

		blueline_test_reset_hooks();
		add_filter(
			'blueline_core_address_fallback_country',
			static function () {
				return '"><script>';
			}
		);
		$this->assertSame( '', blueline_core_address_fallback_country( 'billing', self::BILLING_WITHOUT_COUNTRY, '', 'CA' ) );
	}

	/**
	 * The form hooks run inside WooCommerce's edit-address form, and the submission fallback runs
	 * before WooCommerce's own handler (template_redirect, priority 10).
	 */
	public function test_hooks_are_registered(): void {
		$this->assertSame( 10, has_action( 'woocommerce_after_edit_address_form_billing', 'blueline_core_print_billing_country_input' ) );
		$this->assertSame( 10, has_action( 'woocommerce_after_edit_address_form_shipping', 'blueline_core_print_shipping_country_input' ) );
		$this->assertSame( 9, has_action( 'template_redirect', 'blueline_core_fill_address_country' ) );
	}

	/**
	 * Without WooCommerce nothing is printed or changed.
	 */
	public function test_without_woocommerce_nothing_happens(): void {
		$this->assertFalse( function_exists( 'WC' ), 'This suite runs without WooCommerce outside its separate processes.' );
		$this->log_in( 7 );
		$this->post_edit_address( 'billing' );

		ob_start();
		blueline_core_print_billing_country_input();
		$this->assertSame( '', (string) ob_get_clean() );

		blueline_core_fill_address_country();
		$this->assertArrayNotHasKey( 'billing_country', $_POST );
	}

	/**
	 * With WooCommerce: the billing form (no country field) carries the country as a hidden input;
	 * the shipping form (which has one) does not.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_billing_form_carries_a_hidden_country(): void {
		$this->fake_woocommerce();
		$this->log_in( 7 );

		ob_start();
		blueline_core_print_billing_country_input();
		$this->assertSame( '<input type="hidden" name="billing_country" value="CA" />', trim( (string) ob_get_clean() ) );

		ob_start();
		blueline_core_print_shipping_country_input();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * A billing submission without a country (a form rendered before this fix) gets one just before
	 * WooCommerce's handler reads it; the customer's stored country wins over the store's.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_billing_submission_without_a_country_is_given_one(): void {
		$this->fake_woocommerce();
		$this->log_in( 7 );

		$this->post_edit_address( 'billing' );
		blueline_core_fill_address_country();
		$this->assertSame( 'CA', $_POST['billing_country'] );
		$this->assertSame( 'CA', $_REQUEST['billing_country'] );

		update_user_meta( 7, 'billing_country', 'US' );
		$this->post_edit_address( 'billing' );
		blueline_core_fill_address_country();
		$this->assertSame( 'US', $_POST['billing_country'] );
	}

	/**
	 * A posted country is never replaced; a shipping form with its own field, a GET, another form's
	 * POST and a logged-out visitor are left alone.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_other_requests_are_left_alone(): void {
		$this->fake_woocommerce();
		$this->log_in( 7 );

		$this->post_edit_address( 'billing' );
		$_POST['billing_country'] = 'US';
		blueline_core_fill_address_country();
		$this->assertSame( 'US', $_POST['billing_country'] );

		$this->post_edit_address( 'shipping' );
		blueline_core_fill_address_country();
		$this->assertArrayNotHasKey( 'shipping_country', $_POST );

		$this->post_edit_address( 'billing' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		blueline_core_fill_address_country();
		$this->assertArrayNotHasKey( 'billing_country', $_POST );

		$this->post_edit_address( 'billing' );
		$_POST['action'] = 'save_account_details';
		blueline_core_fill_address_country();
		$this->assertArrayNotHasKey( 'billing_country', $_POST );

		$this->log_in( 0 );
		$this->post_edit_address( 'billing' );
		blueline_core_fill_address_country();
		$this->assertArrayNotHasKey( 'billing_country', $_POST );
	}

	/**
	 * Simulate the POST of My Account > Addresses > $type, without any country.
	 *
	 * @param string $type billing or shipping.
	 */
	private function post_edit_address( string $type ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'action'                         => 'edit_address',
			'woocommerce-edit-address-nonce' => 'n',
			$type . '_postcode'              => 'L7M 1A1',
		);
		$_REQUEST                  = $_POST;
		$GLOBALS['wp']             = (object) array( 'query_vars' => array( 'edit-address' => $type ) );
	}

	/**
	 * Log a user in (0 logs out).
	 *
	 * @param int $user_id User ID.
	 */
	private function log_in( int $user_id ): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = $user_id;
	}

	/**
	 * Define WC() (this process only) with a countries object shaped like the site's: base country
	 * CA, billing fields without a country, shipping fields with one.
	 */
	private function fake_woocommerce(): void {
		if ( function_exists( 'WC' ) ) {
			return;
		}

		$GLOBALS['bl_core_test_address_fields'] = array(
			'billing_'  => self::BILLING_WITHOUT_COUNTRY,
			'shipping_' => array(
				'shipping_country'  => array( 'required' => true ),
				'shipping_postcode' => array( 'required' => true ),
			),
		);

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- defines the WooCommerce() accessor in this separate process only.
		eval( 'function WC() { return (object) array( "countries" => new class() { public function get_base_country() { return "CA"; } public function get_address_fields( $country, $type ) { return $GLOBALS["bl_core_test_address_fields"][ $type ] ?? array(); } } ); }' );
	}
}
