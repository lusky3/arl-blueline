<?php
/**
 * `wp blueline-core migrate-yith-avatars [--apply]`. Ported from
 * scripts/one-off/2026-08-11-migrate-yith-avatars.php (same source, same
 * safety checks; see that file's docblock for the staging evidence).
 *
 * Source is user meta `yith-wcmap-avatar` (YITH's real per-user link), not the
 * `yith_wcmap_users_avatar_ids` option, which is a flat bookkeeping list of
 * attachment IDs and is printed as evidence only. Neither YITH record is ever
 * written or deleted: this copies into `blueline_avatar_id`. Report-only unless
 * `--apply`; idempotent.
 *
 * A user who already has a DIFFERENT `blueline_avatar_id` is reported as
 * CONFLICT and left alone: that value was chosen on this site (or by an
 * earlier run) and YITH's copy is the older record, so overwriting it
 * silently would lose the newer choice. `--force` overwrites deliberately.
 *
 * Exit status: a row whose write did not stick is FAILED and the command ends
 * with WP_CLI::error() (non-zero). Skipped rows and conflicts end with a
 * warning (exit 0), since they are data to review, not errors in the run.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Copies YITH WCMAP custom avatars into `blueline_avatar_id`.
 */
class Blueline_Core_Migrate_Yith_Avatars_Command extends WP_CLI_Command {

	/**
	 * YITH's per-user avatar meta (the migration source).
	 */
	const YITH_META_KEY = 'yith-wcmap-avatar';

	/**
	 * YITH's bookkeeping option (evidence only).
	 */
	const YITH_OPTION = 'yith_wcmap_users_avatar_ids';

	/**
	 * Column => width for the printed table.
	 */
	const COLUMNS = array(
		'user_id'       => 7,
		'user_login'    => 16,
		'attachment_id' => 13,
		'before'        => 22,
		'after'         => 22,
		'status'        => 42,
	);

	/**
	 * Copy each user's YITH custom avatar into `blueline_avatar_id`.
	 *
	 * Report-only by default. `--apply` writes, and needs a current user with
	 * edit_users (pass `--user=<an administrator>`).
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write `blueline_avatar_id`. Without it nothing is written.
	 *
	 * [--force]
	 * : Overwrite a different `blueline_avatar_id` that is already set (otherwise reported as CONFLICT and left alone).
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueline-core migrate-yith-avatars
	 *     wp blueline-core migrate-yith-avatars --apply --user=9
	 *     wp blueline-core migrate-yith-avatars --apply --force --user=9
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI dispatch signature; no positional arguments.
		$apply = ! empty( $assoc_args['apply'] );
		$force = ! empty( $assoc_args['force'] );

		// Refuse before reading or printing anything, as the original script did.
		if ( $apply && ! current_user_can( 'edit_users' ) ) {
			WP_CLI::error( 'Refusing to apply: no current user with the edit_users capability. Re-run with --user=<an-administrator-id>.' );
			return;
		}

		$option_value = get_option( self::YITH_OPTION, array() );
		$option_value = is_array( $option_value ) ? $option_value : array();

		WP_CLI::log( '=== Before-state (evidence, not the migration source) ===' );
		WP_CLI::log( self::YITH_OPTION . ' = ' . wp_json_encode( $option_value ) );
		WP_CLI::log( '' );

		$seen = array();
		$rows = array();
		foreach ( $this->yith_users() as $user ) {
			$rows[] = $this->migrate_user( (int) $user->ID, (string) $user->user_login, $apply, $force, $seen );
		}

		WP_CLI::log( $apply ? '=== APPLYING -- writing blueline_avatar_id ===' : '=== REPORT MODE (default) -- no writes will be made; pass --apply to write ===' );
		WP_CLI::log( self::table_row( array_combine( array_keys( self::COLUMNS ), array_keys( self::COLUMNS ) ) ) );
		foreach ( $rows as $row ) {
			WP_CLI::log( self::table_row( $row ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Rows found (real user -> attachment links): ' . count( $rows ) );

		$orphans = self::orphaned_attachment_ids( $option_value, $seen );
		if ( $orphans ) {
			WP_CLI::log( 'Orphaned entries in ' . self::YITH_OPTION . ' with no owning user (not migrated -- nothing to attribute them to): ' . implode( ', ', $orphans ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( self::YITH_OPTION . ' and ' . self::YITH_META_KEY . ' were not written to or deleted.' );

		$this->report_outcomes( $rows );
	}

	/**
	 * Summarise the run and surface what needs attention: warnings for skipped
	 * rows and conflicts, and a final error (non-zero exit) when any write failed.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from migrate_user().
	 * @return void
	 */
	private function report_outcomes( array $rows ): void {
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[ $row['outcome'] ][] = $row['user_id'];
		}

		WP_CLI::log(
			sprintf(
				'Summary: %d migrated/would migrate, %d already migrated, %d skipped, %d conflict(s), %d failed.',
				count( $ids['migrate'] ?? array() ),
				count( $ids['noop'] ?? array() ),
				count( $ids['skipped'] ?? array() ),
				count( $ids['conflict'] ?? array() ),
				count( $ids['failed'] ?? array() )
			)
		);

		if ( ! empty( $ids['skipped'] ) ) {
			WP_CLI::warning( sprintf( '%d row(s) were SKIPPED and not migrated (user IDs: %s). See the status column above.', count( $ids['skipped'] ), implode( ', ', $ids['skipped'] ) ) );
		}

		if ( ! empty( $ids['conflict'] ) ) {
			WP_CLI::warning( sprintf( '%d user(s) already have a different blueline_avatar_id and were left unchanged (user IDs: %s). Re-run with --apply --force to overwrite them.', count( $ids['conflict'] ), implode( ', ', $ids['conflict'] ) ) );
		}

		if ( ! empty( $ids['failed'] ) ) {
			WP_CLI::error( sprintf( '%d row(s) FAILED: the blueline_avatar_id write did not stick (user IDs: %s). Nothing else was changed for those users.', count( $ids['failed'] ), implode( ', ', $ids['failed'] ) ) );
		}
	}

	/**
	 * Every user with a non-empty `yith-wcmap-avatar`, ID ascending.
	 *
	 * @return object[] Rows carrying ID and user_login.
	 */
	protected function yith_users(): array {
		return get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off migration command, not a request-time query.
					array(
						'key'     => self::YITH_META_KEY,
						'value'   => '',
						'compare' => '!=',
					),
				),
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'fields'     => array( 'ID', 'user_login' ),
			)
		);
	}

	/**
	 * Evaluate (and with $apply, write) one user's avatar; returns its table row.
	 *
	 * The row's extra `outcome` key (not a printed column) is one of
	 * migrate, noop, skipped, conflict or failed, for the run summary.
	 *
	 * @param int              $user_id    User ID.
	 * @param string           $user_login Login, for the report.
	 * @param bool             $apply      Whether to write.
	 * @param bool             $force      Whether to overwrite a different existing avatar.
	 * @param array<int, true> &$seen      Attachment IDs owned by some user (filled in).
	 * @return array<string, string|int>
	 */
	private function migrate_user( int $user_id, string $user_login, bool $apply, bool $force, array &$seen ): array {
		$raw_value     = get_user_meta( $user_id, self::YITH_META_KEY, true );
		$attachment_id = absint( $raw_value );
		$existing      = absint( get_user_meta( $user_id, BLUELINE_AVATAR_META_KEY, true ) );
		$before        = $existing ? (string) $existing : '(none)';
		$after         = $before;

		// Non-numeric data is reported, never skipped silently.
		if ( ! $attachment_id ) {
			return array(
				'user_id'       => $user_id,
				'user_login'    => $user_login,
				'attachment_id' => '(invalid)',
				'before'        => $before,
				'after'         => $after,
				'status'        => 'SKIPPED -- yith-wcmap-avatar value is non-numeric/zero: ' . wp_json_encode( $raw_value ),
				'outcome'       => 'skipped',
			);
		}

		$seen[ $attachment_id ] = true;
		$planned                = $apply ? (string) $attachment_id : $attachment_id . ' (report only)';

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			$outcome = 'skipped';
			$status  = 'SKIPPED -- attachment ' . $attachment_id . ' no longer exists';
		} elseif ( $existing === $attachment_id ) {
			$outcome = 'noop';
			$status  = 'already migrated (no-op)';
			$after   = (string) $attachment_id;
		} elseif ( $existing && ! $force ) {
			// A different avatar is already set: keep it unless --force says otherwise.
			$outcome = 'conflict';
			$status  = 'CONFLICT -- has ' . $existing . ', YITH has ' . $attachment_id . '; kept (use --force)';
		} elseif ( ! $apply ) {
			$outcome = 'migrate';
			$status  = $existing ? 'would overwrite ' . $existing : 'would migrate';
			$after   = $planned;
		} elseif ( $this->write_avatar( $user_id, $attachment_id ) ) {
			$outcome = 'migrate';
			$status  = $existing ? 'migrated (overwrote ' . $existing . ')' : 'migrated';
			$after   = (string) $attachment_id;
		} else {
			$outcome = 'failed';
			$status  = 'FAILED -- meta write rejected, nothing changed';
		}

		return array(
			'user_id'       => $user_id,
			'user_login'    => $user_login,
			'attachment_id' => $attachment_id,
			'before'        => $before,
			'after'         => $after,
			'status'        => $status,
			'outcome'       => $outcome,
		);
	}

	/**
	 * Write `blueline_avatar_id` and confirm it stuck.
	 *
	 * The function update_user_meta() returns false for BOTH a rejected write (for example a
	 * plugin vetoing it through `update_user_metadata`) and an unchanged value,
	 * so its return value alone cannot say which. The stored value is read back
	 * instead: the write counts only if the meta now holds the attachment ID.
	 *
	 * @param int $user_id       User ID.
	 * @param int $attachment_id Attachment ID to store.
	 * @return bool Whether the stored value is now $attachment_id.
	 */
	protected function write_avatar( int $user_id, int $attachment_id ): bool {
		update_user_meta( $user_id, BLUELINE_AVATAR_META_KEY, $attachment_id );

		return absint( get_user_meta( $user_id, BLUELINE_AVATAR_META_KEY, true ) ) === $attachment_id;
	}

	/**
	 * Attachment IDs in the YITH option that no user's meta points at.
	 *
	 * @param array            $option_value The option's value.
	 * @param array<int, true> $seen         Attachment IDs some user owns.
	 * @return int[]
	 */
	public static function orphaned_attachment_ids( array $option_value, array $seen ): array {
		return array_values( array_diff( array_map( 'absint', array_values( $option_value ) ), array_keys( $seen ) ) );
	}

	/**
	 * One padded, pipe-separated table line.
	 *
	 * @param array<string, string|int> $row Row keyed by COLUMNS.
	 * @return string
	 */
	public static function table_row( array $row ): string {
		$cells = array();
		foreach ( self::COLUMNS as $key => $width ) {
			$cells[] = str_pad( (string) $row[ $key ], $width );
		}

		return implode( ' | ', $cells );
	}
}

WP_CLI::add_command( 'blueline-core migrate-yith-avatars', 'Blueline_Core_Migrate_Yith_Avatars_Command' );
