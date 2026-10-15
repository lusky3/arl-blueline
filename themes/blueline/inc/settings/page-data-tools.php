<?php
/**
 * Appearance -> Blueline: "Export and import". The export nonce, the diff
 * status labels, the data-tools section and the import preview.
 *
 * Loaded by inc/settings/page.php, whose docblock holds the constraints
 * this part follows.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nonce action for the export download, separate from the import one:
 * they are separate requests to separate endpoints, and one token that
 * covered both would let a stale import form authorise a download or the
 * other way round.
 */
const BLUELINE_SETTINGS_EXPORT_NONCE = 'blueline_settings_export';

/**
 * The admin-facing label for one blueline_settings_diff() status.
 *
 * `carried_forward` gets the longest one on purpose: it is the state an
 * admin is most likely to misread, and "Not in the file" alone would leave
 * "so what happens to it?" unanswered.
 *
 * @param string $status A blueline_settings_diff() status.
 * @return string
 */
function blueline_settings_diff_status_label( string $status ): string {
	switch ( $status ) {
		case 'changed':
			return __( 'Changing', 'blueline' );
		case 'added':
			return __( 'Adding', 'blueline' );
		case 'carried_forward':
			return __( 'Not in the file — kept as it is', 'blueline' );
		default:
			return __( 'No change', 'blueline' );
	}
}

/**
 * Render "Export and import": the download control, the two-input import
 * form, and, when this request produced one, the preview of what that
 * import would do.
 *
 * Rendered below the tab's own form, alongside "Recent saves", rather than
 * on a tab of its own: these operate on the whole option rather than on one
 * tab's fields, and tabs here are derived from the schema
 * (blueline_settings_tab_slugs()), so a tab with no fields behind it would
 * have to be special-cased into that list.
 *
 * @param array<string, mixed>|null $preview A blueline_settings_maybe_handle_import() result.
 * @return void
 */
function blueline_settings_render_data_tools( ?array $preview ): void {
	$here = blueline_settings_tab_url( blueline_settings_current_tab() );
	?>
	<section class="bl-settings-data">
		<h2><?php echo esc_html( __( 'Export and import', 'blueline' ) ); ?></h2>

		<p>
			<?php
			echo esc_html(
				__( 'The download below contains only the settings on this page: the page choices, the copy, the toggles, and the contact address the site already publishes. No passwords, no API keys, and nothing about any member.', 'blueline' )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="blueline_settings_export">
			<?php wp_nonce_field( BLUELINE_SETTINGS_EXPORT_NONCE ); ?>
			<p>
				<button type="submit" class="button">
					<?php echo esc_html( __( 'Download these settings as JSON', 'blueline' ) ); ?>
				</button>
			</p>
		</form>

		<h3><?php echo esc_html( __( 'Import a settings file', 'blueline' ) ); ?></h3>

		<p>
			<?php
			echo esc_html(
				__( 'Nothing is written until you have seen exactly what would change. A setting the file does not mention is left as it is rather than reset, so a file carrying one setting changes one setting.', 'blueline' )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( $here ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( BLUELINE_SETTINGS_IMPORT_NONCE ); ?>

			<p>
				<label for="blueline-import-file"><?php echo esc_html( __( 'Settings file', 'blueline' ) ); ?></label><br>
				<input type="file" id="blueline-import-file" name="blueline_import_file" accept=".json,application/json">
			</p>

			<p>
				<label for="blueline-import-json"><?php echo esc_html( __( 'Or paste the file\'s contents', 'blueline' ) ); ?></label><br>
				<textarea id="blueline-import-json" name="blueline_import_json" rows="4" class="large-text code"></textarea>
			</p>

			<p>
				<button type="submit" class="button" name="blueline_import_preview" value="1">
					<?php echo esc_html( __( 'Preview changes', 'blueline' ) ); ?>
				</button>
			</p>
		</form>

		<?php
		if ( null !== $preview ) {
			blueline_settings_render_import_preview( $preview, $here );
		}
		?>
	</section>
	<?php
	blueline_settings_render_delete_all_data();
}

/**
 * Render one import preview: what would be refused, what would be ignored,
 * what would happen to every setting, and, only when there is nothing to
 * refuse, the control that actually applies it.
 *
 * Both notices below are `<section>`s, never `<div>`s; see inc/settings/page.php's
 * docblock's "Never a `<div>`" section and tests/NoticeDivGuardTest.php.
 *
 * EVERY key gets a row, including the ones that are not changing. That is
 * the point of the preview and the same choice
 * blueline_settings_cli_diff_lines() makes: an import that omits a key does
 * not reset it, so a preview showing only differences would read as "these
 * are the only differences" while every omitted key sat invisible in it.
 *
 * @param array<string, mixed> $preview A blueline_settings_import_preview_state() result.
 * @param string               $here    URL this preview's own apply form posts back to.
 * @return void
 */
function blueline_settings_render_import_preview( array $preview, string $here ): void {
	?>
	<section class="bl-settings-import-preview">
		<h3><?php echo esc_html( __( 'What this file would do', 'blueline' ) ); ?></h3>

		<?php if ( ! empty( $preview['errors'] ) ) : ?>
			<section class="notice notice-error">
				<p><?php echo esc_html( __( 'This file cannot be imported as it stands:', 'blueline' ) ); ?></p>
				<ul>
					<?php foreach ( $preview['errors'] as $message ) : ?>
						<li><?php echo esc_html( (string) $message ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $preview['dropped'] ) ) : ?>
			<section class="notice notice-warning">
				<p><?php echo esc_html( __( 'These entries are not settings this version of the theme has, and would be ignored:', 'blueline' ) ); ?></p>
				<ul>
					<?php foreach ( $preview['dropped'] as $dropped_key ) : ?>
						<li><code><?php echo esc_html( (string) $dropped_key ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( is_array( $preview['diff'] ) ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( __( 'Setting', 'blueline' ) ); ?></th>
						<th scope="col"><?php echo esc_html( __( 'What would happen', 'blueline' ) ); ?></th>
						<th scope="col"><?php echo esc_html( __( 'Value afterwards', 'blueline' ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $preview['diff'] as $key => $entry ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $key ); ?></code></td>
							<td><?php echo esc_html( blueline_settings_diff_status_label( (string) $entry['status'] ) ); ?></td>
							<td><code><?php echo esc_html( blueline_settings_import_render_value( $entry['to'] ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( $here ); ?>">
				<?php wp_nonce_field( BLUELINE_SETTINGS_IMPORT_NONCE ); ?>
				<input type="hidden" name="blueline_import_payload" value="<?php echo esc_attr( (string) $preview['raw'] ); ?>">
				<p>
					<button type="submit" class="button button-primary" name="blueline_import_apply" value="1">
						<?php echo esc_html( __( 'Import these settings', 'blueline' ) ); ?>
					</button>
				</p>
			</form>
		<?php endif; ?>
	</section>
	<?php
}
