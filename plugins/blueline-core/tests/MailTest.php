<?php
/**
 * Unit tests for the mail module: wp-email-template pins, the plain-text wrapper and the brand
 * filter. Moved from the theme's EmailBrandingTest together with the code they cover.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/mail/mail.php';

/**
 * Covers includes/mail/mail.php and templates/plain-text-fallback.php.
 */
final class MailTest extends TestCase {

	/**
	 * Reset the stub stores.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
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
	 * A multi-line STRING headers value is split into one array entry per header line (core's
	 * wp_mail() treats each array element as exactly one header), so From and Reply-To stay
	 * separate and the Content-Type is appended as its own entry.
	 */
	public function test_maybe_wrap_splits_multiline_string_headers_into_separate_entries(): void {
		$wrapped = blueline_maybe_wrap_plain_text_email(
			array(
				'subject' => 'Hi',
				'message' => 'plain body',
				'headers' => "From: A <a@x.test>\r\nReply-To: b@x.test",
			)
		);

		$this->assertSame(
			array(
				'From: A <a@x.test>',
				'Reply-To: b@x.test',
				'Content-Type: text/html; charset=UTF-8',
			),
			$wrapped['headers']
		);
	}

	/**
	 * Bare LF and bare CR separators and trailing newlines are handled too, without empty entries.
	 */
	public function test_maybe_wrap_splits_string_headers_on_any_newline_style(): void {
		$wrapped = blueline_maybe_wrap_plain_text_email(
			array(
				'subject' => 'Hi',
				'message' => 'plain body',
				'headers' => "Cc: c@x.test\nBcc: d@x.test\rReply-To: e@x.test\r\n\r\n",
			)
		);

		$this->assertSame(
			array(
				'Cc: c@x.test',
				'Bcc: d@x.test',
				'Reply-To: e@x.test',
				'Content-Type: text/html; charset=UTF-8',
			),
			$wrapped['headers']
		);
	}

	/**
	 * An already-array headers value is kept as is, and an empty string, a missing key or a
	 * whitespace-only string all leave just the Content-Type header.
	 */
	public function test_maybe_wrap_keeps_array_headers_and_handles_empty_headers(): void {
		$content_type = 'Content-Type: text/html; charset=UTF-8';

		$with_array = blueline_maybe_wrap_plain_text_email(
			array(
				'subject' => 'Hi',
				'message' => 'plain body',
				'headers' => array( 'Reply-To: b@x.test' ),
			)
		);
		$this->assertSame( array( 'Reply-To: b@x.test', $content_type ), $with_array['headers'] );

		foreach ( array( '', "  \r\n ", null ) as $empty ) {
			$wrapped = blueline_maybe_wrap_plain_text_email(
				array(
					'subject' => 'Hi',
					'message' => 'plain body',
					'headers' => $empty,
				)
			);
			$this->assertSame( array( $content_type ), $wrapped['headers'] );
		}

		$missing = blueline_maybe_wrap_plain_text_email(
			array(
				'subject' => 'Hi',
				'message' => 'plain body',
			)
		);
		$this->assertSame( array( $content_type ), $missing['headers'] );
	}

	/**
	 * Core applies `wp_mail_content_type` after parsing headers, so a plugin forcing text/plain
	 * (for example WooCommerce's "Plain text" email type) beats our Content-Type header. Such
	 * mail must not get the HTML wrapper, or the recipient would see raw markup.
	 */
	public function test_maybe_wrap_skips_mail_when_a_filter_forces_plain_text(): void {
		add_filter( 'wp_mail_content_type', static fn() => 'text/plain' );

		$original = array(
			'to'      => 'admin@example.com',
			'subject' => 'Test',
			'message' => 'plain body',
			'headers' => array( 'Reply-To: b@x.test' ),
		);

		$result = blueline_maybe_wrap_plain_text_email( $original );

		blueline_test_reset_hooks();

		$this->assertSame( $original, $result );
	}

	/**
	 * Any other forced type (e.g. multipart) also means our text/html header would not survive.
	 */
	public function test_content_type_override_detects_any_forced_non_html_type(): void {
		add_filter( 'wp_mail_content_type', static fn() => 'multipart/alternative' );
		$forced = blueline_email_content_type_is_overridden();
		blueline_test_reset_hooks();

		$this->assertTrue( $forced );
	}

	/**
	 * No filter, a pass-through filter and a filter that itself forces text/html all let our
	 * Content-Type header survive, so the mail is still wrapped.
	 */
	public function test_maybe_wrap_still_wraps_when_the_content_type_filter_does_not_override_html(): void {
		$args = array(
			'subject' => 'Test',
			'message' => 'plain body',
			'headers' => array(),
		);

		$this->assertFalse( blueline_email_content_type_is_overridden() );
		$this->assertStringContainsString( 'plain body', blueline_maybe_wrap_plain_text_email( $args )['message'] );

		add_filter( 'wp_mail_content_type', static fn( $type ) => $type );
		$passthrough = blueline_maybe_wrap_plain_text_email( $args );
		blueline_test_reset_hooks();
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $passthrough['headers'] );

		add_filter( 'wp_mail_content_type', static fn() => 'text/html' );
		$forced_html = blueline_maybe_wrap_plain_text_email( $args );
		blueline_test_reset_hooks();
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $forced_html['headers'] );
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

	/**
	 * With no theme callback, the hard-coded Blueline token defaults are used.
	 */
	public function test_email_brand_defaults_to_the_blueline_tokens(): void {
		$brand = blueline_core_email_brand();

		$this->assertSame( '#F7FBFC', $brand['paper'] );
		$this->assertSame( '#132343', $brand['ink'] );
		$this->assertSame( '#3F6E9D', $brand['accent_text'] );
	}

	/**
	 * A theme's filter callback overrides the colours and the logo, and the wrapper uses them.
	 */
	public function test_email_brand_filter_overrides_flow_into_the_wrapped_mail(): void {
		add_filter(
			'blueline_core_email_brand',
			static function ( array $brand ): array {
				$brand['ink']  = '#0A0B0C';
				$brand['logo'] = 'https://example.test/brand.png';
				return $brand;
			}
		);

		$wrapped = blueline_maybe_wrap_plain_text_email(
			array(
				'subject' => 'Hi',
				'message' => 'plain body',
				'headers' => array(),
			)
		);

		blueline_test_reset_hooks();

		$this->assertStringContainsString( '#0A0B0C', $wrapped['message'] );
		$this->assertStringNotContainsString( '#132343', $wrapped['message'] );
		$this->assertStringContainsString( 'https://example.test/brand.png', $wrapped['message'] );
	}

	/**
	 * An invalid colour from a filter falls back to the default for that key, and a non-array
	 * filter result falls back to all defaults.
	 */
	public function test_email_brand_rejects_invalid_filter_values(): void {
		add_filter(
			'blueline_core_email_brand',
			static function ( array $brand ): array {
				$brand['ink'] = 'red; background:url(x)';
				return $brand;
			}
		);
		$this->assertSame( '#132343', blueline_core_email_brand()['ink'] );

		blueline_test_reset_hooks();
		add_filter( 'blueline_core_email_brand', static fn() => 'nope' );
		$this->assertSame( '#F7FBFC', blueline_core_email_brand()['paper'] );

		blueline_test_reset_hooks();
	}

	/**
	 * The wp-email-template general pin takes the paper colour from the brand filter.
	 */
	public function test_general_overrides_use_the_brand_paper_colour(): void {
		add_filter(
			'blueline_core_email_brand',
			static function ( array $brand ): array {
				$brand['paper'] = '#ABCDEF';
				return $brand;
			}
		);

		$patched = blueline_wp_email_template_general_overrides( array( 'background_colour' => array( 'color' => '#f4f4f4' ) ) );

		blueline_test_reset_hooks();

		$this->assertSame( '#ABCDEF', $patched['background_colour']['color'] );
	}
}
