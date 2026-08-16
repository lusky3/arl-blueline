<?php
/**
 * Covers a Critical correctness bug found by live testing on staging, not by
 * any unit test: `wp blueline settings import` (inc/cli/settings-command.php)
 * -- and any other script calling update_option( BLUELINE_SETTINGS_OPTION, ... )
 * directly -- could not durably set any of the twelve section toggles (or any
 * other boolean field) to `false`. A config exported with sections disabled
 * re-imported with them all enabled, reporting success.
 *
 * ## The actual mechanism, and why the literal staging repro didn't survive
 * as a unit test unchanged
 *
 * inc/settings/store.php's blueline_settings_merge() (the pre_update_option_
 * cross-tab merge) already refuses to let its own carry-forward loop
 * overwrite a key the submission actually POSTS: `array_key_exists( $key,
 * $new_value )` short-circuits before `_posted_fields` is ever consulted, so
 * a field explicitly present in the write -- `array( 'chrome_sponsors' =>
 * false )`, with no other bookkeeping at all -- was already safe under the
 * code this task started from. That specific literal shape from the staging
 * report could not be reproduced as a failing test against this exact
 * codebase; test_programmatic_write_can_set_a_section_toggle_to_false()
 * below pins it as a passing regression guard rather than a red-then-green
 * fix, and says so in its own docblock.
 *
 * The REAL, reproducible gap was one call-shape away: a caller that mimics
 * the control panel's own "unchecked checkbox" idiom -- name the field in
 * `_posted_fields`, omit its value, to mean "clear it" -- WITHOUT also
 * declaring `_tab`, because a programmatic caller has no tab to declare.
 * Before this task's fix, blueline_settings_merge() honoured `_posted_fields`
 * unconditionally, with no notion of `_tab` at all, so this call:
 *
 *   update_option( BLUELINE_SETTINGS_OPTION, array(
 *       '_posted_fields' => array( 'chrome_sponsors' ),
 *   ) );
 *
 * deleted `chrome_sponsors` outright (not merely left it untouched) --
 * indistinguishable, once read back through blueline_settings()'s own
 * default-fallback, from "the toggle silently returned to its default (which
 * happens to be `true`)". That is the observable, staging-shaped symptom
 * this file's tests are built to prove is gone:
 * test_merge_ignores_posted_fields_ownership_without_a_tab() is the one that
 * actually fails before the fix and passes after it.
 *
 * ## Why testing blueline_settings_merge() directly, not only through
 * update_option()
 *
 * inc/settings/page.php's blueline_settings_sanitize_callback() (the
 * sanitize_option_ pass that runs immediately before this merge on every
 * real write) ALSO filters `_posted_fields` to entries whose own schema
 * `tab` matches the submission's `_tab` -- and no real schema field's `tab`
 * is ever the empty string, so that filtering alone already reduces
 * `_posted_fields` to an empty array for any field a real programmatic
 * write through the full pipeline could plausibly name. That is real
 * protection, but it is a DIFFERENT file's protection: blueline_settings_merge()
 * is registered independently, at file scope, on the same hook a future
 * write path (or, as this task's own brief points out, a test) could reach
 * without ever going through page.php's callback first. This file therefore
 * exercises blueline_settings_merge() both directly (proving the function
 * itself, not merely its upstream caller, now enforces the `_tab` boundary)
 * and through the full update_option() pipeline (proving the two files
 * still cooperate correctly end to end).
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/commerce.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/page.php';

/**
 * Covers blueline_settings_merge()'s `_tab`-gated `_posted_fields` contract:
 * a tab-scoped submission (one that names `_tab`) keeps today's delete-vs-
 * carry-forward behaviour exactly; a programmatic submission (no `_tab`) is
 * authoritative for whatever it names a real value for, and `_posted_fields`
 * carries no ownership at all without `_tab` to legitimise it.
 */
final class SettingsMergeProgrammaticWriteTest extends TestCase {

	/**
	 * Reset every in-memory store before each test -- this file exercises
	 * update_option()/get_option() directly in some tests, and
	 * blueline_settings_migrate() (hooked on `init`, itself registered by
	 * inc/settings/store.php at file scope) is not invoked here, but the
	 * shared option/hook stores must still start clean.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Requirement 2 (literal wording): a programmatic write can set a
	 * section toggle to `false`. This passes both before and after this
	 * task's fix -- blueline_settings_merge()'s carry-forward loop never
	 * touches a key the submission actually posts, `_tab`/`_posted_fields`
	 * notwithstanding -- so it is a regression guard for a real staging
	 * complaint, not a red-then-green pin. See this file's own docblock for
	 * why the ACTUAL red-then-green test is
	 * test_merge_ignores_posted_fields_ownership_without_a_tab() below.
	 */
	public function test_programmatic_write_can_set_a_section_toggle_to_false(): void {
		$stored                    = blueline_settings_defaults();
		$stored['chrome_sponsors'] = true;
		$stored['_schema']         = BLUELINE_SETTINGS_SCHEMA_VERSION;
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = $stored;

		// A programmatic write: the full settings array (as an export/import
		// round trip, or a script reading blueline_settings() and writing it
		// back, would produce), with one toggle explicitly flipped to false.
		// No `_tab`, no `_posted_fields` -- this caller has no tab.
		$settings                    = blueline_settings();
		$settings['chrome_sponsors'] = false;
		update_option( BLUELINE_SETTINGS_OPTION, $settings );

		$this->assertFalse(
			get_option( BLUELINE_SETTINGS_OPTION )['chrome_sponsors'],
			'a programmatic write naming chrome_sponsors => false must actually store false'
		);
	}

	/**
	 * The red-then-green test: a programmatic write (no `_tab`) naming a
	 * field in `_posted_fields` without posting its value must NOT delete
	 * that field. Before this task's fix, blueline_settings_merge() treated
	 * `_posted_fields` as sufficient ownership on its own, with no notion of
	 * `_tab` at all -- so this exact call deleted `chrome_sponsors` outright,
	 * which (read back through blueline_settings()'s default-fallback) is
	 * indistinguishable from "silently reverted to its default value". Only
	 * a TAB-SCOPED submission (see test_tab_scoped_submission_still_deletes_a_named_absent_field_and_carries_forward_an_untouched_one()
	 * below) may use `_posted_fields` this way.
	 *
	 * Calls blueline_settings_merge() directly rather than through
	 * update_option(): inc/settings/page.php's own tab-match filtering
	 * already happens to reduce `_posted_fields` to empty for any
	 * programmatic write reaching it, which would mask this exact bug at
	 * the full-pipeline level -- see this file's own docblock. Testing the
	 * merge function directly proves blueline_settings_merge() enforces
	 * this boundary itself, not merely benefits from page.php's cooperation.
	 */
	public function test_merge_ignores_posted_fields_ownership_without_a_tab(): void {
		$old = array(
			'chrome_sponsors' => true,
			'contact_email'   => 'kept@example.com',
		);

		// No `_tab` at all: a programmatic write, not a panel form post.
		$new = array(
			'_posted_fields' => array( 'chrome_sponsors' ),
		);

		$merged = blueline_settings_merge( $new, $old );

		$this->assertArrayHasKey(
			'chrome_sponsors',
			$merged,
			'_posted_fields must carry no delete authority without _tab -- a programmatic write is not this field\'s owner'
		);
		$this->assertTrue(
			$merged['chrome_sponsors'],
			'without _tab, chrome_sponsors belongs to no submission and must be carried forward untouched'
		);
		$this->assertSame( 'kept@example.com', $merged['contact_email'] );
	}

	/**
	 * Requirement 3: a tab-scoped form post (one that names `_tab`) keeps
	 * today's behaviour exactly -- `_posted_fields` still decides ownership,
	 * and a field named there but absent from the submission is still
	 * deleted, while a field belonging to a tab the submission never
	 * touched (named in neither the submission nor `_posted_fields`)
	 * survives untouched. Self-contained here (rather than relying solely
	 * on SettingsStoreTest.php's equivalent coverage) so this file proves
	 * the guarantee on its own, standalone.
	 */
	public function test_tab_scoped_submission_still_deletes_a_named_absent_field_and_carries_forward_an_untouched_one(): void {
		$old = array(
			'chrome_sponsors' => true,
			'contact_email'   => 'kept@example.com',
		);

		// A real tab-scoped submission: the Sections tab, having rendered
		// chrome_sponsors as an (now unchecked) checkbox, owns it and names
		// it in _posted_fields without posting a value.
		$new = array(
			'_tab'           => 'sections',
			'_posted_fields' => array( 'chrome_sponsors' ),
		);

		$merged = blueline_settings_merge( $new, $old );

		$this->assertArrayNotHasKey(
			'chrome_sponsors',
			$merged,
			'named in _posted_fields but absent from a TAB-SCOPED submission must still be deleted -- an unchecked checkbox'
		);
		$this->assertSame(
			'kept@example.com',
			$merged['contact_email'],
			'a field belonging to a tab this submission did not touch must still survive'
		);
	}

	/**
	 * Requirement 4: a programmatic write cannot use `_schema` to defeat
	 * inc/settings/store.php's forward-only migration guard. inc/settings/page.php's
	 * blueline_settings_sanitize_callback() already lets `_schema` survive a
	 * programmatic write at all (unlike a tab-scoped one, which drops it
	 * outright -- see that file's own docblock and
	 * SettingsPageTest::test_sanitize_callback_drops_schema_from_a_form_submission()),
	 * but clamps it to BLUELINE_SETTINGS_SCHEMA_VERSION -- so "cannot set
	 * _schema" here means specifically: cannot push it ABOVE the version
	 * this running code understands, which is the one thing that would
	 * actually corrupt the migration guard (blueline_settings_migrate()'s
	 * forward-only compare would then treat the install as already current
	 * and silently skip every future migration).
	 *
	 * Exercised through the full update_option() pipeline (not merge()
	 * directly): `_schema` is reserved-key bookkeeping page.php's sanitize
	 * callback owns entirely -- blueline_settings_merge() does not special-
	 * case it at all -- so this test proves the two files still cooperate
	 * correctly, including through this task's `_tab`-gating change to the
	 * merge, not merely that the callback's own clamp still exists in
	 * isolation (SettingsPageTest.php already covers that directly).
	 */
	public function test_programmatic_write_cannot_push_schema_above_the_current_version(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array( '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION )
		);

		// No `_tab`: a programmatic write attempting to smuggle a newer
		// schema version than this code understands.
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array( '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION + 5 )
		);

		$stored = get_option( BLUELINE_SETTINGS_OPTION );
		$this->assertSame(
			BLUELINE_SETTINGS_SCHEMA_VERSION,
			$stored['_schema'],
			'_schema must never be allowed to exceed the running code\'s own schema version, even from a programmatic write'
		);
	}
}
