<?php
/**
 * "Delete all Blueline data" -- the explicit teardown: themes have no
 * uninstall hook, so this ships as an explicit 'Delete all Blueline data'
 * action plus a WP-CLI equivalent.
 *
 * NOT the same operation as `wp blueline settings reset`, and the difference
 * matters. `reset`
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
 *   - BLUELINE_SP_CACHE_GEN_OPTION        the SportsPress data-cache generation
 *   - the `blueline_season_state` transient (a 15-minute cache, but leaving a
 *     stale one behind after deleting everything else would be untidy at best
 *     and confusing at worst)
 *   - every `bl_*` transient (the roster-stat and league-table caches built
 *     by blueline_sp_cache_key())
 *   - the updater's `blueline_updater_state` site transient
 *   - the scheduled `blueline_occasion_boundary_purge` cron event, which would
 *     otherwise fire into a theme that may no longer be active
 *
 * NOT DELETED -- user content and other plugins' data that merely passes
 * through this theme:
 *   - BLUELINE_THEME_PREFERENCE_META_KEY (`blueline_theme_preference`), a
 *     member's own light/dark choice stored on their user account. It is their
 *     setting, not the site's configuration, and the panel copy says it stays.
 *   - BLUELINE_AVATAR_META_KEY (`blueline_avatar_id`), defined and written by
 *     the blueline-core plugin. This points at a real media attachment a member
 *     uploaded. It is their content, not our
 *     configuration, and the attachment itself would survive regardless, so
 *     dropping the pointer would orphan the file rather than clean anything up.
 *   - `sp_user` (BLUELINE_PLAYER_USER_META), which the blueline-core plugin
 *     reads and writes. The key belongs to SportsPress -- it is how SportsPress
 *     itself links a player post to a user, and SportsPress keeps using it
 *     after this theme is gone.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

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
		BLUELINE_SP_CACHE_GEN_OPTION,
	);
}

/**
 * Delete every `bl_*` data-cache transient this theme created through
 * blueline_sp_cache_key() (rosters, league tables), and their timeout rows.
 *
 * Direct SQL because WordPress cannot enumerate transients by prefix. `bl_` is
 * this theme's own prefix for those keys (see blueline_sp_cache_key()). It is
 * escaped through esc_like(), since `_` is a LIKE wildcard and an unescaped
 * `_transient_bl_` would also match `_transient_blX...`.
 *
 * Does nothing when an external object cache is in use: transients then live
 * in the cache rather than in the options table, cannot be enumerated here, and
 * expire on their own within BLUELINE_SP_CACHE_TTL (15 minutes).
 *
 * @return int How many rows were removed.
 */
function blueline_settings_delete_cache_transients(): int {
	global $wpdb;

	if ( wp_using_ext_object_cache() || ! isset( $wpdb ) ) {
		return 0;
	}

	$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress cannot enumerate transients by prefix, and the rows are being deleted, so there is nothing to cache.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bl_' ) . '%'
		)
	);

	return is_int( $deleted ) ? $deleted : 0;
}

/**
 * Delete every option this theme's settings layer owns, plus the theme's
 * transients and its scheduled cron event, and report what was actually
 * removed.
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
 * the most confusing possible end state. The general "delete_option() bypasses
 * the purge" gap is wider than this one path.
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

	if ( defined( 'BLUELINE_UPDATER_CACHE_KEY' ) && delete_site_transient( BLUELINE_UPDATER_CACHE_KEY ) ) {
		$deleted[] = BLUELINE_UPDATER_CACHE_KEY;
	}

	$cache_rows = blueline_settings_delete_cache_transients();

	if ( $cache_rows > 0 ) {
		$deleted[] = sprintf( 'bl_* cache transients (%d rows)', $cache_rows );
	}

	// Removed after the options above so nothing in this function can
	// re-schedule it: delete_option() fires neither add_option_ nor
	// update_option_, the hooks that (re)schedule the boundary purge.
	if ( false !== wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ) ) {
		blueline_occasion_clear_scheduled_boundary_purge();
		$deleted[] = BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK;
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
 * This is what `advanced_enabled` gates, and the pairing is the point: Advanced
 * is a "here be dragons" disclosure affordance, and this is the
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
				__( 'Removes every setting on this page, the saved copies under "Recent saves", the theme\'s cached roster and league-table data, its update-check result and its scheduled occasion cache refresh. Your pages, posts, players, events, photographs and member accounts are untouched, and so is each member\'s own light or dark choice -- this deletes the theme\'s own settings and caches, not your content.', 'blueline' )
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
			esc_html__( 'Nothing was deleted: the confirmation box was not ticked.', 'blueline' ),
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
			esc_html__( 'There was nothing to delete: no Blueline settings were stored.', 'blueline' ),
			'warning'
		);
	} else {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_deleted',
			$result['purge_ran']
				? esc_html__( 'All Blueline data deleted. The site is now using the built-in defaults.', 'blueline' )
				: esc_html__( 'All Blueline data deleted. The site is now using the built-in defaults. The page cache was not purged, so visitors may keep seeing the old settings until it is purged by hand.', 'blueline' ),
			'success'
		);
	}

	set_transient( 'settings_errors', get_settings_errors(), 30 );
	wp_safe_redirect( blueline_settings_tab_url( blueline_settings_current_tab() ) );
	exit;
}
