<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why.
/**
 * The page-cache purge driving a Predis client.
 *
 * The Redis Object Cache plugin can be configured to use Predis instead of
 * phpredis, and Predis's SCAN is a different shape: the cursor goes in by
 * value, options go in as an array, and the reply is `array( cursor, keys )`
 * with the cursor as a string. Predis is not installed here, so this declares
 * a minimal `Predis\ClientInterface` (tests/predis-stubs.php) and a client
 * behind it that models those rules.
 *
 * @package blueline
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- a one-off stand-in for a third-party client has nowhere more useful to live than beside the one test that needs it.


use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/predis-stubs.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/cache.php';

/**
 * A Predis-shaped client. SCAN is reached through __call(), as in the real
 * library, so method_exists( $client, 'scan' ) and 'unlink' are both false.
 */
final class Blueline_Fake_Predis_Client implements \Predis\ClientInterface {

	/**
	 * Commands received, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = array();

	/**
	 * Queued replies: each `array( cursor_string, keys )`, or a non-array to model a failure.
	 *
	 * @var array<int, mixed>
	 */
	private array $replies;

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $replies Queued SCAN replies.
	 */
	public function __construct( array $replies ) {
		$this->replies = $replies;
	}

	/**
	 * Predis forwards every command through __call().
	 *
	 * @param string            $name Command.
	 * @param array<int, mixed> $args Arguments.
	 * @return mixed
	 */
	public function __call( $name, $args ) {
		$this->calls[] = array_merge( array( $name ), $args );

		if ( 'scan' === $name ) {
			return array_shift( $this->replies );
		}

		return count( (array) ( $args[0] ?? array() ) );
	}
}

/**
 * Fixes the object cache global to a client for the purge under test.
 */
final class SettingsCachePredisTest extends TestCase {

	/**
	 * Reset shared in-memory state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Point the object-cache global at a Predis client.
	 *
	 * @param Blueline_Fake_Predis_Client $client Client.
	 */
	private function use_client( Blueline_Fake_Predis_Client $client ): void {
		global $wp_object_cache;
		$wp_object_cache = new class( $client ) { // phpcs:ignore WordPress.Variables.GlobalVariables.OverrideProhibited -- this IS the global the Redis Object Cache drop-in populates.
			/**
			 * The client redis_instance() returns.
			 *
			 * @var object
			 */
			private object $client;

			/**
			 * Constructor.
			 *
			 * @param object $client Client.
			 */
			public function __construct( object $client ) {
				$this->client = $client;
			}

			/**
			 * The one method the purge calls.
			 *
			 * @return object
			 */
			public function redis_instance() {
				return $this->client;
			}
		};
	}

	/**
	 * The scan follows the string cursor to '0', passes the options as an
	 * array, and deletes each batch with DEL.
	 */
	public function test_predis_scan_runs_to_completion_and_deletes_with_del(): void {
		$client = new Blueline_Fake_Predis_Client(
			array(
				array( '12', array( 'nginx-cache:GET:example.test:/one' ) ),
				array( '0', array( 'nginx-cache:GET:example.test:/two' ) ),
			)
		);
		$this->use_client( $client );

		$this->assertTrue( blueline_srcache_purge_attempt() );

		$scans = array_values( array_filter( $client->calls, static fn( $c ) => 'scan' === $c[0] ) );
		$this->assertCount( 2, $scans );
		$this->assertSame( 0, $scans[0][1], 'a Predis scan starts at cursor 0' );
		$this->assertSame( '12', $scans[1][1], 'the next call carries the cursor the last reply returned' );
		$this->assertSame( 'nginx-cache:*example.test*', $scans[0][2]['MATCH'] );

		$dels = array_values( array_filter( $client->calls, static fn( $c ) => 'del' === $c[0] ) );
		$this->assertCount( 2, $dels );
	}

	/**
	 * A malformed reply is a failure, not a completed purge.
	 */
	public function test_predis_malformed_reply_is_not_a_completed_purge(): void {
		$this->use_client( new Blueline_Fake_Predis_Client( array( false ) ) );

		$this->assertFalse( blueline_srcache_purge_attempt() );
	}
}
