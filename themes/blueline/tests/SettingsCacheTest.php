<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests.
 *
 * This file carries three object structures -- two fakes standing in for
 * the Redis Object Cache drop-in's global and its raw client, alongside the
 * SettingsCacheTest case itself -- the same trade-off tests/bootstrap.php
 * makes for its own WordPress stand-ins, and RegistrationPricingTest.php
 * makes for BluelineFakeProduct: a one-off stub of an external library's
 * shape has nowhere more useful to live than beside the one test file that
 * needs it. Both file-organisation sniffs are disabled (not ignored) for
 * exactly that reason, per that identical precedent.
 *
 * @package blueline
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- same trade-off as the FileName disable above.

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/cache.php';

/**
 * A fake Redis client standing in for the object phpredis' redis_instance()
 * returns: only the methods blueline_srcache_purge_attempt() calls (scan(),
 * unlink(), del()) plus keys() -- included ONLY so a test can prove it is
 * never invoked. Every call is recorded in $this->calls, in order, so a
 * test can assert both which methods ran and in what sequence.
 */
final class Blueline_Fake_Redis_Client {

	/**
	 * Every call made against this fake, in order: each entry is
	 * array( 'method', ...args ).
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = array();

	/**
	 * Queued SCAN batches, each shaped array( 'cursor' => int, 'keys' => string[] ).
	 * Consumed one per scan() call; once empty, scan() reports cursor 0 with
	 * no keys (a natural "nothing left" end state).
	 *
	 * @var array<int, array{cursor:int, keys:string[]}>
	 */
	private array $batches;

	/**
	 * Constructor.
	 *
	 * @param array<int, array{cursor:int, keys:string[]}> $batches Queued SCAN batches.
	 */
	public function __construct( array $batches ) {
		$this->batches = $batches;
	}

	/**
	 * Stand-in for phpredis' Redis::scan( &$cursor, $pattern, $count ): pops
	 * the next queued batch, mutates $cursor by reference exactly as the
	 * real client does, and returns that batch's keys.
	 *
	 * @param int    $cursor  Cursor, passed by reference.
	 * @param string $pattern MATCH pattern.
	 * @param int    $count   COUNT hint.
	 * @return string[]
	 */
	public function scan( &$cursor, $pattern, $count ) {
		$this->calls[] = array( 'scan', $cursor, $pattern, $count );

		if ( empty( $this->batches ) ) {
			$cursor = 0;
			return array();
		}

		$batch  = array_shift( $this->batches );
		$cursor = $batch['cursor'];
		return $batch['keys'];
	}

	/**
	 * Stand-in for Redis::unlink() -- a client (like this one) that HAS this
	 * method is what makes blueline_srcache_purge_attempt()'s
	 * method_exists() check prefer it. The del()-only fallback path is
	 * exercised with a SEPARATE fake class below
	 * (Blueline_Fake_Redis_Client_No_Unlink) that never declares this
	 * method at all -- PHP's method_exists() cannot be faked on a class
	 * that does declare it.
	 *
	 * @param string[] $keys Keys to unlink.
	 * @return int
	 */
	public function unlink( $keys ) {
		$this->calls[] = array( 'unlink', $keys );
		return count( $keys );
	}

	/**
	 * Stand-in for Redis::del() -- the pre-4.0 fallback.
	 *
	 * @param string[] $keys Keys to delete.
	 * @return int
	 */
	public function del( $keys ) {
		$this->calls[] = array( 'del', $keys );
		return count( $keys );
	}

	/**
	 * Stand-in for Redis::keys() -- the O(N)-blocking call
	 * blueline_srcache_purge_attempt() must NEVER invoke. Recorded like any
	 * other call, so a test can assert this array stays empty even without
	 * relying on method_exists()'s absence to prove it.
	 *
	 * @param string $pattern Pattern (unused; this stand-in exists only to
	 *                        be provably uncalled).
	 * @return string[]
	 */
	public function keys( $pattern ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with Redis::keys(); this stub exists only to be provably uncalled.
		$this->calls[] = array( 'keys', $pattern );
		return array();
	}
}

/**
 * A second Redis client fake, identical to Blueline_Fake_Redis_Client except
 * it has no unlink() method at all -- exercising
 * blueline_srcache_purge_attempt()'s method_exists() fallback to del() for a
 * pre-4.0 Redis server.
 */
final class Blueline_Fake_Redis_Client_No_Unlink {

	/**
	 * Every call made against this fake, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = array();

	/**
	 * Queued SCAN batches; see Blueline_Fake_Redis_Client::$batches.
	 *
	 * @var array<int, array{cursor:int, keys:string[]}>
	 */
	private array $batches;

	/**
	 * Constructor.
	 *
	 * @param array<int, array{cursor:int, keys:string[]}> $batches Queued SCAN batches.
	 */
	public function __construct( array $batches ) {
		$this->batches = $batches;
	}

	/**
	 * See Blueline_Fake_Redis_Client::scan().
	 *
	 * @param int    $cursor  Cursor, passed by reference.
	 * @param string $pattern MATCH pattern.
	 * @param int    $count   COUNT hint.
	 * @return string[]
	 */
	public function scan( &$cursor, $pattern, $count ) {
		$this->calls[] = array( 'scan', $cursor, $pattern, $count );

		if ( empty( $this->batches ) ) {
			$cursor = 0;
			return array();
		}

		$batch  = array_shift( $this->batches );
		$cursor = $batch['cursor'];
		return $batch['keys'];
	}

	/**
	 * See Blueline_Fake_Redis_Client::del().
	 *
	 * @param string[] $keys Keys to delete.
	 * @return int
	 */
	public function del( $keys ) {
		$this->calls[] = array( 'del', $keys );
		return count( $keys );
	}
}

/**
 * A fake standing in for the Redis Object Cache drop-in's global
 * $wp_object_cache: the only method blueline_srcache_purge_attempt() calls
 * on it is redis_instance().
 */
final class Blueline_Fake_Object_Cache {

	/**
	 * The client redis_instance() returns.
	 *
	 * @var object
	 */
	private $redis;

	/**
	 * Constructor.
	 *
	 * @param object $redis The client redis_instance() should return.
	 */
	public function __construct( $redis ) {
		$this->redis = $redis;
	}

	/**
	 * Stand-in for the Redis Object Cache drop-in's public redis_instance().
	 *
	 * @return object
	 */
	public function redis_instance() {
		return $this->redis;
	}
}

/**
 * Covers inc/settings/cache.php: the page-cache purge that ships DISABLED
 * by default (BLUELINE_SRCACHE_PURGE === false), because whether the
 * WordPress object cache's Redis and nginx srcache's Redis share a server
 * AND logical DB index is an unverified infrastructure fact -- see the
 * file's own docblock. This suite proves:
 *
 * - the shipped default (constant off) never touches Redis and always
 *   records the honest manual-purge notice;
 * - the guarded purge, when exercised directly via
 *   blueline_apply_cache_purge_policy( true ), uses SCAN and never KEYS,
 *   prefers UNLINK and falls back to DEL only when unlink() doesn't exist,
 *   and degrades to the same manual notice (without fataling) when the
 *   object-cache shape isn't there.
 */
final class SettingsCacheTest extends TestCase {

	/**
	 * Reset every in-memory store, and the global the Redis Object Cache
	 * drop-in would otherwise populate, before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();

		global $wp_object_cache;
		$wp_object_cache = null; // phpcs:ignore WordPress.Variables.GlobalVariables.OverrideProhibited -- this IS the global the Redis Object Cache drop-in populates in production; a test must control it directly to exercise every guard.
	}

	/**
	 * The shipped-default contract: BLUELINE_SRCACHE_PURGE is false unless
	 * something has already defined it (nothing in this theme does), so
	 * saving settings through the REAL update_option()/hook dispatch -- not
	 * a direct function call -- must never touch Redis at all, and must
	 * record the manual-purge notice.
	 */
	public function test_default_constant_is_off(): void {
		$this->assertFalse( BLUELINE_SRCACHE_PURGE, 'BLUELINE_SRCACHE_PURGE must ship disabled by default' );
	}

	/**
	 * Saving settings with the default (off) constant, via a REAL
	 * update_option() call -- not a direct function call -- must record the
	 * manual-purge notice and must never call redis_instance() at all,
	 * proven here by a fake object cache whose redis_instance() would throw
	 * if called.
	 *
	 * This is deliberately the option's very FIRST-EVER write in this test
	 * (setUp() resets the option store before every test), so it fires
	 * add_option_{$option} -- and blueline_flush_page_cache_on_first_save()
	 * -- not update_option_{$option}; see
	 * test_default_off_path_records_notice_on_a_later_save_too() below for
	 * the update_option_{$option} branch on a save that isn't the first.
	 */
	public function test_default_off_path_records_notice_and_never_touches_redis(): void {
		global $wp_object_cache;
		$wp_object_cache = new class() {
			/**
			 * Would prove the disabled path reached into Redis if ever called.
			 *
			 * @throws \RuntimeException Always -- this method must never run.
			 * @return never
			 */
			public function redis_instance() {
				throw new \RuntimeException( 'redis_instance() must not be called while BLUELINE_SRCACHE_PURGE is off' );
			}
		};

		$this->assertFalse( blueline_cache_purge_needed(), 'notice must not be pre-set before the save' );

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array( 'content' => array( 'contact_email' => 'a@example.com' ) )
		);

		$this->assertTrue( blueline_cache_purge_needed(), 'the honest manual-purge notice must be recorded on a real settings save, including the very first one' );
	}

	/**
	 * The companion to the test above: a save that is NOT the option's
	 * first write fires update_option_{$option} (blueline_flush_page_cache()
	 * itself), rather than add_option_{$option} -- both must reach the same
	 * policy. Without inc/settings/cache.php hooking update_option_{$option}
	 * at all, this is the case that would have silently stopped working.
	 */
	public function test_default_off_path_records_notice_on_a_later_save_too(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'content' => array( 'contact_email' => 'a@example.com' ) ) );
		blueline_clear_cache_purge_needed(); // Undo the first save's own notice so this assertion is about the SECOND save only.

		update_option( BLUELINE_SETTINGS_OPTION, array( 'content' => array( 'contact_email' => 'b@example.com' ) ) );

		$this->assertTrue( blueline_cache_purge_needed(), 'a save that is not the option\'s first write must also record the manual-purge notice' );
	}

	/**
	 * The disabled branch of blueline_apply_cache_purge_policy(), exercised
	 * in isolation, must mark the notice and nothing else.
	 */
	public function test_policy_disabled_marks_notice(): void {
		blueline_apply_cache_purge_policy( false );

		$this->assertTrue( blueline_cache_purge_needed() );
	}

	/**
	 * Enabling the purge on a site with no Redis Object Cache global at all
	 * (the common case: no persistent object cache) must degrade to the
	 * exact same manual notice, and must not fatal.
	 */
	public function test_policy_enabled_with_no_object_cache_degrades_to_notice(): void {
		blueline_apply_cache_purge_policy( true );

		$this->assertTrue( blueline_cache_purge_needed(), 'enabling the purge with no object cache present must still leave an honest notice, not silently do nothing' );
	}

	/**
	 * Guard 1: $wp_object_cache set to a non-object (e.g. a stray scalar)
	 * must be rejected exactly like "absent".
	 */
	public function test_purge_attempt_rejects_non_object_global(): void {
		global $wp_object_cache;
		$wp_object_cache = 'not-an-object';

		$this->assertFalse( blueline_srcache_purge_attempt() );
	}

	/**
	 * Guard 2: an object without redis_instance() (some other object-cache
	 * backend) must be rejected.
	 */
	public function test_purge_attempt_rejects_object_without_redis_instance_method(): void {
		global $wp_object_cache;
		$wp_object_cache = new class() {
			/**
			 * Deliberately not named redis_instance() -- stands in for any
			 * object-cache backend that isn't the Redis Object Cache drop-in.
			 *
			 * @return void
			 */
			public function something_else() {}
		};

		$this->assertFalse( blueline_srcache_purge_attempt() );
	}

	/**
	 * Guard 3: redis_instance() returning something non-object (e.g. false,
	 * from a client that failed to connect) must be rejected.
	 */
	public function test_purge_attempt_rejects_non_object_redis_instance(): void {
		global $wp_object_cache;
		$wp_object_cache = new Blueline_Fake_Object_Cache( false );

		$this->assertFalse( blueline_srcache_purge_attempt() );
	}

	/**
	 * The core proof the brief asks for: with every guard satisfied, the
	 * purge uses SCAN (never KEYS), and prefers UNLINK when the client
	 * exposes it.
	 */
	public function test_purge_attempt_uses_scan_never_keys_and_prefers_unlink(): void {
		$redis = new Blueline_Fake_Redis_Client(
			array(
				array(
					'cursor' => 0,
					'keys'   => array( 'nginx-cache:GET:example.test:/' ),
				),
			)
		);

		global $wp_object_cache;
		$wp_object_cache = new Blueline_Fake_Object_Cache( $redis );

		$this->assertTrue( blueline_srcache_purge_attempt() );

		$methods_called = array_column( $redis->calls, 0 );

		$this->assertContains( 'scan', $methods_called );
		$this->assertNotContains( 'keys', $methods_called, 'KEYS must never be called -- it blocks Redis single-threaded across a large keyspace' );
		$this->assertContains( 'unlink', $methods_called, 'UNLINK (async reclaim) must be preferred when the client exposes it' );
		$this->assertNotContains( 'del', $methods_called );
	}

	/**
	 * The SCAN pattern must be scoped to this site's own host, derived from
	 * home_url() -- not hardcoded -- and the cursor loop must keep calling
	 * scan() across multiple batches until it reports cursor 0.
	 */
	public function test_purge_attempt_scopes_pattern_to_host_and_follows_cursor_across_batches(): void {
		$redis = new Blueline_Fake_Redis_Client(
			array(
				array(
					'cursor' => 7, // Non-zero: more to scan; the loop must not stop here.
					'keys'   => array( 'nginx-cache:GET:example.test:/one' ),
				),
				array(
					'cursor' => 0, // Zero: SCAN reports the keyspace fully covered.
					'keys'   => array( 'nginx-cache:GET:example.test:/two' ),
				),
			)
		);

		global $wp_object_cache;
		$wp_object_cache = new Blueline_Fake_Object_Cache( $redis );

		$this->assertTrue( blueline_srcache_purge_attempt() );

		$scan_calls = array_values( array_filter( $redis->calls, static fn( $call ) => 'scan' === $call[0] ) );
		$this->assertCount( 2, $scan_calls, 'the cursor loop must keep scanning until cursor 0 is reported' );
		$this->assertSame( 'nginx-cache:*example.test*', $scan_calls[0][2] );

		$unlink_calls = array_values( array_filter( $redis->calls, static fn( $call ) => 'unlink' === $call[0] ) );
		$this->assertCount( 2, $unlink_calls, 'each non-empty batch must be unlinked, not just the last one' );
	}

	/**
	 * A client without unlink() (pre-Redis-4.0) must fall back to del(), not
	 * silently skip deletion or fatal on a missing method.
	 */
	public function test_purge_attempt_falls_back_to_del_without_unlink(): void {
		$redis = new Blueline_Fake_Redis_Client_No_Unlink(
			array(
				array(
					'cursor' => 0,
					'keys'   => array( 'nginx-cache:GET:example.test:/' ),
				),
			)
		);

		global $wp_object_cache;
		$wp_object_cache = new Blueline_Fake_Object_Cache( $redis );

		$this->assertTrue( blueline_srcache_purge_attempt() );

		$methods_called = array_column( $redis->calls, 0 );
		$this->assertContains( 'del', $methods_called );
		$this->assertNotContains( 'unlink', $methods_called );
	}

	/**
	 * A batch with no keys at all must not call unlink()/del() with an
	 * empty array -- nothing to remove, nothing to call.
	 */
	public function test_purge_attempt_skips_unlink_for_empty_batch(): void {
		$redis = new Blueline_Fake_Redis_Client(
			array(
				array(
					'cursor' => 0,
					'keys'   => array(),
				),
			)
		);

		global $wp_object_cache;
		$wp_object_cache = new Blueline_Fake_Object_Cache( $redis );

		$this->assertTrue( blueline_srcache_purge_attempt() );

		$methods_called = array_column( $redis->calls, 0 );
		$this->assertNotContains( 'unlink', $methods_called );
		$this->assertNotContains( 'del', $methods_called );
	}

	/**
	 * Calling blueline_apply_cache_purge_policy( true ), with every guard
	 * satisfied, must clear a previously-recorded manual-purge notice -- the
	 * purge running successfully is what makes the manual instruction stale.
	 */
	public function test_policy_enabled_with_working_purge_clears_notice(): void {
		blueline_mark_cache_purge_needed();
		$this->assertTrue( blueline_cache_purge_needed() );

		$redis = new Blueline_Fake_Redis_Client(
			array(
				array(
					'cursor' => 0,
					'keys'   => array( 'nginx-cache:GET:example.test:/' ),
				),
			)
		);

		global $wp_object_cache;
		$wp_object_cache = new Blueline_Fake_Object_Cache( $redis );

		blueline_apply_cache_purge_policy( true );

		$this->assertFalse( blueline_cache_purge_needed(), 'a successful guarded purge must clear the manual notice' );
	}

	/**
	 * The exact notice text and command an admin sees -- pinned so a future
	 * edit cannot silently drop the command or soften the message into a
	 * shrug without a failing test to answer for it.
	 */
	public function test_notice_message_and_command_exact_text(): void {
		$this->assertSame(
			'Blueline settings were saved, but the page cache was not purged automatically -- the front end will keep serving the old version of any page these settings affect until it is. Run this on the server to clear it:',
			blueline_cache_purge_notice_message()
		);

		$this->assertSame(
			"redis-cli --scan --pattern 'nginx-cache:*example.test*' | xargs -r redis-cli unlink",
			blueline_cache_purge_command( blueline_cache_purge_host() )
		);
	}

	/**
	 * The purge-command host must be derived from home_url() via
	 * blueline_cache_purge_host(), rather than a hardcoded string.
	 */
	public function test_cache_purge_host_derives_from_home_url(): void {
		$this->assertSame( 'example.test', blueline_cache_purge_host() );
	}

	/**
	 * Calling blueline_cache_purge_command( '' ) must NEVER build the unsafe
	 * `nginx-cache:**` pattern -- an empty host would otherwise produce a
	 * command that matches (and UNLINKs) every key in that Redis, not just
	 * this site's, including any other site sharing the instance. It must
	 * return '' instead, which is what tells the notice renderer to show
	 * blueline_cache_purge_unresolvable_host_message() rather than a
	 * command at all.
	 */
	public function test_purge_command_refuses_to_build_a_pattern_for_an_empty_host(): void {
		$command = blueline_cache_purge_command( '' );

		$this->assertSame( '', $command );
		$this->assertStringNotContainsString( 'nginx-cache:', $command );
	}

	/**
	 * The notice-path guard the reviewer asked for, proven at the exact
	 * decision point blueline_render_cache_purge_notice() branches on: with
	 * an unresolvable host, the admin must see the plain "could not be
	 * parsed" explanation and NOTHING that looks like a command -- never a
	 * command built from an empty pattern, and never the ordinary command
	 * either (there is no safe one to show).
	 */
	public function test_notice_path_with_unresolvable_host_shows_no_command(): void {
		$unresolvable_host = ''; // What blueline_cache_purge_host() returns when home_url() can't be parsed.

		$command = blueline_cache_purge_command( $unresolvable_host );
		$this->assertSame( '', $command, 'no command must be generated for an unresolvable host' );

		$shown_to_admin = ( '' !== $command )
			? $command
			: blueline_cache_purge_unresolvable_host_message();

		$this->assertSame(
			"This site's URL could not be parsed, so a safe purge command could not be generated. Contact your host to purge the Redis-backed page cache manually.",
			$shown_to_admin
		);
		$this->assertStringNotContainsString( 'nginx-cache:**', $shown_to_admin );
		$this->assertStringNotContainsString( 'redis-cli', $shown_to_admin, 'no command of any shape must appear when the host is unresolvable' );
	}

	/**
	 * The notice-needed flag helpers: mark, clear, and read back.
	 */
	public function test_notice_flag_mark_and_clear(): void {
		$this->assertFalse( blueline_cache_purge_needed() );

		blueline_mark_cache_purge_needed();
		$this->assertTrue( blueline_cache_purge_needed() );

		blueline_clear_cache_purge_needed();
		$this->assertFalse( blueline_cache_purge_needed() );
	}
}
