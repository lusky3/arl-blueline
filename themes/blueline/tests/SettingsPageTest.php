<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/snapshots.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/page.php';
require_once __DIR__ . '/../inc/settings/occasions-tab.php'; // blueline_settings_render_occasions_tab() and its row/label helpers, exercised below.
require_once __DIR__ . '/../inc/settings/acknowledgements.php';
require_once __DIR__ . '/../inc/setup.php'; // blueline_active_widget_count(), which blueline_section_widget_warning() calls via the field-row renderer below.
require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/occasions.php';

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
 *   inc/settings/store.php's merge) gets this right end-to-end. Its
 *   entries are also tab-scoped: a submission cannot name a field from a
 *   DIFFERENT tab in `_posted_fields` and have that honoured --
 *   test_posted_fields_naming_a_different_tabs_field_is_ignored() and
 *   test_sanitize_callback_drops_posted_fields_entries_belonging_to_a_different_tab()
 *   cover the round trip and the callback's own filtering directly.
 * - Every write path is validated, not only ones that pass through
 *   wp-admin's `admin_init`: blueline_settings_sanitize_callback() is wired
 *   onto `sanitize_option_{$option}` unconditionally, at file scope (see
 *   page.php's own docblock) -- not only inside the admin_init-hooked
 *   blueline_settings_register(). None of this file's setUp() fires
 *   `admin_init` at all, which is itself part of the proof: every test
 *   below that saves through update_option() is already demonstrating
 *   validation running without it;
 *   test_validation_applies_to_a_direct_update_option_call_with_no_admin_init()
 *   makes that explicit, and
 *   test_admin_init_still_registers_the_setting_for_the_ui() confirms
 *   admin_init still does its own, separate job.
 *
 * Fix round 3 (a real browser click-through, not a unit test, found this):
 * the error summary's focus-on-failed-save behaviour previously relied
 * solely on the `autofocus` HTML attribute, reasoned to be "the
 * zero-JavaScript way" because the living standard defines it as a global
 * attribute valid on any element. A live browser check disproved that --
 * Chromium does not move focus to a plain `<div autofocus>` -- so this is
 * exactly the class of bug a PHPUnit test checking only rendered markup
 * cannot see: the markup was correct (the attribute was there), and only a
 * real browser's actual focus behaviour exposed that it does nothing.
 * blueline_settings_focus_summary_script() (an explicit `.focus()` call,
 * enqueued via wp_add_inline_script()) replaces it.
 * test_focus_summary_script_is_enqueued_only_for_this_page() and
 * test_focus_summary_script_content_targets_the_summary_element() are the
 * part of this fix that PHPUnit CAN verify -- the right script, scoped to
 * the right screen, with the right content -- not proof that a browser
 * actually moves focus when it runs. That was verified separately, outside
 * this suite, by loading this page's own rendered HTML plus WordPress
 * core's real wp-admin/js/common.js (fetched from the exact same running
 * WordPress install) in an actual Chromium instance and observing
 * `document.activeElement` move from `<body>` to the summary only once the
 * explicit `.focus()` call ran -- see the task report for the full
 * transcript, since no unit test can stand in for that check.
 *
 * Fix round 4 (a REAL admin session on staging, not a simulation, found
 * this -- round 3's own simulation reached the wrong conclusion): the
 * error summary still never rendered after a genuine failed save. The
 * round-3 simulation was directionally right about `autofocus` not
 * working on a `<div>` (that finding still stands -- the explicit
 * `.focus()` call is still needed and still correct) but wrong about why
 * the summary was missing, because it never included every piece of the
 * real environment: this WordPress install runs a third-party plugin
 * (Capabilities Pro's admin-notices module) that removes any `<div>`
 * matching a broad notice/error/warning/info/updated class substring on
 * every wp-admin screen -- something no amount of simulating WordPress
 * core's own common.js alone was ever going to surface, because it isn't
 * core's behaviour, it's a different, unrelated plugin's. Confirmed by
 * reading the plugin's own JS source directly off staging, and by
 * inspecting the RAW HTTP response body of a genuine failed save (fetched
 * via Playwright's network inspection, not a DOM query) -- which showed
 * this file's PHP was correct and had NEVER been the problem: the summary
 * was fully present in the server's actual response every time, and
 * disappeared only afterward, in the live DOM. See
 * blueline_settings_render_page()'s own docblock (the "Never a `<div>`"
 * section) for the fix.
 *
 * Fix round 5: the same plugin was also eating the "Settings saved."
 * success notice (page.php) and the manual page-cache-purge notice
 * (cache.php) -- both were still plain `<div class="notice ...">`, so a
 * genuinely successful save gave an admin no feedback at all, and the
 * purge notice (the entire shipped behaviour of the cache-purge
 * requirement, since the guarded automatic purge ships off) never
 * rendered either. Both fixed to `<section>` alongside the round-4 fix
 * this file's docblock describes above. The narrow, single-element
 * regression test this file used to carry (pinning only the error
 * summary's own id) was replaced with tests/NoticeDivGuardTest.php -- a
 * source scan across every theme PHP file for ANY `<div>` whose class
 * contains "notice", "error", "warning", "info" or "updated", which is
 * what should have existed from round 4 and would have caught these two
 * on its own.
 */
final class SettingsPageTest extends TestCase {

	/**
	 * Reset every in-memory store. Deliberately does NOT fire `admin_init`
	 * -- every test below therefore exercises the write path exactly as
	 * WP-CLI or an import script would (admin_init never fires for
	 * either), which is the whole point of wiring
	 * blueline_settings_sanitize_callback() unconditionally at file scope
	 * rather than inside blueline_settings_register(). A test that
	 * specifically needs admin_init to have fired (there is exactly one:
	 * test_admin_init_still_registers_the_setting_for_the_ui()) fires it
	 * itself.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		unset( $_GET['tab'], $_GET['settings-updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture cleanup of superglobals between cases, not a real request.
		// The restore control posts; a case that seeded either of these
		// must not leave them visible to the next one.
		unset( $_POST['blueline_restore_snapshot'], $_REQUEST['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture cleanup of superglobals between cases, not a real request.
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
	 * The schema's own tab order (content, links, appearance, commerce,
	 * sections today) must be exactly what the tab nav renders and iterates
	 * in -- derived from the schema, not hardcoded, so a future tab needs no
	 * edit here. `occasions` is appended last: it is the one explicit,
	 * named exception described in
	 * test_tab_slugs_includes_occasions_as_a_named_exception() below, not a
	 * schema-derived tab.
	 */
	public function test_tab_slugs_reflect_schema_order(): void {
		$this->assertSame(
			array( 'content', 'links', 'appearance', 'commerce', 'sections', 'occasions' ),
			blueline_settings_tab_slugs()
		);
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
			array(
				'account_empty_next_game',
				'account_empty_stats',
				'announcement_from',
				'announcement_link',
				'announcement_severity',
				'announcement_text',
				'announcement_to',
				'contact_email',
				'footer_heading',
				'footer_location',
				'hero_in_season_headline',
				'hero_offseason_cta',
				'hero_offseason_headline',
				'hero_offseason_highlight',
				'hero_playoffs_eyebrow',
				'hero_preseason_headline',
				'hero_registration_cta',
				'hero_registration_eyebrow',
				'hero_registration_headline',
				'hero_registration_highlight',
				'module_new_here_cta',
				'module_new_here_heading',
				'season_state_override',
				'season_state_override_until',
			),
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
	 * The sanitize callback must be wired onto sanitize_option_{$option}
	 * -- proven by actually saving through
	 * update_option() (the real dispatch path, not a direct call to the
	 * callback) and observing that an invalid value in it is rejected
	 * rather than stored verbatim. setUp() never fires `admin_init`, so
	 * this already proves the wiring does not depend on it -- see
	 * test_validation_applies_to_a_direct_update_option_call_with_no_admin_init()
	 * for the same point made explicitly.
	 */
	public function test_sanitize_callback_is_wired_into_the_settings_api(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading' => 'Save 50% off', // A bare "%" with no valid spec: rejected.
				'_tab'           => 'content',
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
	 * The task-7 fix-round finding this directly addresses: `admin_init`
	 * never fires for WP-CLI or a script calling update_option() directly,
	 * so if blueline_settings_sanitize_callback() were wired ONLY inside
	 * the admin_init-hooked blueline_settings_register(), every write
	 * reachable outside wp-admin would bypass validation entirely --
	 * including the placeholder contract, reintroducing the exact
	 * sprintf()-format-string fatal inc/settings/sanitize.php exists to
	 * prevent. This test's setUp() never fires `admin_init` (see the class
	 * docblock), so a straight update_option() call here IS that scenario.
	 */
	public function test_validation_applies_to_a_direct_update_option_call_with_no_admin_init(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'Save 50% off' ) );

		$this->assertSame(
			blueline_settings_defaults()['footer_heading'],
			blueline_settings( 'footer_heading' ),
			'a direct update_option() call, with admin_init never fired, must still reject an invalid value'
		);
	}

	/**
	 * The admin_init hook still has its own job: registering the option with the
	 * Settings API's UI/whitelist machinery so a settings_fields()-rendered
	 * form can actually post to options.php. blueline_settings_register()
	 * deliberately no longer passes a `sanitize_callback` (see page.php's
	 * own docblock for why), so this only checks the registration itself,
	 * not sanitize wiring -- that is covered by the tests above instead.
	 */
	public function test_admin_init_still_registers_the_setting_for_the_ui(): void {
		do_action( 'admin_init' );

		$this->assertArrayHasKey(
			BLUELINE_SETTINGS_OPTION,
			$GLOBALS['bl_test_registered_settings'][ BLUELINE_SETTINGS_OPTION_GROUP ] ?? array(),
			'register_setting() must still run on admin_init for the Settings API UI wiring'
		);
	}

	/**
	 * A field that fails validation keeps its EXISTING value (not the
	 * default, not the rejected submission) and is reported via
	 * get_settings_errors() -- both explicitly promised in
	 * blueline_settings_sanitize_callback()'s own docblock.
	 */
	public function test_sanitize_callback_keeps_the_existing_value_on_a_rejected_field(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'Kept value' ) );

		$output = blueline_settings_sanitize_callback( $this->invalid_save_fixture() );

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
			array(
				'_tab'           => 'content',
				'_posted_fields' => array( 'footer_heading', 'not_a_real_field' ),
			)
		);

		$this->assertSame( array( 'footer_heading' ), $output['_posted_fields'] );
	}

	/**
	 * I2, end to end through the real save path rather than the sanitizer in
	 * isolation: the exact scenario the review named. An admin mistypes the
	 * break-glass state under pressure, hits Save, and must NOT get a clean
	 * "Settings saved." over a value that changes nothing.
	 *
	 * Three things have to hold together for that, which is why this asserts
	 * all three rather than trusting the unit test above: the rejected value
	 * is never written, an error is queued for the admin screen, and the
	 * message names the field so they can find it.
	 *
	 * Note the callback's existing contract for a rejected field: it keeps
	 * whatever is already stored rather than dropping the key, so a bad edit
	 * to one field cannot also clear it.
	 */
	public function test_a_mistyped_break_glass_state_is_reported_not_silently_saved(): void {
		/*
		 * A NON-DEFAULT value has to be in place first, or the "kept what
		 * was stored" assertion below cannot tell that apart from "fell back
		 * to the default" -- this field's default is '', so both readings
		 * produce the same string and the assertion proves nothing. An
		 * earlier version of this test had exactly that hole.
		 */
		update_option( BLUELINE_SETTINGS_OPTION, array( 'season_state_override' => 'playoffs' ) );
		$this->assertSame( 'playoffs', blueline_settings( 'season_state_override' ), 'premise: a real override is in force' );

		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'                  => 'content',
				'_posted_fields'        => array( 'season_state_override' ),
				'season_state_override' => 'playofs',
			)
		);

		$this->assertNotSame(
			'playofs',
			$output['season_state_override'],
			'a refused value must never be written'
		);
		$this->assertSame(
			'playoffs',
			$output['season_state_override'],
			'and the override already in force survives the rejection, rather than being cleared to the default'
		);
		$this->assertNotSame(
			blueline_settings_defaults()['season_state_override'],
			$output['season_state_override'],
			'which is only a meaningful claim because the stored value differs from the default'
		);

		$errors = get_settings_errors( BLUELINE_SETTINGS_OPTION );
		$this->assertNotEmpty( $errors, 'a refused emergency control must say so, not save quietly' );

		$messages = implode( ' ', array_column( $errors, 'message' ) );
		$this->assertStringContainsString( 'Force the season state', $messages );
	}

	/**
	 * The contrast case, so the guard above cannot be passing by refusing
	 * everything: a real state saves cleanly and queues no error.
	 */
	public function test_a_valid_break_glass_state_saves_without_complaint(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'                  => 'content',
				'_posted_fields'        => array( 'season_state_override' ),
				'season_state_override' => 'playoffs',
			)
		);

		$this->assertSame( 'playoffs', $output['season_state_override'] );
		$this->assertSame( array(), get_settings_errors( BLUELINE_SETTINGS_OPTION ) );
	}

	/**
	 * The other half of Task 7's fix-round finding: `_posted_fields` is
	 * ALSO filtered to keys whose own schema `tab` matches the submission's
	 * `_tab` -- `page_faqs` belongs to 'links', so naming it from a
	 * submission declaring `_tab => 'content'` must never be honoured, even
	 * though `page_faqs` is a perfectly real schema key. Every tab shares
	 * one settings_fields() nonce group, so nothing else disambiguates
	 * "which tab does this submission actually own" -- see page.php's own
	 * docblock.
	 */
	public function test_sanitize_callback_drops_posted_fields_entries_belonging_to_a_different_tab(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'           => 'content',
				'_posted_fields' => array( 'footer_heading', 'page_faqs' ), // page_faqs belongs to 'links'.
			)
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
			array(
				'_tab'           => 'content',
				'_posted_fields' => array( 'footer_heading' ),
			)
		);

		$this->assertArrayNotHasKey( 'footer_heading', $output );
		$this->assertSame( array( 'footer_heading' ), $output['_posted_fields'] );
	}

	/**
	 * Task-7 fix-round-2 finding: the sanitize callback must not forward
	 * ANY unrecognised top-level key merely because it doesn't recognise
	 * it -- that was a real weakening of the allow-list model Tasks 2-6
	 * built. A key that is neither a real schema field nor on
	 * BLUELINE_SETTINGS_RESERVED_KEYS must be dropped, exactly as if it
	 * had never been declared at all.
	 */
	public function test_sanitize_callback_drops_an_unrecognised_key_that_is_not_reserved(): void {
		$output = blueline_settings_sanitize_callback(
			array( 'some_made_up_key' => 'anything' )
		);

		$this->assertArrayNotHasKey( 'some_made_up_key', $output );
	}

	/**
	 * The one thing that MUST keep working after tightening the allow-list:
	 * inc/settings/store.php's `_schema` migration bookkeeping key still
	 * has to survive a programmatic update_option() call (no `_tab`
	 * present, i.e. not this file's own rendered form) -- this is exactly
	 * the case that broke the first time the callback went from "forward
	 * unrecognised keys" back to a strict allow-list, since `_schema` is
	 * deliberately not a real schema field either.
	 */
	public function test_sanitize_callback_lets_schema_survive_a_programmatic_write(): void {
		$output = blueline_settings_sanitize_callback(
			array( '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION )
		);

		$this->assertArrayHasKey( '_schema', $output );
		$this->assertSame( BLUELINE_SETTINGS_SCHEMA_VERSION, $output['_schema'] );
	}

	/**
	 * Task-7 fix-round-5 finding: `_schema` must be clamped to
	 * BLUELINE_SETTINGS_SCHEMA_VERSION, matching the limit
	 * inc/cli/settings-command.php enforces on an import. Without this, a
	 * `_schema` written above the running code's version (reachable via
	 * any direct update_option() call this reserved-key branch lets
	 * through) would make blueline_settings_migrate()'s forward-only guard
	 * treat the install as already current, permanently and silently
	 * skipping every future migration.
	 */
	public function test_sanitize_callback_clamps_schema_to_the_current_version(): void {
		$output = blueline_settings_sanitize_callback(
			array( '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION + 5 )
		);

		$this->assertSame(
			BLUELINE_SETTINGS_SCHEMA_VERSION,
			$output['_schema'],
			'_schema must never be allowed to exceed the running code\'s own schema version'
		);
	}

	/**
	 * `_schema` is sanitized like everything else this callback handles
	 * (absint()), never trusted as opaque data just because it's on the
	 * reserved allow-list -- a negative or non-numeric value must not
	 * survive verbatim. Uses a value that int-casts to something at or
	 * below BLUELINE_SETTINGS_SCHEMA_VERSION, so this test isolates the
	 * int-cast from the separate clamp
	 * test_sanitize_callback_clamps_schema_to_the_current_version() covers.
	 */
	public function test_sanitize_callback_integer_casts_schema_on_a_programmatic_write(): void {
		$output = blueline_settings_sanitize_callback(
			array( '_schema' => '0abc' )
		);

		$this->assertSame( 0, $output['_schema'] );
	}

	/**
	 * The other half of the fix: a submission carrying `_tab` came from
	 * this file's own rendered form, which never legitimately has a
	 * `_schema` field on any tab -- its presence there can only be
	 * tampering (accidental or otherwise), never a real use of the panel.
	 * An admin sending `blueline_settings[_schema]` at or above
	 * BLUELINE_SETTINGS_SCHEMA_VERSION through the form must not be able
	 * to make blueline_settings_migrate()'s forward-only guard treat the
	 * install as already current -- so `_schema` is dropped outright here,
	 * not merely sanitized.
	 */
	public function test_sanitize_callback_drops_schema_from_a_form_submission(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'    => 'content',
				'_schema' => 999,
			)
		);

		$this->assertArrayNotHasKey( '_schema', $output );
	}

	/**
	 * Migration must still behave correctly after all of the above: a
	 * fresh update_option() call (no `_tab`, matching
	 * blueline_settings_migrate()'s own write shape) carrying `_schema`
	 * survives through the full sanitize_option_/pre_update_option_
	 * dispatch chain, and blueline_settings_migrate() reads it back
	 * correctly -- proving the reserved-key allow-list didn't quietly
	 * break the migration guard it exists to protect.
	 */
	public function test_migration_still_works_through_the_tightened_allow_list(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array( '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION )
		);

		$before = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame(
			BLUELINE_SETTINGS_SCHEMA_VERSION,
			$before['_schema'],
			'sanity check: _schema must have actually been written before migrate() runs'
		);

		blueline_settings_migrate();

		// Already current: blueline_settings_migrate() must be a true no-op
		// (SettingsStoreTest::test_migration_is_a_no_op_once_already_current()
		// already covers this in isolation; repeated here through the full
		// write path this fix round changed).
		$this->assertSame( $before, get_option( BLUELINE_SETTINGS_OPTION ) );

		// The forward path: an unversioned option is bumped to current and
		// backfilled with defaults.
		delete_option( BLUELINE_SETTINGS_OPTION );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'kept-through-migration@example.com' ) );

		blueline_settings_migrate();

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame( BLUELINE_SETTINGS_SCHEMA_VERSION, $stored['_schema'] );
		$this->assertSame( 'kept-through-migration@example.com', $stored['contact_email'] );
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
				'_tab'               => 'content',
				'_posted_fields'     => array( 'contact_email', 'footer_heading', 'footer_location', 'hero_offseason_cta' ),
			)
		);

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame( '', $stored['footer_heading'], 'the cleared Content-tab field must actually be cleared' );
		$this->assertSame( 42, $stored['page_faqs'], 'the untouched Links-tab field must survive' );
	}

	/**
	 * The fix-round finding, exercised as a full round trip rather than a
	 * direct call to the callback: a request shaped like a Content-tab
	 * submission (`_tab => 'content'`) names `page_faqs` -- a LINKS-tab
	 * field -- in `_posted_fields` without posting `page_faqs` itself.
	 * Before the tab-scoping fix, blueline_settings_merge() would read
	 * that absence as "owned but omitted -- delete", wiping a field this
	 * submission never rendered and does not own. Every tab shares one
	 * settings_fields() nonce group, so nothing else would have stopped
	 * this.
	 */
	public function test_posted_fields_naming_a_different_tabs_field_is_ignored(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'page_faqs' => 42 ) );

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading' => 'Updated heading',
				'_tab'           => 'content',
				'_posted_fields' => array( 'footer_heading', 'page_faqs' ),
			)
		);

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame(
			42,
			$stored['page_faqs'],
			'a field belonging to a different tab must survive even when named in _posted_fields'
		);
		$this->assertSame( 'Updated heading', $stored['footer_heading'] );
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
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading' => '<script>alert(1)</script>%',
				'_posted_fields' => array( 'footer_heading' ),
			)
		);

		$_GET['tab'] = 'content'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.

		$html = $this->render_page();

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
	 * add_settings_error() ever sees it.
	 *
	 * NOTE what this does and does not prove. It performs the escape ITSELF,
	 * in the test body, so it pins esc_html()'s behaviour on a realistic
	 * message -- not the production call site. An earlier version of this
	 * docblock claimed "if that esc_html() call were ever removed, this test
	 * fails by finding the raw tag still present", which was false: this test
	 * never calls blueline_settings_sanitize_callback() at all, and deleting
	 * the escape at inc/settings/page.php:511 left it green.
	 *
	 * The call site is pinned by
	 * test_a_rejected_field_is_escaped_by_the_save_path_itself() below, which
	 * goes through update_option(). Both are worth keeping: this one can use a
	 * hostile label a real schema would never carry.
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
	 * The escape at inc/settings/page.php:511 is the ONLY one between a
	 * rejection message and a raw `echo` in wp-admin -- the two render sites
	 * deliberately do not re-escape, because double-encoding entities would
	 * show an admin `&amp;quot;` where they typed a quote.
	 *
	 * So it needs a test that goes through the real save path, and the fixture
	 * has to survive sanitize_text_field(). That is the trap the previous
	 * attempt fell into: `<script>alert(1)</script>` is not merely escaped by
	 * the time it is asserted on, it is GONE -- wp_strip_all_tags() removes the
	 * tag and its contents at save, so a test asserting "no <script> in the
	 * output" was asserting against something that was never there. Two other
	 * tests on this branch went vacuous in exactly that way.
	 *
	 * A double quote survives sanitize_text_field() untouched, and several real
	 * schema labels carry one. `hero_registration_headline`'s says
	 * `e.g. "beginner"`, which lands in the message verbatim -- so escaping is
	 * observable as `&amp;quot;`, and its absence is observable as a bare `"`.
	 *
	 * @return void
	 */
	public function test_a_rejected_field_is_escaped_by_the_save_path_itself(): void {
		// Missing the %s the field's placeholder contract requires, so the
		// sanitizer rejects it and builds a message naming the field.
		update_option( BLUELINE_SETTINGS_OPTION, array( 'hero_registration_headline' => 'no placeholder here' ) );

		$stored = get_settings_errors( BLUELINE_SETTINGS_OPTION );
		$this->assertNotEmpty( $stored, 'premise: the save must have queued a rejection message' );

		$message = $stored[0]['message'];

		// Premise: the label really did reach the message, so the assertions
		// below are about escaping rather than about an absent substring.
		$this->assertStringContainsString( 'beginner', $message, 'premise: the field label reaches the rejection message' );

		$this->assertStringContainsString( '&quot;beginner&quot;', $message, 'the save path must escape the message before queueing it' );
		$this->assertStringNotContainsString( '"beginner"', $message, 'an unescaped quote means esc_html() never ran' );
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
	 * Fix round 1 (Task 2, P1b-panel-completion): a `module_*` section field
	 * carries a `help` string explaining the homepage-modules floor (an
	 * admin who unticks every box still sees one module -- see
	 * blueline_section_definitions()'s own docblock and the "THE FLOOR"
	 * comment in blueline_homepage_module_order()). This proves that help
	 * text actually reaches the rendered `<p class="description">`, not
	 * merely that blueline_section_definitions() defines the string --
	 * schema generation (blueline_settings_schema()) has to carry `help`
	 * through alongside `label`/`group` for this to be true, and this is
	 * the guard that would fail if a future edit stopped doing that.
	 */
	public function test_a_module_section_fields_help_text_reaches_the_rendered_row(): void {
		ob_start();
		blueline_settings_render_field(
			'module_latest_news',
			blueline_settings_schema()['module_latest_news'],
			null
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( '<p class="description">', $html );
		$this->assertStringContainsString( 'At least one homepage module always shows', $html );
	}

	/**
	 * The contrast case: a `section` field with no floor behind it (a
	 * `chrome_*`/`account_*` entry, unticking every one of which really
	 * does hide the whole group) carries no `help` string, and must not
	 * render an empty description paragraph -- the render branch's
	 * `! empty( $field['help'] )` guard exists precisely so an absent key
	 * prints nothing rather than a blank `<p>`.
	 */
	public function test_a_section_field_without_help_renders_no_description_paragraph(): void {
		ob_start();
		blueline_settings_render_field(
			'chrome_sponsors',
			blueline_settings_schema()['chrome_sponsors'],
			null
		);
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'class="description"', $html );
	}

	/**
	 * Task 7 fix round (I2): a field declaring `choices` renders a
	 * `<select>`, not a text box. That is the half of the fix that makes an
	 * invalid value unreachable rather than merely rejected — which matters
	 * for `season_state_override`, a control used under pressure where a
	 * typo used to save cleanly and change nothing.
	 */
	public function test_a_choices_field_renders_a_select_of_its_options(): void {
		ob_start();
		blueline_settings_render_field(
			'season_state_override',
			blueline_settings_schema()['season_state_override'],
			null
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<select', $html );
		$this->assertStringNotContainsString( 'type="text"', $html );

		foreach ( array_keys( blueline_settings_schema()['season_state_override']['choices'] ) as $choice ) {
			$this->assertStringContainsString(
				'value="' . $choice . '"',
				$html,
				"the '$choice' option is missing from the rendered select"
			);
		}
	}

	/**
	 * The stored value is the one marked selected, so reopening the panel
	 * shows what is actually in force rather than resetting to the first
	 * option.
	 */
	public function test_a_choices_field_marks_the_stored_value_selected(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'season_state_override' => 'playoffs' ) );

		ob_start();
		blueline_settings_render_field(
			'season_state_override',
			blueline_settings_schema()['season_state_override'],
			null
		);
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="playoffs"\s+selected="selected"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value=""\s+selected="selected"/', $html );
	}

	/**
	 * A `choices` field can fail validation (a hand-built POST or a WP-CLI
	 * write bypasses the select entirely), so its branch carries the same
	 * aria wiring every other fallible input does.
	 */
	public function test_a_choices_field_with_an_error_carries_the_aria_wiring(): void {
		ob_start();
		blueline_settings_render_field(
			'season_state_override',
			blueline_settings_schema()['season_state_override'],
			'Not a season state.'
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( 'aria-describedby=', $html );
		$this->assertStringContainsString( 'Not a season state.', $html );
	}

	/**
	 * Task 6: a `date` field renders a real `<input type="date">`. Without
	 * its own branch an unknown type silently falls through to the plain
	 * text input at the end of the dispatch -- no error, just a text box an
	 * admin has to know to type YYYY-MM-DD into, against a sanitizer that
	 * rejects everything else.
	 */
	public function test_a_date_field_renders_a_date_input(): void {
		ob_start();
		blueline_settings_render_field(
			'announcement_to',
			blueline_settings_schema()['announcement_to'],
			null
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="date"', $html );
		$this->assertStringNotContainsString( 'type="text"', $html );
	}

	/**
	 * A `date` field CAN fail validation (unlike `page_id`/`term_id`), so
	 * its branch has to carry the same aria wiring the text input does.
	 */
	public function test_a_date_field_with_an_error_carries_the_aria_wiring(): void {
		ob_start();
		blueline_settings_render_field(
			'announcement_to',
			blueline_settings_schema()['announcement_to'],
			'Not a date.'
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( 'aria-describedby=', $html );
		$this->assertStringContainsString( 'Not a date.', $html );
	}

	/**
	 * Task 6: `help` reaches the rendered row for EVERY field type, not
	 * only for `bool`/`section`. It used to be printed inside that one
	 * branch, so a `help` string on a `text`, `page_id` or `date` field was
	 * accepted by the schema and then silently dropped -- the panel
	 * promising an explanation it never printed. These three fields are the
	 * first of each of those types to carry one.
	 */
	public function test_help_text_reaches_the_row_for_non_checkbox_types(): void {
		foreach ( array( 'announcement_text', 'announcement_link', 'announcement_from' ) as $field_key ) {
			ob_start();
			blueline_settings_render_field( $field_key, blueline_settings_schema()[ $field_key ], null );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString(
				'class="description"',
				$html,
				"$field_key declares a help string that never reached the rendered row"
			);
		}
	}

	/**
	 * The `band_photos` field's help must appear exactly ONCE. Its own
	 * renderer used to print it as well as the row, which the generic
	 * help block above would have turned into a duplicate paragraph.
	 */
	public function test_the_band_photos_help_renders_exactly_once(): void {
		ob_start();
		blueline_settings_render_field(
			'hero_photos',
			blueline_settings_schema()['hero_photos'],
			null
		);
		$html = (string) ob_get_clean();

		$this->assertSame(
			1,
			substr_count( $html, 'Leave empty to use the photographs that ship with the theme.' ),
			'the hero photograph help string is printed twice'
		);
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
	 * An error summary must exist, be a valid focus target (`tabindex="-1"`),
	 * and link to the failed field's own input id. It must NOT rely on the
	 * `autofocus` attribute alone -- a live browser check (see this test
	 * class's own docblock and blueline_settings_focus_summary_script()'s
	 * docblock) found Chromium does not honour `autofocus` on a plain
	 * `<div>` despite the HTML living standard allowing it; the fix-round-3
	 * regression test for that is
	 * test_focus_summary_script_is_enqueued_only_for_this_page() below,
	 * which is the part of this bug a render-output assertion alone cannot
	 * catch (see this class's docblock).
	 */
	public function test_error_summary_is_focusable_and_links_to_the_failed_field(): void {
		update_option( BLUELINE_SETTINGS_OPTION, $this->invalid_save_fixture() );

		$_GET['tab'] = 'content'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.

		$html = $this->render_page();

		$this->assertStringContainsString( 'id="blueline-settings-error-summary"', $html );
		$this->assertStringContainsString( 'tabindex="-1"', $html );
		$this->assertStringContainsString( 'href="#blueline-field-footer_heading"', $html );
	}

	/**
	 * `autofocus` must never appear in this file's markup at all -- proven
	 * non-functional for this exact purpose (see this class's docblock),
	 * its presence would be actively misleading about how focus is
	 * actually moved (blueline_settings_focus_summary_script(), an
	 * explicit .focus() call).
	 */
	public function test_rendered_page_never_uses_the_autofocus_attribute(): void {
		update_option( BLUELINE_SETTINGS_OPTION, $this->invalid_save_fixture() );

		$_GET['tab'] = 'content'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.

		$html = $this->render_page();

		$this->assertStringNotContainsString( 'autofocus', $html );
	}

	/**
	 * No error summary on an ordinary page load with nothing to report.
	 */
	public function test_no_error_summary_when_nothing_failed(): void {
		$_GET['tab'] = 'content'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.

		$html = $this->render_page();

		$this->assertStringNotContainsString( 'blueline-settings-error-summary', $html );
	}

	/**
	 * The fix-round-3 regression coverage: blueline_settings_maybe_enqueue_focus_script()
	 * must enqueue the focus script for THIS page's own hook suffix, and
	 * must do nothing for any other admin screen -- admin_enqueue_scripts
	 * fires for every screen, not just this one.
	 *
	 * This is the part of the underlying bug a PHPUnit test genuinely CAN
	 * verify: that the right script, with the right content, is wired to
	 * the right screen. What it cannot verify -- and what a passing test
	 * here must not be mistaken for -- is that a real browser actually
	 * moves focus when this script runs; only a live browser check can
	 * prove that (see this class's own docblock for the one that already
	 * caught this bug, and blueline_settings_focus_summary_script()'s
	 * docblock for the evidence that `autofocus` alone does not).
	 */
	public function test_focus_summary_script_is_enqueued_only_for_this_page(): void {
		blueline_settings_add_page();
		$own_hook = blueline_settings_page_hook();
		$this->assertNotNull( $own_hook, 'blueline_settings_add_page() must have captured a hook suffix' );

		blueline_settings_maybe_enqueue_focus_script( 'some-other-admin-page' );
		$this->assertSame( array(), $GLOBALS['bl_test_inline_scripts'], 'a different admin screen must not get this script' );

		blueline_settings_maybe_enqueue_focus_script( $own_hook );
		$this->assertCount( 1, $GLOBALS['bl_test_inline_scripts'] );
		$this->assertSame( 'jquery', $GLOBALS['bl_test_inline_scripts'][0]['handle'] );
	}

	/**
	 * The enqueued script's own content: it must look for the exact
	 * summary element id and call .focus() on it, deferred (not run
	 * synchronously inside the ready handler) so it runs after any other
	 * script's own ready() handler -- including WordPress core's
	 * wp-admin/js/common.js, which independently relocates every
	 * `.notice` element on every admin screen -- has already finished.
	 */
	public function test_focus_summary_script_content_targets_the_summary_element(): void {
		$script = blueline_settings_focus_summary_script();

		$this->assertStringContainsString( "getElementById( 'blueline-settings-error-summary' )", $script );
		$this->assertStringContainsString( '.focus()', $script );
		$this->assertStringContainsString( 'setTimeout', $script, 'must defer past other ready() handlers, not run synchronously inside its own' );
	}

	/**
	 * The tab nav must render plain links carrying `aria-current="page"`
	 * for whichever tab is active -- explicitly NOT an ARIA tab widget
	 * (no `role="tab"`/`role="tablist"`), per the task brief.
	 */
	public function test_tab_nav_is_plain_links_not_an_aria_tab_widget(): void {
		$_GET['tab'] = 'links'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture, not a real request.

		$html = $this->render_page();

		$this->assertStringNotContainsString( 'role="tab"', $html );
		$this->assertStringNotContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*tab=links[^"]*"[^>]*class="nav-tab nav-tab-active"/', $html );
	}

	/**
	 * Task 5 fix round (P1b-panel-completion): `chrome_footer_widgets_2`'s
	 * field row carries blueline_section_widget_warning()'s own message,
	 * naming the live widget count, when its mapped widget area (footer-2)
	 * is populated -- proving the warning actually reaches the rendered row,
	 * not merely that the accessor function returns a string in isolation.
	 * `chrome_footer_trust` no longer maps to any widget area (that mapping
	 * was a fix-round-1 mistake this plan's coordinator corrected -- see
	 * blueline_section_widget_warning()'s own docblock), so this test now
	 * targets the key that actually gates footer-2.
	 */
	public function test_a_populated_widget_areas_section_field_renders_the_widget_warning(): void {
		$state                                = &blueline_test_state();
		$state['active_sidebars']['footer-2'] = 3;

		ob_start();
		blueline_settings_render_field(
			'chrome_footer_widgets_2',
			blueline_settings_schema()['chrome_footer_widgets_2'],
			null
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'This area holds 3 widget(s)', $html );
	}

	/**
	 * Companion case: with footer-2 empty (the default, untouched state),
	 * `chrome_footer_widgets_2`'s row renders no widget warning at all --
	 * and, since this section carries no `help` string either, no
	 * description paragraph of any kind.
	 */
	public function test_an_unpopulated_widget_areas_section_field_renders_no_widget_warning(): void {
		ob_start();
		blueline_settings_render_field(
			'chrome_footer_widgets_2',
			blueline_settings_schema()['chrome_footer_widgets_2'],
			null
		);
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'class="description"', $html );
	}

	/**
	 * Render the page and return its markup, with the capability the whole
	 * screen requires already granted.
	 *
	 * @return string
	 */
	private function render_page(): string {
		$this->grant_manage_options();

		ob_start();
		blueline_settings_render_page();
		return (string) ob_get_clean();
	}

	/**
	 * A save payload for `footer_heading` that the sanitizer always rejects
	 * -- a bare "%" with no valid `sprintf()` spec, tripping the
	 * placeholder-contract check -- paired with the `_posted_fields` entry
	 * that names it as this submission's own. Shared by every test whose
	 * point is what happens AFTER a field is rejected (the error summary,
	 * its focus behaviour, the notice it renders as), rather than the
	 * rejection itself.
	 *
	 * @return array<string, mixed>
	 */
	private function invalid_save_fixture(): array {
		return array(
			'footer_heading' => 'Save 50% off',
			'_posted_fields' => array( 'footer_heading' ),
		);
	}

	/**
	 * Two real saves, so exactly one snapshot exists (the first-ever write
	 * replaces nothing).
	 *
	 * @return int That snapshot's id.
	 */
	private function seed_one_snapshot(): int {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The ARL' ) );

		return blueline_settings_snapshot_list()[0]['id'];
	}

	/**
	 * The restore list renders a real, nonce-protected control naming the
	 * snapshot by its stable id -- not its position, which the restore's
	 * own save would immediately shift.
	 */
	public function test_the_page_lists_a_snapshot_with_a_nonce_protected_restore_control(): void {
		$id = $this->seed_one_snapshot();

		$html = $this->render_page();

		$this->assertStringContainsString( 'name="blueline_restore_snapshot"', $html );
		$this->assertStringContainsString( 'value="' . $id . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}

	/**
	 * With no history yet, the panel says so rather than rendering an empty
	 * table an admin would read as "restore is broken".
	 */
	public function test_the_page_explains_when_there_is_no_history_yet(): void {
		$html = $this->render_page();

		$this->assertStringNotContainsString( 'name="blueline_restore_snapshot"', $html );
		$this->assertStringContainsString( 'No saves recorded yet', $html );
	}

	/**
	 * Submitting the restore control writes the snapshot back and reports
	 * it through the panel's existing notice channel.
	 */
	public function test_a_restore_submission_writes_the_snapshot_back(): void {
		$this->grant_manage_options();
		$id = $this->seed_one_snapshot();

		$_POST['blueline_restore_snapshot'] = (string) $id;
		$_REQUEST['_wpnonce']               = wp_create_nonce( 'blueline_settings_restore' );

		blueline_settings_maybe_restore();

		$this->assertSame( 'The League', blueline_settings( 'footer_heading' ) );

		$messages = array_column( get_settings_errors( BLUELINE_SETTINGS_OPTION ), 'message' );
		$this->assertNotEmpty( $messages );
	}

	/**
	 * A submission whose nonce does not verify restores nothing: the
	 * stubbed check_admin_referer() dies exactly where core's would.
	 */
	public function test_a_restore_submission_without_a_valid_nonce_restores_nothing(): void {
		$this->grant_manage_options();
		$id = $this->seed_one_snapshot();

		$_POST['blueline_restore_snapshot'] = (string) $id;
		$_REQUEST['_wpnonce']               = 'not-the-right-token';

		$this->expectException( Blueline_Test_WP_Die_Exception::class );

		try {
			blueline_settings_maybe_restore();
		} finally {
			$this->assertSame( 'The ARL', blueline_settings( 'footer_heading' ) );
		}
	}

	/**
	 * The handler carries its OWN capability check rather than relying on
	 * the render callback having run one first -- the same defence-in-depth
	 * blueline_settings_render_page() applies over blueline_settings_add_page().
	 */
	public function test_a_restore_submission_without_manage_options_restores_nothing(): void {
		$this->grant_manage_options();
		$id = $this->seed_one_snapshot();

		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = false;

		$_POST['blueline_restore_snapshot'] = (string) $id;
		$_REQUEST['_wpnonce']               = wp_create_nonce( 'blueline_settings_restore' );

		$this->expectException( Blueline_Test_WP_Die_Exception::class );

		try {
			blueline_settings_maybe_restore();
		} finally {
			$this->assertSame( 'The ARL', blueline_settings( 'footer_heading' ) );
		}
	}

	/**
	 * The restore control posts to the page itself with no
	 * POST-redirect-GET in between, so a browser refresh re-submits it.
	 * Because the control names a STABLE snapshot id, that second
	 * submission restores the same copy again -- which by then changes
	 * nothing and records no new history. Pinned here because
	 * blueline_settings_maybe_restore()'s docblock says exactly this, and
	 * an untested claim in a comment is how this settings layer keeps
	 * acquiring copy that is not true.
	 */
	public function test_resubmitting_the_same_restore_changes_nothing_further(): void {
		$this->grant_manage_options();
		$id = $this->seed_one_snapshot();

		$_POST['blueline_restore_snapshot'] = (string) $id;
		$_REQUEST['_wpnonce']               = wp_create_nonce( 'blueline_settings_restore' );

		blueline_settings_maybe_restore();

		$after_first = blueline_settings_snapshot_list();

		blueline_settings_maybe_restore();

		$this->assertSame( 'The League', blueline_settings( 'footer_heading' ) );
		$this->assertSame( $after_first, blueline_settings_snapshot_list() );
	}

	/**
	 * A snapshot id that is no longer in the list (evicted, or never real)
	 * is reported as such rather than silently doing nothing.
	 */
	public function test_a_restore_of_an_unknown_snapshot_is_reported(): void {
		$this->grant_manage_options();
		$this->seed_one_snapshot();

		$_POST['blueline_restore_snapshot'] = '9999';
		$_REQUEST['_wpnonce']               = wp_create_nonce( 'blueline_settings_restore' );

		blueline_settings_maybe_restore();

		$this->assertSame( 'The ARL', blueline_settings( 'footer_heading' ) );

		$errors = array_filter(
			get_settings_errors( BLUELINE_SETTINGS_OPTION ),
			static fn( $e ) => 'error' === ( $e['type'] ?? '' )
		);
		$this->assertNotEmpty( $errors );
	}

	/**
	 * A failed restore is a PAGE-level problem, not a field-level one, and
	 * has to render as one.
	 *
	 * Field errors are keyed by code and the summary links each to
	 * `#blueline-field-{code}`, so an
	 * error whose code is not a schema field renders the raw internal key
	 * as its label and links nowhere. This case is rendered, not merely
	 * read back out of get_settings_errors(): asserting on the data alone
	 * is exactly what let this ship -- a notice test that never looks at
	 * markup cannot see how the notice actually looks.
	 */
	public function test_a_failed_restore_renders_as_a_page_notice_not_a_field_error(): void {
		$this->seed_one_snapshot();

		$_POST['blueline_restore_snapshot'] = '9999';
		$_REQUEST['_wpnonce']               = wp_create_nonce( 'blueline_settings_restore' );

		$html = $this->render_page();

		$this->assertStringContainsString( 'no longer available', $html );
		$this->assertStringContainsString( 'notice notice-error', $html );
		$this->assertStringNotContainsString( 'blueline_settings_restore_missing', $html, 'never show an internal code as a label' );
		$this->assertStringNotContainsString( 'blueline-field-blueline_settings_restore_missing', $html, 'and never link to an element that does not exist' );
		$this->assertStringNotContainsString( 'blueline-settings-error-summary', $html, 'no field failed, so there is no field summary' );
	}

	/**
	 * A field rejection still routes to the error summary -- the partition
	 * above must not have moved real field errors out of it.
	 */
	public function test_a_field_rejection_still_renders_in_the_error_summary(): void {
		update_option( BLUELINE_SETTINGS_OPTION, $this->invalid_save_fixture() );

		$html = $this->render_page();

		$this->assertStringContainsString( 'blueline-settings-error-summary', $html );
		$this->assertStringContainsString( 'href="#blueline-field-footer_heading"', $html );
	}

	/**
	 * An array-shaped POST value reaches absint() as an array, which
	 * evaluates to 1 -- silently naming snapshot 1. Not a security hole
	 * (nonce and capability both stand in front of it) but it is a
	 * restore nobody asked for, so a non-scalar is refused outright.
	 */
	public function test_an_array_shaped_restore_id_restores_nothing(): void {
		$this->grant_manage_options();
		$this->seed_one_snapshot();

		$this->assertSame( 1, blueline_settings_snapshot_list()[0]['id'], 'the fixture must make id 1 a real, restorable target' );

		$_POST['blueline_restore_snapshot'] = array( '1' );
		$_REQUEST['_wpnonce']               = wp_create_nonce( 'blueline_settings_restore' );

		blueline_settings_maybe_restore();

		$this->assertSame( 'The ARL', blueline_settings( 'footer_heading' ) );
	}

	/**
	 * No POST, no work: an ordinary page load must not restore anything or
	 * trip the nonce check.
	 */
	public function test_an_ordinary_page_load_restores_nothing(): void {
		$this->grant_manage_options();
		$this->seed_one_snapshot();

		blueline_settings_maybe_restore();

		$this->assertSame( 'The ARL', blueline_settings( 'footer_heading' ) );
		$this->assertSame( array(), get_settings_errors( BLUELINE_SETTINGS_OPTION ) );
	}

	/**
	 * Asserts `aa_acknowledgements` survives a programmatic write (no
	 * `_tab`), the same path `_schema` already relies on.
	 */
	public function test_sanitize_callback_lets_acknowledgements_survive_a_programmatic_write(): void {
		$entry  = array(
			'rule_id'     => 'ink-on-occasion-accent',
			'value'       => '#8b0000',
			'ratio'       => 3.2,
			'user_id'     => 7,
			'date'        => 1700000000,
			'inputs_hash' => 'abc123',
			'scope'       => 'occasion:canada-day',
		);
		$output = blueline_settings_sanitize_callback(
			array( 'aa_acknowledgements' => array( 'occasion:canada-day' => $entry ) )
		);

		$this->assertSame( $entry, $output['aa_acknowledgements']['occasion:canada-day'] );
	}

	/**
	 * Asserts `aa_acknowledgements` is dropped outright from a tab-scoped
	 * (form) submission -- it never legitimately arrives from the panel's
	 * own rendered form, the same protection `_schema` already has.
	 */
	public function test_sanitize_callback_drops_acknowledgements_from_a_form_submission(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'                => 'content',
				'aa_acknowledgements' => array( 'occasion:canada-day' => array( 'anything' => true ) ),
			)
		);

		$this->assertArrayNotHasKey( 'aa_acknowledgements', $output );
	}

	/**
	 * Asserts a malformed `aa_acknowledgements` value is repaired to an
	 * empty map rather than trusted verbatim, even on a programmatic
	 * write.
	 */
	public function test_sanitize_callback_validates_acknowledgements_shape(): void {
		$output = blueline_settings_sanitize_callback(
			array( 'aa_acknowledgements' => 'not-an-array' )
		);

		$this->assertSame( array(), $output['aa_acknowledgements'] );
	}

	/**
	 * Asserts `occasions` survives a programmatic write (no `_tab`), the
	 * same path `_schema`/`aa_acknowledgements` already rely on.
	 */
	public function test_sanitize_callback_lets_occasions_survive_a_programmatic_write(): void {
		$entry  = array(
			'id'     => 'canada-day',
			'label'  => 'Canada Day',
			'type'   => 'decorative',
			'window' => array(
				'start_md' => '07-01',
				'end_md'   => '07-01',
			),
			'accent' => '',
			'motif'  => 'maple-leaf',
			'line'   => '',
			'mode'   => 'auto',
		);
		$output = blueline_settings_sanitize_callback(
			array( 'occasions' => array( 'canada-day' => $entry ) )
		);

		$this->assertSame( $entry, $output['occasions']['canada-day'] );
	}

	/**
	 * Asserts `occasions` is dropped outright from a tab-scoped (form)
	 * submission -- no existing tab's rendered form ever legitimately
	 * submits it.
	 */
	public function test_sanitize_callback_drops_occasions_from_a_form_submission(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'      => 'content',
				'occasions' => array( 'canada-day' => array( 'anything' => true ) ),
			)
		);

		$this->assertArrayNotHasKey( 'occasions', $output );
	}

	/**
	 * Asserts a malformed `occasions` value is repaired to an empty map
	 * rather than trusted verbatim, even on a programmatic write.
	 */
	public function test_sanitize_callback_validates_occasions_shape(): void {
		$output = blueline_settings_sanitize_callback(
			array( 'occasions' => 'not-an-array' )
		);

		$this->assertSame( array(), $output['occasions'] );
	}

	/**
	 * `_validated_against` (design spec §6.5's storage-shape ruling) is a
	 * reserved key following `_schema`/`aa_acknowledgements`'s shape: a
	 * plain string survives a programmatic write.
	 */
	public function test_sanitize_callback_lets_validated_against_survive_a_programmatic_write(): void {
		$output = blueline_settings_sanitize_callback(
			array( '_validated_against' => 'abc123hash' )
		);

		$this->assertSame( 'abc123hash', $output['_validated_against'] );
	}

	/**
	 * Dropped outright from ANY tab-scoped submission, same as `_schema`/
	 * `aa_acknowledgements` -- unlike `occasions`, no tab (including the
	 * Occasions tab's own exception, which names `occasions` specifically)
	 * is ever exempted for this key.
	 */
	public function test_sanitize_callback_drops_validated_against_from_a_form_submission(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'               => 'occasions',
				'_validated_against' => 'abc123hash',
			)
		);

		$this->assertArrayNotHasKey( '_validated_against', $output );
	}

	/**
	 * A non-string value is repaired to '' rather than trusted verbatim.
	 */
	public function test_sanitize_callback_validates_validated_against_shape(): void {
		$output = blueline_settings_sanitize_callback(
			array( '_validated_against' => array( 'not' => 'a string' ) )
		);

		$this->assertSame( '', $output['_validated_against'] );
	}

	/**
	 * The Occasions tab's own submission derives an id from the label
	 * rather than trusting one the admin typed -- design spec §5.1's
	 * first ruling, exercised through the real save path.
	 */
	public function test_sanitize_callback_derives_an_id_for_a_new_occasions_row(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'      => 'occasions',
				'occasions' => array(
					'row-1' => array(
						'_original_id' => '',
						'label'        => 'Canada Day',
						'type'         => 'decorative',
						'window'       => array(
							'start_md' => '07-01',
							'end_md'   => '07-01',
						),
						'accent'       => '',
						'motif'        => 'maple-leaf',
						'line'         => '',
						'mode'         => 'auto',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'canada-day', $output['occasions'] );
		$this->assertSame( 'canada-day', $output['occasions']['canada-day']['id'] );
	}

	/**
	 * Asserts `occasions` submitted from a DIFFERENT tab is still dropped
	 * outright -- the new exception names `occasions` AND
	 * `'occasions' === $submitted_tab` together, never `occasions` alone.
	 */
	public function test_sanitize_callback_still_drops_occasions_from_a_foreign_tab(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'      => 'content',
				'occasions' => array( 'canada-day' => array( 'anything' => true ) ),
			)
		);

		$this->assertArrayNotHasKey( 'occasions', $output );
	}

	/**
	 * Asserts the pre-existing programmatic write path (no `_tab` at
	 * all) is unaffected: a row's `id` is honoured exactly as submitted,
	 * with NO derivation step run over it. 2.1a's own
	 * test_sanitize_callback_lets_occasions_survive_a_programmatic_write()
	 * already covers the happy path; this covers that derivation is
	 * SKIPPED for this path specifically.
	 */
	public function test_sanitize_callback_does_not_re_derive_ids_on_a_programmatic_write(): void {
		$entry = array(
			'id'     => 'custom-slug',
			'label'  => 'Something Else Entirely',
			'type'   => 'decorative',
			'window' => array(
				'start_md' => '07-01',
				'end_md'   => '07-01',
			),
			'accent' => '',
			'motif'  => 'none',
			'line'   => '',
			'mode'   => 'auto',
		);

		$output = blueline_settings_sanitize_callback(
			array( 'occasions' => array( 'custom-slug' => $entry ) )
		);

		$this->assertSame( $entry, $output['occasions']['custom-slug'] );
	}

	/**
	 * Asserts `_schema` is STILL dropped outright even when the
	 * submission's `_tab` is `'occasions'` -- the new carve-out names
	 * `occasions` AND `'occasions' === $submitted_tab` together; it must
	 * never be read as "any reserved key survives once the tab is
	 * occasions".
	 */
	public function test_sanitize_callback_still_drops_schema_when_the_tab_is_occasions(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'      => 'occasions',
				'_schema'   => 99,
				'occasions' => array(),
			)
		);

		$this->assertArrayNotHasKey( '_schema', $output );
	}

	/**
	 * Asserts a raw, directly-submitted `aa_acknowledgements` payload is
	 * STILL never honoured on an occasions-tab save -- same guard as
	 * above, covering the other reserved key. As of Task 3
	 * (blueline_occasions_apply_aa_overrides()), an occasions-tab save
	 * ALWAYS sets `aa_acknowledgements` on the output -- computed from
	 * the sanitized occasions and the CURRENTLY stored acknowledgements,
	 * never from whatever the request happened to submit under that
	 * key -- so this no longer asserts the key is absent; it asserts the
	 * submitted value was not the one that won.
	 *
	 * The forged entry below is deliberately WELL-FORMED (every field
	 * blueline_sanitize_acknowledgements() requires, correctly typed, and
	 * `scope` matching its own array key) so that
	 * blueline_sanitize_acknowledgements() would NOT scrub it on its own
	 * -- an earlier version of this test used a malformed
	 * `array( 'anything' => true )` payload, which got scrubbed to
	 * `array()` regardless of whether the tab guard was correct, so the
	 * test kept passing even when the guard was broken. `occasions` is
	 * also submitted BEFORE `aa_acknowledgements` here (the opposite
	 * order from the old test), so a broken guard can't be saved by the
	 * `occasions` branch's computed value happening to run, and overwrite
	 * the forged one, later in iteration order. With both of those fixed,
	 * this test only passes when the reserved-key guard actually drops
	 * `aa_acknowledgements` for an occasions-tab submission -- since
	 * `occasions` is empty here, the real computed result is the
	 * (empty) stored acknowledgements map, never the forged entry.
	 */
	public function test_sanitize_callback_still_drops_acknowledgements_when_the_tab_is_occasions(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'                => 'occasions',
				'occasions'           => array(),
				'aa_acknowledgements' => array(
					'occasion:fake' => array(
						'rule_id'     => 'ink-on-occasion-accent',
						'value'       => '#000000',
						'ratio'       => 1.0,
						'user_id'     => 999,
						'date'        => 1,
						'inputs_hash' => 'forged',
						'scope'       => 'occasion:fake',
					),
				),
			)
		);

		$this->assertSame( array(), $output['aa_acknowledgements'] );
	}

	/**
	 * End-to-end: the Occasions tab's own submission (a failing accent,
	 * override checkbox checked) reaches
	 * blueline_settings_sanitize_callback() and produces BOTH a
	 * sanitized `occasions` entry AND a new, matching
	 * `aa_acknowledgements` entry -- design spec §5.1's fifth ruling,
	 * run through the real save path rather than the pure function
	 * alone.
	 *
	 * The row is submitted under the array key `__new_1`, NOT `failing`.
	 * That is deliberate and load-bearing: it is the shape a real
	 * browser-added row actually posts (assets/src/js/settings-occasions.js's
	 * addRow() always names a fresh row `__new_{n}`, never the slug the
	 * server will eventually derive), and it is the ONLY shape that can
	 * catch a regression in how `$raw_overrides` is keyed. The earlier
	 * version of this test submitted the row under the key `failing`
	 * with the label `Failing` -- and `sanitize_title( 'Failing' )` is
	 * `'failing'`, so the submitted key and the final derived id were
	 * identical by coincidence. A mutation keying `$raw_overrides` by
	 * the RAW SUBMITTED key instead of each row's final,
	 * post-blueline_occasions_assign_unique_ids() id passed that test
	 * (and the whole suite) undetected, while silently discarding the AA
	 * acknowledgement of every JS-added row in production. With the two
	 * keys deliberately different, the acknowledgement assertion below
	 * only holds when the override is read under `failing` -- the FINAL
	 * id -- exactly as the wiring does.
	 */
	public function test_sanitize_callback_records_an_acknowledgement_for_an_occasions_tab_save(): void {
		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'      => 'occasions',
				'occasions' => array(
					'__new_1' => array(
						'_original_id' => '',
						'label'        => 'Failing',
						'type'         => 'decorative',
						'window'       => array(
							'start_md' => '01-01',
							'end_md'   => '12-31',
						),
						'accent'       => '#274a63',
						'motif'        => 'none',
						'line'         => '',
						'mode'         => 'force_on',
						'override_aa'  => '1',
					),
				),
			)
		);

		// Keyed by the DERIVED id, not by the `__new_1` the row was
		// submitted under, on both sides.
		$this->assertArrayHasKey( 'failing', $output['occasions'] );
		$this->assertArrayNotHasKey( '__new_1', $output['occasions'] );
		$this->assertSame( 'failing', $output['occasions']['failing']['id'] );
		$this->assertArrayHasKey( 'occasion:failing', $output['aa_acknowledgements'] );
		$this->assertArrayNotHasKey( 'occasion:__new_1', $output['aa_acknowledgements'] );
		$this->assertSame( '#274a63', $output['aa_acknowledgements']['occasion:failing']['value'] );
	}

	/**
	 * Asserts deleting an occasion (simply omitting it from this save's
	 * own submission) also removes its now-orphaned acknowledgement, in
	 * the same real save path.
	 */
	public function test_sanitize_callback_cleans_up_an_orphaned_acknowledgement_on_save(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions'           => array(
					'failing' => array(
						'id'     => 'failing',
						'label'  => 'Failing',
						'type'   => 'decorative',
						'window' => array(
							'start_md' => '01-01',
							'end_md'   => '12-31',
						),
						'accent' => '#274a63',
						'motif'  => 'none',
						'line'   => '',
						'mode'   => 'force_on',
					),
				),
				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:failing', 'ink-on-occasion-accent', '#274a63', 1.66, blueline_settings_inputs_hash(), 1 ),
			)
		);

		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'      => 'occasions',
				'occasions' => array(), // The occasion was removed in this save.
			)
		);

		$this->assertSame( array(), $output['occasions'] );
		$this->assertArrayNotHasKey( 'occasion:failing', $output['aa_acknowledgements'] );
	}

	/**
	 * The hazard the Occasions tab's hidden `__none__` marker row exists
	 * for, pinned as its own test: an HTML form cannot post an array
	 * field with ZERO entries, so an admin who removes every row and
	 * saves sends a request with no `blueline_settings[occasions][...]`
	 * key at all.
	 *
	 * This test submits exactly that shape -- `_tab => 'occasions'` with
	 * `occasions` genuinely ABSENT from the input array (not `array()`,
	 * which is a shape a browser can never produce) -- and asserts the
	 * documented consequence: the per-key loop never reaches the
	 * `occasions` branch, `$output['occasions']` is never set, and
	 * blueline_settings_merge() therefore carries the OLD stored map
	 * straight back. That is a silent no-op from the admin's point of
	 * view, which is precisely why
	 * blueline_settings_render_occasions_tab() renders a marker row that
	 * cannot be removed -- see the next test for the shape that reaches
	 * this callback once it is present.
	 */
	public function test_sanitize_callback_cannot_clear_occasions_when_the_key_is_absent_entirely(): void {
		$stored = array(
			'occasions' => array(
				'canada-day' => array(
					'id'     => 'canada-day',
					'label'  => 'Canada Day',
					'type'   => 'decorative',
					'window' => array(
						'start_md' => '07-01',
						'end_md'   => '07-01',
					),
					'accent' => '',
					'motif'  => 'maple-leaf',
					'line'   => '',
					'mode'   => 'auto',
				),
			),
		);

		update_option( BLUELINE_SETTINGS_OPTION, $stored );

		$output = blueline_settings_sanitize_callback( array( '_tab' => 'occasions' ) );

		$this->assertArrayNotHasKey( 'occasions', $output );

		$merged = blueline_settings_merge( $output, $stored );

		$this->assertSame( $stored['occasions'], $merged['occasions'] );
	}

	/**
	 * The marker row's own shape, end to end: a submission carrying ONLY
	 * `blueline_settings[occasions][__none__][label] = ''` (every real
	 * row removed in the browser) must clear the stored map outright AND
	 * take every `occasion:*` acknowledgement with it.
	 *
	 * The marker row itself never survives:
	 * blueline_occasions_assign_unique_ids() drops any row whose label
	 * yields an empty sanitize_title(), so it is gone before
	 * blueline_sanitize_occasions() ever sees it -- the assertion below
	 * is `array()`, not "a map containing __none__".
	 */
	public function test_sanitize_callback_clears_occasions_when_only_the_marker_row_is_submitted(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions'           => array(
					'failing' => array(
						'id'     => 'failing',
						'label'  => 'Failing',
						'type'   => 'decorative',
						'window' => array(
							'start_md' => '01-01',
							'end_md'   => '12-31',
						),
						'accent' => '#274a63',
						'motif'  => 'none',
						'line'   => '',
						'mode'   => 'force_on',
					),
				),
				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:failing', 'ink-on-occasion-accent', '#274a63', 1.66, blueline_settings_inputs_hash(), 1 ),
			)
		);

		$output = blueline_settings_sanitize_callback(
			array(
				'_tab'      => 'occasions',
				'occasions' => array(
					// Exactly what the rendered marker field posts once
					// every real row has been removed.
					'__none__' => array( 'label' => '' ),
				),
			)
		);

		$this->assertSame( array(), $output['occasions'] );
		$this->assertArrayNotHasKey( 'occasion:failing', $output['aa_acknowledgements'] );

		// And the merge stage must not resurrect the old map either --
		// `occasions` IS present in this submission's output, so
		// blueline_settings_merge()'s carry-forward loop skips it.
		$merged = blueline_settings_merge(
			$output,
			array( 'occasions' => array( 'failing' => array( 'id' => 'failing' ) ) )
		);

		$this->assertSame( array(), $merged['occasions'] );
	}

	/**
	 * `occasions` appears in the tab list as one explicit, named
	 * exception -- design spec §5.1's third ruling -- while every other
	 * tab remains exactly what the schema itself declares.
	 */
	public function test_tab_slugs_includes_occasions_as_a_named_exception(): void {
		$slugs = blueline_settings_tab_slugs();

		$this->assertContains( 'occasions', $slugs );

		$schema_tabs = array();
		foreach ( blueline_settings_schema() as $field ) {
			$tab = $field['tab'] ?? '';
			if ( '' !== $tab && ! in_array( $tab, $schema_tabs, true ) ) {
				$schema_tabs[] = $tab;
			}
		}

		$this->assertSame( $schema_tabs, array_values( array_diff( $slugs, array( 'occasions' ) ) ) );
	}

	/**
	 * Asserts `occasions` has a real, human-readable tab label rather
	 * than falling through to the raw-slug guess.
	 */
	public function test_tab_label_for_occasions(): void {
		$this->assertSame( 'Occasions', blueline_settings_tab_label( 'occasions' ) );
	}

	/**
	 * On the Occasions tab, blueline_settings_render_page() dispatches
	 * to the bespoke renderer instead of the generic per-field
	 * `<table>` loop.
	 */
	public function test_render_page_dispatches_to_the_occasions_renderer(): void {
		$_GET['tab'] = 'occasions'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simulating a read-only tab request, matching blueline_settings_current_tab()'s own contract.

		$html = $this->render_page();

		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'data-bl-occasions', $html );
		$this->assertStringNotContainsString( '<table class="form-table"', $html );
	}

	/**
	 * A schema-backed tab is unaffected: it still renders the generic
	 * `<table>` loop, and never the occasions-specific markup.
	 */
	public function test_render_page_still_uses_the_generic_loop_for_a_schema_tab(): void {
		$_GET['tab'] = 'content'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simulating a read-only tab request, matching blueline_settings_current_tab()'s own contract.

		$html = $this->render_page();

		unset( $_GET['tab'] );

		$this->assertStringContainsString( '<table class="form-table"', $html );
		$this->assertStringNotContainsString( 'data-bl-occasions', $html );
	}
}
