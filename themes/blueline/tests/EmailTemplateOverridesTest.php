<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/email-template-stubs.php';

/**
 * Renders the theme's email overrides with stub orders.
 */
final class EmailTemplateOverridesTest extends TestCase {

	/**
	 * Restore the email_improvements flag.
	 */
	protected function tearDown(): void {
		FeaturesUtil::$email_improvements = true;
	}

	/**
	 * A stand-in order with what the customer templates read.
	 *
	 * @return object
	 */
	private function order(): object {
		return new class() {
			/**
			 * Billing first name.
			 *
			 * @return string
			 */
			public function get_billing_first_name() {
				return 'Sam';
			}

			/**
			 * Order number.
			 *
			 * @return string
			 */
			public function get_order_number() {
				return '4321';
			}
		};
	}

	/**
	 * Render one template from woocommerce/emails/.
	 *
	 * @param string               $name Template file name.
	 * @param array<string, mixed> $vars Variables wc_get_template() would extract.
	 * @return string
	 */
	private function render( string $name, array $vars ): string {
		$defaults = array(
			'email_heading'      => 'Heading',
			'email'              => null,
			'additional_content' => '',
			'sent_to_admin'      => false,
			'plain_text'         => false,
			'blogname'           => 'Adult Recreational League',
		);
		extract( array_merge( $defaults, $vars ) ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- mirrors wc_get_template(), which extracts the template's args the same way.
		ob_start();
		include __DIR__ . '/../woocommerce/emails/' . $name;

		return (string) ob_get_clean();
	}

	/**
	 * The "try again" link is built from the site's own My Account URL, not a hard-coded production host.
	 */
	public function test_failed_order_links_to_this_sites_registrations_page(): void {
		foreach ( array( true, false ) as $improvements ) {
			FeaturesUtil::$email_improvements = $improvements;

			$html = $this->render( 'customer-failed-order.php', array( 'order' => $this->order() ) );

			$this->assertStringContainsString( '<a href="https://example.test/account/registrations/">account page</a>', $html );
			$this->assertStringNotContainsString( 'rookiehockey.ca', $html );
			$this->assertStringContainsString( 'complete your registration due to an issue with your payment method.', $html );
		}
	}

	/**
	 * The failed-order sentence is theme copy, translated in the theme's own text domain.
	 */
	public function test_failed_order_copy_uses_the_blueline_text_domain(): void {
		add_filter(
			'gettext',
			static function ( $translation, $text, $domain ) {
				return 'blueline' === $domain ? 'T:' . $translation : $translation;
			},
			10,
			3
		);

		foreach ( array( true, false ) as $improvements ) {
			FeaturesUtil::$email_improvements = $improvements;

			$html = $this->render( 'customer-failed-order.php', array( 'order' => $this->order() ) );

			// Each theme-written sentence is translatable in the theme's domain...
			$this->assertStringContainsString( 'T:Unfortunately', $html );
			$this->assertStringContainsString( 'T:If you', $html );
			$this->assertStringContainsString( 'T:Your registration details are as follows:', $html );
			// ...and the greeting WooCommerce wrote stays in WooCommerce's, so its translations keep applying.
			$this->assertStringContainsString( 'Hi Sam,', $html );
			$this->assertStringNotContainsString( 'T:Hi', $html );
		}
	}

	/**
	 * Refund emails: registration wording in both layouts, partial and full.
	 */
	public function test_refunded_order_wording(): void {
		foreach ( array( true, false ) as $improvements ) {
			FeaturesUtil::$email_improvements = $improvements;

			$full    = $this->render(
				'customer-refunded-order.php',
				array(
					'order'          => $this->order(),
					'partial_refund' => false,
				)
			);
			$partial = $this->render(
				'customer-refunded-order.php',
				array(
					'order'          => $this->order(),
					'partial_refund' => true,
				)
			);

			$this->assertStringContainsString( 'Your registration has been refunded.', $full );
			$this->assertStringContainsString( 'Your registration has been partially refunded.', $partial );
			$this->assertStringNotContainsString( 'Your order', $full . $partial );
		}
	}
}
