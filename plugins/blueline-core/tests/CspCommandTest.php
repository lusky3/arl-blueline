<?php
/**
 * Unit tests for `wp blueline-core csp report` and the CSP Site Health test.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/headers/headers.php';
require_once dirname( __DIR__, 3 ) . '/themes/blueline/tests/cli-stubs.php';
require_once __DIR__ . '/../includes/headers/class-blueline-core-csp-command.php';

/**
 * Covers Blueline_Core_Csp_Command and blueline_core_csp_site_health_result().
 */
final class CspCommandTest extends TestCase {

	/**
	 * Fresh options and CLI log.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		$GLOBALS['bl_test_cli_log'] = array();
	}

	/**
	 * Seed the store: $rows of [directive, blocked, path, count, last day].
	 *
	 * @param array<int, array{0: string, 1: string, 2: string, 3: int, 4: string}> $rows Entries.
	 * @return void
	 */
	private function seed( array $rows ): void {
		$store = blueline_core_csp_empty_store();
		foreach ( $rows as $row ) {
			for ( $i = 0; $i < $row[3]; $i++ ) {
				$store = blueline_core_csp_add_to_store(
					$store,
					array(
						'directive' => $row[0],
						'blocked'   => $row[1],
						'path'      => $row[2],
					),
					$row[4],
					BLUELINE_CORE_CSP_REPORT_MAX_ENTRIES
				);
			}
		}
		$store['since'] = '2026-10-14 08:00';
		update_option( BLUELINE_CORE_CSP_REPORT_OPTION, $store, false );
	}

	/**
	 * Messages logged by the command, by type.
	 *
	 * @param string $type log, success, warning or error.
	 * @return string[]
	 */
	private function logged( string $type = 'log' ): array {
		return array_values(
			array_map(
				static fn( $entry ) => $entry['message'],
				array_filter( $GLOBALS['bl_test_cli_log'], static fn( $entry ) => $type === $entry['type'] )
			)
		);
	}

	/**
	 * Three distinct violations.
	 */
	private function seed_three(): void {
		$this->seed(
			array(
				array( 'img-src', 'https://img.test', '/team/a', 2, '2026-10-14' ),
				array( 'script-src-elem', 'https://cdn.test', '/schedule/', 5, '2026-10-15' ),
				array( 'frame-src', 'https://www.youtube.com', '/news/x', 1, '2026-10-13' ),
			)
		);
	}

	/**
	 * Registered as `wp blueline-core csp`.
	 */
	public function test_command_registered(): void {
		$this->assertSame( 'Blueline_Core_Csp_Command', $GLOBALS['bl_test_cli_commands']['blueline-core csp'] ?? null );
	}

	/**
	 * The table lists the most frequent violations first, then a summary.
	 */
	public function test_report_table(): void {
		$this->seed_three();

		( new Blueline_Core_Csp_Command() )->report( array(), array() );
		$lines = $this->logged();

		$this->assertMatchesRegularExpression( '/^count\s+directive\s+blocked\s+path\s+last_seen$/', $lines[0] );
		$this->assertMatchesRegularExpression( '#^5\s+script-src-elem\s+https://cdn\.test\s+/schedule/\s+2026-10-15$#', $lines[1] );
		$this->assertMatchesRegularExpression( '#^2\s+img-src\s+https://img\.test\s+/team/a\s+2026-10-14$#', $lines[2] );
		$this->assertMatchesRegularExpression( '#^1\s+frame-src\s+https://www\.youtube\.com\s+/news/x\s+2026-10-13$#', $lines[3] );
		$this->assertContains( 'Mode: report-only. 3 distinct violations, 8 reports since 2026-10-14 08:00 UTC.', $lines );
	}

	/**
	 * CSV output is just the header and the rows, so it can be piped.
	 */
	public function test_report_csv(): void {
		$this->seed_three();

		( new Blueline_Core_Csp_Command() )->report( array(), array( 'format' => 'csv' ) );

		$this->assertSame(
			array(
				'count,directive,blocked,path,last_seen',
				'5,script-src-elem,https://cdn.test,/schedule/,2026-10-15',
				'2,img-src,https://img.test,/team/a,2026-10-14',
				'1,frame-src,https://www.youtube.com,/news/x,2026-10-13',
			),
			$this->logged()
		);
	}

	/**
	 * JSON output is one array of rows.
	 */
	public function test_report_json(): void {
		$this->seed_three();

		( new Blueline_Core_Csp_Command() )->report( array(), array( 'format' => 'json' ) );
		$rows = json_decode( $this->logged()[0], true );

		$this->assertCount( 3, $rows );
		$this->assertSame(
			array(
				'count'     => 5,
				'directive' => 'script-src-elem',
				'blocked'   => 'https://cdn.test',
				'path'      => '/schedule/',
				'last_seen' => '2026-10-15',
			),
			$rows[0]
		);
	}

	/**
	 * An unknown format stops before printing anything.
	 */
	public function test_unknown_format(): void {
		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

		( new Blueline_Core_Csp_Command() )->report( array(), array( 'format' => 'yaml' ) );
	}

	/**
	 * An empty store says so.
	 */
	public function test_empty_report(): void {
		( new Blueline_Core_Csp_Command() )->report( array(), array() );

		$this->assertSame( array( 'No CSP violations have been reported. Mode: report-only.' ), $this->logged() );
	}

	/**
	 * --reset prints the report, then clears it.
	 */
	public function test_reset(): void {
		$this->seed_three();

		( new Blueline_Core_Csp_Command() )->report( array(), array( 'reset' => true ) );

		$this->assertCount( 6, $this->logged() );
		$this->assertSame( array( 'Cleared the CSP violation report.' ), $this->logged( 'success' ) );
		$this->assertSame( array(), blueline_core_csp_store()['entries'] );
	}

	/**
	 * Registered as an informational direct test.
	 */
	public function test_site_health_registered(): void {
		$this->assertNotFalse( has_filter( 'site_status_tests', 'blueline_core_csp_register_site_health_test' ) );

		$tests = blueline_core_csp_register_site_health_test( array( 'direct' => array() ) );
		$this->assertSame( 'blueline_core_csp_run_site_health_test', $tests['direct']['blueline_csp_reports']['test'] );
	}

	/**
	 * The label counts distinct violations; the description lists the top five, escaped.
	 */
	public function test_site_health_result(): void {
		$rows = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$rows[] = array( 'img-src', 'https://img' . $i . '.test', '/p<' . $i . '>', $i, '2026-10-14' );
		}
		$this->seed( $rows );

		$result = blueline_core_csp_site_health_result( blueline_core_csp_store(), 'report-only' );

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'blueline_csp_reports', $result['test'] );
		$this->assertSame( 'CSP report-only: 7 distinct violations', $result['label'] );
		$this->assertStringContainsString( 'https://img7.test', $result['description'] );
		$this->assertStringContainsString( 'https://img3.test', $result['description'] );
		$this->assertStringNotContainsString( 'https://img2.test', $result['description'] );
		$this->assertStringContainsString( '/p&lt;7&gt;', $result['description'] );
		$this->assertStringNotContainsString( '<7>', $result['description'] );
		$this->assertStringContainsString( 'wp blueline-core csp report', $result['description'] );
	}

	/**
	 * With nothing reported, or with the policy enforced or off, the label says so.
	 */
	public function test_site_health_states(): void {
		$empty = blueline_core_csp_empty_store();

		$this->assertSame( 'CSP report-only: 0 distinct violations', blueline_core_csp_site_health_result( $empty, 'report-only' )['label'] );
		$this->assertSame( 'CSP enforced: 0 distinct violations', blueline_core_csp_site_health_result( $empty, 'enforce' )['label'] );
		$this->assertSame( 'Content Security Policy is off', blueline_core_csp_site_health_result( $empty, 'off' )['label'] );
	}
}
