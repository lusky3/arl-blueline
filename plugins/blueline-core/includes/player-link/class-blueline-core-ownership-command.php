<?php
/**
 * `wp blueline-core ownership report|apply|unlink`: the safe path for the SEC-01
 * ownership decision. `report` lists Player-role members who are linked by
 * sp_user but are not the player's post_author; `apply` fixes explicit ids;
 * `unlink` removes a wrong name claim. Shell-only (WP-CLI), so no capability
 * check is needed, matching the other operator commands.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports and fixes sp_player ownership (post_author) for Player-role members.
 */
class Blueline_Core_Ownership_Command extends WP_CLI_Command {

	/**
	 * Columns `report` prints.
	 */
	const FIELDS = array( 'player_id', 'player', 'sp_user', 'user_login', 'post_author', 'post_date', 'name_score', 'flags' );

	/**
	 * List linked players whose Player-role user is not the post_author. Read-only.
	 *
	 * Also prints a count per ownership category (verified, role_not_author,
	 * author_not_role, name_claim, missing_user).
	 *
	 * Each row carries the evidence for the call: post_date, name_score (the
	 * linked user's billing/display name against the player title, 0-1) and
	 * flags (owns_other_player: the user already owns a different player;
	 * author_is_player: the current author is a Player-role user). `apply`
	 * refuses flagged rows. A low name_score is a sign of someone else's name claim.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueline-core ownership report
	 *     wp blueline-core ownership report --format=csv > role-not-author.csv
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function report( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI dispatch signature; no positional arguments.
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( ! in_array( $format, array( 'table', 'csv' ), true ) ) {
			WP_CLI::error( 'Unknown --format. Use table or csv.' );
			return;
		}

		$rows    = blueline_core_ownership_rows( $this->linked_players() );
		$targets = array_values(
			array_filter(
				$rows,
				static fn( $row ) => BLUELINE_CORE_OWNERSHIP_ROLE_NOT_AUTHOR === $row['category']
			)
		);

		if ( 'csv' === $format ) {
			$this->print_csv( $targets );
			return; // Keep CSV output pipeable: no summary lines.
		}

		$this->print_table( $targets );
		WP_CLI::log( '' );
		foreach ( $this->count_categories( $rows ) as $category => $count ) {
			WP_CLI::log( sprintf( '%-16s %d', $category, $count ) );
		}
		WP_CLI::log( sprintf( '%-16s %d', 'linked players', count( $rows ) ) );
	}

	/**
	 * Set post_author to the linked sp_user for the given players only.
	 *
	 * Dry run unless --yes. A player is changed only when its linked user
	 * exists, holds the Player role, is not already the author and owns no
	 * other player, and the current author is not a Player-role user. sp_user
	 * is never touched. The replaced author is saved in the
	 * _blueline_prev_author post meta, so a change can be undone. There is no
	 * bulk mode: --ids is required.
	 *
	 * ## OPTIONS
	 *
	 * --ids=<ids>
	 * : Comma-separated sp_player IDs (from `report`).
	 *
	 * [--yes]
	 * : Write. Without it, only prints what would change.
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueline-core ownership apply --ids=101,102
	 *     wp blueline-core ownership apply --ids=101,102 --yes
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function apply( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI dispatch signature; no positional arguments.
		$ids = blueline_core_ownership_parse_ids( (string) ( $assoc_args['ids'] ?? '' ) );
		if ( is_wp_error( $ids ) ) {
			WP_CLI::error( $ids->get_error_message() );
			return;
		}

		$write   = ! empty( $assoc_args['yes'] );
		$changed = 0;

		if ( $write ) {
			// Without --user, WP-CLI runs as user 0 and kses would rewrite post_content on save.
			kses_remove_filters();
		}

		foreach ( $ids as $player_id ) {
			$plan = blueline_core_ownership_plan( $player_id );

			if ( ! $plan['ok'] ) {
				WP_CLI::warning( sprintf( 'Player %d skipped: %s.', $player_id, $plan['reason'] ) );
				continue;
			}

			if ( ! $write ) {
				WP_CLI::log( sprintf( 'Would set player %1$d post_author %2$d -> %3$d (previous author would be saved in %4$s).', $player_id, $plan['from'], $plan['to'], BLUELINE_CORE_OWNERSHIP_PREV_AUTHOR_META ) );
				++$changed;
				continue;
			}

			$changed += $this->set_author( $player_id, $plan['from'], $plan['to'] ) ? 1 : 0;
		}

		if ( ! $write ) {
			WP_CLI::success( sprintf( 'Dry run: %d player(s) would change. Nothing was written; re-run with --yes to apply.', $changed ) );
			return;
		}

		WP_CLI::success( sprintf( '%d player(s) updated.', $changed ) );
	}

	/**
	 * Remove the sp_user link from wrongly name-claimed players. Dry run unless --yes.
	 *
	 * The league's undo for a squatted name claim. Only a plain name claim (the
	 * linked user is neither the post_author nor a Player-role member) or a link
	 * to a user that no longer exists is removed; a verified owner or any
	 * Player-role link is refused. post_author is never touched. The removed
	 * user is logged so the link can be restored by hand. There is no bulk mode:
	 * --ids is required.
	 *
	 * ## OPTIONS
	 *
	 * --ids=<ids>
	 * : Comma-separated sp_player IDs.
	 *
	 * [--yes]
	 * : Write. Without it, only prints what would change.
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueline-core ownership unlink --ids=101
	 *     wp blueline-core ownership unlink --ids=101 --yes
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function unlink( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI dispatch signature; no positional arguments.
		$ids = blueline_core_ownership_parse_ids( (string) ( $assoc_args['ids'] ?? '' ) );
		if ( is_wp_error( $ids ) ) {
			WP_CLI::error( $ids->get_error_message() );
			return;
		}

		$write   = ! empty( $assoc_args['yes'] );
		$changed = 0;

		foreach ( $ids as $player_id ) {
			$plan = blueline_core_ownership_unlink_plan( $player_id );

			if ( ! $plan['ok'] ) {
				WP_CLI::warning( sprintf( 'Player %d skipped: %s.', $player_id, $plan['reason'] ) );
				continue;
			}

			if ( ! $write ) {
				WP_CLI::log( sprintf( 'Would unlink player %1$d from user %2$d (%3$s).', $player_id, $plan['user'], $plan['category'] ) );
				++$changed;
				continue;
			}

			delete_post_meta( $player_id, BLUELINE_PLAYER_USER_META, $plan['user'] );
			blueline_forget_linked_player_cache( $plan['user'] );
			WP_CLI::log( sprintf( 'Unlinked player %1$d from user %2$d. To restore: wp post meta add %1$d %3$s %2$d', $player_id, $plan['user'], BLUELINE_PLAYER_USER_META ) );
			++$changed;
		}

		if ( ! $write ) {
			WP_CLI::success( sprintf( 'Dry run: %d player(s) would be unlinked. Nothing was written; re-run with --yes to apply.', $changed ) );
			return;
		}

		WP_CLI::success( sprintf( '%d player(s) unlinked.', $changed ) );
	}

	/**
	 * Every sp_player row carrying an sp_user meta value.
	 *
	 * @return object[] Rows with ID, post_title, post_author, post_date, sp_user.
	 */
	protected function linked_players(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read-only CLI report join; no core API returns post_author with a meta value.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_author, p.post_date, m.meta_value AS sp_user FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = %s ORDER BY p.ID",
				'sp_player',
				BLUELINE_PLAYER_USER_META
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Write one post_author change through the normal save path.
	 *
	 * @param int $player_id sp_player post ID.
	 * @param int $from      Current author.
	 * @param int $to        New author (the linked user).
	 * @return bool Whether it was written.
	 */
	private function set_author( int $player_id, int $from, int $to ): bool {
		$result = wp_update_post(
			array(
				'ID'          => $player_id,
				'post_author' => $to,
			),
			true
		);

		if ( is_wp_error( $result ) || ! $result ) {
			WP_CLI::warning( sprintf( 'Player %d: update failed%s.', $player_id, is_wp_error( $result ) ? ' (' . $result->get_error_message() . ')' : '' ) );
			return false;
		}

		update_post_meta( $player_id, BLUELINE_CORE_OWNERSHIP_PREV_AUTHOR_META, $from );

		WP_CLI::log( sprintf( 'Set player %1$d post_author %2$d -> %3$d. Previous author saved in %4$s (undo: set post_author back to %2$d).', $player_id, $from, $to, BLUELINE_CORE_OWNERSHIP_PREV_AUTHOR_META ) );
		return true;
	}

	/**
	 * Rows per category, every category listed.
	 *
	 * @param array<int, array{category:string}> $rows Classified rows.
	 * @return array<string, int>
	 */
	private function count_categories( array $rows ): array {
		$counts = array_fill_keys(
			array(
				BLUELINE_CORE_OWNERSHIP_VERIFIED,
				BLUELINE_CORE_OWNERSHIP_ROLE_NOT_AUTHOR,
				BLUELINE_CORE_OWNERSHIP_AUTHOR_NOT_ROLE,
				BLUELINE_CORE_OWNERSHIP_NAME_CLAIM,
				BLUELINE_CORE_OWNERSHIP_MISSING_USER,
			),
			0
		);

		foreach ( $rows as $row ) {
			++$counts[ $row['category'] ];
		}

		return $counts;
	}

	/**
	 * Print rows as CSV with a header line.
	 *
	 * @param array<int, array<string, scalar>> $rows Rows.
	 * @return void
	 */
	private function print_csv( array $rows ): void {
		WP_CLI::log( blueline_core_ownership_csv_line( self::FIELDS ) );
		foreach ( $rows as $row ) {
			WP_CLI::log( blueline_core_ownership_csv_line( array_intersect_key( $row, array_flip( self::FIELDS ) ) ) );
		}
	}

	/**
	 * Print rows as a tab-separated table with a header line.
	 *
	 * @param array<int, array<string, scalar>> $rows Rows.
	 * @return void
	 */
	private function print_table( array $rows ): void {
		WP_CLI::log( implode( "\t", self::FIELDS ) );
		foreach ( $rows as $row ) {
			WP_CLI::log( implode( "\t", array_intersect_key( $row, array_flip( self::FIELDS ) ) ) );
		}
	}
}

WP_CLI::add_command( 'blueline-core ownership', 'Blueline_Core_Ownership_Command' );
