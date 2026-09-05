<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests for the branded-email-templates work: pinning WooCommerce's
 * email_improvements-flag color/type options to this theme's own brand
 * tokens, and configuring wp-email-template's own general-purpose wrapper
 * (CF7, Gravity Forms, WP core mail) to use the same brand tokens instead
 * of competing with them or its own generic defaults. See
 * inc/woocommerce.php's own docblocks above blueline_wc_email_option_overrides()
 * and blueline_wp_email_template_general_overrides() for the full
 * reasoning, and docs/superpowers/specs/2026-09-04-blueline-email-templates-design.md
 * for the design this implements.
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

require_once __DIR__ . '/../inc/woocommerce.php';

/**
 * Exercises the pure functions this feature adds to inc/woocommerce.php.
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
	 * The master switch is forced on (so CF7/Gravity Forms/WP-core mail
	 * actually gets wrapped in HTML at all -- confirmed live it defaults
	 * to "no"), the WooCommerce-wrapping toggle stays forced off, and the
	 * outer canvas colour is repointed to --bl-paper -- every other stored
	 * setting in that same option survives untouched, confirming this is
	 * a targeted merge, not a wholesale replacement of an admin's other
	 * configuration there.
	 */
	public function test_wp_email_template_general_overrides_enables_wrapping_and_disables_woo(): void {
		$original = array(
			'apply_template_all_emails'     => 'no',
			'email_content_type'            => 'multipart',
			'email_container_width'         => '600',
			'background_colour'             => array(
				'enable' => '1',
				'color'  => '#f4f4f4',
			),
			'deactivate_pattern_background' => 'no',
			'outlook_apply_border'          => 'yes',
			'apply_for_woo_emails'          => 'yes',
		);

		$patched = blueline_wp_email_template_general_overrides( $original );

		$this->assertSame( 'yes', $patched['apply_template_all_emails'] );
		$this->assertSame( 'no', $patched['apply_for_woo_emails'] );
		$this->assertSame( '#F7FBFC', $patched['background_colour']['color'] );
		$this->assertSame( '600', $patched['email_container_width'] );
	}

	/**
	 * A malformed stored option (never been saved yet, or some other code
	 * stored something unexpected -- a bare boolean/string/null rather than
	 * an array) is tolerated rather than fataling on array access; it is
	 * returned completely unchanged, since there is no array to merge the
	 * override into.
	 */
	public function test_wp_email_template_general_overrides_tolerates_a_non_array_option(): void {
		$this->assertFalse( blueline_wp_email_template_general_overrides( false ) );
		$this->assertNull( blueline_wp_email_template_general_overrides( null ) );
	}

	/**
	 * An empty array IS still a real array to merge the override into --
	 * distinct from the non-array case above, which has nothing to merge
	 * into at all. background_colour is absent here entirely (nothing to
	 * merge a colour into), unlike the fixture above.
	 */
	public function test_wp_email_template_general_overrides_sets_keys_even_on_an_empty_array(): void {
		$this->assertSame(
			array(
				'apply_template_all_emails' => 'yes',
				'apply_for_woo_emails'      => 'no',
			),
			blueline_wp_email_template_general_overrides( array() )
		);
	}

	/**
	 * The logo band's own background colour is pinned, and an unrelated
	 * stored setting (header_image_alignment) survives untouched.
	 */
	public function test_wp_email_template_style_header_image_overrides_pins_background(): void {
		$original = array(
			'header_image_alignment'        => 'center',
			'header_image_background_color' => array(
				'enable' => '1',
				'color'  => '#ffffff',
			),
		);

		$patched = blueline_wp_email_template_style_header_image_overrides( $original );

		$this->assertSame( '#F7FBFC', $patched['header_image_background_color']['color'] );
		$this->assertSame( 'center', $patched['header_image_alignment'] );
	}

	/**
	 * A malformed stored option is tolerated rather than fataling.
	 */
	public function test_wp_email_template_style_header_image_overrides_tolerates_a_non_array_option(): void {
		$this->assertFalse( blueline_wp_email_template_style_header_image_overrides( false ) );
		$this->assertNull( blueline_wp_email_template_style_header_image_overrides( null ) );
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
	 * No real plugin is ever loaded in this plain-PHPUnit environment, so
	 * WP_EMAIL_TEMPLATE_DIR is never defined -- a real, honest assertion
	 * of this environment's actual state, not a faked one: the constant
	 * cannot safely be defined and later undefined within a test run
	 * without leaking into every other test that runs afterward.
	 */
	public function test_email_template_plugin_active_is_false_when_the_constant_is_undefined(): void {
		$this->assertFalse( blueline_email_template_plugin_active() );
	}

	/**
	 * An explicit text/html header, in either the array or single-string
	 * header shape wp_mail() accepts, is recognised.
	 */
	public function test_email_is_already_html_detects_an_explicit_content_type_header(): void {
		$this->assertTrue(
			blueline_email_is_already_html(
				array(
					'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
					'message' => 'plain text, no markup at all',
				)
			)
		);
		$this->assertTrue(
			blueline_email_is_already_html(
				array(
					'headers' => 'Content-Type: text/html; charset=UTF-8',
					'message' => 'plain text, no markup at all',
				)
			)
		);
	}

	/**
	 * A message that already looks like a real HTML document (WooCommerce/
	 * FUE's own complete emails) is recognised even with no header at all.
	 */
	public function test_email_is_already_html_detects_html_markup_in_the_message(): void {
		$this->assertTrue(
			blueline_email_is_already_html(
				array(
					'headers' => array(),
					'message' => '<html><body><table><tr><td>Order confirmation</td></tr></table></body></html>',
				)
			)
		);
	}

	/**
	 * Genuinely plain text -- no HTML header, no HTML-looking content --
	 * is correctly NOT treated as already HTML.
	 */
	public function test_email_is_already_html_is_false_for_genuine_plain_text(): void {
		$this->assertFalse(
			blueline_email_is_already_html(
				array(
					'headers' => array(),
					'message' => "Name: Test\r\nEmail: test@example.com\r\nMessage: hello",
				)
			)
		);
	}

	/**
	 * An already-HTML wp_mail() call (WooCommerce/FUE's own complete
	 * emails) is returned completely untouched -- never double-wrapped.
	 */
	public function test_maybe_wrap_plain_text_email_leaves_html_mail_untouched(): void {
		$original = array(
			'to'      => 'player@example.com',
			'subject' => 'Your order',
			'message' => '<html><body>Order confirmation</body></html>',
			'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
		);

		$this->assertSame( $original, blueline_maybe_wrap_plain_text_email( $original ) );
	}

	/**
	 * A genuinely plain-text wp_mail() call (CF7/Gravity Forms/WP core's
	 * own default) is wrapped in the theme's own branded template: the
	 * subject and message both appear (message HTML-escaped, since it may
	 * originate from a public form submission), the brand ink/paper
	 * colours are present, and a text/html Content-Type header is added
	 * without discarding whatever header the caller already had.
	 */
	public function test_maybe_wrap_plain_text_email_wraps_genuine_plain_text(): void {
		$original = array(
			'to'      => 'admin@example.com',
			'subject' => 'Contact Us: General Inquiry',
			'message' => "Name: A <script>alert(1)</script> Tester\nMessage: hello there",
			'headers' => array( 'Reply-To: someone@example.com' ),
		);

		$wrapped = blueline_maybe_wrap_plain_text_email( $original );

		$this->assertStringContainsString( 'Contact Us: General Inquiry', $wrapped['message'] );
		$this->assertStringContainsString( 'hello there', $wrapped['message'] );
		$this->assertStringNotContainsString( '<script>', $wrapped['message'] );
		$this->assertStringContainsString( '#132343', $wrapped['message'] );
		$this->assertStringContainsString( '#F7FBFC', $wrapped['message'] );
		$this->assertContains( 'Reply-To: someone@example.com', $wrapped['headers'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $wrapped['headers'] );
	}

	/**
	 * The wp_mail filter callback itself defers entirely to wp-email-template
	 * when it's active -- in this test environment that's always false (see
	 * test_email_template_plugin_active_is_false_when_the_constant_is_undefined()),
	 * so this exercises the same wrapping behaviour as
	 * blueline_maybe_wrap_plain_text_email() by construction, confirming the
	 * two are actually wired together.
	 */
	public function test_wrap_plain_text_email_in_brand_template_wraps_when_plugin_inactive(): void {
		$original = array(
			'to'      => 'admin@example.com',
			'subject' => 'Test',
			'message' => 'plain body',
			'headers' => array(),
		);

		$wrapped = blueline_wrap_plain_text_email_in_brand_template( $original );

		$this->assertStringContainsString( 'plain body', $wrapped['message'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $wrapped['headers'] );
	}
}
