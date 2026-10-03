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
	 * ## EXAMPLES
	 *
	 *     wp blueline-core migrate-yith-avatars
	 *     wp blueline-core migrate-yith-avatars --apply --user=9
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI dispatch signature; no positional arguments.
		$apply = ! empty( $assoc_args['apply'] );

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
			$rows[] = $this->migrate_user( (int) $user->ID, (string) $user->user_login, $apply, $seen );
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
	 * @param int              $user_id    User ID.
	 * @param string           $user_login Login, for the report.
	 * @param bool             $apply      Whether to write.
	 * @param array<int, true> &$seen      Attachment IDs owned by some user (filled in).
	 * @return array<string, string|int>
	 */
	private function migrate_user( int $user_id, string $user_login, bool $apply, array &$seen ): array {
		$raw_value     = get_user_meta( $user_id, self::YITH_META_KEY, true );
		$attachment_id = absint( $raw_value );
		$existing      = absint( get_user_meta( $user_id, BLUELINE_AVATAR_META_KEY, true ) );
		$before        = $existing ? (string) $existing : '(none)';

		// Non-numeric data is reported, never skipped silently.
		if ( ! $attachment_id ) {
			return array(
				'user_id'       => $user_id,
				'user_login'    => $user_login,
				'attachment_id' => '(invalid)',
				'before'        => $before,
				'after'         => $before,
				'status'        => 'SKIPPED -- yith-wcmap-avatar value is non-numeric/zero: ' . wp_json_encode( $raw_value ),
			);
		}

		$seen[ $attachment_id ] = true;
		$exists                 = 'attachment' === get_post_type( $attachment_id );

		if ( ! $exists ) {
			$status = 'SKIPPED -- attachment ' . $attachment_id . ' no longer exists';
		} elseif ( $existing === $attachment_id ) {
			$status = 'already migrated (no-op)';
		} elseif ( ! $apply ) {
			$status = 'would migrate';
		} else {
			update_user_meta( $user_id, BLUELINE_AVATAR_META_KEY, $attachment_id );
			$status = 'migrated';
		}

		if ( ! $exists ) {
			$after = '(none)';
		} else {
			$after = $apply ? (string) $attachment_id : $attachment_id . ' (report only)';
		}

		return array(
			'user_id'       => $user_id,
			'user_login'    => $user_login,
			'attachment_id' => $attachment_id,
			'before'        => $before,
			'after'         => $after,
			'status'        => $status,
		);
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
