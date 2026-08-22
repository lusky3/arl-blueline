<?php
/**
 * Page-cache purge on settings save -- shipped OFF by default.
 *
 * This site sits behind a Redis-backed nginx srcache page cache. When the
 * control panel changes a setting, any already-cached page reflecting the
 * old value must be purged or the front end keeps serving stale HTML.
 * Research established the mechanism: the Redis Object Cache drop-in
 * exposes a public redis_instance() returning the raw client, and
 * SCAN + UNLINK over `nginx-cache:*<host>*` is the right key shape.
 *
 * BUT there is an unverified infrastructure assumption underneath that
 * mechanism: whether the Redis server *and logical database* the WordPress
 * object cache connects to is the SAME one nginx's srcache module writes
 * page-cache entries into. Hardened production setups routinely separate
 * them (different `SELECT` index, sometimes a different Redis instance
 * entirely). If they differ here, the guarded purge below connects fine,
 * runs SCAN against a keyspace that simply never contains nginx's cache
 * keys, finds zero matches, and reports success while purging nothing --
 * which is strictly worse than not purging at all, because the admin who
 * just saved settings is told the front end is current when it is not.
 *
 * That fact cannot be settled by reading this repository. It is an
 * `nginx.conf` / `wp-config.php` fact about the actual server. Staging has
 * no page-cache layer at all, so a green staging run cannot distinguish
 * "the purge worked" from "there was nothing to purge either way" --
 * this is the one component staging structurally cannot validate.
 *
 * So: the guarded purge is fully implemented below, but gated behind the
 * `BLUELINE_SRCACHE_PURGE` constant, which defaults to `false`. While off,
 * blueline_flush_page_cache() never touches Redis at all -- it only records
 * that a manual purge is needed and shows a persistent wp-admin notice
 * naming the exact command to run.
 *
 * ## Checklist before ever defining BLUELINE_SRCACHE_PURGE as true
 *
 * Whoever flips this constant must first confirm, on the actual server
 * (not by reading this repo):
 *
 * 1. Which Redis server and logical DB index nginx's srcache module writes
 *    into -- read `srcache_store`/`redis2_query` (or equivalent) directives
 *    and the `redis_pass`/upstream block in `nginx.conf`.
 * 2. Which Redis server and logical DB index the WordPress object cache
 *    (the Redis Object Cache drop-in) connects to -- read the
 *    `WP_REDIS_HOST` / `WP_REDIS_PORT` / `WP_REDIS_DATABASE` (or
 *    equivalent) constants in `wp-config.php`.
 * 3. That both of the above name the SAME host/port AND the SAME DB index
 *    -- not merely the same host. A shared host with different `SELECT`
 *    indexes is exactly the hardened setup that makes this purge silently
 *    purge nothing.
 * 4. Only once (1)-(3) are confirmed identical, define BLUELINE_SRCACHE_PURGE
 *    as true (e.g. in wp-config.php) and verify on that server, by hand,
 *    that a save actually evicts a known cached page before trusting it
 *    unattended.
 *
 * This same checklist is mirrored in docs/DESIGN.md's Contributing section
 * so the person doing that verification does not have to reconstruct this
 * reasoning from scratch.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BLUELINE_SRCACHE_PURGE' ) ) {
	/**
	 * Master switch for the guarded Redis/nginx srcache purge. Defaults to
	 * `false` -- see this file's docblock for exactly why, and the
	 * checklist that must be completed before a deploy ever defines this
	 * as `true` (in wp-config.php, ahead of this theme loading).
	 */
	define( 'BLUELINE_SRCACHE_PURGE', false );
}

/**
 * Option name for the "a manual page-cache purge is needed" flag. Written
 * whenever a save could not be automatically purged (the common, default
 * case) and cleared once the guarded purge actually runs. Deliberately a
 * plain option, not a transient: the notice it drives must persist across
 * page loads and outlive any object-cache TTL until an admin acts on it,
 * not quietly disappear on its own.
 */
const BLUELINE_CACHE_PURGE_NEEDED_OPTION = 'blueline_cache_purge_needed';

add_action( 'update_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_flush_page_cache', 10, 3 );
add_action( 'add_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_flush_page_cache_on_first_save', 10, 2 );

/**
 * Hooked to `update_option_{BLUELINE_SETTINGS_OPTION}` -- fires after the
 * settings panel's one option is actually written, PROVIDED the option
 * already existed before this save (core's own post-write hook for that
 * case). Never fires on a no-op save the Settings API short-circuited, and
 * -- this is the one core footgun this pair of hooks exists to work around
 * -- never fires on the very FIRST write to the option either; see
 * blueline_flush_page_cache_on_first_save()'s docblock for that case.
 *
 * All the decision logic lives in blueline_apply_cache_purge_policy(),
 * which takes the enabled/disabled flag as a plain parameter rather than
 * reading the BLUELINE_SRCACHE_PURGE constant itself -- constants cannot be
 * redefined, so a parameter is what lets tests exercise both the "on" and
 * "off" branches of the policy in the same process while this function
 * alone still reflects the real, deployed constant value.
 *
 * One save is deliberately NOT purged for: one whose only difference from
 * the stored value is a bookkeeping key
 * (blueline_settings_diff_is_bookkeeping_only(), inc/settings/store.php).
 * Nothing any visitor can see derives from `_schema` or
 * `_validated_against`, so no cached page can have gone stale because one
 * of them moved -- and inc/settings/validation.php's deploy-drift check
 * writes `_validated_against` back on every deploy that touches style.css
 * or contrast-rules.json, which would otherwise raise the persistent
 * "manual cache purge pending" notice on a wp-admin screen with nothing
 * behind it that the admin did or could act on. This is the same judgement
 * core already makes for a true no-op save (which never fires this hook at
 * all), widened by exactly those two keys: a save that changes ANY other
 * key still purges, including one carrying an incidental
 * `_validated_against` update alongside a real change.
 *
 * @param mixed  $old_value The value before this save.
 * @param mixed  $new_value The value just written.
 * @param string $option    The option name (unused; this callback is only
 *                          ever bound to one option).
 * @return void
 */
function blueline_flush_page_cache( $old_value, $new_value, $option ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with the update_option_{$option} hook's 3-argument dispatch; $option itself is never needed, this callback being bound to one option only.
	if ( is_array( $old_value ) && is_array( $new_value )
		&& blueline_settings_diff_is_bookkeeping_only( $old_value, $new_value ) ) {
		return;
	}

	blueline_maybe_purge_page_cache();
}

/**
 * Hooked to `add_option_{BLUELINE_SETTINGS_OPTION}` -- covers the ONE case
 * blueline_flush_page_cache() above cannot: core's real update_option()
 * does not fire `update_option_{$option}` on the very first write to an
 * option that does not already exist in the database. Internally it
 * delegates that first write to add_option(), which fires
 * `add_option_{$option}` instead (verified against a faithful stub in
 * BootstrapFidelityTest::test_update_option_fires_add_option_hook_on_first_write_only()).
 * Without this second hook, the settings panel's very first-ever save on a
 * fresh install -- when `BLUELINE_SETTINGS_OPTION` genuinely does not
 * exist yet -- would silently skip the purge-or-notice policy entirely.
 *
 * That is not a negligible edge case: a fresh install can already have
 * visitor-facing pages cached against the schema's DEFAULTS (nothing
 * about a persistent page cache requires the settings option to exist
 * first), and the first real save is exactly the moment those defaults
 * change under already-cached HTML -- precisely the scenario this whole
 * file exists to catch. Hooking both `add_option_{$option}` and
 * `update_option_{$option}` (rather than leaving the gap documented) is
 * the safer of the two defensible choices for that reason.
 *
 * @param string $option The option name (unused; see
 *                        blueline_flush_page_cache()'s docblock for why).
 * @param mixed  $value  The value just added (unused; see above).
 * @return void
 */
function blueline_flush_page_cache_on_first_save( $option, $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with the add_option_{$option} hook's 2-argument dispatch; the purge decision itself needs neither.
	blueline_maybe_purge_page_cache();
}

/**
 * Shared entry point for both blueline_flush_page_cache() (any save after
 * the option already exists) and blueline_flush_page_cache_on_first_save()
 * (the very first save) -- see blueline_apply_cache_purge_policy() for the
 * actual decision either one triggers.
 *
 * @return void
 */
function blueline_maybe_purge_page_cache(): void {
	blueline_apply_cache_purge_policy( BLUELINE_SRCACHE_PURGE );
}

/**
 * The actual purge-or-notice decision, factored out of
 * blueline_flush_page_cache() so a test can exercise the "enabled" branch
 * without needing to redefine the BLUELINE_SRCACHE_PURGE constant (which
 * PHP does not allow).
 *
 * - `$purge_enabled` false (the shipped default): never touch Redis at
 *   all -- just record that a manual purge is needed.
 * - `$purge_enabled` true: attempt the guarded purge. If it actually ran
 *   (every guard passed), clear any pending manual-purge notice. If any
 *   guard failed -- no object cache, wrong shape, no resolvable host --
 *   degrade to exactly the same manual notice the disabled path shows,
 *   rather than fail loudly or silently do nothing. This is also what
 *   keeps a site with the constant flipped on but a differently-shaped (or
 *   temporarily unavailable) object cache from fataling on save.
 *
 * @param bool $purge_enabled Whether the guarded Redis purge should be attempted.
 * @return void
 */
function blueline_apply_cache_purge_policy( bool $purge_enabled ): void {
	if ( ! $purge_enabled ) {
		blueline_mark_cache_purge_needed();
		return;
	}

	if ( blueline_srcache_purge_attempt() ) {
		blueline_clear_cache_purge_needed();
		return;
	}

	blueline_mark_cache_purge_needed();
}

/**
 * The guarded Redis/nginx srcache purge itself. Never called unless
 * BLUELINE_SRCACHE_PURGE is true (via blueline_apply_cache_purge_policy()).
 *
 * Guards, checked in this exact order, each a precondition for the next:
 *
 * 1. `is_object( $wp_object_cache )` -- the global the Redis Object Cache
 *    drop-in populates; absent entirely on a site with no persistent
 *    object cache.
 * 2. `method_exists( $wp_object_cache, 'redis_instance' )` -- only that
 *    specific drop-in exposes the raw client this way; a different object
 *    cache backend (or no drop-in) does not.
 * 3. `is_object( $redis )` -- redis_instance() returning something falls
 *    short of proving it returned a usable client object.
 * 4. A non-empty `blueline_cache_purge_host()` -- an unparseable
 *    `home_url()` would otherwise build the pattern `nginx-cache:**`,
 *    which matches (and would purge) EVERY key in that Redis, not just
 *    this site's -- see blueline_cache_purge_command()'s docblock for the
 *    matching guard on the manual-purge notice's command text.
 *
 * Any guard failing returns `false` immediately -- no partial purge is
 * attempted, and nothing here can fatal a save on a site that simply
 * doesn't have this object-cache shape.
 *
 * Uses SCAN (cursor-based), NEVER KEYS: KEYS blocks Redis's single-threaded
 * event loop for as long as the full keyspace scan takes, which on a large
 * keyspace can stall every other client (including real visitor traffic)
 * for the duration. SCAN yields the same eventual result in bounded-size
 * batches instead.
 *
 * Prefers UNLINK (asynchronous reclaim, off the main thread) over DEL
 * (synchronous, blocking), falling back to DEL only when the client object
 * doesn't expose an `unlink` method -- UNLINK requires Redis >= 4.0, and a
 * `method_exists()` check is the cheap way to stay correct against an
 * older server without a version probe of its own.
 *
 * The host is derived from `wp_parse_url( home_url(), PHP_URL_HOST )`
 * rather than hardcoded, so this keeps working correctly if the site's
 * domain ever changes without anyone having to remember to update this file.
 *
 * @return bool True if the purge actually ran (every guard passed and at
 *              least one SCAN cursor cycle completed), false if any guard
 *              failed and nothing was attempted.
 */
function blueline_srcache_purge_attempt(): bool {
	global $wp_object_cache;

	if ( ! is_object( $wp_object_cache ) ) {
		return false;
	}

	if ( ! method_exists( $wp_object_cache, 'redis_instance' ) ) {
		return false;
	}

	$redis = $wp_object_cache->redis_instance();

	if ( ! is_object( $redis ) ) {
		return false;
	}

	$host = blueline_cache_purge_host();
	if ( '' === $host ) {
		return false;
	}

	$pattern    = 'nginx-cache:*' . $host . '*';
	$use_unlink = method_exists( $redis, 'unlink' );

	$cursor          = 0;
	$max_iterations  = 10000; // Defensive cap only -- a well-behaved SCAN converges to cursor 0 long before this; this exists solely so a misbehaving client can never spin this loop forever.
	$iterations_done = 0;

	do {
		$keys = $redis->scan( $cursor, $pattern, 1000 );
		++$iterations_done;

		if ( false === $keys ) {
			break; // phpredis returns false on a SCAN error -- stop rather than loop on a broken cursor.
		}

		if ( ! empty( $keys ) ) {
			if ( $use_unlink ) {
				$redis->unlink( $keys );
			} else {
				$redis->del( $keys );
			}
		}
	} while ( 0 !== $cursor && $iterations_done < $max_iterations );

	return true;
}

/**
 * The host this site's cached pages are keyed by, for building both the
 * SCAN pattern and the manual-purge command shown to admins.
 *
 * @return string The host name, or '' if home_url() somehow yields none.
 */
function blueline_cache_purge_host(): string {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return is_string( $host ) ? $host : '';
}

/**
 * The exact shell command an admin should run on the server to purge the
 * page cache by hand -- shown verbatim in the manual-purge notice, and
 * kept in the same SCAN-based shape as the guarded purge above (never
 * KEYS) so it is safe to run against a large production keyspace too.
 *
 * Refuses, on principle, to ever build a command with an empty `$host`:
 * `nginx-cache:**` (the pattern an unguarded sprintf() would produce)
 * matches -- and this command would UNLINK -- every key in that Redis,
 * not just this site's, including any other site sharing the same
 * instance. Unlike blueline_srcache_purge_attempt()'s equivalent guard,
 * this one matters MORE here, not less: this command is copy-pasted and
 * run by a human who trusts what the panel told them, so a wrong command
 * shown here has a worse blast radius than the same wrong precondition
 * silently skipping the automated path.
 *
 * @param string $host Host to scope the purge to (see blueline_cache_purge_host()).
 * @return string The command, ready to paste into a shell on the server, or
 *                '' if $host is empty -- callers MUST treat '' as "no safe
 *                command exists" and show blueline_cache_purge_unresolvable_host_message()
 *                instead, never fall back to an empty-host pattern.
 */
function blueline_cache_purge_command( string $host ): string {
	if ( '' === $host ) {
		return '';
	}

	return sprintf(
		"redis-cli --scan --pattern 'nginx-cache:*%s*' | xargs -r redis-cli unlink",
		$host
	);
}

/**
 * Shown in place of the purge command when blueline_cache_purge_host()
 * could not resolve a host -- see blueline_cache_purge_command()'s
 * docblock for why a command is never shown in that case, even though the
 * unsafe pattern could technically still be built.
 *
 * @return string
 */
function blueline_cache_purge_unresolvable_host_message(): string {
	return __( 'This site\'s URL could not be parsed, so a safe purge command could not be generated. Contact your host to purge the Redis-backed page cache manually.', 'blueline' );
}

/**
 * Record that a manual page-cache purge is needed -- the persistent flag
 * the admin notice below is conditioned on. A plain option (see
 * BLUELINE_CACHE_PURGE_NEEDED_OPTION's own docblock for why not a
 * transient): it must survive until an admin dismisses it, not expire on
 * its own.
 *
 * @return void
 */
function blueline_mark_cache_purge_needed(): void {
	update_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, true );
}

/**
 * Clear the "manual purge needed" flag -- called either when the guarded
 * purge actually ran, or when an admin dismisses the notice after purging
 * by hand.
 *
 * @return void
 */
function blueline_clear_cache_purge_needed(): void {
	delete_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION );
}

/**
 * Whether the "manual purge needed" notice should currently be shown.
 *
 * @return bool
 */
function blueline_cache_purge_needed(): bool {
	return (bool) get_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, false );
}

/**
 * The admin-facing explanation shown above the purge command -- kept as
 * its own function (rather than inlined into the notice markup) so a test
 * can assert its exact wording without needing a full wp-admin render.
 *
 * @return string
 */
function blueline_cache_purge_notice_message(): string {
	return __( 'Blueline settings were saved, but the page cache was not purged automatically -- the front end will keep serving the old version of any page these settings affect until it is. Run this on the server to clear it:', 'blueline' );
}

add_action( 'admin_notices', 'blueline_render_cache_purge_notice' );
/**
 * Render the persistent "manual purge needed" admin notice: shown on every
 * wp-admin screen (not just the settings panel's own page, since a
 * volunteer admin may not return to that screen for a while) until either
 * the guarded purge runs or the admin dismisses it via
 * blueline_handle_dismiss_cache_purge_notice().
 *
 * Gated on `manage_options` -- the same capability the settings panel
 * itself requires -- so a lower-privileged user who happens to load
 * wp-admin never sees an instruction they have no ability to act on.
 *
 * All dynamic output (the message and the command, both of which
 * ultimately derive from this site's own host name) is escaped with
 * esc_html(): this is wp-admin output, and unescaped content there is
 * stored XSS.
 *
 * When the host can't be resolved, NO command is rendered at all -- see
 * blueline_cache_purge_command()'s docblock for why an empty-host command
 * must never be shown, even as a fallback. This is the same guard
 * blueline_srcache_purge_attempt() applies before it will attempt the
 * automated purge, applied here to the manual instruction instead.
 *
 * Rendered as a `<section>`, deliberately NOT a `<div>` -- a real browser
 * check (Task 7's fix rounds) found a third-party plugin active on this
 * install (Capabilities Pro's admin-notices "declutter" module) removes,
 * on every wp-admin screen, any `<div>` whose class attribute contains
 * "notice", "error", "warning", "info" or "updated" as a substring. This
 * notice's own `notice notice-warning` classes match that pattern
 * exactly -- and with the guarded Redis purge shipped OFF by default (see
 * this file's own docblock), this notice, including its dismiss button,
 * IS the entire shipped behaviour of the cache-purge requirement: the
 * only thing that would ever tell an admin the front end is stale. A
 * `<section>` carries the identical classes (wp-admin's `.notice` CSS is
 * a plain class selector with no tag qualifier, so styling is unaffected)
 * and is never matched by that plugin's `div[...]`-scoped selector.
 *
 * @return void
 */
function blueline_render_cache_purge_notice(): void {
	if ( ! blueline_cache_purge_needed() ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$message = blueline_cache_purge_notice_message();
	$command = blueline_cache_purge_command( blueline_cache_purge_host() );
	$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=blueline_dismiss_cache_purge_notice' ), 'blueline_dismiss_cache_purge_notice' );
	?>
	<section class="notice notice-warning bl-cache-purge-notice">
		<p><?php echo esc_html( $message ); ?></p>
		<?php if ( '' !== $command ) : ?>
			<p><code><?php echo esc_html( $command ); ?></code></p>
		<?php else : ?>
			<p><?php echo esc_html( blueline_cache_purge_unresolvable_host_message() ); ?></p>
		<?php endif; ?>
		<p>
			<a class="button" href="<?php echo esc_url( $dismiss ); ?>">
				<?php esc_html_e( "I've purged the cache manually -- dismiss this notice", 'blueline' ); ?>
			</a>
		</p>
	</section>
	<?php
}

add_action( 'admin_post_blueline_dismiss_cache_purge_notice', 'blueline_handle_dismiss_cache_purge_notice' );
/**
 * Handle the notice's dismiss link: only ever reached after an admin has
 * (per the notice's own text) purged the cache by hand, so clearing the
 * flag here is safe -- this is the only path that CLEARS it (besides a successful guarded
 * purge) that clears BLUELINE_CACHE_PURGE_NEEDED_OPTION.
 *
 * @return void
 */
function blueline_handle_dismiss_cache_purge_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'blueline' ), 403 );
	}

	check_admin_referer( 'blueline_dismiss_cache_purge_notice' );

	blueline_clear_cache_purge_needed();

	$redirect = wp_get_referer();
	wp_safe_redirect( $redirect ? $redirect : admin_url() );
	exit;
}
