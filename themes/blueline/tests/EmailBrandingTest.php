<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests for the theme's side of the branded-email-templates work: pinning
 * WooCommerce's email_improvements-flag color/type options to this theme's own brand
 * tokens, the AngellEYE template reclaim, and the brand-token callback the blueline-core
 * mail module reads. See inc/woocommerce.php's own docblocks above
 * blueline_wc_email_option_overrides() for the full reasoning, and
 * docs/superpowers/specs/2026-09-04-blueline-email-templates-design.md for the design.
 * The wrapper and wp-email-template pins moved to the plugin (plugins/blueline-core/tests/MailTest.php).
 *
 * The require below would otherwise no-op in this plain-PHPUnit
 * environment: inc/woocommerce.php's own
 * `if ( ! class_exists( 'WooCommerce' ) ) { return; }` guard (necessary in
 * production, since this file must no-op on a site without WooCommerce
 * active) needs SOME WooCommerce class to exist first, and no real
 * WooCommerce is ever loaded here -- tests/bootstrap.php declares a shared
 * minimal stub for exactly this, loaded before this file, so
 * `class_exists( 'WooCommerce' )` is already true by the time the require
 * below runs.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/woocommerce.php';
require_once __DIR__ . '/../inc/email.php';

/**
 * Exercises the pure functions this feature adds to inc/woocommerce.php and inc/email.php.
 */
final class EmailBrandingTest extends TestCase {

	/**
	 * The one hard accessibility constraint this whole feature turns on:
	 * base_color drives link text color under WooCommerce's
	 * email_improvements flag, so it must be the WCAG-AA text-safe token
	 * (--bl-accent-text), never the fill-only --bl-ice token.
	 */
	public function test_base_color_is_the_accent_text_token_not_the_fill_only_ice_token(): void {
		$overrides = blueline_wc_email_option_overrides();

		$this->assertSame( '#3f6e9d', strtolower( $overrides['woocommerce_email_base_color'] ) );
		$this->assertNotSame( '#74c0e1', strtolower( $overrides['woocommerce_email_base_color'] ) );
	}

	/**
	 * Every option WooCommerce's own email-styles.php actually reads must
	 * have a pinned value -- a missing key would silently fall back to
	 * whatever wp-admin happens to have stored.
	 */
	public function test_every_expected_option_is_present(): void {
		$overrides = blueline_wc_email_option_overrides();

		$expected_keys = array(
			'woocommerce_email_background_color',
			'woocommerce_email_body_background_color',
			'woocommerce_email_base_color',
			'woocommerce_email_text_color',
			'woocommerce_email_footer_text_color',
			'woocommerce_email_header_alignment',
			'woocommerce_email_font_family',
			'woocommerce_email_header_image_width',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $overrides, "missing override for {$key}" );
		}
	}

	/**
	 * EmailFont::$font (Automattic\WooCommerce\Internal\Email\EmailFont) is
	 * a fixed 10-choice list; an unsupported value here would silently fall
	 * back to Helvetica inside WooCommerce's own code, so this value should
	 * just BE 'Helvetica' rather than something WooCommerce quietly ignores.
	 */
	public function test_font_family_is_one_of_woocommerces_own_supported_choices(): void {
		$overrides = blueline_wc_email_option_overrides();

		$this->assertSame( 'Helvetica', $overrides['woocommerce_email_font_family'] );
	}

	/**
	 * Every registered override is actually reachable through the real
	 * WordPress filter mechanism, not just present in the array -- exercises
	 * blueline_register_wc_email_option_overrides() itself (normally fired
	 * on 'init' in production; called directly here since this stub
	 * environment never fires that hook on its own), not only the pure data
	 * function it loops over.
	 */
	public function test_overrides_are_actually_registered_as_option_filters(): void {
		blueline_register_wc_email_option_overrides();

		foreach ( blueline_wc_email_option_overrides() as $option => $value ) {
			$this->assertSame(
				$value,
				apply_filters( "option_{$option}", 'whatever-was-actually-stored' ),
				"option_{$option} filter did not return the pinned value"
			);
		}
	}

	/**
	 * Exercises blueline_paypal_email_templates_to_reclaim() -- both
	 * filenames this theme actually has an override for should be named.
	 * A fixed list, not a wildcard, so it can't drift ahead of what really
	 * exists in woocommerce/emails/.
	 */
	public function test_reclaim_list_names_both_known_overrides(): void {
		$list = blueline_paypal_email_templates_to_reclaim();

		$this->assertContains( 'angelleye-customer-partial-paid-order.php', $list );
		$this->assertContains( 'angelleye-admin-new-partial-paid-order.php', $list );
	}

	/**
	 * A template name outside the reclaim list is passed through
	 * completely unchanged -- this filter must not touch anything it
	 * wasn't explicitly told to reclaim.
	 */
	public function test_reclaim_passes_through_unrelated_template_names(): void {
		$this->assertSame(
			'/some/plugin/path/emails/unrelated-template.php',
			blueline_reclaim_paypal_email_template_override(
				'/some/plugin/path/emails/unrelated-template.php',
				'emails/unrelated-template.php'
			)
		);
	}

	/**
	 * A reclaim-list template name whose theme override does NOT exist
	 * (get_stylesheet_directory() in this test environment points at a
	 * real but empty temp directory -- see tests/bootstrap.php's own
	 * stub) falls back to whatever $template the plugin's own filter
	 * already resolved, rather than pointing at a file that isn't there.
	 */
	public function test_reclaim_falls_back_when_theme_override_is_missing(): void {
		$this->assertSame(
			'/plugin/own/path/emails/angelleye-customer-partial-paid-order.php',
			blueline_reclaim_paypal_email_template_override(
				'/plugin/own/path/emails/angelleye-customer-partial-paid-order.php',
				'emails/angelleye-customer-partial-paid-order.php'
			)
		);
	}

	/**
	 * The real bug this function exists to fix: a reclaim-list template
	 * name whose theme override DOES exist must resolve to that theme
	 * file, not whatever the PayPal for WooCommerce plugin's own
	 * woocommerce_locate_template filter forced $template to (confirmed
	 * live, 2026-09-04: it forced its own plugin copy even with a real
	 * theme override already in place -- see this function's own
	 * docblock).
	 */
	public function test_reclaim_prefers_the_theme_override_when_it_exists(): void {
		$override_dir  = get_stylesheet_directory() . '/woocommerce/emails';
		$override_path = $override_dir . '/angelleye-admin-new-partial-paid-order.php';

		if ( ! is_dir( $override_dir ) ) {
			mkdir( $override_dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- local test fixture, no WordPress bootstrap (hence no WP_Filesystem) exists in this plain-PHPUnit environment.
		}
		file_put_contents( $override_path, '<?php // test fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture, see mkdir() above.

		try {
			$this->assertSame(
				$override_path,
				blueline_reclaim_paypal_email_template_override(
					'/plugin/own/path/emails/angelleye-admin-new-partial-paid-order.php',
					'emails/angelleye-admin-new-partial-paid-order.php'
				)
			);
		} finally {
			unlink( $override_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup, see mkdir() above.
		}
	}

	/**
	 * Email option overrides read a live brand-color override when one is set --
	 * the admin's chosen value appears immediately in the resolved email options,
	 * not just in the theme CSS.
	 */
	public function test_email_option_overrides_reflect_a_live_brand_color_override(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_accent_text' => '#123456' );

		$overrides = blueline_wc_email_option_overrides();

		$this->assertSame( '#123456', $overrides['woocommerce_email_base_color'] );
	}

	/**
	 * Email option overrides fall back to the theme's own style.css defaults
	 * when no admin override is set for each individual token.
	 */
	public function test_email_option_overrides_fall_back_to_the_theme_default_when_unset(): void {
		blueline_test_reset(); // Clears $GLOBALS['bl_test_options'] among other stubs -- see tests/FooterAndHeroSettingsRenderTest.php's identical setUp().

		$overrides = blueline_wc_email_option_overrides();

		$this->assertSame( '#3F6E9D', $overrides['woocommerce_email_base_color'] );
		$this->assertSame( '#132343', $overrides['woocommerce_email_text_color'] );
		$this->assertSame( '#2E4A74', $overrides['woocommerce_email_footer_text_color'] );
		$this->assertSame( '#F7FBFC', $overrides['woocommerce_email_background_color'] );
		$this->assertSame( '#FFFFFF', $overrides['woocommerce_email_body_background_color'] );
	}

	/**
	 * The brand filter callback fills the plugin's brand array with the theme's resolved colours,
	 * following an admin override, and leaves keys it does not own alone.
	 */
	public function test_brand_filter_callback_supplies_the_resolved_brand_colors(): void {
		blueline_test_reset();
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array( 'brand_color_accent_text' => '#123456' );

		$brand = blueline_core_email_brand_tokens(
			array(
				'paper'  => '#000000',
				'border' => '#DBE7F0',
				'logo'   => 'https://example.test/logo.png',
			)
		);

		$this->assertSame( '#F7FBFC', $brand['paper'] );
		$this->assertSame( '#123456', $brand['accent_text'] );
		$this->assertSame( '#DBE7F0', $brand['border'] );
		$this->assertSame( 'https://example.test/logo.png', $brand['logo'] );
	}

	/**
	 * A non-array value is returned untouched.
	 */
	public function test_brand_filter_callback_tolerates_a_non_array_value(): void {
		$this->assertFalse( blueline_core_email_brand_tokens( false ) );
	}

	/**
	 * The callback is registered on the plugin's brand filter.
	 */
	public function test_brand_filter_callback_is_registered(): void {
		$this->assertSame( '#F7FBFC', apply_filters( 'blueline_core_email_brand', array( 'paper' => '#000000' ) )['paper'] );
	}
}
