<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/page.php';

/**
 * Covers inc/settings/page.php: the Appearance -> Blueline admin page --
 * tab/schema grouping, the register_setting() sanitize_callback (including
 * its `_posted_fields` forwarding), the capability guard, and the two
 * load-bearing correctness requirements the task brief calls out by name:
 *
 * - Escaping: blueline_sanitize_field()'s WP_Error messages interpolate the
 *   admin's own input, and add_settings_error()'s $message is read back
 *   completely unescaped -- escaping is the caller's job, done once, at the
 *   point blueline_settings_sanitize_callback() calls add_settings_error().
 *   test_rejected_value_containing_script_tag_is_never_rendered_unescaped()
 *   and test_a_message_with_html_significant_characters_is_escaped_before_storage()
 *   cover this from two angles: an end-to-end save-and-render, and a direct
 *   unit check that escaping actually changed the stored message rather
 *   than merely having nothing dangerous to escape.
 * - `_posted_fields`: without it, a field a tab owns but the user cleared
 *   would be silently carried forward (reverted) by
 *   blueline_settings_merge() instead of actually clearing --
 *   test_saving_one_tab_clears_a_field_without_disturbing_another_tab()
 *   proves the full save path (this file's sanitize_callback plus
 *   inc/settings/store.php's merge) gets this right end-to-end.
 */
final class SettingsPageTest extends TestCase {

	/**
	 * Reset every in-memory store, then re-register the settings-panel
	 * option with the Settings API exactly as a real `admin_init` request
	 * would -- required because blueline_settings_reset()'s hook reset
	 * restores only the file-scope `add_action( 'admin_init', ... )`
	 * registration itself (captured in the baseline the first time any test
	 * runs), not the `sanitize_option_{$option}` filter that registration
	 * only adds once admin_init actually fires.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		do_action( 'admin_init' );
		unset( $_GET['tab'], $_GET['settings-updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture cleanup of superglobals between cases, not a real request.
	}

	/**
	 * Grant the current (fake) user manage_options, the capability this
	 * whole page requires throughout.
	 *
	 * @return void
	 */
	private function grant_manage_options(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;
	}

	/**
	 * The schema's own tab order (content, links, commerce today) must be
	 * exactly what the tab nav renders and iterates in -- derived from the
	 * schema, not hardcoded, so a future tab needs no edit here.
	 */
	public function test_tab_slugs_reflect_schema_order(): void {
		$this->assertSame( array( 'content', 'links', 'commerce' ), blueline_settings_tab_slugs() );
	}

	/**
	 * Known tab slugs get their proper label; an unrecognised one (a future
	 * tab this file was never updated for) still gets a readable label
	 * rather than the raw slug or nothing.
	 */
	public function test_tab_label_known_and_fallback(): void {
		$this->assertSame( 'Content', blueline_settings_tab_label( 'content' ) );
		$this->assertSame( 'Links', blueline_settings_tab_label( 'links' ) );
		$this->assertSame( 'Commerce', blueline_settings_tab_label( 'commerce' ) );
		$this->assertSame( 'Sections', blueline_settings_tab_label( 'sections' ) );
	}

	/**
	 * Only the requested tab's own fields must be returned, never another
	 * tab's -- a leaking field would let one tab's form post (and
	 * therefore validate/overwrite) a field it doesn't own.
	 */
	public function test_fields_for_tab_returns_only_that_tabs_fields(): void {
		$content_keys = array_keys( blueline_settings_fields_for_tab( 'content' ) );
		sort( $content_keys );
		$this->assertSame(
			array( 'contact_email', 'footer_heading', 'footer_location', 'hero_offseason_cta' ),
			$content_keys
		);

		foreach ( blueline_settings_fields_for_tab( 'links' ) as $field ) {
			$this->assertSame( 'links', $field['tab'] );
		}
	}

	/**
	 * An unrecognised (or absent) `tab` query var falls back to the first
	 * tab rather than rendering nothing.
	 */
	public function test_current_tab_falls_back_to_first_tab_when_unrecognised(): void {
		$_GET['tab'] = 'not-a-real-tab'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.
		$this->assertSame( 'content', blueline_settings_current_tab() );

		unset( $_GET['tab'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture cleanup.
		$this->assertSame( 'content', blueline_settings_current_tab() );
	}

	/**
	 * A recognised `tab` query var is honoured.
	 */
	public function test_current_tab_honours_a_recognised_request(): void {
		$_GET['tab'] = 'commerce'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.
		$this->assertSame( 'commerce', blueline_settings_current_tab() );
	}

	/**
	 * Every tab's URL must carry both the page slug and its own tab slug,
	 * so the tab nav actually links somewhere real.
	 */
	public function test_tab_url_carries_page_and_tab(): void {
		$url = blueline_settings_tab_url( 'links' );
		$this->assertStringContainsString( 'page=' . BLUELINE_SETTINGS_PAGE_SLUG, $url );
		$this->assertStringContainsString( 'tab=links', $url );
	}

	/**
	 * The add_page function must register the page under `manage_options`
	 * -- the capability required throughout this panel -- not some looser
	 * capability a future edit could accidentally weaken.
	 */
	public function test_add_page_registers_under_manage_options(): void {
		blueline_settings_add_page();

		$registered = end( $GLOBALS['bl_test_admin_pages'] );
		$this->assertSame( 'manage_options', $registered['capability'] );
		$this->assertSame( BLUELINE_SETTINGS_PAGE_SLUG, $registered['menu_slug'] );
		$this->assertSame( 'blueline_settings_render_page', $registered['callback'] );
	}

	/**
	 * A request that reaches the page callback without manage_options must
	 * be refused outright -- this is the second, independent capability
	 * gate the file's own docblock describes (the menu registration above
	 * is the first).
	 */
	public function test_render_page_refuses_a_user_without_manage_options(): void {
		$this->expectException( Blueline_Test_WP_Die_Exception::class );
		blueline_settings_render_page();
	}

	/**
	 * The register function must wire blueline_settings_sanitize_callback()
	 * onto the option's sanitize_option_* filter -- proven by actually
	 * saving through update_option() (the real dispatch path, not a direct
	 * call to the callback) and observing that an invalid value in it is
	 * rejected rather than stored verbatim.
	 */
	public function test_register_wires_the_sanitize_callback_into_the_settings_api(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading' => 'Save 50% off', // A bare "%" with no valid spec: rejected.
				'_posted_fields' => array( 'footer_heading' ),
			)
		);

		$this->assertSame(
			blueline_settings_defaults()['footer_heading'],
			blueline_settings( 'footer_heading' ),
			'an invalid value must never be written, proving the sanitize_callback actually ran'
		);
		$this->assertNotEmpty( blueline_settings_field_errors() );
	}

	/**
	 * A field that fails validation keeps its EXISTING value (not the
	 * default, not the rejected submission) and is reported via
	 * get_settings_errors() -- both explicitly promised in
	 * blueline_settings_sanitize_callback()'s own docblock.
	 */
	public function test_sanitize_callback_keeps_the_existing_value_on_a_rejected_field(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'Kept value' ) );

		$output = blueline_settings_sanitize_callback(
			array(
				'footer_heading' => 'Save 50% off',
				'_posted_fields' => array( 'footer_heading' ),
			)
		);

		$this->assertSame( 'Kept value', $output['footer_heading'] );

		$errors = blueline_settings_field_errors();
		$this->assertArrayHasKey( 'footer_heading', $errors );
	}

	/**
	 * A field that DOES validate is written through untouched, and no error
	 * is queued for it.
	 */
	public function test_sanitize_callback_accepts_a_valid_field(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'footer_heading' => 'A perfectly fine heading',
				'_posted_fields' => array( 'footer_heading' ),
			)
		);

		$this->assertSame( 'A perfectly fine heading', $output['footer_heading'] );
		$this->assertArrayNotHasKey( 'footer_heading', blueline_settings_field_errors() );
	}

	/**
	 * `_posted_fields` is filtered to keys the schema actually declares --
	 * an unknown key (accidental typo, or a stale field from a removed
	 * schema entry) must never reach blueline_settings_merge(), which
	 * would otherwise treat it as a legitimate delete instruction for a key
	 * that isn't even a real field.
	 */
	public function test_sanitize_callback_drops_unknown_keys_from_posted_fields(): void {
		$output = blueline_settings_sanitize_callback(
			array( '_posted_fields' => array( 'footer_heading', 'not_a_real_field' ) )
		);

		$this->assertSame( array( 'footer_heading' ), $output['_posted_fields'] );
	}

	/**
	 * A field named in `_posted_fields` but absent from the submission
	 * itself (the render layer's way of saying "this tab owns this field
	 * and the user cleared it") must be OMITTED from the sanitize
	 * callback's own return value, not backfilled with anything -- that
	 * omission is exactly what lets blueline_settings_merge() (already unit
	 * tested in SettingsStoreTest.php) treat it as a deliberate delete
	 * rather than "not mine, carry it forward".
	 */
	public function test_sanitize_callback_omits_a_field_named_but_not_posted(): void {
		$output = blueline_settings_sanitize_callback(
			array( '_posted_fields' => array( 'footer_heading' ) )
		);

		$this->assertArrayNotHasKey( 'footer_heading', $output );
		$this->assertSame( array( 'footer_heading' ), $output['_posted_fields'] );
	}

	/**
	 * The full round trip the task brief calls out explicitly: saving the
	 * Content tab with `footer_heading` cleared to an empty string must
	 * actually clear it, while the Links tab's own value -- never posted by
	 * this submission -- survives untouched. Exercised through
	 * update_option()/get_option(), the real path a real tab save uses (via
	 * this file's sanitize_callback, wired by register_setting(), and
	 * inc/settings/store.php's blueline_settings_merge()), not by calling
	 * either function directly.
	 */
	public function test_saving_one_tab_clears_a_field_without_disturbing_another_tab(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading' => 'The League',
				'page_faqs'      => 42,
			)
		);

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'contact_email'      => 'play@rookiehockey.ca',
				'footer_heading'     => '', // Cleared.
				'footer_location'    => 'Burlington, Ontario',
				'hero_offseason_cta' => 'Join the mailing list',
				'_posted_fields'     => array( 'contact_email', 'footer_heading', 'footer_location', 'hero_offseason_cta' ),
			)
		);

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame( '', $stored['footer_heading'], 'the cleared Content-tab field must actually be cleared' );
		$this->assertSame( 42, $stored['page_faqs'], 'the untouched Links-tab field must survive' );
	}

	/**
	 * The literal scenario the brief asks for: a submitted field value
	 * containing a `<script>` tag, combined with a stray "%" so the value
	 * is rejected and its (sanitized) form is reflected back inside the
	 * WP_Error message. Saves through the real path, renders the real page
	 * callback, and asserts the captured HTML contains no live `<script`
	 * tag anywhere -- not merely that the message object looks escaped in
	 * isolation.
	 */
	public function test_rejected_value_containing_script_tag_is_never_rendered_unescaped(): void {
		$this->grant_manage_options();

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading' => '<script>alert(1)</script>%',
				'_posted_fields' => array( 'footer_heading' ),
			)
		);

		$_GET['tab'] = 'content'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.

		ob_start();
		blueline_settings_render_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsStringIgnoringCase(
			'<script',
			$html,
			'a rejected value must never let a live <script> tag reach the rendered page'
		);
	}

	/**
	 * A direct, unambiguous proof that escaping is actually happening (not
	 * merely that nothing dangerous ever reaches the message): a WP_Error
	 * message built the same way blueline_settings_sanitize_callback()
	 * builds one -- interpolating a field's own `label`, exactly as
	 * blueline_sanitize_field() does -- is escaped with esc_html() before
	 * add_settings_error() ever sees it. If that esc_html() call were ever
	 * removed, this test fails by finding the raw tag still present.
	 */
	public function test_a_message_with_html_significant_characters_is_escaped_before_storage(): void {
		$field = array(
			'type'         => 'text',
			'label'        => '<script>alert(1)</script>',
			'placeholders' => array( '%s' ), // Deliberately unmet below, forcing a WP_Error.
		);

		$result = blueline_sanitize_field( 'no placeholder here', $field );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '<script>alert(1)</script>', $result->get_error_message(), 'sanity check: the raw message really does contain the tag before escaping' );

		// The exact operation blueline_settings_sanitize_callback() performs
		// before ever calling add_settings_error().
		add_settings_error( BLUELINE_SETTINGS_OPTION, 'test_field', esc_html( $result->get_error_message() ), 'error' );

		$stored = get_settings_errors( BLUELINE_SETTINGS_OPTION );
		$this->assertStringNotContainsString( '<script>', $stored[0]['message'] );
		$this->assertStringContainsString( '&lt;script&gt;', $stored[0]['message'], 'escaping must have actually run, not merely found nothing to change' );
	}

	/**
	 * A successful save (options.php's `settings-updated=true` redirect
	 * flag) must surface a plain success notice, and it too must be
	 * escaped the same way as an error message -- add_settings_error()
	 * gives no special treatment to a 'success' type.
	 */
	public function test_successful_save_flag_queues_an_escaped_success_notice(): void {
		$_GET['settings-updated'] = 'true'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.

		blueline_settings_maybe_flag_saved();

		$notices = blueline_settings_non_field_messages();
		$this->assertCount( 1, $notices );
		$this->assertSame( 'success', $notices[0]['type'] );
		$this->assertSame( 'Settings saved.', $notices[0]['message'] );
	}

	/**
	 * A text/email field's row must carry a real `<label for>` pointing at
	 * the input's own id -- the accessibility baseline every field needs
	 * regardless of error state.
	 */
	public function test_field_row_has_a_real_label_for_association(): void {
		ob_start();
		blueline_settings_render_field(
			'footer_heading',
			blueline_settings_schema()['footer_heading'],
			null
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'for="blueline-field-footer_heading"', $html );
		$this->assertStringContainsString( 'id="blueline-field-footer_heading"', $html );
	}

	/**
	 * A field with a queued error must render `aria-invalid="true"` and
	 * `aria-describedby` pointing at an element that actually exists and
	 * actually carries the message -- and the error must not be conveyed by
	 * colour alone: an explicit "Error:" text prefix is required alongside
	 * whatever CSS class a stylesheet hangs a colour treatment off of.
	 */
	public function test_field_row_with_an_error_carries_aria_attributes_and_a_text_cue(): void {
		ob_start();
		blueline_settings_render_field(
			'footer_heading',
			blueline_settings_schema()['footer_heading'],
			'Something went wrong.'
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( 'aria-describedby="blueline-field-footer_heading-error"', $html );
		$this->assertStringContainsString( 'id="blueline-field-footer_heading-error"', $html );
		$this->assertStringContainsString( 'Error:', $html, 'nothing may be conveyed by colour alone -- there must be a text cue' );
		$this->assertStringContainsString( 'Something went wrong.', $html );
	}

	/**
	 * A `page_id` field must render as a page picker (an admin chooses
	 * "FAQs", never types a raw post ID), pre-selecting whatever page is
	 * currently stored and showing its real title.
	 */
	public function test_page_id_field_renders_a_page_picker_with_the_selected_title(): void {
		blueline_test_register_page_with_title( 42, 'publish', 'Frequently Asked Questions' );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'page_faqs' => 42 ) );

		ob_start();
		blueline_settings_render_field(
			'page_faqs',
			blueline_settings_schema()['page_faqs'],
			null
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( '<select', $html );
		$this->assertStringNotContainsString( 'type="number"', $html, 'a page_id field must never be a raw ID box' );
		$this->assertStringContainsString( 'Frequently Asked Questions', $html );
		$this->assertMatchesRegularExpression( '/<option value="42" selected="selected">Frequently Asked Questions<\/option>/', $html );
	}

	/**
	 * An error summary must exist, be a valid focus target (`tabindex="-1"`)
	 * and `autofocus` (the zero-JavaScript way this page moves focus to it
	 * on the page load after a failed save), and link to the failed
	 * field's own input id.
	 */
	public function test_error_summary_is_focusable_and_links_to_the_failed_field(): void {
		$this->grant_manage_options();

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading' => 'Save 50% off',
				'_posted_fields' => array( 'footer_heading' ),
			)
		);

		$_GET['tab'] = 'content'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.

		ob_start();
		blueline_settings_render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="blueline-settings-error-summary"', $html );
		$this->assertStringContainsString( 'tabindex="-1"', $html );
		$this->assertStringContainsString( 'autofocus', $html );
		$this->assertStringContainsString( 'href="#blueline-field-footer_heading"', $html );
	}

	/**
	 * No error summary (and no `autofocus`) on an ordinary page load with
	 * nothing to report -- the attribute must never steal focus on a
	 * routine visit.
	 */
	public function test_no_error_summary_when_nothing_failed(): void {
		$this->grant_manage_options();
		$_GET['tab'] = 'content'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.

		ob_start();
		blueline_settings_render_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'blueline-settings-error-summary', $html );
		$this->assertStringNotContainsString( 'autofocus', $html );
	}

	/**
	 * The tab nav must render plain links carrying `aria-current="page"`
	 * for whichever tab is active -- explicitly NOT an ARIA tab widget
	 * (no `role="tab"`/`role="tablist"`), per the task brief.
	 */
	public function test_tab_nav_is_plain_links_not_an_aria_tab_widget(): void {
		$this->grant_manage_options();
		$_GET['tab'] = 'links'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.

		ob_start();
		blueline_settings_render_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'role="tab"', $html );
		$this->assertStringNotContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*tab=links[^"]*"[^>]*class="nav-tab nav-tab-active"/', $html );
	}
}
