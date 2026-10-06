<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/updater-rules.php';
require_once __DIR__ . '/../inc/updater-client.php';
require_once __DIR__ . '/../inc/updater.php';

/**
 * Covers the updater's Site Health test.
 */
final class UpdaterSiteHealthTest extends TestCase {

	/**
	 * Reset the in-memory stores before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * A state record.
	 *
	 * @param string                    $status    Status.
	 * @param array<string, mixed>|null $candidate Candidate.
	 * @return array<string, mixed>
	 */
	private function state( string $status, ?array $candidate = null ): array {
		return array(
			'status'    => $status,
			'channel'   => 'beta',
			'installed' => '1.0.1',
			'checked'   => 0,
			'candidate' => $candidate,
		);
	}

	/**
	 * Each state's verdict and badge colour.
	 *
	 * @return array<string, array{0: string, 1: ?array<string, mixed>, 2: string, 3: string}>
	 */
	public static function verdicts(): array {
		return array(
			'no token'     => array( 'no_token', null, 'recommended', 'orange' ),
			'unauthorized' => array( 'unauthorized', null, 'critical', 'red' ),
			'rate limited' => array( 'rate_limited', null, 'recommended', 'orange' ),
			'error'        => array( 'error', null, 'recommended', 'orange' ),
			'ok'           => array( 'ok', null, 'good', 'blue' ),
			'ok with new'  => array( 'ok', array( 'version' => '1.2.0-rc.1' ), 'good', 'blue' ),
		);
	}

	/**
	 * Verdicts.
	 *
	 * @param string                    $status    Status.
	 * @param array<string, mixed>|null $candidate Candidate.
	 * @param string                    $level     Expected Site Health status.
	 * @param string                    $color     Expected badge colour.
	 */
	#[DataProvider( 'verdicts' )]
	public function test_health_result_status_and_badge( string $status, ?array $candidate, string $level, string $color ): void {
		$result = blueline_updater_health_result( $this->state( $status, $candidate ) );

		$this->assertSame( $level, $result['status'] );
		$this->assertSame( $color, $result['badge']['color'] );
		$this->assertSame( 'blueline_updater', $result['test'] );
		$this->assertStringStartsWith( '<p>', $result['description'] );
	}

	/**
	 * The ok description names the channel and the latest version.
	 */
	public function test_ok_description_mentions_channel_and_version(): void {
		$up_to_date = blueline_updater_health_result( $this->state( 'ok' ) )['description'];
		$available  = blueline_updater_health_result( $this->state( 'ok', array( 'version' => '1.2.0-rc.1' ) ) )['description'];

		$this->assertStringContainsString( 'up to date', $up_to_date );
		$this->assertStringContainsString( 'beta', $up_to_date );
		$this->assertStringContainsString( '1.2.0-rc.1', $available );
		$this->assertStringContainsString( 'beta', $available );
	}

	/**
	 * Text that could carry markup is escaped.
	 */
	public function test_description_escapes_html_in_version(): void {
		$result = blueline_updater_health_result( $this->state( 'ok', array( 'version' => '<b>x</b>' ) ) );

		$this->assertStringNotContainsString( '<b>', $result['description'] );
		$this->assertStringContainsString( '&lt;b&gt;', $result['description'] );
	}

	/**
	 * The expired-token message tells the admin what to do.
	 */
	public function test_unauthorized_message_points_at_the_token(): void {
		$description = blueline_updater_health_result( $this->state( 'unauthorized' ) )['description'];

		$this->assertStringContainsString( 'BLUELINE_GITHUB_TOKEN', $description );
		$this->assertStringContainsString( 'expire', $description );
	}

	/**
	 * Registered with no token configured.
	 */
	public function test_register_adds_direct_test_with_no_token(): void {
		$tests = blueline_updater_register_health_test( array( 'direct' => array( 'existing' => array() ) ) );

		$this->assertArrayHasKey( 'existing', $tests['direct'] );
		$this->assertSame( 'blueline_updater_run_health_test', $tests['direct']['blueline_updater']['test'] );
		$this->assertMatchesRegularExpression( "/^add_filter\\( 'site_status_tests', 'blueline_updater_register_health_test' \\);/m", (string) file_get_contents( __DIR__ . '/../inc/updater.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test; wp_remote_get() is for HTTP.
	}

	/**
	 * No token: a recommendation, and not a single request.
	 */
	public function test_run_makes_no_http_when_no_token(): void {
		$result = blueline_updater_run_health_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( array(), $GLOBALS['bl_test_http_log'] );
	}

	/**
	 * Whatever the check reports, the token itself is never printed.
	 */
	#[RunInSeparateProcess]
	public function test_token_never_appears_in_any_result(): void {
		define( 'BLUELINE_GITHUB_TOKEN', 'secret-token-xyz' );
		blueline_test_http_expect( 'https://api.github.com/repos/lusky3/arl-blueline/releases?per_page=10', array( 'response' => array( 'code' => 401 ) ) );

		$result = blueline_updater_run_health_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringNotContainsString( 'secret-token-xyz', (string) wp_json_encode( $result ) );
	}
}
