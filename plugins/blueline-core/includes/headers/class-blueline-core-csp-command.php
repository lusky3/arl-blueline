<?php
/**
 * `wp blueline-core csp report`: the Content-Security-Policy violations browsers have reported, most
 * frequent first. Shell-only (WP-CLI), so no capability check is needed.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads (and optionally clears) the aggregated CSP violation reports.
 */
class Blueline_Core_Csp_Command extends WP_CLI_Command {

	/**
	 * Columns `report` prints.
	 */
	const FIELDS = array( 'count', 'directive', 'blocked', 'path', 'last_seen' );

	/**
	 * List the reported CSP violations, most frequent first.
	 *
	 * Each row is one (directive, blocked origin or keyword, page path) with how many reports named it
	 * and the last day (UTC) one arrived. `inline` means an inline script, style or event handler; `eval`
	 * means eval() or new Function().
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--reset]
	 * : After printing, clear the collected reports (for example after fixing what they showed).
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueline-core csp report
	 *     wp blueline-core csp report --format=csv > csp-violations.csv
	 *     wp blueline-core csp report --reset
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function report( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI dispatch signature; no positional arguments.
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( ! in_array( $format, array( 'table', 'csv', 'json' ), true ) ) {
			WP_CLI::error( 'Unknown --format. Use table, csv or json.' );
			return;
		}

		$store = blueline_core_csp_store();
		$rows  = array_map(
			static fn( array $entry ): array => array(
				'count'     => $entry['count'],
				'directive' => $entry['directive'],
				'blocked'   => $entry['blocked'],
				'path'      => $entry['path'],
				'last_seen' => $entry['last'],
			),
			blueline_core_csp_top_entries( $store )
		);

		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode( $rows ) );
		} elseif ( 'csv' === $format ) {
			$this->print_csv( $rows );
		} elseif ( ! $rows ) {
			WP_CLI::log( sprintf( 'No CSP violations have been reported. Mode: %s.', blueline_core_csp_mode() ) );
		} else {
			$this->print_table( $rows );
			WP_CLI::log( '' );
			WP_CLI::log(
				sprintf(
					'Mode: %1$s. %2$d distinct violations, %3$d reports since %4$s UTC.',
					blueline_core_csp_mode(),
					count( $rows ),
					$store['total'],
					$store['since']
				)
			);
		}

		if ( ! empty( $assoc_args['reset'] ) ) {
			blueline_core_csp_reset_store();
			WP_CLI::success( 'Cleared the CSP violation report.' );
		}
	}

	/**
	 * Print rows as an aligned table.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows keyed by self::FIELDS.
	 * @return void
	 */
	private function print_table( array $rows ): void {
		$widths = array();
		foreach ( self::FIELDS as $field ) {
			$widths[ $field ] = max( array_merge( array( strlen( $field ) ), array_map( static fn( $row ) => strlen( (string) $row[ $field ] ), $rows ) ) );
		}

		$line = static function ( array $cells ) use ( $widths ): string {
			$out = array();
			foreach ( self::FIELDS as $field ) {
				$out[] = str_pad( (string) $cells[ $field ], $widths[ $field ] );
			}
			return rtrim( implode( '  ', $out ) );
		};

		WP_CLI::log( $line( array_combine( self::FIELDS, self::FIELDS ) ) );
		foreach ( $rows as $row ) {
			WP_CLI::log( $line( $row ) );
		}
	}

	/**
	 * Print rows as CSV, header first.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows keyed by self::FIELDS.
	 * @return void
	 */
	private function print_csv( array $rows ): void {
		WP_CLI::log( self::csv_line( self::FIELDS ) );
		foreach ( $rows as $row ) {
			WP_CLI::log( self::csv_line( array_values( $row ) ) );
		}
	}

	/**
	 * One CSV line (RFC 4180 quoting, no trailing newline).
	 *
	 * @param array<int, mixed> $cells Cells.
	 * @return string
	 */
	private static function csv_line( array $cells ): string {
		$quoted = array();
		foreach ( $cells as $cell ) {
			$cell     = (string) $cell;
			$quoted[] = preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
		}
		return implode( ',', $quoted );
	}
}

WP_CLI::add_command( 'blueline-core csp', 'Blueline_Core_Csp_Command' );
