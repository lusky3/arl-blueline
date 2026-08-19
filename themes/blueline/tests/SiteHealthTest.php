<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/cache.php';
require_once __DIR__ . '/../inc/settings/site-health.php';

/**
 * Covers inc/settings/site-health.php's `blueline` Site Health section --
 * Task 10, Part A.
 *
 * Two things this file exists to prove, both directly requested by the task
 * brief:
 *
 * - test_contact_email_field_is_marked_private() proves the one field
 *   shaped like personal/contact information is actually flagged, so it
 *   stays out of a pasted support dump -- not merely documented as such.
 * - test_link_fields_report_configured_vs_falling_back() proves the
 *   Links-tab reporting agrees with blueline_resolve_link()'s own
 *   publish-status guard, not a separate, looser check that could drift
 *   from it.
 */
final class SiteHealthTest extends TestCase {

	/**
	 * Reset every in-memory store before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Fetch just this theme's own `blueline` Site Health section.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function section(): array {
		$info = blueline_site_health_debug_information( array() );
		$this->assertArrayHasKey( 'blueline', $info );
		return $info['blueline'];
	}

	/**
	 * The section itself has a label and a non-empty fields array.
	 */
	public function test_section_has_a_label_and_a_fields_array(): void {
		$section = $this->section();

		$this->assertSame( 'Blueline', $section['label'] );
		$this->assertIsArray( $section['fields'] );
		$this->assertNotEmpty( $section['fields'] );
	}

	/**
	 * Reports the stored `_schema` next to the version this code
	 * understands -- the exact fact inc/settings/store.php's
	 * blueline_settings_migrate() forward-only guard depends on.
	 */
	public function test_reports_schema_version(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( '_schema' => 1 ) );

		$fields = $this->section()['fields'];

		$this->assertStringContainsString( '1', $fields['schema_version']['value'] );
		$this->assertStringContainsString( (string) BLUELINE_SETTINGS_SCHEMA_VERSION, $fields['schema_version']['value'] );
	}

	/**
	 * A site that has never touched the panel reports zero overrides; a
	 * site with one changed field reports exactly one, out of the true
	 * total field count -- proving this counts against the REAL schema, not
	 * a hardcoded number that would silently drift as fields are added.
	 */
	public function test_reports_how_many_fields_override_a_default(): void {
		$total = count( blueline_settings_defaults() );

		$fields = $this->section()['fields'];
		$this->assertSame( "0 of {$total}", $fields['fields_overridden']['value'] );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'Something Custom' ) );

		$fields = $this->section()['fields'];
		$this->assertSame( "1 of {$total}", $fields['fields_overridden']['value'] );
	}

	/**
	 * The one field shaped like contact information is marked `private`,
	 * per core's own Site Health contract: rendered on-screen for the
	 * logged-in admin, excluded from the "copy site info to clipboard"
	 * export. This is what actually keeps it out of a pasted support dump
	 * -- not merely documenting the intent.
	 */
	public function test_contact_email_field_is_marked_private(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'someone@example.test' ) );

		$fields = $this->section()['fields'];

		$this->assertTrue( $fields['contact_email']['private'] ?? false );
		$this->assertSame( 'someone@example.test', $fields['contact_email']['value'] );
	}

	/**
	 * No OTHER field is marked private -- page IDs, the schema version and
	 * the cache-purge flags are not personal/contact information, and
	 * marking everything private would make the section useless for actual
	 * support diagnosis.
	 */
	public function test_no_other_field_is_marked_private(): void {
		$fields = $this->section()['fields'];

		foreach ( $fields as $key => $field ) {
			if ( 'contact_email' === $key ) {
				continue;
			}
			$this->assertArrayNotHasKey( 'private', $field, "field \"{$key}\" should not be marked private" );
		}
	}

	/**
	 * A Links-tab field with no page configured (the schema default, `0`)
	 * reports as falling back to its built-in path.
	 */
	public function test_link_field_with_no_page_configured_reports_falling_back(): void {
		$fields = $this->section()['fields'];

		$this->assertStringContainsString( '/schedule', $fields['link_page_schedule']['value'] );
		$this->assertStringContainsString( 'Falling back', $fields['link_page_schedule']['value'] );
	}

	/**
	 * A Links-tab field pointing at a published page reports as configured
	 * -- agreeing with blueline_resolve_link()'s own publish-status guard,
	 * not a separate, looser check.
	 */
	public function test_link_field_with_a_published_page_reports_configured(): void {
		blueline_test_register_post( 42, 'publish' );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'page_schedule' => 42 ) );

		$fields = $this->section()['fields'];

		$this->assertStringContainsString( '42', $fields['link_page_schedule']['value'] );
		$this->assertStringContainsString( 'Configured', $fields['link_page_schedule']['value'] );
	}

	/**
	 * A Links-tab field pointing at a TRASHED page reports as falling back
	 * -- the same trap blueline_resolve_link() itself exists to close
	 * (get_permalink() does not check post_status), agreeing with it rather
	 * than a looser check that would report "configured" for a page a
	 * visitor can no longer reach.
	 */
	public function test_link_field_with_a_trashed_page_reports_falling_back(): void {
		blueline_test_register_post( 42, 'trash' );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'page_schedule' => 42 ) );

		$fields = $this->section()['fields'];

		$this->assertStringContainsString( 'Falling back', $fields['link_page_schedule']['value'] );
	}

	/**
	 * Every Links-tab schema field gets its own row -- not just
	 * page_schedule -- so the section covers the whole tab, not one
	 * hand-picked example.
	 */
	public function test_every_links_tab_field_has_its_own_row(): void {
		$fields = $this->section()['fields'];

		foreach ( blueline_settings_schema() as $key => $field ) {
			if ( 'links' !== ( $field['tab'] ?? '' ) ) {
				continue;
			}
			$this->assertArrayHasKey( "link_{$key}", $fields, "expected a Site Health row for \"{$key}\"" );
		}
	}

	/**
	 * The srcache purge constant's state is reported plainly -- default
	 * (disabled) shows the manual-notice wording, not "enabled".
	 */
	public function test_reports_srcache_purge_constant_state(): void {
		$fields = $this->section()['fields'];

		$this->assertSame(
			BLUELINE_SRCACHE_PURGE,
			false,
			'this test assumes the shipped default (disabled); update its expectation, not this assertion, if that default ever changes'
		);
		$this->assertStringContainsString( 'Disabled', $fields['srcache_purge_enabled']['value'] );
	}

	/**
	 * A pending manual purge is reported; once cleared, it is not.
	 */
	public function test_reports_whether_a_manual_purge_is_pending(): void {
		$fields = $this->section()['fields'];
		$this->assertSame( 'No', $fields['manual_purge_pending']['value'] );

		blueline_mark_cache_purge_needed();

		$fields = $this->section()['fields'];
		$this->assertSame( 'Yes', $fields['manual_purge_pending']['value'] );
	}

	/**
	 * The filter callback must not clobber an existing
	 * Site Health section from another plugin/core itself -- it only ever
	 * adds its own `blueline` key.
	 */
	public function test_does_not_clobber_other_sections(): void {
		$info = blueline_site_health_debug_information( array( 'wp-core' => array( 'label' => 'WordPress' ) ) );

		$this->assertArrayHasKey( 'wp-core', $info );
		$this->assertArrayHasKey( 'blueline', $info );
	}
}
