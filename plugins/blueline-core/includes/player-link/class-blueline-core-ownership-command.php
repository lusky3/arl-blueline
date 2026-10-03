<?php
/**
 * `wp blueline-core ownership report|apply`: the safe path for the SEC-01
 * ownership decision. `report` lists Player-role members who are linked by
 * sp_user but are not the player's post_author; `apply` fixes explicit ids.
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
	const FIELDS = array( 'player_id', 'player', 'sp_user', 'user_login', 'post_author' );

	/**
	 * List linked players whose Player-role user is not the post_author. Read-only.
	 *
	 * Also prints a count per ownership category (verified, role_not_author,
	 * author_not_role, name_claim, missing_user).
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
	 * exists, holds the Player role and is not already the author. sp_user is
	 * never touched. There is no bulk mode: --ids is required.
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
				WP_CLI::log( sprintf( 'Would set player %d post_author %d -> %d.', $player_id, $plan['from'], $plan['to'] ) );
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
	 * Every sp_player row carrying an sp_user meta value.
	 *
	 * @return object[] Rows with ID, post_title, post_author, sp_user.
	 */
	protected function linked_players(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read-only CLI report join; no core API returns post_author with a meta value.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_author, m.meta_value AS sp_user FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = %s ORDER BY p.ID",
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

		WP_CLI::log( sprintf( 'Set player %d post_author %d -> %d.', $player_id, $from, $to ) );
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
