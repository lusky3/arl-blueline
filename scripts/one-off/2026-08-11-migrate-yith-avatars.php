<?php
/**
 * One-off: migrate yith-woocommerce-customize-myaccount-page's custom
 * per-user avatars to theme-owned user meta (`blueline_avatar_id`), so the
 * plugin can be deactivated without silently losing anyone's avatar.
 *
 * IMPORTANT -- corrected premise, verified against staging 2026-08-11:
 *
 * The option `yith_wcmap_users_avatar_ids` is NOT a user_id => attachment_id
 * map. Reading YITH's own source (includes/class-yith-wcmap-avatar.php)
 * shows it is built by `$medias[] = $media_id; update_option(...)` on every
 * avatar upload (a sequential push) and pruned with `unset()` on delete/
 * erase -- a flat, internal bookkeeping list of "every attachment ID this
 * plugin has ever used as *someone's* avatar", kept only so those
 * attachments can be filtered out of other media queries. On staging it
 * currently holds 11 entries whose array KEYS are leftover indices from
 * that push/unset history (2, 4-13 -- three earlier entries were removed),
 * not user IDs; confirmed by checking every one of those "user IDs" against
 * `wp_usermeta` and finding no such link.
 *
 * The real per-user link -- the one `YITH_WCMAP_Avatar::get_user_avatar_id()`
 * actually reads to decide whose avatar is whose -- is user meta
 * `yith-wcmap-avatar`, one row per user who has ever set a custom avatar.
 * On staging this is exactly 10 rows, matching 10 of the option's 11
 * attachment IDs; the 11th (114470 at the time of writing) is an orphan in
 * the bookkeeping list with no owning user (most likely an avatar that was
 * uploaded via the modal's preview step but never confirmed) and therefore
 * has nothing to migrate it to -- it is reported, not migrated.
 *
 * This script therefore reads `wp_usermeta` (`yith-wcmap-avatar`) as the
 * migration source, not the option. The option is still fetched and printed
 * verbatim, purely as evidence/cross-check, and is never written to or
 * deleted -- nor is `yith-wcmap-avatar` itself. This is a copy, not a move:
 * if something is wrong, YITH's own record is untouched and the theme meta
 * can simply be re-derived by running this script again.
 *
 * Idempotent: a user already carrying the correct `blueline_avatar_id`
 * is reported "already migrated" and is not written to again.
 *
 * Usage -- `wp eval-file` only ever hands this file POSITIONAL arguments
 * (via the `$args` array WP-CLI documents for that command); a bare
 * `--dry-run` flag is rejected by WP-CLI's own argument parser before this
 * file is even loaded ("Error: Parameter errors: unknown --dry-run
 * parameter"), verified empirically against this exact staging install.
 * Pass the WORD `dry-run` (no dashes) as a positional argument instead:
 *
 *   wp eval-file - dry-run   < this-file.php   (dry run: prints the table, writes nothing)
 *   wp eval-file -           < this-file.php   (apply: writes blueline_avatar_id)
 *
 * A literal `--dry-run`/`dry-run` string is also honoured if it somehow
 * arrives via `$assoc_args` or the `BLUELINE_DRY_RUN` environment variable,
 * for callers other than a bare `wp eval-file` invocation.
 *
 * @package blueline
 */

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI script, WP_Filesystem isn't available before WordPress has loaded (which is exactly the condition being reported).
	fwrite( STDERR, "This script must be run via `wp eval-file`, not the PHP CLI directly.\n" );
	exit( 1 );
}

$blueline_migrate_avatars_args        = isset( $args ) && is_array( $args ) ? $args : array();
$blueline_migrate_avatars_assoc_args  = isset( $assoc_args ) && is_array( $assoc_args ) ? $assoc_args : array();
$blueline_migrate_avatars_dry_run_env = getenv( 'BLUELINE_DRY_RUN' );

$blueline_migrate_avatars_dry_run = in_array( 'dry-run', $blueline_migrate_avatars_args, true )
	|| in_array( '--dry-run', $blueline_migrate_avatars_args, true )
	|| ! empty( $blueline_migrate_avatars_assoc_args['dry-run'] )
	|| ( false !== $blueline_migrate_avatars_dry_run_env && '' !== $blueline_migrate_avatars_dry_run_env && '0' !== $blueline_migrate_avatars_dry_run_env );

const BLUELINE_MIGRATE_META_KEY = 'blueline_avatar_id';
const BLUELINE_YITH_META_KEY    = 'yith-wcmap-avatar';
const BLUELINE_YITH_OPTION      = 'yith_wcmap_users_avatar_ids';

/**
 * Evidence only -- see the file docblock. Not used as the migration source.
 *
 * @var array<int|string, int>
 */
$blueline_yith_option_value = get_option( BLUELINE_YITH_OPTION, array() );

echo '=== Before-state (evidence, not the migration source) ===' . PHP_EOL;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI STDOUT via `wp eval-file`, not browser-rendered HTML; esc_html() would corrupt the human-readable table.
echo BLUELINE_YITH_OPTION . ' = ' . wp_json_encode( $blueline_yith_option_value ) . PHP_EOL;
echo PHP_EOL;

/**
 * The real source: every user with a non-empty `yith-wcmap-avatar` link.
 *
 * @var WP_User[] $blueline_yith_users
 */
$blueline_yith_users = get_users(
	array(
		'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off migration script, run once, not a request-time query.
			array(
				'key'     => BLUELINE_YITH_META_KEY,
				'value'   => '',
				'compare' => '!=',
			),
		),
		'orderby'    => 'ID',
		'order'      => 'ASC',
		'fields'     => array( 'ID', 'user_login' ),
	)
);

$blueline_migration_rows             = array();
$blueline_option_attachment_ids_seen = array();

foreach ( $blueline_yith_users as $blueline_user ) {
	$user_id       = (int) $blueline_user->ID;
	$attachment_id = absint( get_user_meta( $user_id, BLUELINE_YITH_META_KEY, true ) );

	if ( ! $attachment_id ) {
		continue;
	}

	$blueline_option_attachment_ids_seen[ $attachment_id ] = true;

	$attachment_exists = 'attachment' === get_post_type( $attachment_id );
	$existing_meta     = absint( get_user_meta( $user_id, BLUELINE_MIGRATE_META_KEY, true ) );

	if ( ! $attachment_exists ) {
		$row_status = 'SKIPPED -- attachment ' . $attachment_id . ' no longer exists';
	} elseif ( $existing_meta === $attachment_id ) {
		$row_status = 'already migrated (no-op)';
	} elseif ( $blueline_migrate_avatars_dry_run ) {
		$row_status = 'would migrate';
	} else {
		update_user_meta( $user_id, BLUELINE_MIGRATE_META_KEY, $attachment_id );
		$row_status = 'migrated';
	}

	$blueline_migration_rows[] = array(
		'user_id'       => $user_id,
		'user_login'    => $blueline_user->user_login,
		'attachment_id' => $attachment_id,
		'before'        => $existing_meta ? (string) $existing_meta : '(none)',
		'after'         => $attachment_exists
			? ( $blueline_migrate_avatars_dry_run ? (string) $attachment_id . ' (dry run)' : (string) $attachment_id )
			: '(none)',
		'status'        => $row_status,
	);
}

// Orphans in the option's bookkeeping list -- an attachment ID with no
// owning user's `yith-wcmap-avatar` meta pointing at it. Nothing to
// migrate; reported for transparency only. See the file docblock.
$blueline_orphaned_attachment_ids = array_values(
	array_diff(
		array_map( 'absint', array_values( $blueline_yith_option_value ) ),
		array_keys( $blueline_option_attachment_ids_seen )
	)
);

$blueline_column_widths = array(
	'user_id'       => 7,
	'user_login'    => 16,
	'attachment_id' => 13,
	'before'        => 22,
	'after'         => 22,
	'status'        => 42,
);

$blueline_print_row = static function ( array $row ) use ( $blueline_column_widths ) {
	$cells = array();
	foreach ( $blueline_column_widths as $key => $width ) {
		$cells[] = str_pad( (string) $row[ $key ], $width );
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI STDOUT table row via `wp eval-file`, not browser-rendered HTML.
	echo implode( ' | ', $cells ) . PHP_EOL;
};

echo ( $blueline_migrate_avatars_dry_run ? '=== DRY RUN -- no writes will be made ===' : '=== APPLYING -- writing blueline_avatar_id ===' ) . PHP_EOL;
$blueline_print_row(
	array(
		'user_id'       => 'user_id',
		'user_login'    => 'user_login',
		'attachment_id' => 'attachment_id',
		'before'        => 'before',
		'after'         => 'after',
		'status'        => 'status',
	)
);

foreach ( $blueline_migration_rows as $row ) {
	$blueline_print_row( $row );
}

echo PHP_EOL;
echo 'Rows found (real user -> attachment links): ' . count( $blueline_migration_rows ) . PHP_EOL;

if ( ! empty( $blueline_orphaned_attachment_ids ) ) {
	$blueline_orphan_line = 'Orphaned entries in ' . BLUELINE_YITH_OPTION . ' with no owning user (not migrated -- nothing to attribute them to): '
		. implode( ', ', $blueline_orphaned_attachment_ids );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI STDOUT via `wp eval-file`, not browser-rendered HTML.
	echo $blueline_orphan_line . PHP_EOL;
}

echo PHP_EOL;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI STDOUT via `wp eval-file`, not browser-rendered HTML.
echo BLUELINE_YITH_OPTION . ' and ' . BLUELINE_YITH_META_KEY . ' were not written to or deleted by this script.' . PHP_EOL;
