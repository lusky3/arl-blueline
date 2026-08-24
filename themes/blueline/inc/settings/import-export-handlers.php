<?php
/**
 * The settings panel's export download and import preview/apply requests:
 * the request-handling layer that sits between inc/settings/import.php's pure
 * decode/prepare/sanitize logic and the panel's own UI (still rendered from
 * inc/settings/page.php).
 *
 * Export is a real `admin_post_*` handler, registered below, because the
 * response is a file download and has to send its own headers. Import is
 * not, since it is read directly out of $_POST by blueline_settings_render_page()
 * before that function renders anything (see blueline_settings_maybe_handle_import()'s
 * own docblock for why), so both the preview and apply steps live here as
 * plain functions rather than admin-post callbacks.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The exact bytes the panel's Download button hands back: the SAME
 * payload and the SAME JSON flags `wp blueline settings export` prints, so
 * the two surfaces cannot drift into two shapes `import` would then have to
 * accept both of. tests/SettingsPanelImportExportTest.php asserts that
 * equality directly rather than trusting this comment.
 *
 * @return string
 */
function blueline_settings_export_json(): string {
	return (string) wp_json_encode( blueline_settings_export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

/**
 * The download's filename: dated, in the SITE's timezone (wp_timezone(),
 * the same source inc/settings/snapshots.php uses for its own labels)
 * rather than UTC, because the admin reading their downloads folder thinks
 * in local time. Dated at all so two exports taken from two environments on
 * two days do not both land as one anonymous "settings.json".
 *
 * @return string
 */
function blueline_settings_export_filename(): string {
	return 'blueline-settings-' . ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' ) . '.json';
}

add_action( 'admin_post_blueline_settings_export', 'blueline_settings_handle_export' );
/**
 * Serve the export as a file download.
 *
 * On `admin_post_*` rather than inside the panel's own render, because a
 * download has to send its headers before any other output; rendering
 * half a wp-admin page and then trying to become a file is not something
 * that can be recovered from.
 *
 * Capability first, then nonce: an export hands the caller every stored
 * setting, so it is gated exactly as the panel itself is.
 *
 * The final four lines (the headers, the echo and the exit) are the one
 * part of these controls no test here exercises, because a PHPUnit process
 * cannot usefully assert on headers it also has to keep running after. Everything
 * they depend on (the payload, the filename, the capability refusal) is
 * covered separately.
 *
 * @return void
 */
function blueline_settings_handle_export(): void {
	blueline_settings_require_manage_options_and_nonce( BLUELINE_SETTINGS_EXPORT_NONCE );

	$json = blueline_settings_export_json();

	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . blueline_settings_export_filename() . '"' );
	header( 'Content-Length: ' . strlen( $json ) );

	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this response IS a JSON file, not HTML, so escaping it would corrupt the download it exists to produce. Its content comes from blueline_settings(), which every write path has already sanitized, and wp_json_encode() has already escaped it as JSON.
	exit;
}

/**
 * Work out what an import of $raw would do, without doing any of it.
 *
 * Runs the identical machinery `wp blueline settings import --dry-run`
 * runs, in the identical order (inc/settings/import.php): bound and decode,
 * refuse a newer `_schema`, collect the keys the schema does not declare,
 * then walk every recognised field through blueline_sanitize_field(). The
 * diff is built from the SANITIZED values, never the file's own text,
 * because that sanitizer normalises as well as validates, and a preview
 * built from the raw payload would report a change to a value the import
 * is never going to store.
 *
 * A payload with any rejected field returns `diff => null` deliberately:
 * a rejected field keeps its currently-stored value, so a diff drawn
 * alongside the failures would be a preview of something that is not going
 * to happen. The caller withholds the apply control in that case, which is
 * the panel's equivalent of the CLI dry run's "fix the file and re-run to
 * see the diff".
 *
 * @param string $raw Raw JSON, from an upload or a paste.
 * @return array{errors: string[], dropped: string[], diff: array<string, array{status:string, from:mixed, to:mixed}>|null, prepared: array<string, mixed>, raw: string}
 */
function blueline_settings_import_preview_state( string $raw ): array {
	$state = array(
		'errors'   => array(),
		'dropped'  => array(),
		'diff'     => null,
		'prepared' => array(),
		'raw'      => $raw,
	);

	$payload = blueline_settings_import_decode( $raw );

	if ( is_wp_error( $payload ) ) {
		$state['errors'][] = $payload->get_error_message();
		return $state;
	}

	$prepared = blueline_settings_import_prepare( $payload, BLUELINE_SETTINGS_SCHEMA_VERSION );

	if ( is_wp_error( $prepared ) ) {
		$state['errors'][] = $prepared->get_error_message();
		return $state;
	}

	$schema = blueline_settings_schema();

	$state['prepared'] = $prepared;
	$state['dropped']  = blueline_settings_import_dropped_keys( $prepared, $schema );

	$sanitized = blueline_settings_import_sanitize_payload( $prepared, $schema );

	if ( ! empty( $sanitized['errors'] ) ) {
		$state['errors'] = $sanitized['errors'];
		return $state;
	}

	$state['diff'] = blueline_settings_diff( blueline_settings(), $sanitized['values'] );

	return $state;
}

/**
 * Read the bytes an import submission is offering, from whichever of its
 * two inputs was used.
 *
 * The form offers both an upload and a paste box, in that order of
 * precedence. Two inputs rather than one because an admin who can reach
 * wp-admin cannot necessarily get a file onto the machine they are browsing
 * from (a shared laptop, a phone), and the whole point of this feature is
 * moving a small config between environments.
 *
 * @return string|WP_Error The raw payload, or an error naming what went
 *                          wrong with the submission itself (not with its
 *                          contents, which is blueline_settings_import_preview_state()'s
 *                          job).
 */
function blueline_settings_import_submitted_payload() {
	// Both sniffs are silenced on the line itself rather than from the line
	// above: phpcs lets a trailing annotation REPLACE a preceding-line one, so
	// splitting them across two comments silently drops the first.
	$file = isset( $_FILES['blueline_import_file'] ) && is_array( $_FILES['blueline_import_file'] ) ? $_FILES['blueline_import_file'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce for this submission is verified by blueline_settings_maybe_handle_import(), this function's only caller, before it is called. This is an upload descriptor, not text: every field it reads is cast and validated in blueline_settings_import_read_upload(), and its bytes go through the JSON decoder's own bounds rather than a text sanitizer.

	if ( null !== $file && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
		return blueline_settings_import_read_upload( $file );
	}

	$pasted = isset( $_POST['blueline_import_json'] ) ? trim( (string) wp_unslash( $_POST['blueline_import_json'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by the only caller, as above. It is deliberately NOT run through sanitize_text_field(), because this value is a JSON document, and stripping tags/newlines out of it would corrupt a valid payload rather than protect anything. It is validated by blueline_settings_import_decode()'s size, depth and shape bounds, and every value inside it by blueline_sanitize_field(), before any of it is stored.

	if ( '' !== $pasted ) {
		return $pasted;
	}

	return new WP_Error(
		'blueline_import_nothing_submitted',
		__( 'Choose a settings file, or paste one in, and try again.', 'blueline' )
	);
}

/**
 * Turn one `$_FILES` entry into its contents, refusing anything that is not
 * a plausible settings file BEFORE reading it.
 *
 * The size check happens on the size the upload REPORTS, ahead of any read:
 * a browser upload is a much easier thing to point at an enormous file than
 * a CLI invocation is, and a bound applied only after the bytes are in
 * memory would not be much of a bound.
 *
 * is_uploaded_file() is the last gate before the read. It is what
 * distinguishes a path PHP itself created while parsing a multipart request
 * from any other path that might somehow appear in this array.
 *
 * @param array<string, mixed> $file One `$_FILES` entry.
 * @return string|WP_Error
 */
function blueline_settings_import_read_upload( array $file ) {
	$upload_error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

	if ( UPLOAD_ERR_OK !== $upload_error ) {
		return new WP_Error(
			'blueline_import_upload_failed',
			__( 'That file did not finish uploading. Try again, or paste its contents instead.', 'blueline' )
		);
	}

	$size = isset( $file['size'] ) ? (int) $file['size'] : 0;

	if ( $size > BLUELINE_SETTINGS_IMPORT_MAX_BYTES ) {
		return new WP_Error( 'blueline_import_too_large', blueline_settings_import_too_large_message( $size ) );
	}

	$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

	if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
		return new WP_Error(
			'blueline_import_upload_failed',
			__( 'That file did not finish uploading. Try again, or paste its contents instead.', 'blueline' )
		);
	}

	return (string) file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local temp file PHP itself just wrote from a multipart upload (proven by is_uploaded_file() immediately above), not an HTTP fetch, since wp_remote_get() is for URLs.
}

/**
 * Handle an import submission, if this request carries one.
 *
 * Called from blueline_settings_render_page() BEFORE anything is rendered,
 * for the same reason blueline_settings_maybe_restore() is: an applied
 * import rewrites the very values the fields below are about to be filled
 * from.
 *
 * Guards, in this order and shared by both steps:
 *
 * 1. Neither `blueline_import_preview` nor `blueline_import_apply` in the
 *    POST: not an import request at all, return before touching anything.
 * 2. `manage_options`.
 * 3. check_admin_referer() against BLUELINE_SETTINGS_IMPORT_NONCE. The
 *    apply step carries its own copy of the token rather than inheriting
 *    the preview's: it is a separate request, and a separate write.
 *
 * @return array<string, mixed>|null The preview to render, or null when
 *                                    there is nothing to show (not an
 *                                    import request, or an apply that
 *                                    succeeded and has said so via
 *                                    add_settings_error()).
 */
function blueline_settings_maybe_handle_import(): ?array {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence checks only, to decide whether this is an import request at all; the nonce is verified below before anything is read or written.
	$is_apply = isset( $_POST['blueline_import_apply'] );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
	$is_preview = isset( $_POST['blueline_import_preview'] );

	if ( ! $is_apply && ! $is_preview ) {
		return null;
	}

	blueline_settings_require_manage_options_and_nonce( BLUELINE_SETTINGS_IMPORT_NONCE );

	if ( $is_apply ) {
		return blueline_settings_apply_import();
	}

	$raw = blueline_settings_import_submitted_payload();

	if ( is_wp_error( $raw ) ) {
		return array(
			'errors'   => array( $raw->get_error_message() ),
			'dropped'  => array(),
			'diff'     => null,
			'prepared' => array(),
			'raw'      => '',
		);
	}

	return blueline_settings_import_preview_state( $raw );
}

/**
 * Apply the payload the preview step handed back through a hidden field.
 *
 * Re-runs the whole preview (decode, bounds, `_schema` refusal, sanitizer
 * walk) rather than trusting that the payload has not changed between the
 * two requests. It has travelled through a browser in the meantime, and the
 * preview's verdict is not a token of any kind.
 *
 * ALL OR NOTHING: if any field is refused, nothing is written at all. The
 * ordinary panel save deliberately does the opposite (a rejected field
 * keeps its stored value while every other field in the same submission
 * saves), because there a human is looking at one form and can fix the one
 * field. An import is a file the admin has just been shown a preview of; a
 * partial application would leave the site in a state that preview never
 * described.
 *
 * The write goes through update_option(), so the same
 * `sanitize_option_{$option}` callback and the same merge every other write
 * runs apply here too, which is also what makes an omitted key carry its
 * stored value forward rather than resetting, exactly as the preview said.
 *
 * @return array<string, mixed>|null The preview state again when nothing
 *                                    could be applied (so the page can
 *                                    re-show why), or null on success.
 */
function blueline_settings_apply_import(): ?array {
	$raw = isset( $_POST['blueline_import_payload'] ) ? (string) wp_unslash( $_POST['blueline_import_payload'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by blueline_settings_maybe_handle_import(), this function's only caller, immediately before it is called. This is a JSON document, not text; see blueline_settings_import_submitted_payload() for why a text sanitizer would corrupt rather than protect it, and what does validate it instead.

	$state = blueline_settings_import_preview_state( $raw );

	if ( ! empty( $state['errors'] ) || null === $state['diff'] ) {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_import_failed',
			esc_html__( 'Nothing was imported. The problems below have to be fixed in the file first.', 'blueline' ),
			'error'
		);

		return $state;
	}

	update_option( BLUELINE_SETTINGS_OPTION, $state['prepared'] );

	$changed = 0;
	$kept    = 0;

	foreach ( $state['diff'] as $entry ) {
		if ( 'changed' === $entry['status'] ) {
			++$changed;
		} elseif ( 'carried_forward' === $entry['status'] ) {
			++$kept;
		}
	}

	add_settings_error(
		BLUELINE_SETTINGS_OPTION,
		'blueline_settings_imported',
		esc_html(
			sprintf(
				/* translators: 1: how many settings the file changed, 2: how many settings the file did not mention. */
				__( 'Imported. %1$d setting(s) changed; %2$d the file did not mention were left exactly as they were.', 'blueline' ),
				$changed,
				$kept
			)
		),
		'success'
	);

	return null;
}
