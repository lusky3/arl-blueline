<?php
/**
 * Unit tests for the account-endpoints module's routing and menu gating.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/account-endpoints/account-endpoints.php';

/**
 * Covers blueline_account_endpoint_is_active(), the gated blueline_account_menu_items()
 * and blueline_register_account_rewrite_endpoints().
 */
final class AccountEndpointRoutingTest extends TestCase {

	/**
	 * The four league slugs the theme renders.
	 */
	private const LEAGUE_SLUGS = array( 'my-team', 'my-schedule', 'player-profile', 'preferences' );

	/**
	 * Reset stub stores and drop any renderer a theme file registered at load time.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		foreach ( self::LEAGUE_SLUGS as $slug ) {
			unset( $GLOBALS['bl_test_hooks'][ "woocommerce_account_{$slug}_endpoint" ] );
		}
		$GLOBALS['blueline_core_test_rewrite_endpoints'] = array();
	}

	/**
	 * Register a renderer for each slug, as the theme does.
	 *
	 * @param string[] $slugs Endpoint slugs.
	 */
	private function add_renderers( array $slugs ): void {
		foreach ( $slugs as $slug ) {
			add_action( "woocommerce_account_{$slug}_endpoint", '__return_true' );
		}
	}

	/**
	 * Run the menu filter over WooCommerce's two fixed items.
	 *
	 * @return array<string, string>
	 */
	private function menu(): array {
		return blueline_account_menu_items(
			array(
				'dashboard'       => 'Dashboard',
				'customer-logout' => 'Log out',
			)
		);
	}

	/**
	 * The plugin's league list matches the endpoint map's league and preferences groups.
	 */
	public function test_league_slugs_match_the_endpoint_map(): void {
		$this->assertSame( self::LEAGUE_SLUGS, blueline_account_league_endpoint_slugs() );

		foreach ( blueline_account_league_endpoint_slugs() as $slug ) {
			$this->assertContains( blueline_account_endpoints()[ $slug ]['group'], array( 'league', 'preferences' ), $slug );
		}
	}

	/**
	 * With every renderer present and no filter, the full menu renders in order.
	 */
	public function test_full_menu_when_every_renderer_exists(): void {
		$this->add_renderers( self::LEAGUE_SLUGS );

		$this->assertSame(
			array( 'dashboard', 'my-team', 'my-schedule', 'player-profile', 'preferences', 'orders', 'store-credit', 'refund-requests', 'payment-methods', 'edit-address', 'edit-account', 'customer-logout' ),
			array_keys( $this->menu() )
		);
	}

	/**
	 * A league endpoint without a renderer (another theme) is left out of the menu.
	 */
	public function test_league_endpoints_without_a_renderer_are_omitted(): void {
		$this->add_renderers( array( 'my-team' ) );

		$items = $this->menu();

		$this->assertArrayHasKey( 'my-team', $items );
		foreach ( array( 'my-schedule', 'player-profile', 'preferences' ) as $slug ) {
			$this->assertArrayNotHasKey( $slug, $items, $slug );
		}
	}

	/**
	 * A league endpoint the filter denies is left out even though a renderer exists.
	 */
	public function test_league_endpoints_the_filter_denies_are_omitted(): void {
		$this->add_renderers( self::LEAGUE_SLUGS );
		add_filter(
			'blueline_core_account_endpoint_enabled',
			static fn( $enabled, $slug ) => 'my-schedule' === $slug ? false : $enabled,
			10,
			2
		);

		$items = $this->menu();

		$this->assertArrayNotHasKey( 'my-schedule', $items );
		$this->assertArrayHasKey( 'my-team', $items );
		$this->assertArrayHasKey( 'player-profile', $items );
		$this->assertArrayHasKey( 'preferences', $items );
	}

	/**
	 * The filter receives true and the slug, once per rendered league endpoint and never for billing slugs.
	 */
	public function test_filter_receives_the_slug_for_league_endpoints_only(): void {
		$this->add_renderers( self::LEAGUE_SLUGS );
		$calls = new \ArrayObject();
		add_filter(
			'blueline_core_account_endpoint_enabled',
			static function ( $enabled, $slug ) use ( $calls ) {
				$calls[] = array( $enabled, $slug );
				return $enabled;
			},
			10,
			2
		);

		$this->menu();

		$this->assertSame(
			array( array( true, 'my-team' ), array( true, 'my-schedule' ), array( true, 'player-profile' ), array( true, 'preferences' ) ),
			$calls->getArrayCopy()
		);
	}

	/**
	 * The filter is not consulted for a league slug that has no renderer.
	 */
	public function test_filter_is_skipped_without_a_renderer(): void {
		add_filter(
			'blueline_core_account_endpoint_enabled',
			static function () {
				throw new \LogicException( 'filter must not run without a renderer' );
			}
		);

		$this->assertFalse( blueline_account_endpoint_is_active( 'my-team' ) );
	}

	/**
	 * Billing slugs (registrations via the orders query var, store-credit) stay even with no renderers and a deny-all filter.
	 */
	public function test_billing_slugs_are_always_in_the_menu(): void {
		add_filter( 'blueline_core_account_endpoint_enabled', '__return_false' );

		$items = $this->menu();

		$this->assertSame( 'My Registrations', $items['orders'] );
		$this->assertSame( 'Credits', $items['store-credit'] );
		foreach ( self::LEAGUE_SLUGS as $slug ) {
			$this->assertArrayNotHasKey( $slug, $items, $slug );
		}
	}

	/**
	 * The orders -> registrations slug remap does not depend on any renderer or filter.
	 */
	public function test_registrations_remap_is_unconditional(): void {
		add_filter( 'blueline_core_account_endpoint_enabled', '__return_false' );

		$this->assertSame(
			array(
				'orders'       => 'registrations',
				'store-credit' => 'store-credit',
			),
			blueline_remap_account_query_vars(
				array(
					'orders'       => 'orders',
					'store-credit' => 'store-credit',
				)
			)
		);
	}

	/**
	 * League rewrite endpoints register even without a renderer, so stored rules survive theme switches.
	 */
	public function test_league_rewrite_endpoints_register_without_a_renderer(): void {
		blueline_register_account_rewrite_endpoints();

		$names = array_column( $GLOBALS['blueline_core_test_rewrite_endpoints'], 'name' );
		$this->assertSame( self::LEAGUE_SLUGS, array_slice( $names, 0, 4 ) );
		foreach ( $GLOBALS['blueline_core_test_rewrite_endpoints'] as $endpoint ) {
			$this->assertSame( EP_ROOT | EP_PAGES, $endpoint['places'] );
		}
	}

	/**
	 * Without WooCommerce's query object the legacy catch endpoints are skipped, never registered blind.
	 */
	public function test_legacy_endpoints_are_skipped_without_wc_query_vars(): void {
		$this->assertFalse( function_exists( 'WC' ), 'this test assumes no WC() stub' );

		blueline_register_account_rewrite_endpoints();

		$names = array_column( $GLOBALS['blueline_core_test_rewrite_endpoints'], 'name' );
		$this->assertNotContains( 'orders', $names );
		$this->assertNotContains( 'credit', $names );
	}

	/**
	 * Routing hooks are registered at file scope on the original hooks, below the flush priority (99).
	 */
	public function test_hooks_are_registered_like_the_theme_did(): void {
		$this->assertSame( 10, has_action( 'init', 'blueline_register_account_rewrite_endpoints' ) );
		$this->assertSame( 10, has_action( 'init', 'blueline_register_account_endpoint_title_filters' ) );
		$this->assertSame( 20, has_filter( 'woocommerce_get_query_vars', 'blueline_remap_account_query_vars' ) );
		$this->assertSame( 10, has_filter( 'woocommerce_account_menu_items', 'blueline_account_menu_items' ) );
		$this->assertSame( 10, has_action( 'template_redirect', 'blueline_redirect_legacy_account_endpoints' ) );
	}

	/**
	 * The plugin never flushes on a theme switch and never calls flush_rewrite_rules() itself.
	 */
	public function test_no_theme_switch_flush(): void {
		$this->assertFalse( has_action( 'after_switch_theme', 'flush_rewrite_rules' ) );
		$this->assertStringNotContainsString( 'flush_rewrite_rules', $this->module_source() );
	}

	/**
	 * The plugin reads theme decisions only through the filter, never the theme's settings store.
	 */
	public function test_module_never_reads_theme_settings(): void {
		$source = $this->module_source();

		foreach ( array( 'blueline_settings', 'blueline_section_enabled', 'BLUELINE_SETTINGS_OPTION', 'blueline_account_endpoint_section_keys' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $source, $needle );
		}
	}

	/**
	 * The module file's source.
	 *
	 * @return string
	 */
	private function module_source(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/includes/account-endpoints/account-endpoints.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	}
}
