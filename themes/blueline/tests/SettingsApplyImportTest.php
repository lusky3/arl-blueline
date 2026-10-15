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
require_once __DIR__ . '/../inc/settings/snapshots.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/import.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/page.php';
require_once __DIR__ . '/../inc/settings/import-export-handlers.php';

/**
 * The second step of a wp-admin import, blueline_settings_apply_import():
 * what it reports, what it returns, and that it re-validates the payload it
 * is handed rather than trusting the preview that came before it.
 * SettingsPanelImportExportTest covers the request plumbing around it (the
 * nonce, the all-or-nothing refusal of a field the sanitizer rejects).
 */
final class SettingsApplyImportTest extends TestCase {

	/**
	 * Fresh stores, and an admin making the request.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;
	}

	/**
	 * Put a payload where the apply step reads it, as the preview's hidden
	 * field would.
	 *
	 * @param string $payload JSON.
	 */
	private function post_payload( string $payload ): void {
		$_POST['blueline_import_payload'] = $payload; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture standing in for a real submission.
	}

	/**
	 * Queued admin notices for the settings option, keyed by code.
	 *
	 * @return array<string, string>
	 */
	private function messages(): array {
		$by_code = array();

		foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
			$by_code[ $error['code'] ] = $error['message'];
		}

		return $by_code;
	}

	/**
	 * Success returns null and reports exactly how many settings changed and
	 * how many the file left alone, counted from the same diff the preview
	 * showed.
	 */
	public function test_success_reports_what_changed_and_what_was_kept(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );
		$payload = '{"footer_heading":"Burlington Rookie League","contact_email":"info@example.test"}';

		$kept = count(
			array_filter(
				blueline_settings_import_preview_state( $payload )['diff'],
				static fn( array $entry ): bool => 'carried_forward' === $entry['status']
			)
		);
		$this->post_payload( $payload );

		$this->assertNull( blueline_settings_apply_import() );

		$this->assertSame( 'Burlington Rookie League', blueline_settings( 'footer_heading' ) );
		$this->assertSame( 'info@example.test', blueline_settings( 'contact_email' ) );
		$this->assertGreaterThan( 0, $kept, 'premise: a two-key file leaves most settings unmentioned' );
		$this->assertSame(
			sprintf( 'Imported. 2 setting(s) changed; %d the file did not mention were left exactly as they were.', $kept ),
			$this->messages()['blueline_settings_imported']
		);
	}

	/**
	 * A file that only restates current values changes nothing and says so.
	 */
	public function test_restating_current_values_counts_as_no_change(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );
		$this->post_payload( '{"footer_heading":"The League"}' );

		$this->assertNull( blueline_settings_apply_import() );
		$this->assertStringStartsWith( 'Imported. 0 setting(s) changed;', $this->messages()['blueline_settings_imported'] );
	}

	/**
	 * A payload that no longer parses (it travelled through a browser between
	 * preview and apply) writes nothing and returns the state the page needs
	 * to explain why.
	 */
	public function test_a_malformed_payload_writes_nothing_and_explains(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );
		$this->post_payload( '{"footer_heading":' );

		$state = blueline_settings_apply_import();

		$this->assertIsArray( $state );
		$this->assertNotEmpty( $state['errors'] );
		$this->assertNull( $state['diff'] );
		$this->assertSame( 'The League', blueline_settings( 'footer_heading' ) );
		$this->assertArrayHasKey( 'blueline_settings_import_failed', $this->messages() );
		$this->assertArrayNotHasKey( 'blueline_settings_imported', $this->messages() );
	}

	/**
	 * No payload at all (the hidden field was stripped) is refused the same
	 * way, never treated as "import nothing, successfully".
	 */
	public function test_a_missing_payload_is_refused(): void {
		$state = blueline_settings_apply_import();

		$this->assertIsArray( $state );
		$this->assertNotEmpty( $state['errors'] );
		$this->assertArrayHasKey( 'blueline_settings_import_failed', $this->messages() );
	}

	/**
	 * A user without manage_options cannot apply an import even with a valid
	 * nonce; the handler stops before reading the payload.
	 */
	public function test_a_non_admin_cannot_apply_even_with_a_valid_nonce(): void {
		$state         = &blueline_test_state();
		$state['caps'] = array();
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );

		$_REQUEST['_wpnonce']           = wp_create_nonce( BLUELINE_SETTINGS_IMPORT_NONCE ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- seeding the token the code under test verifies.
		$_POST['blueline_import_apply'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture standing in for a real submission.
		$this->post_payload( '{"footer_heading":"Hijacked"}' );

		try {
			blueline_settings_maybe_handle_import();
			$this->fail( 'a non-admin import must be stopped' );
		} catch ( Blueline_Test_WP_Die_Exception $e ) {
			$this->assertSame( 'The League', blueline_settings( 'footer_heading' ) );
		}
	}
}
