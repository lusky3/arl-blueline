<?php
/**
 * Appearance -> Blueline: the "Recent saves" list and the restore it submits.
 *
 * Loaded by inc/settings/page.php, whose docblock holds the constraints
 * this part follows.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply a restore submitted from the "Recent saves" list, if this request
 * carries one.
 *
 * Called from blueline_settings_render_page() BEFORE it renders anything,
 * so the page an admin sees after clicking Restore already shows the
 * restored values and the outcome notice. That is deliberately not a
 * POST-redirect-GET round trip: the control names a snapshot by its stable
 * id (see blueline_settings_snapshot_take()), so re-submitting the same
 * request (the browser refresh a non-redirecting POST invites) restores
 * the same snapshot again, which by then changes nothing and records no
 * new history (blueline_settings_snapshot_on_save() skips a write whose
 * merged result matches storage).
 *
 * Three guards, in this order:
 *
 * 1. No `blueline_restore_snapshot` in the POST: not a restore request at
 *    all, return before touching anything else.
 * 2. `manage_options`. This is checked here as well as in
 *    blueline_settings_render_page(), the same defence in depth that
 *    function already applies over blueline_settings_add_page()'s own
 *    capability argument, so this function is safe whatever ends up
 *    calling it.
 * 3. check_admin_referer() against the restore's own action, which is a
 *    separate nonce from settings_fields()' save nonce because this is a
 *    separate, differently-shaped write.
 *
 * @return void
 */
function blueline_settings_maybe_restore(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only, to decide whether this is a restore request at all; the nonce is verified below before anything is read or written.
	if ( ! isset( $_POST['blueline_restore_snapshot'] ) ) {
		return;
	}

	blueline_settings_require_manage_options_and_nonce( 'blueline_settings_restore' );

	// is_scalar() first: PHP evaluates a non-empty array to 1 on the way
	// through absint(), so an array-shaped POST value (`...[]=x`) would
	// silently name snapshot 1 and restore it. Nonce and capability both
	// stand in front of this, so it is not a security hole; it is a
	// restore nobody asked for, which is bad enough. 0 is never a real
	// snapshot id (they start at 1), so a non-scalar falls into the
	// "no longer available" branch below and says so.
	$id = is_scalar( $_POST['blueline_restore_snapshot'] ) ? absint( wp_unslash( $_POST['blueline_restore_snapshot'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by blueline_settings_require_manage_options_and_nonce() immediately above.

	if ( blueline_settings_snapshot_restore( $id ) ) {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_restored',
			// "Any values it replaced" rather than "the values it
			// replaced": restoring a copy the settings ALREADY match
			// replaces nothing and records nothing (see
			// blueline_settings_snapshot_on_save()), which is exactly what
			// happens if this page is refreshed after a restore.
			esc_html__( 'Those settings were restored. Any values it replaced were recorded below first, so a restore can itself be undone.', 'blueline' ),
			'success'
		);
		return;
	}

	add_settings_error(
		BLUELINE_SETTINGS_OPTION,
		'blueline_settings_restore_missing',
		esc_html(
			sprintf(
				/* translators: %d: how many saved copies are kept. */
				__( 'That saved copy is no longer available: only the last %d are kept, so nothing was changed.', 'blueline' ),
				BLUELINE_SETTINGS_SNAPSHOT_LIMIT
			)
		),
		'error'
	);
}

/**
 * Render the "Recent saves" list: every stored snapshot, newest first,
 * each with its own nonce-protected restore control.
 *
 * The copy here makes three claims, and tests/SettingsSnapshotsTest.php
 * pins one test on each, named here so a future edit to either side can
 * be checked against the other:
 *
 * - "through the same checks an ordinary save uses":
 *   test_restoring_an_invalid_value_keeps_the_stored_one_and_reports_it()
 *   plants a value the sanitizer refuses into a snapshot (which nothing
 *   validates on the way in) and proves the restore refuses it too, keeping
 *   the stored value and reporting the refusal.
 * - "records the current values first, so a restore can itself be undone":
 *   test_a_restore_is_itself_undoable().
 * - "a setting a saved copy does not carry keeps its current value":
 *   test_restore_leaves_a_key_the_snapshot_does_not_carry_alone(). This is
 *   the merge-not-replace behaviour every write to this option has
 *   (inc/settings/store.php), and an admin told "this puts the settings
 *   back exactly as they were" would be told something this code does not
 *   do.
 *
 * Each row is its own small <form> posting back to the current tab's URL,
 * rather than one form with several submit buttons, so the id being
 * restored is unambiguous in the submitted request.
 *
 * @return void
 */
function blueline_settings_render_snapshots(): void {
	$snapshots = blueline_settings_snapshot_list();
	?>
	<section class="bl-settings-history">
		<h2><?php echo esc_html( __( 'Recent saves', 'blueline' ) ); ?></h2>

		<?php if ( empty( $snapshots ) ) : ?>
			<p><?php echo esc_html( __( 'No saves recorded yet. The next time these settings are saved, the values it replaces are recorded here.', 'blueline' ) ); ?></p>
		<?php else : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: how many saves are kept. */
						__( 'Every save that changes something records the values it replaced; the last %d are kept. Restoring one writes those values back through the same checks an ordinary save uses, and records the current values first, so a restore can itself be undone. A setting a saved copy does not carry keeps its current value rather than being reset.', 'blueline' ),
						BLUELINE_SETTINGS_SNAPSHOT_LIMIT
					)
				);
				?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( __( 'Recorded', 'blueline' ) ); ?></th>
						<th scope="col"><?php echo esc_html( __( 'Settings in this copy', 'blueline' ) ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php echo esc_html( __( 'Restore', 'blueline' ) ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $snapshots as $snapshot ) : ?>
						<tr>
							<td><?php echo esc_html( blueline_settings_snapshot_time_label( $snapshot['time'] ) ); ?></td>
							<td><?php echo esc_html( (string) count( $snapshot['settings'] ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( blueline_settings_tab_url( blueline_settings_current_tab() ) ); ?>">
									<?php wp_nonce_field( 'blueline_settings_restore' ); ?>
									<button
										type="submit"
										class="button"
										name="blueline_restore_snapshot"
										value="<?php echo esc_attr( (string) $snapshot['id'] ); ?>"
									>
										<?php echo esc_html( __( 'Restore', 'blueline' ) ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
	<?php
}
