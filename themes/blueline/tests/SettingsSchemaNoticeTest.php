<?php
/**
 * Covers the admin notice for the one state inc/settings/store.php's
 * forward-only migration deliberately refuses to act on: a stored `_schema`
 * NEWER than the running code's BLUELINE_SETTINGS_SCHEMA_VERSION, which
 * happens when the theme is rolled back after a newer version already
 * migrated the option.
 *
 * The refusal itself was already built and documented (blueline_settings_migrate()
 * returns before writing whenever the stored `_schema` is >= the code's).
 * The spec's other half -- "must refuse to write, still render, AND show a
 * notice" (6.7) -- was not: Site Health reports both version numbers under
 * Tools -> Site Health -> Info, but nothing told an admin anything, and
 * nobody reads Site Health unprompted.
 *
 * ## Every claim in the notice's copy is probed here, not reasoned about
 *
 * Destructive- and reassuring-sounding admin copy is the easiest thing in
 * this codebase to get wrong, so the three factual claims the notice makes
 * each have a test of their own below rather than being inferred from the
 * code's shape:
 *
 * - "the settings upgrade has not run, and has not rewritten anything" ->
 *   test_the_migration_refuses_to_write_and_leaves_the_stored_value_alone()
 * - "the site still reads every setting it recognises" ->
 *   test_settings_still_read_normally_under_a_newer_stored_schema()
 * - "saving from this panel keeps [the newer values]" ->
 *   test_an_ordinary_panel_save_keeps_both_the_newer_schema_and_its_unknown_keys()
 *
 * And the notice is asserted on its RENDERED MARKUP, not on the fact that
 * some function was called: a notice test that never renders cannot see
 * that the notice names a raw internal key or links nowhere, which is
 * exactly how a previous notice on this branch shipped broken.
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

/**
 * See this file's own docblock.
 */
final class SettingsSchemaNoticeTest extends TestCase {

	/**
	 * Reset every in-memory store between cases.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Grant the fake current user `manage_options` -- the capability this
	 * notice (like the rest of the panel) is gated on.
	 */
	private function grant_manage_options(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;
	}

	/**
	 * Store a settings option whose `_schema` is one newer than this code
	 * understands, alongside a field key this code's schema does not
	 * declare -- the shape a rolled-back theme actually finds in the
	 * database.
	 *
	 * @return int The stored (newer) schema version.
	 */
	private function store_a_newer_schema(): int {
		$newer = BLUELINE_SETTINGS_SCHEMA_VERSION + 1;

		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array(
			'footer_heading'          => 'The League',
			'a_field_from_the_future' => 'set by a newer theme version',
			'_schema'                 => $newer,
		);

		return $newer;
	}

	/**
	 * Capture one render of the notice.
	 *
	 * @return string
	 */
	private function render_notice(): string {
		ob_start();
		blueline_settings_newer_schema_notice();
		return (string) ob_get_clean();
	}

	/**
	 * The ordinary case -- stored schema equal to the code's -- renders
	 * nothing at all. A notice that showed up on every install would be
	 * worse than no notice.
	 */
	public function test_nothing_renders_when_the_stored_schema_matches_this_code(): void {
		$this->grant_manage_options();

		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = array(
			'_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION,
		);

		$this->assertSame( '', $this->render_notice() );
	}

	/**
	 * A fresh install (no option at all) renders nothing either -- an
	 * absent `_schema` reads as 0, which is older, not newer.
	 */
	public function test_nothing_renders_on_a_fresh_install(): void {
		$this->grant_manage_options();

		$this->assertSame( '', $this->render_notice() );
	}

	/**
	 * `admin_notices` fires on every admin screen for every logged-in user,
	 * so this carries its own capability check: nothing renders for a user
	 * who could not act on it anyway.
	 */
	public function test_nothing_renders_without_manage_options(): void {
		$this->store_a_newer_schema();

		$this->assertSame( '', $this->render_notice() );
	}

	/**
	 * The notice itself, asserted on its rendered markup: a `<section>` (a
	 * `<div>` would be swept out of the DOM by the third-party plugin
	 * tests/NoticeDivGuardTest.php exists for), carrying both version
	 * numbers so the reader can tell which way round the mismatch is, and
	 * no raw internal key or dead link.
	 */
	public function test_the_notice_renders_a_section_naming_both_version_numbers(): void {
		$this->grant_manage_options();
		$newer = $this->store_a_newer_schema();

		$html = $this->render_notice();

		$this->assertStringContainsString( '<section', $html );
		$this->assertStringNotContainsString( '<div', $html );
		$this->assertStringContainsString( 'notice notice-warning', $html );

		$this->assertStringContainsString( (string) $newer, $html, 'the stored (newer) version must be named' );
		$this->assertStringContainsString( (string) BLUELINE_SETTINGS_SCHEMA_VERSION, $html, "this code's own version must be named" );

		$this->assertStringNotContainsString( '_schema', $html, 'the notice must not print the raw internal option key at an admin' );
		$this->assertStringNotContainsString( 'href="#"', $html, 'no link to nowhere' );
	}

	/**
	 * The notice is actually wired to `admin_notices`; without this the
	 * markup above would be correct and never rendered by anything.
	 */
	public function test_the_notice_is_registered_on_admin_notices(): void {
		$registered = array();

		foreach ( $GLOBALS['bl_test_hooks']['admin_notices'] ?? array() as $bucket ) {
			foreach ( $bucket as $hook ) {
				$registered[] = $hook['cb'];
			}
		}

		$this->assertContains(
			'blueline_settings_newer_schema_notice',
			$registered,
			'the newer-schema notice must be hooked to admin_notices at file scope'
		);
	}

	/**
	 * Claim 1 of the copy: the settings upgrade has not run and has not
	 * rewritten anything. blueline_settings_migrate() must leave the stored
	 * array byte-for-byte as it found it.
	 */
	public function test_the_migration_refuses_to_write_and_leaves_the_stored_value_alone(): void {
		$this->store_a_newer_schema();

		$before = get_option( BLUELINE_SETTINGS_OPTION );

		blueline_settings_migrate();

		$this->assertSame( $before, get_option( BLUELINE_SETTINGS_OPTION ) );
	}

	/**
	 * Claim 2: the site still reads every setting it recognises. A stored
	 * key this code's schema does not declare is ignored on read rather
	 * than breaking anything, and a key it does declare reads back
	 * normally.
	 */
	public function test_settings_still_read_normally_under_a_newer_stored_schema(): void {
		$this->store_a_newer_schema();

		$this->assertSame( 'The League', blueline_settings( 'footer_heading' ) );
		$this->assertArrayNotHasKey( 'a_field_from_the_future', blueline_settings() );
		$this->assertSame(
			blueline_settings_defaults()['contact_email'],
			blueline_settings( 'contact_email' ),
			'a field the newer version never stored still falls back to its default'
		);
	}

	/**
	 * Claim 3: saving from this panel keeps the newer values. An ordinary
	 * tab-scoped save must carry forward BOTH the newer `_schema` and the
	 * unrecognised key beside it, rather than silently downgrading either.
	 */
	public function test_an_ordinary_panel_save_keeps_both_the_newer_schema_and_its_unknown_keys(): void {
		$newer = $this->store_a_newer_schema();

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading' => 'Burlington Rookie League',
				'_tab'           => 'content',
				'_posted_fields' => array( 'footer_heading' ),
			)
		);

		$stored = get_option( BLUELINE_SETTINGS_OPTION );

		$this->assertSame( 'Burlington Rookie League', $stored['footer_heading'], 'the edited field still saves' );
		$this->assertSame( $newer, $stored['_schema'], 'the newer schema version must not be downgraded by an ordinary save' );
		$this->assertSame(
			'set by a newer theme version',
			$stored['a_field_from_the_future'],
			'a stored key this version does not recognise must survive an ordinary save'
		);
	}
}
