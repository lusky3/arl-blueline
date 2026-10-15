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
	 * @param array<string, mixed>|null $rejected  A newer release that cannot be installed.
	 * @return array<string, mixed>
	 */
	private function state( string $status, ?array $candidate = null, ?array $rejected = null ): array {
		return array(
			'status'    => $status,
			'channel'   => 'beta',
			'installed' => '1.0.1',
			'checked'   => 0,
			'candidate' => $candidate,
			'rejected'  => $rejected,
		);
	}

	/**
	 * Each state's verdict and badge colour.
	 *
	 * @return array<string, array{0: string, 1: ?array<string, mixed>, 2: string, 3: string}>
	 */
	public static function verdicts(): array {
		return array(
			'unauthorized' => array( 'unauthorized', null, 'critical', 'red' ),
			'not found'    => array( 'not_found', null, 'critical', 'red' ),
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
	 * The refusal message covers both causes: a bad configured token, and (with
	 * no token) a repository that is no longer public.
	 */
	public function test_unauthorized_message_covers_a_bad_token_and_a_private_repo(): void {
		$description = blueline_updater_health_result( $this->state( 'unauthorized' ) )['description'];

		$this->assertStringContainsString( 'BLUELINE_GITHUB_TOKEN', $description );
		$this->assertStringContainsString( 'expire', $description );
		$this->assertStringContainsString( 'no longer be public', $description );
	}

	/**
	 * A repository that is gone or private is not a token problem and must not say so.
	 */
	public function test_not_found_message_is_not_about_the_token(): void {
		$description = blueline_updater_health_result( $this->state( 'not_found' ) )['description'];

		$this->assertStringContainsString( 'could not find', $description );
		$this->assertStringContainsString( 'private', $description );
		$this->assertStringContainsString( 'not a token problem', $description );
		$this->assertStringNotContainsString( 'revoked', $description );
	}

	/**
	 * A newer release whose files are missing or broken is a warning, never "up to date".
	 */
	public function test_a_rejected_newer_release_is_reported_instead_of_up_to_date(): void {
		foreach ( array( 'assets', 'manifest' ) as $reason ) {
			$result = blueline_updater_health_result(
				$this->state(
					'ok',
					null,
					array(
						'version' => '1.1.5',
						'reason'  => $reason,
					)
				)
			); // phpcs:ignore Universal.Arrays.MixedKeyedUnkeyedArray.Found -- one-line fixture.

			$this->assertSame( 'recommended', $result['status'], $reason );
			$this->assertSame( 'orange', $result['badge']['color'], $reason );
			$this->assertStringContainsString( '1.1.5', $result['description'], $reason );
			$this->assertStringContainsString( 'not being offered', $result['description'], $reason );
			$this->assertStringNotContainsString( 'up to date', $result['description'], $reason );
		}

		$this->assertStringContainsString(
			'zip or update manifest was not uploaded',
			blueline_updater_health_result(
				$this->state(
					'ok',
					null,
					array(
						'version' => '1.1.5',
						'reason'  => 'assets',
					)
				)
			)['description']
		); // phpcs:ignore Universal.Arrays.MixedKeyedUnkeyedArray.Found -- one-line fixture.
		$this->assertStringContainsString(
			'manifest is missing, unreadable',
			blueline_updater_health_result(
				$this->state(
					'ok',
					null,
					array(
						'version' => '1.1.5',
						'reason'  => 'manifest',
					)
				)
			)['description']
		); // phpcs:ignore Universal.Arrays.MixedKeyedUnkeyedArray.Found -- one-line fixture.
	}

	/**
	 * With an older release still offered, the warning says so as well.
	 */
	public function test_a_rejected_release_still_mentions_the_available_older_one(): void {
		$result = blueline_updater_health_result(
			$this->state(
				'ok',
				array( 'version' => '1.1.4' ),
				array(
					'version' => '1.1.5',
					'reason'  => 'manifest',
				)
			)
		); // phpcs:ignore Universal.Arrays.MixedKeyedUnkeyedArray.Found -- one-line fixture.

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '1.1.5', $result['description'] );
		$this->assertStringContainsString( 'Blueline 1.1.4 is still available', $result['description'] );
	}

	/**
	 * A state without the key (cached by an older build) still reads as healthy.
	 */
	public function test_a_cached_state_without_the_rejected_key_is_unchanged(): void {
		$state = $this->state( 'ok' );
		unset( $state['rejected'] );

		$this->assertSame( 'good', blueline_updater_health_result( $state )['status'] );
	}

	/**
	 * A rate-limit message tells the admin the optional token raises the limit.
	 */
	public function test_rate_limited_message_mentions_the_optional_token(): void {
		$description = blueline_updater_health_result( $this->state( 'rate_limited' ) )['description'];

		$this->assertStringContainsString( 'BLUELINE_GITHUB_TOKEN', $description );
		$this->assertStringContainsString( 'raises the limit', $description );
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
	 * No token configured: the check still runs, anonymously, and a healthy
	 * answer is a healthy result (this is the state of a live site that never
	 * had a token, which used to report updates as switched off).
	 */
	public function test_run_checks_anonymously_when_no_token(): void {
		blueline_test_http_expect(
			'https://api.github.com/repos/lusky3/arl-blueline/releases?per_page=10',
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '[]',
			)
		);

		$result = blueline_updater_run_health_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertCount( 1, $GLOBALS['bl_test_http_log'] );
		$this->assertArrayNotHasKey( 'Authorization', $GLOBALS['bl_test_http_log'][0]['args']['headers'] );
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
