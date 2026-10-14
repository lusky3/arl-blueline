<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Runs scripts/check-template-versions.sh against fixture theme and plugin trees,
 * and checks the real overrides all name their source.
 */
final class TemplateVersionCheckTest extends TestCase {

	/**
	 * Fixture root for the current test.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Build a fixture theme and plugins directory that agree.
	 */
	protected function setUp(): void {
		exec( 'command -v bash', $unused, $found ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- probing for bash in a test.
		if ( 0 !== $found ) {
			$this->markTestSkipped( 'bash is not installed.' );
		}

		$this->dir = sys_get_temp_dir() . '/bl-tplcheck-' . getmypid() . '-' . $this->name();
		$this->put( 'plugins/woocommerce/woocommerce.php', "<?php\n/**\n * Plugin Name: WooCommerce\n * Version: 11.0.1\n */\n" );
		$this->put( 'plugins/woocommerce/templates/emails/note.php', "<?php\n/**\n * @version 10.4.0\n */\n" );
		$this->put( 'plugins/woocommerce/templates/cart/cart-empty.php', "<?php\n/**\n * @version 7.0.1\n */\n" );
		$this->put( 'plugins/paypal/paypal.php', "<?php\n/**\n * Plugin Name: PayPal\n * Version: 4.6.15\n */\n" );
		$this->put( 'plugins/paypal/template/emails/paid.php', "<?php\n// No version here.\n" );
		$this->put( 'plugins/sportspress-pro/sportspress-pro.php', "<?php\n/**\n * Plugin Name: SportsPress Pro\n * Version: 2.7.29\n */\n" );
		$this->put( 'plugins/sportspress-pro/includes/sportspress/templates/event-list.php', "<?php\n/**\n * @version     2.7.23\n */\n" );

		$this->put( 'theme/woocommerce/emails/note.php', "<?php\n/**\n * Copied from WooCommerce 11.0.1: woocommerce/templates/emails/note.php\n *\n * @version 10.4.0\n */\n" );
		$this->put( 'theme/woocommerce/emails/paid.php', "<?php\n/**\n * Copied from PayPal 4.6.15: paypal/template/emails/paid.php\n *\n * @package blueline\n */\n" );
		$this->put( 'theme/woocommerce/cart/cart-empty.php', "<?php\n/**\n * @version 7.0.1\n */\n" );
		$this->put( 'theme/sportspress/event-list.php', "<?php\n/**\n * @package blueline\n * @version 2.7.23 (copied from SportsPress Pro 2.7.29: sportspress-pro/includes/sportspress/templates/event-list.php)\n */\n" );
		$this->put( 'theme/sportspress/single-event.php', "<?php\n/**\n * Upstream: none (theme-authored).\n *\n * @package blueline\n */\n" );
		$this->put( 'theme/sportspress/index.php', "<?php\n// Silence is golden.\n" );
	}

	/**
	 * Remove the fixture.
	 */
	protected function tearDown(): void {
		if ( '' !== $this->dir && is_dir( $this->dir ) ) {
			exec( 'rm -rf ' . escapeshellarg( $this->dir ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- test cleanup of a temp dir.
		}
	}

	/**
	 * Write a fixture file.
	 *
	 * @param string $rel      Path under the fixture root.
	 * @param string $contents File contents.
	 */
	private function put( string $rel, string $contents ): void {
		$path = $this->dir . '/' . $rel;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test fixture.
		}
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
	}

	/**
	 * Run the check against the fixture.
	 *
	 * @return array{0: int, 1: string} Exit status and output.
	 */
	private function check(): array {
		exec( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- running the script under test.
			sprintf(
				'BLUELINE_THEME_DIR=%s BLUELINE_PLUGINS_DIR=%s bash %s 2>&1',
				escapeshellarg( $this->dir . '/theme' ),
				escapeshellarg( $this->dir . '/plugins' ),
				escapeshellarg( dirname( __DIR__, 3 ) . '/scripts/check-template-versions.sh' )
			),
			$output,
			$status
		);

		return array( $status, implode( "\n", $output ) );
	}

	/**
	 * Matching versions pass, and every kind of override is listed.
	 */
	public function test_all_matching_passes(): void {
		list( $status, $output ) = $this->check();

		$this->assertSame( 0, $status, $output );
		$this->assertStringContainsString( 'All overrides match their originals.', $output );
		$this->assertMatchesRegularExpression( '#sportspress/event-list\.php\s+2\.7\.23\s+2\.7\.23\s+ok#', $output );
		$this->assertMatchesRegularExpression( '#woocommerce/cart/cart-empty\.php\s+7\.0\.1\s+7\.0\.1\s+ok#', $output, 'a WooCommerce override with no Copied-from line is compared with templates/ of the same path' );
		$this->assertStringContainsString( 'original unversioned; PayPal still 4.6.15', $output );
		$this->assertStringContainsString( 'theme-authored', $output );
		$this->assertStringNotContainsString( 'index.php', $output );
	}

	/**
	 * A newer original is drift and fails.
	 */
	public function test_newer_original_is_drift(): void {
		$this->put( 'plugins/woocommerce/templates/emails/note.php', "<?php\n/**\n * @version 11.1.0\n */\n" );

		list( $status, $output ) = $this->check();

		$this->assertSame( 1, $status, $output );
		$this->assertMatchesRegularExpression( '#woocommerce/emails/note\.php\s+10\.4\.0\s+11\.1\.0\s+DRIFT#', $output );
	}

	/**
	 * An unversioned original whose plugin moved on needs a look.
	 */
	public function test_unversioned_original_after_plugin_update_needs_review(): void {
		$this->put( 'plugins/paypal/paypal.php', "<?php\n/**\n * Plugin Name: PayPal\n * Version: 4.7.0\n */\n" );

		list( $status, $output ) = $this->check();

		$this->assertSame( 1, $status, $output );
		$this->assertStringContainsString( 'REVIEW: original unversioned and PayPal moved 4.6.15 -> 4.7.0', $output );
	}

	/**
	 * A removed original fails, and so does an override that names no source.
	 */
	public function test_missing_original_and_missing_source_fail(): void {
		unlink( $this->dir . '/plugins/sportspress-pro/includes/sportspress/templates/event-list.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing a test fixture file; no WordPress here.
		$this->put( 'theme/sportspress/team-logo.php', "<?php\n/**\n * @package blueline\n */\n" );

		list( $status, $output ) = $this->check();

		$this->assertSame( 1, $status, $output );
		$this->assertMatchesRegularExpression( '#sportspress/event-list\.php.+MISSING#', $output );
		$this->assertMatchesRegularExpression( '#sportspress/team-logo\.php.+NO-SOURCE#', $output );
	}

	/**
	 * Every override this theme ships names its source (or says it has none), so the check can follow it.
	 */
	public function test_real_overrides_record_their_source(): void {
		$root  = dirname( __DIR__ );
		$files = array_merge(
			(array) glob( $root . '/woocommerce/emails/*.php' ),
			(array) glob( $root . '/woocommerce/myaccount/*store-credit*.php' ),
			array( $root . '/woocommerce/myaccount/my-refund-requests.php' ),
			array_filter(
				(array) glob( $root . '/sportspress/*.php' ),
				static function ( $file ) {
					return 'index.php' !== basename( $file );
				}
			)
		);

		$this->assertGreaterThan( 30, count( $files ), 'premise: overrides found' );
		foreach ( $files as $file ) {
			$head = (string) file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test.
			$this->assertMatchesRegularExpression(
				'#[Cc]opied from .+ \d[\d.]*: [^ )]+\.php|Upstream: none#',
				$head,
				basename( dirname( $file ) ) . '/' . basename( $file ) . ' names no source'
			);
		}
	}
}
