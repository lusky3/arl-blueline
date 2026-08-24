<?php
/**
 * "Delete all Blueline data" -- the explicit teardown the spec asks for at
 * section 6.7: "Themes have no uninstall hook, so ship an explicit 'Delete all
 * Blueline data' action plus a WP-CLI equivalent, and add it to the cutover
 * checklist."
 *
 * NOT the same operation as `wp blueline settings reset`, and the difference
 * matters enough that the spec draws it explicitly at section 6.8. `reset`
 * WRITES every field's default into the option, so the option still exists and
 * still carries a full settings array. This DELETES the option, so nothing is
 * stored at all and blueline_settings() falls back to blueline_settings_defaults()
 * because there is nothing to merge over it. The rendered site looks identical
 * either way; the database does not, and a rollback to `rookie-child` is a live
 * scenario in which "no Blueline rows at all" is the thing being asked for.
 *
 * WHAT THIS DELETES, and what it deliberately does not, because a teardown that
 * is vague about its own scope is worse than one that does less:
 *
 * DELETED -- everything this theme's settings layer created:
 *   - BLUELINE_SETTINGS_OPTION            the settings themselves
 *   - BLUELINE_SETTINGS_SNAPSHOTS_OPTION  the save-snapshot undo history
 *   - BLUELINE_CACHE_PURGE_NEEDED_OPTION  the "a purge is owed" flag
 *   - the `blueline_season_state` transient (a 15-minute cache, but leaving a
 *     stale one behind after deleting everything else would be untidy at best
 *     and confusing at worst)
 *
 * NOT DELETED -- user content and other plugins' data that merely passes
 * through this theme:
 *   - BLUELINE_AVATAR_META_KEY (`blueline_avatar_id`). This points at a real
 *     media attachment a member uploaded. It is their content, not our
 *     configuration, and the attachment itself would survive regardless, so
 *     dropping the pointer would orphan the file rather than clean anything up.
 *   - `sp_user` (BLUELINE_PLAYER_USER_META). Despite this theme reading and
 *     writing it, the key belongs to SportsPress -- it is how SportsPress
 *     itself links a player post to a user, and SportsPress keeps using it
 *     after this theme is gone.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The transient this teardown clears alongside the options.
 *
 * Named here rather than reached for inline so the list below and
 * inc/season-state.php's own set_transient() call cannot drift apart silently:
 * a rename in one place with no rename here would leave a stale cache behind
 * after a "delete everything", which is exactly the kind of quiet
 * incompleteness this function exists to avoid.
 *
 * @var string
 */
const BLUELINE_SEASON_STATE_TRANSIENT = 'blueline_season_state';

/**
 * Every option name "delete all Blueline data" removes.
 *
 * One list, one place, so the admin action, the WP-CLI subcommand and the tests
 * all read the SAME set. A future option added to the settings layer has
 * exactly one line to add here, and
 * SettingsDeleteDataTest::test_every_settings_layer_option_constant_is_covered()
 * is what fails when that line is forgotten: it scans inc/settings/ for
 * `const BLUELINE_*OPTION` declarations rather than comparing this list to
 * itself, which is the only version of that check that can catch an omission.
 *
 * @return string[] Option names, in the order they are deleted.
 */
function blueline_settings_deletable_options(): array {
	return array(
		BLUELINE_SETTINGS_OPTION,
		BLUELINE_SETTINGS_SNAPSHOTS_OPTION,
		BLUELINE_CACHE_PURGE_NEEDED_OPTION,
	);
}

/**
 * Delete every option this theme's settings layer owns, plus the season-state
 * transient, and report what was actually removed.
 *
 * Reports what was REMOVED, not what was attempted: `delete_option()` returns
 * false for an option that was not there, and an honest teardown says "three
 * rows deleted" or "nothing was stored" rather than claiming three either way.
 * An admin running this twice should be told the second run found nothing.
 *
 * Purges the page cache itself, because `delete_option()` fires neither of the
 * hooks the purge listens on -- it fires `delete_option`/`deleted_option`, not
 * `update_option_{$option}`/`add_option_{$option}` (see inc/settings/cache.php).
 * Without that, deleting every setting would leave the old settings still
 * rendering from cache with nothing in the database to explain them, which is
 * the most confusing possible end state. This closes P1a's deferred item 7 for
 * this path; the general "delete_option() bypasses the purge" gap is wider.
 *
 * It calls blueline_srcache_purge_attempt() DIRECTLY rather than going through
 * blueline_maybe_purge_page_cache(), and that detour is the whole reason this
 * is worth a paragraph: when purging is disabled or fails, the normal path
 * calls blueline_mark_cache_purge_needed(), which does
 * `update_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, true )` -- RE-CREATING an
 * option this function has just deleted, so "delete all Blueline data" would
 * leave a Blueline row behind. Beyond the tidiness, that marker exists only to
 * drive a Blueline admin notice, and after a teardown there is no Blueline
 * left to show it. So the failure is reported to the CALLER instead, which can
 * tell the admin to purge by hand while the panel is still on screen.
 *
 * @return array{deleted: string[], purge_ran: bool} The option names (and the
 *               transient name, if it was present) actually removed, and
 *               whether the cache purge actually succeeded.
 */
function blueline_settings_delete_all_data(): array {
	$deleted = array();

	foreach ( blueline_settings_deletable_options() as $option ) {
		if ( delete_option( $option ) ) {
			$deleted[] = $option;
		}
	}

	if ( delete_transient( BLUELINE_SEASON_STATE_TRANSIENT ) ) {
		$deleted[] = BLUELINE_SEASON_STATE_TRANSIENT;
	}

	// Attempted unconditionally: the stored settings are gone either way, so any
	// cached page rendered from them is stale whether or not a row happened to
	// exist a moment ago.
	$purge_ran = BLUELINE_SRCACHE_PURGE && blueline_srcache_purge_attempt();

	return array(
		'deleted'   => $deleted,
		'purge_ran' => $purge_ran,
	);
}

/**
 * Render the "Delete all Blueline data" control -- but only when the Advanced
 * toggle is on.
 *
 * This is what `advanced_enabled` gates, and the pairing is the point: the spec
 * calls Advanced a "here be dragons" disclosure affordance, and this is the
 * most dragon-like control the panel has. Hiding it by default costs an admin
 * who genuinely wants it one extra checkbox, and stops everyone else from
 * finding it next to the harmless-looking export button.
 *
 * It is a DISCLOSURE, not a lock, and the copy on the toggle itself says so.
 * Anyone who can see this page holds `manage_options` and could delete these
 * rows by other means; hiding the button protects against the slip, not
 * against the determined.
 *
 * @return void
 */
function blueline_settings_render_delete_all_data(): void {
	if ( ! blueline_settings( 'advanced_enabled' ) ) {
		return;
	}
	?>
	<section class="bl-settings-data bl-settings-data--danger">
		<h2><?php echo esc_html( __( 'Delete all Blueline data', 'blueline' ) ); ?></h2>

		<p>
			<?php
			echo esc_html(
				__( 'Removes every setting on this page, the saved copies under "Recent saves", and nothing else. Your pages, posts, players, events, photographs and member accounts are untouched -- this deletes the theme\'s own settings, not your content.', 'blueline' )
			);
			?>
		</p>

		<p>
			<?php
			echo esc_html(
				__( 'This is not the same as resetting. Resetting puts every field back to its default and leaves the saved copies in place, so you can undo it. This deletes the saved copies too: afterwards there is nothing to restore from except a database backup. The site keeps working and falls back to the same defaults either way.', 'blueline' )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'blueline_settings_delete_all_data' ); ?>
			<input type="hidden" name="action" value="blueline_settings_delete_all_data">
			<p>
				<label>
					<input type="checkbox" name="blueline_delete_confirm" value="1">
					<?php echo esc_html( __( 'Yes, delete all Blueline settings and their saved copies', 'blueline' ) ); ?>
				</label>
			</p>
			<button type="submit" class="button button-secondary">
				<?php echo esc_html( __( 'Delete all Blueline data', 'blueline' ) ); ?>
			</button>
		</form>
	</section>
	<?php
}

add_action( 'admin_post_blueline_settings_delete_all_data', 'blueline_settings_handle_delete_all_data' );

/**
 * Handle the "Delete all Blueline data" submission.
 *
 * Three gates, in the order that fails most cheaply first: capability, nonce,
 * then the explicit confirmation checkbox. The checkbox is not ceremony -- it
 * is the only one of the three a logged-in admin cannot satisfy by accident,
 * since both of the others are satisfied merely by being who they already are
 * and clicking something on a page they already have open.
 *
 * @return void
 */
function blueline_settings_handle_delete_all_data(): void {
	blueline_settings_require_manage_options_and_nonce( 'blueline_settings_delete_all_data' );

	$confirmed = isset( $_POST['blueline_delete_confirm'] ) && '1' === $_POST['blueline_delete_confirm']; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified by blueline_settings_require_manage_options_and_nonce() immediately above; and this is compared against an exact literal rather than used as text, so there is nothing for a sanitizer to strip.

	if ( ! $confirmed ) {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_delete_unconfirmed',
			__( 'Nothing was deleted: the confirmation box was not ticked.', 'blueline' ),
			'warning'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( blueline_settings_tab_url( blueline_settings_current_tab() ) );
		exit;
	}

	$result = blueline_settings_delete_all_data();

	if ( ! $result['deleted'] ) {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_delete_nothing_stored',
			__( 'There was nothing to delete: no Blueline settings were stored.', 'blueline' ),
			'warning'
		);
	} else {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_deleted',
			$result['purge_ran']
				? __( 'All Blueline data deleted. The site is now using the built-in defaults.', 'blueline' )
				: __( 'All Blueline data deleted. The site is now using the built-in defaults. The page cache was not purged, so visitors may keep seeing the old settings until it is purged by hand.', 'blueline' ),
			'success'
		);
	}

	set_transient( 'settings_errors', get_settings_errors(), 30 );
	wp_safe_redirect( blueline_settings_tab_url( blueline_settings_current_tab() ) );
	exit;
}
