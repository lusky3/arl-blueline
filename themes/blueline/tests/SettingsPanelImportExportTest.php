<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Covers the panel's own export and import controls (inc/settings/page.php)
 * -- the half of the design spec's 6.8 ("JSON, both directions,
 * manage_options + nonce") that only ever existed in WP-CLI.
 *
 * ## The preview is the CLI's preview, and this file proves it
 *
 * The spec's central property for an import preview is that a write which
 * OMITS a key carries the stored value forward rather than resetting it, so
 * an admin has to be able to see which keys the file simply does not
 * mention. `wp blueline settings import --dry-run` has printed exactly that
 * since P1a; the risk when adding a second surface is a second, weaker
 * preview that shows only what changes.
 *
 * test_the_panel_preview_lists_the_same_keys_and_statuses_as_the_cli_dry_run()
 * therefore runs the real CLI dry-run over a temp file, parses the keys and
 * status words out of its output, and requires the panel's rendered HTML to
 * carry a row for every one of them with the equivalent status -- so the two
 * cannot drift into disagreeing about what an import would do.
 *
 * ## What is exercised through the handler, and what is not
 *
 * The preview form accepts either an uploaded file or pasted JSON. The
 * paste branch is driven end-to-end here, through
 * blueline_settings_maybe_handle_import() with real superglobals, nonce and
 * capability. The upload branch shares every line after the bytes are
 * read; of the lines it does not share, this file covers the
 * upload-error-code branch, the size-refused-before-reading branch, and the
 * is_uploaded_file() refusal (which is what a non-upload temp path gets),
 * leaving only the final file_get_contents() of a genuine multipart upload
 * unexercised -- PHP will not report a test's own temp file as an upload,
 * and faking that would be testing the fake.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/cli-stubs.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/snapshots.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/import.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/page.php';

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

require_once __DIR__ . '/../inc/cli/settings-command.php';

/**
 * See this file's own docblock.
 */
final class SettingsPanelImportExportTest extends TestCase {

	/**
	 * Temp files created by a test, removed in tearDown().
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	/**
	 * Reset every in-memory store and every superglobal these controls read.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_cli_log'] = array();
		$this->temp_files           = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture cleanup of superglobals between cases, not a real request.
		unset( $_POST['blueline_import_preview'], $_POST['blueline_import_apply'], $_POST['blueline_import_json'], $_POST['blueline_import_payload'], $_REQUEST['_wpnonce'] );
		$_FILES = array();
	}

	/**
	 * Remove any temp file a case created.
	 */
	protected function tearDown(): void {
		foreach ( $this->temp_files as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Grant the fake current user `manage_options`.
	 */
	private function grant_manage_options(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;
	}

	/**
	 * Put a valid nonce for the import controls into $_REQUEST, as a real
	 * submitted form would.
	 */
	private function seed_import_nonce(): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( BLUELINE_SETTINGS_IMPORT_NONCE ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- seeding the very token the code under test verifies.
	}

	/**
	 * Set up a pasted-JSON preview submission.
	 *
	 * @param string $raw JSON to paste.
	 */
	private function post_paste_preview( string $raw ): void {
		$this->grant_manage_options();
		$this->seed_import_nonce();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture standing in for a real submission; the nonce is seeded above and verified by the code under test.
		$_POST['blueline_import_preview'] = '1';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$_POST['blueline_import_json'] = $raw;
	}

	/**
	 * Render the data-tools section for a given preview state.
	 *
	 * @param array<string, mixed>|null $preview A blueline_settings_import_preview_state() result, or null.
	 * @return string
	 */
	private function render_tools( ?array $preview ): string {
		ob_start();
		blueline_settings_render_data_tools( $preview );
		return (string) ob_get_clean();
	}

	/**
	 * Queued settings-error messages, by code.
	 *
	 * @return array<string, string>
	 */
	private function queued_messages(): array {
		$by_code = array();
		foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
			$by_code[ $error['code'] ] = $error['message'];
		}
		return $by_code;
	}

	/**
	 * The panel's export is byte-for-byte the CLI's export: the same payload
	 * builder, the same JSON flags. Two surfaces producing two shapes would
	 * mean `import` had to accept both.
	 */
	public function test_the_panel_export_json_matches_the_cli_export(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );

		( new Blueline_Settings_Command() )->export( array(), array() );
		$cli_json = $GLOBALS['bl_test_cli_log'][0]['message'];

		$this->assertSame( $cli_json, blueline_settings_export_json() );
	}

	/**
	 * The download's filename names the site and the day, so two exports
	 * from two environments do not both land as "settings.json".
	 */
	public function test_the_export_filename_is_dated_and_recognisable(): void {
		$name = blueline_settings_export_filename();

		$this->assertMatchesRegularExpression( '/^blueline-settings-\d{4}-\d{2}-\d{2}\.json$/', $name );
	}

	/**
	 * The export endpoint is capability-gated -- it hands out every stored
	 * setting.
	 */
	public function test_the_export_endpoint_refuses_without_manage_options(): void {
		$this->expectException( Blueline_Test_WP_Die_Exception::class );

		blueline_settings_handle_export();
	}

	/**
	 * A preview submission with no capability is refused outright, before
	 * anything is read.
	 */
	public function test_preview_refuses_without_manage_options(): void {
		$this->seed_import_nonce();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture standing in for a real submission.
		$_POST['blueline_import_preview'] = '1';

		$this->expectException( Blueline_Test_WP_Die_Exception::class );

		blueline_settings_maybe_handle_import();
	}

	/**
	 * ...and with no valid nonce, likewise.
	 */
	public function test_preview_refuses_without_a_valid_nonce(): void {
		$this->grant_manage_options();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture standing in for a real submission with a bad token.
		$_POST['blueline_import_preview'] = '1';
		$_REQUEST['_wpnonce']             = 'not-the-right-token'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- deliberately wrong, that is the point of this case.

		$this->expectException( Blueline_Test_WP_Die_Exception::class );

		blueline_settings_maybe_handle_import();
	}

	/**
	 * An ordinary page load -- no import submission at all -- produces no
	 * preview and touches nothing.
	 */
	public function test_an_ordinary_page_load_produces_no_preview(): void {
		$this->assertNull( blueline_settings_maybe_handle_import() );
	}

	/**
	 * The preview's central property, end-to-end through the handler: a
	 * file that mentions one key must show every OTHER key as carried
	 * forward, with the value that will survive -- not silently omit them.
	 */
	public function test_the_preview_shows_omitted_keys_as_carried_forward(): void {
		$this->post_paste_preview( '{"footer_heading":"Burlington Rookie League"}' );

		$preview = blueline_settings_maybe_handle_import();

		$this->assertIsArray( $preview );
		$this->assertSame( array(), $preview['errors'] );
		$this->assertSame( 'changed', $preview['diff']['footer_heading']['status'] );
		$this->assertSame( 'carried_forward', $preview['diff']['contact_email']['status'] );
		$this->assertSame(
			blueline_settings( 'contact_email' ),
			$preview['diff']['contact_email']['to'],
			'a carried-forward key must preview the value that survives, not a default'
		);
	}

	/**
	 * And the rendered markup says so in words an admin can act on, rather
	 * than leaving them to infer it from an absence.
	 */
	public function test_the_rendered_preview_explains_a_carried_forward_key(): void {
		$this->post_paste_preview( '{"footer_heading":"Burlington Rookie League"}' );

		$html = $this->render_tools( blueline_settings_maybe_handle_import() );

		$this->assertStringContainsString( 'footer_heading', $html );
		$this->assertStringContainsString( 'contact_email', $html );
		$this->assertStringContainsString( 'Not in the file', $html );
		$this->assertStringContainsString( 'kept', $html );
		$this->assertStringContainsString( 'name="blueline_import_apply"', $html, 'a clean preview must offer the apply step' );
	}

	/**
	 * The parity test this file exists for -- see its own docblock.
	 */
	public function test_the_panel_preview_lists_the_same_keys_and_statuses_as_the_cli_dry_run(): void {
		$raw = '{"footer_heading":"Burlington Rookie League","page_faqs":0}';

		$path               = sys_get_temp_dir() . '/blueline-panel-parity-' . uniqid( '', true ) . '.json';
		$this->temp_files[] = $path;
		file_put_contents( $path, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture in the system temp dir, not theme runtime code.

		$this->grant_manage_options();
		( new Blueline_Settings_Command() )->import( array( $path ), array( 'dry-run' => true ) );

		$cli_statuses = array();
		foreach ( $GLOBALS['bl_test_cli_log'] as $entry ) {
			if ( 'log' !== $entry['type'] ) {
				continue;
			}
			if ( preg_match( '/^(changed|added|unchanged|not in the file)\s+(\S+?):/', $entry['message'], $m ) ) {
				$cli_statuses[ $m[2] ] = $m[1];
			}
		}

		$this->assertNotEmpty( $cli_statuses, 'premise: the CLI dry run printed a per-key diff' );

		$this->post_paste_preview( $raw );
		$preview = blueline_settings_maybe_handle_import();

		$panel_statuses = array();
		foreach ( $preview['diff'] as $key => $entry ) {
			$panel_statuses[ $key ] = str_replace( '_', ' ', $entry['status'] );
		}

		$expected = array();
		foreach ( $cli_statuses as $key => $status ) {
			$expected[ $key ] = 'not in the file' === $status ? 'carried forward' : $status;
		}

		ksort( $expected );
		ksort( $panel_statuses );

		$this->assertSame( $expected, $panel_statuses, 'the panel preview and the CLI dry run must agree, key for key' );

		$html = $this->render_tools( $preview );
		foreach ( array_keys( $expected ) as $key ) {
			$this->assertStringContainsString( $key, $html, "the rendered preview must name $key, as the CLI dry run does" );
		}
	}

	/**
	 * A file whose value the sanitizer refuses is reported, and NO apply
	 * control is offered: applying it would keep the stored value for that
	 * field, so a preview offering the button would be offering a write
	 * that does not do what the preview showed.
	 */
	public function test_a_rejected_value_is_reported_and_the_apply_step_is_withheld(): void {
		$this->post_paste_preview( '{"contact_email":"not-an-email"}' );

		$preview = blueline_settings_maybe_handle_import();

		$this->assertNotEmpty( $preview['errors'] );
		$this->assertNull( $preview['diff'] );

		$html = $this->render_tools( $preview );
		$this->assertStringNotContainsString( 'name="blueline_import_apply"', $html );
		$this->assertStringContainsString( 'Contact email', $html, 'the rejection must name the field by its label' );
	}

	/**
	 * A key the schema does not declare is named, not silently dropped --
	 * the same report `wp blueline settings import` makes.
	 */
	public function test_an_unrecognised_key_is_named_in_the_preview(): void {
		$this->post_paste_preview( '{"footer_heading":"x","not_a_real_setting":"y"}' );

		$preview = blueline_settings_maybe_handle_import();

		$this->assertSame( array( 'not_a_real_setting' ), $preview['dropped'] );
		$this->assertStringContainsString( 'not_a_real_setting', $this->render_tools( $preview ) );
	}

	/**
	 * Both payload bounds reach the panel, with their own messages -- this
	 * is the surface where pointing at a large file is easiest.
	 */
	public function test_the_payload_bounds_apply_to_the_panel(): void {
		$this->post_paste_preview( str_repeat( 'a', BLUELINE_SETTINGS_IMPORT_MAX_BYTES + 1 ) );
		$too_big = blueline_settings_maybe_handle_import();

		$this->assertNotEmpty( $too_big['errors'] );
		$this->assertStringContainsString( (string) BLUELINE_SETTINGS_IMPORT_MAX_BYTES, implode( ' ', $too_big['errors'] ) );

		$this->post_paste_preview( '{"a":' . str_repeat( '[', 40 ) . '1' . str_repeat( ']', 40 ) . '}' );
		$too_deep = blueline_settings_maybe_handle_import();

		$this->assertNotEmpty( $too_deep['errors'] );
		$this->assertStringContainsString( (string) BLUELINE_SETTINGS_IMPORT_MAX_DEPTH, implode( ' ', $too_deep['errors'] ) );
	}

	/**
	 * An upload larger than the bound is refused from its reported size
	 * alone, before a byte is read.
	 */
	public function test_an_oversized_upload_is_refused_before_it_is_read(): void {
		$result = blueline_settings_import_read_upload(
			array(
				'error'    => UPLOAD_ERR_OK,
				'size'     => BLUELINE_SETTINGS_IMPORT_MAX_BYTES + 1,
				'tmp_name' => '/nonexistent/never-read',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_import_too_large', $result->get_error_code() );
	}

	/**
	 * A failed upload reports the upload failure, not a JSON error.
	 */
	public function test_a_failed_upload_is_reported_as_an_upload_failure(): void {
		$result = blueline_settings_import_read_upload(
			array(
				'error'    => UPLOAD_ERR_INI_SIZE,
				'size'     => 10,
				'tmp_name' => '',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_import_upload_failed', $result->get_error_code() );
	}

	/**
	 * A tmp_name that is not a genuine multipart upload is refused by
	 * is_uploaded_file(), never read.
	 */
	public function test_a_path_that_is_not_a_real_upload_is_refused(): void {
		$path               = sys_get_temp_dir() . '/blueline-not-an-upload-' . uniqid( '', true ) . '.json';
		$this->temp_files[] = $path;
		file_put_contents( $path, '{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture in the system temp dir.

		$result = blueline_settings_import_read_upload(
			array(
				'error'    => UPLOAD_ERR_OK,
				'size'     => 2,
				'tmp_name' => $path,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blueline_import_upload_failed', $result->get_error_code() );
	}

	/**
	 * Applying writes the file's values through the ordinary save path, and
	 * -- the property the preview promised -- leaves every key the file did
	 * not mention exactly as it was.
	 */
	public function test_applying_writes_the_file_and_keeps_what_it_omits(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'footer_heading' => 'The League',
				'page_faqs'      => 42,
			)
		);

		$this->grant_manage_options();
		$this->seed_import_nonce();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture standing in for a real submission; the nonce is seeded above.
		$_POST['blueline_import_apply'] = '1';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$_POST['blueline_import_payload'] = '{"footer_heading":"Burlington Rookie League"}';

		blueline_settings_maybe_handle_import();

		$this->assertSame( 'Burlington Rookie League', blueline_settings( 'footer_heading' ) );
		$this->assertSame( 42, blueline_settings( 'page_faqs' ), 'a key the file omits must be carried forward, not reset' );

		$this->assertArrayHasKey( 'blueline_settings_imported', $this->queued_messages() );
	}

	/**
	 * Applying is nonce-gated too -- it is a write, and the preview step's
	 * nonce does not carry over on its own.
	 */
	public function test_applying_refuses_without_a_valid_nonce(): void {
		$this->grant_manage_options();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture standing in for a real submission with no token.
		$_POST['blueline_import_apply'] = '1';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$_POST['blueline_import_payload'] = '{"footer_heading":"x"}';

		$this->expectException( Blueline_Test_WP_Die_Exception::class );

		blueline_settings_maybe_handle_import();
	}

	/**
	 * Applying a payload the sanitizer refuses writes nothing at all and
	 * reports the refusal -- it must not half-apply the acceptable fields
	 * while the admin believes the whole file went in.
	 */
	public function test_applying_a_rejected_payload_writes_nothing(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'The League' ) );

		$this->grant_manage_options();
		$this->seed_import_nonce();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture standing in for a real submission.
		$_POST['blueline_import_apply'] = '1';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$_POST['blueline_import_payload'] = '{"footer_heading":"Changed","contact_email":"not-an-email"}';

		blueline_settings_maybe_handle_import();

		$this->assertSame( 'The League', blueline_settings( 'footer_heading' ), 'nothing may be written when any field is refused' );
		$this->assertArrayNotHasKey( 'blueline_settings_imported', $this->queued_messages() );
	}

	/**
	 * The section renders on an ordinary page load with no preview, and
	 * emits no forbidden `<div>` notice (tests/NoticeDivGuardTest.php scans
	 * source; this asserts the rendered output too) and no dead link.
	 */
	public function test_the_section_renders_cleanly_with_no_preview(): void {
		$html = $this->render_tools( null );

		$this->assertStringContainsString( 'name="blueline_import_preview"', $html );
		$this->assertStringContainsString( 'blueline_settings_export', $html, 'the download control must point at its own endpoint' );
		$this->assertStringNotContainsString( 'href="#"', $html );
		$this->assertDoesNotMatchRegularExpression( '/<div[^>]*class="[^"]*(notice|error|warning|info|updated)/i', $html );
	}
}
