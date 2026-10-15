<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/enqueue.php';
require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Plugin assets that block the first paint are kept only on requests that
 * render their feature; jQuery leaves <head> when nothing there needs it;
 * the header logo stops taking the page's fetch priority; one theme-color.
 */
final class RenderBlockingAssetsTest extends TestCase {

	/**
	 * Every handle a request with no special features drops.
	 */
	public function test_a_plain_page_drops_every_gated_plugin_asset(): void {
		$drop = blueline_unused_plugin_asset_handles( array() );

		foreach ( array( 'contact-form-7', 'fue-account-subscriptions', 'angelleye-paypal-checkout-sdk', 'angelleye_ppcp-google-pay', 'angelleye_ppcp-apple-pay', 'angelleye_ppcp-common-functions', 'angelleye_ppcp' ) as $script ) {
			$this->assertContains( $script, $drop['scripts'] );
		}

		foreach ( array( 'follow-up-emails', 'sportspress-league-menu', 'sportspress-results-matrix', 'sportspress-event-statistics', 'sportspress-user-scores', 'sportspress-scoreboard', 'sportspress-scoreboard-ltr', 'quotes-llama-css-style', 'dashicons' ) as $style ) {
			$this->assertContains( $style, $drop['styles'] );
		}
	}

	/**
	 * The PayPal plugin's stylesheet stays everywhere: it also sets the
	 * add-to-cart form's spacing, so dropping it moved the product page.
	 */
	public function test_the_payment_stylesheet_is_kept(): void {
		$this->assertNotContains( 'angelleye_ppcp', blueline_unused_plugin_asset_handles( array() )['styles'] );
	}

	/**
	 * Stylesheets the theme builds on are never touched, and Leaflet stays
	 * unless the event map is click-to-load.
	 */
	public function test_base_sportspress_styles_and_leaflet_are_kept_by_default(): void {
		$drop = blueline_unused_plugin_asset_handles( array() );

		foreach ( array( 'sportspress-general', 'sportspress-icons', 'sportspress-sponsors', 'leaflet_stylesheet' ) as $style ) {
			$this->assertNotContains( $style, $drop['styles'] );
		}

		$this->assertNotContains( 'leaflet_js', $drop['scripts'] );
	}

	/**
	 * My Account (and FUE's own endpoints) keep Follow-Up Emails' form script
	 * and styles; a signed-in visitor keeps dashicons for the admin bar.
	 */
	public function test_account_requests_keep_fue_and_signed_in_visitors_keep_dashicons(): void {
		$drop = blueline_unused_plugin_asset_handles(
			array(
				'fue'       => true,
				'logged_in' => true,
			)
		);

		$this->assertNotContains( 'fue-account-subscriptions', $drop['scripts'] );
		$this->assertNotContains( 'follow-up-emails', $drop['styles'] );
		$this->assertNotContains( 'dashicons', $drop['styles'] );
	}

	/**
	 * An event keeps the match-stats, user-scores and scoreboard styles (the
	 * last also styles the theme's own .sp-scoreboard hero) and drops Leaflet
	 * when the map is click-to-load.
	 */
	public function test_an_event_keeps_its_module_styles_and_drops_leaflet_for_the_click_to_load_map(): void {
		$drop = blueline_unused_plugin_asset_handles(
			array(
				'sp_event'     => true,
				'sp_event_map' => true,
			)
		);

		foreach ( array( 'sportspress-event-statistics', 'sportspress-user-scores', 'sportspress-scoreboard', 'sportspress-scoreboard-ltr' ) as $style ) {
			$this->assertNotContains( $style, $drop['styles'] );
		}

		$this->assertContains( 'leaflet_js', $drop['scripts'] );
		$this->assertContains( 'leaflet_stylesheet', $drop['styles'] );
		$this->assertContains( 'sportspress-league-menu', $drop['styles'] );
	}

	/**
	 * SportsPress's own scoreboard and results matrix keep their stylesheets
	 * wherever they are in use.
	 */
	public function test_scoreboard_and_matrix_keep_their_styles_where_used(): void {
		$drop = blueline_unused_plugin_asset_handles(
			array(
				'sp_scoreboard' => true,
				'sp_matrix'     => true,
			)
		);

		$this->assertNotContains( 'sportspress-scoreboard', $drop['styles'] );
		$this->assertNotContains( 'sportspress-results-matrix', $drop['styles'] );
		$this->assertContains( 'sportspress-event-statistics', $drop['styles'] );
	}

	/**
	 * Cart, checkout and add-payment-method keep every payment asset, and the
	 * filter restores them everywhere.
	 */
	public function test_payment_assets_stay_on_payment_pages_or_when_the_filter_keeps_them(): void {
		foreach ( array( 'payment_page', 'keep_payment_assets' ) as $flag ) {
			$drop = blueline_unused_plugin_asset_handles( array( $flag => true ) );

			$this->assertSame( array(), preg_grep( '/angelleye/', $drop['scripts'] ), $flag );
			$this->assertNotContains( 'angelleye_ppcp', $drop['styles'], $flag );
		}
	}

	/**
	 * A rendered Quotes Llama widget keeps the plugin's base stylesheet.
	 */
	public function test_a_rendered_quotes_widget_keeps_its_stylesheet(): void {
		$drop = blueline_unused_plugin_asset_handles( array( 'quotes_widget' => true ) );

		$this->assertNotContains( 'quotes-llama-css-style', $drop['styles'] );
	}

	/**
	 * The dequeue pass reads the real request: an event page with the OSM
	 * map module, logged out, no widgets, on no payment page.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_dequeue_pass_on_a_logged_out_event_page(): void {
		$this->define_request_stubs();
		blueline_test_reset_state();
		blueline_test_set_queried_post_type( 'sp_event' );
		$this->hook_map_module( 'SportsPress_OpenStreetMap' );

		blueline_dequeue_unused_plugin_assets();

		$this->assertContains( 'leaflet_js', $GLOBALS['bl_test_dequeued']['scripts'] );
		$this->assertContains( 'angelleye-paypal-checkout-sdk', $GLOBALS['bl_test_dequeued']['scripts'] );
		$this->assertContains( 'dashicons', $GLOBALS['bl_test_dequeued']['styles'] );
		$this->assertNotContains( 'sportspress-scoreboard', $GLOBALS['bl_test_dequeued']['styles'] );
	}

	/**
	 * With SportsPress's Google Maps module drawing venue maps, the event page
	 * keeps whatever that module loads: the placeholder is Leaflet-only.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_dequeue_pass_leaves_maps_alone_when_google_maps_draws_them(): void {
		$this->define_request_stubs();
		blueline_test_reset_state();
		blueline_test_set_queried_post_type( 'sp_event' );
		$this->hook_map_module( 'SportsPress_Google_Maps' );

		blueline_dequeue_unused_plugin_assets();

		$this->assertNotContains( 'leaflet_js', $GLOBALS['bl_test_dequeued']['scripts'] );
		$this->assertFalse( blueline_venue_map_is_click_to_load() );
	}

	/**
	 * Checkout keeps every payment script and Follow-Up Emails' opt-in assets.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_dequeue_pass_on_checkout_keeps_payment_and_fue_assets(): void {
		$this->define_request_stubs( array( 'checkout' => true ) );
		blueline_test_reset_state();

		blueline_dequeue_unused_plugin_assets();

		$this->assertSame( array(), preg_grep( '/angelleye/', $GLOBALS['bl_test_dequeued']['scripts'] ) );
		$this->assertNotContains( 'follow-up-emails', $GLOBALS['bl_test_dequeued']['styles'] );
	}

	/**
	 * A widget rendered after the head's dequeue pass enqueues the stylesheet
	 * again, and the footer pass then leaves it alone.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_quotes_widget_rendering_late_re_enqueues_its_stylesheet(): void {
		$this->define_request_stubs();
		blueline_test_reset_state();
		eval( 'function wp_enqueue_style( $h ) { $GLOBALS["bl_test_enqueued"][] = $h; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- records enqueues.

		blueline_dequeue_unused_plugin_assets();
		$this->assertContains( 'quotes-llama-css-style', $GLOBALS['bl_test_dequeued']['styles'] );

		$other           = new stdClass();
		$other->id_base  = 'recent-posts';
		$quotes          = new stdClass();
		$quotes->id_base = 'widgetquotesllama';
		blueline_note_quotes_llama_widget( array( 'callback' => array( $other, 'display_callback' ) ) );
		$this->assertArrayNotHasKey( 'bl_test_enqueued', $GLOBALS );

		blueline_note_quotes_llama_widget( array( 'callback' => array( $quotes, 'display_callback' ) ) );
		$this->assertSame( array( 'quotes-llama-css-style' ), $GLOBALS['bl_test_enqueued'] );

		$GLOBALS['bl_test_dequeued'] = array(
			'scripts' => array(),
			'styles'  => array(),
		);
		blueline_dequeue_unused_plugin_assets();
		$this->assertNotContains( 'quotes-llama-css-style', $GLOBALS['bl_test_dequeued']['styles'] );
	}

	/**
	 * The dependency walk follows deps recursively, once each, and ignores
	 * handles that are not registered.
	 */
	public function test_script_dependency_closure(): void {
		$registered = array(
			'woocommerce' => (object) array( 'deps' => array( 'jquery', 'js-cookie' ) ),
			'jquery'      => (object) array( 'deps' => array( 'jquery-core', 'jquery-migrate' ) ),
			'jquery-core' => (object) array( 'deps' => array() ),
			'js-cookie'   => (object) array( 'deps' => array() ),
			'theme'       => (object) array( 'deps' => array( 'jquery', 'missing' ) ),
		);

		$closure = blueline_script_dependency_closure( $registered, array( 'woocommerce', 'theme' ) );
		sort( $closure );

		$this->assertSame( array( 'jquery', 'jquery-core', 'js-cookie', 'theme', 'woocommerce' ), $closure );
	}

	/**
	 * Deferred and async head scripts join jQuery in the footer group;
	 * blocking head scripts are left for WordPress to keep jQuery up top.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_jquery_and_deferred_head_scripts_move_to_the_footer_group(): void {
		$scripts                    = $this->fake_scripts(
			array(
				'wc-blockui'   => array( array( 'jquery' ), array( 'strategy' => 'defer' ) ),
				'fue-account'  => array( array( 'jquery' ), array() ),
				'already-foot' => array(
					array(),
					array(
						'strategy' => 'defer',
						'group'    => 1,
					),
				),
				'jquery'       => array( array( 'jquery-core', 'jquery-migrate' ), array() ),
				'jquery-core'  => array( array(), array() ),
			),
			array( 'wc-blockui', 'fue-account', 'already-foot' )
		);
		$GLOBALS['bl_test_scripts'] = $scripts;
		eval( 'function wp_scripts() { return $GLOBALS["bl_test_scripts"]; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WordPress's script registry stand-in.
		eval( 'function did_action( $hook ) { return "wp_head" === $hook ? 1 : 0; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a theme template's head is printing.

		blueline_move_jquery_to_footer();

		$this->assertSame( 1, $scripts->get_data( 'wc-blockui', 'group' ) );
		$this->assertFalse( $scripts->get_data( 'fue-account', 'group' ) );
		$this->assertSame( 1, $scripts->get_data( 'jquery', 'group' ) );
		$this->assertSame( 1, $scripts->get_data( 'jquery-core', 'group' ) );
		$this->assertSame( 1, $scripts->get_data( 'jquery-migrate', 'group' ) );
	}

	/**
	 * The filter keeps jQuery where the plugins put it.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_filter_keeps_jquery_in_the_head(): void {
		$scripts                    = $this->fake_scripts( array( 'jquery' => array( array(), array() ) ), array( 'jquery' ) );
		$GLOBALS['bl_test_scripts'] = $scripts;
		eval( 'function wp_scripts() { return $GLOBALS["bl_test_scripts"]; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WordPress's script registry stand-in.
		eval( 'function did_action( $hook ) { return "wp_head" === $hook ? 1 : 0; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a theme template's head is printing.
		add_filter( 'blueline_jquery_in_footer', static fn() => false );

		blueline_move_jquery_to_footer();

		$this->assertFalse( $scripts->get_data( 'jquery', 'group' ) );
	}

	/**
	 * The login screen prints head scripts without wp_head() and never runs
	 * wp_footer(), so jQuery stays where it is there.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_jquery_stays_put_outside_theme_templates(): void {
		$scripts                    = $this->fake_scripts( array( 'jquery' => array( array(), array() ) ), array( 'jquery' ) );
		$GLOBALS['bl_test_scripts'] = $scripts;
		eval( 'function wp_scripts() { return $GLOBALS["bl_test_scripts"]; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WordPress's script registry stand-in.
		eval( 'function did_action( $hook ) { return 0; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the login screen: no wp_head.

		blueline_move_jquery_to_footer();

		$this->assertFalse( $scripts->get_data( 'jquery', 'group' ) );
	}

	/**
	 * At the top of the footer jQuery prints once, and only when a script on
	 * the page needs it and it has not printed yet.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_footer_jquery_prints_only_when_needed_and_not_yet_printed(): void {
		$GLOBALS['bl_test_scripts'] = new class() {
			/**
			 * Query answers by "handle:status".
			 *
			 * @var array<string, bool>
			 */
			public array $answers = array();

			/**
			 * Handles printed.
			 *
			 * @var string[]
			 */
			public array $printed = array();

			/**
			 * Stand-in for WP_Scripts::query().
			 *
			 * @param string $handle Handle.
			 * @param string $status Status.
			 * @return bool
			 */
			public function query( $handle, $status ) {
				return ! empty( $this->answers[ "$handle:$status" ] );
			}

			/**
			 * Stand-in for WP_Scripts::do_items().
			 *
			 * @param string[] $handles Handles.
			 * @return void
			 */
			public function do_items( $handles ) {
				$this->printed = array_merge( $this->printed, $handles );
			}
		};
		eval( 'function wp_scripts() { return $GLOBALS["bl_test_scripts"]; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WordPress's script registry stand-in.
		$scripts = $GLOBALS['bl_test_scripts'];

		blueline_print_footer_jquery();
		$this->assertSame( array(), $scripts->printed, 'not needed' );

		$scripts->answers['jquery:enqueued'] = true;
		$scripts->answers['jquery:done']     = true;
		blueline_print_footer_jquery();
		$this->assertSame( array(), $scripts->printed, 'already printed in the head' );

		$scripts->answers['jquery:done'] = false;
		blueline_print_footer_jquery();
		$this->assertSame( array( 'jquery' ), $scripts->printed );
	}

	/**
	 * Only the Browser Theme Color plugin's own wp_head callback is found.
	 */
	public function test_hooked_methods_finds_only_the_named_class_and_method(): void {
		eval( 'class Browser_Theme_Color { public function output_theme_color_meta() {} public function other() {} }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the plugin's class.
		$plugin    = new Browser_Theme_Color();
		$callbacks = array(
			1  => array( 'a' => array( 'function' => 'wp_enqueue_scripts' ) ),
			10 => array(
				'b' => array( 'function' => array( $plugin, 'output_theme_color_meta' ) ),
				'c' => array( 'function' => array( $plugin, 'other' ) ),
				'd' => array( 'function' => array( new stdClass(), 'output_theme_color_meta' ) ),
			),
		);

		$found = blueline_hooked_methods( $callbacks, 'Browser_Theme_Color', 'output_theme_color_meta' );

		$this->assertCount( 1, $found );
		$this->assertSame( 10, $found[0]['priority'] );
		$this->assertSame( $plugin, $found[0]['callback'][0] );
	}

	/**
	 * The plugin's theme-color callback is removed from wp_head.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_plugin_theme_color_tag_is_unhooked(): void {
		eval( 'class Browser_Theme_Color { public function output_theme_color_meta() { echo "plugin"; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the plugin's class.
		blueline_test_reset_hooks();
		$plugin = new Browser_Theme_Color();
		add_action( 'wp_head', array( $plugin, 'output_theme_color_meta' ) );
		$GLOBALS['wp_filter']['wp_head'] = (object) array(
			'callbacks' => array( 10 => array( 'x' => array( 'function' => array( $plugin, 'output_theme_color_meta' ) ) ) ),
		);

		blueline_unhook_plugin_theme_color();

		$remaining = array();
		foreach ( $GLOBALS['bl_test_hooks']['wp_head'] as $bucket ) {
			foreach ( $bucket as $hook ) {
				$remaining[] = $hook['cb'];
			}
		}

		$this->assertNotContains( array( $plugin, 'output_theme_color_meta' ), $remaining );
		$this->assertContains( 'blueline_preload_fonts', $remaining );
	}

	/**
	 * The header's theme-color follows the ink-deep brand colour.
	 */
	public function test_header_prints_one_theme_color_from_the_brand_token(): void {
		$header = (string) file_get_contents( __DIR__ . '/../header.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertSame( 1, substr_count( $header, 'name="theme-color"' ) );
		$this->assertStringContainsString( "blueline_resolved_brand_color( 'ink_deep' )", $header );
		$this->assertSame( '#0D1729', blueline_resolved_brand_color( 'ink_deep' ) );
	}

	/**
	 * The logo gives up fetchpriority; hero images claim it and load eagerly.
	 */
	public function test_logo_and_hero_image_loading_attributes(): void {
		$logo = blueline_custom_logo_fetch_priority(
			array(
				'class'   => 'custom-logo',
				'loading' => false,
			)
		);

		$this->assertFalse( $logo['fetchpriority'] );
		$this->assertFalse( $logo['loading'] );

		$entry = blueline_entry_thumbnail_attributes();
		$this->assertSame( 'high', $entry['fetchpriority'] );
		$this->assertSame( 'eager', $entry['loading'] );
		$this->assertSame( '(max-width: 1200px) calc(100vw - 48px), 1152px', $entry['sizes'] );

		$this->assertSame(
			array(
				'alt'   => '',
				'sizes' => '56px',
			),
			blueline_sp_scoreboard_crest_attributes()
		);

		$team = blueline_sp_hero_crest_attributes( 'team' );
		$this->assertSame( '76px', $team['sizes'] );
		$this->assertSame( 'high', $team['fetchpriority'] );
		$this->assertSame( '', $team['alt'] );
		$this->assertSame( '96px', blueline_sp_hero_crest_attributes( 'staff' )['sizes'] );
	}

	/**
	 * The emoji detection script and its styles are unhooked.
	 */
	public function test_emoji_detection_is_unhooked(): void {
		blueline_test_reset_hooks();
		add_action( 'wp_head', 'print_emoji_detection_script', 7 );
		add_action( 'wp_print_styles', 'print_emoji_styles' );

		blueline_disable_emoji_detection();

		$this->assertSame( array(), $GLOBALS['bl_test_hooks']['wp_head'][7] );
		$this->assertSame( array(), $GLOBALS['bl_test_hooks']['wp_print_styles'][10] );
	}

	/**
	 * Stand-ins for the WordPress/WooCommerce request API the dequeue pass
	 * reads. Must run in a separate process.
	 *
	 * @param array<string, bool> $woo WooCommerce conditionals answering true.
	 * @return void
	 */
	private function define_request_stubs( array $woo = array() ): void {
		$GLOBALS['bl_test_woo']      = $woo;
		$GLOBALS['bl_test_dequeued'] = array(
			'scripts' => array(),
			'styles'  => array(),
		);

		eval( 'function wp_dequeue_script( $h ) { $GLOBALS["bl_test_dequeued"]["scripts"][] = $h; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- records dequeues.
		eval( 'function wp_dequeue_style( $h ) { $GLOBALS["bl_test_dequeued"]["styles"][] = $h; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- records dequeues.
		eval( 'function is_active_widget() { return false; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- no widgets on this request.
		eval( 'function get_queried_object() { return null; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- no queried post.
		eval( 'function is_account_page() { return ! empty( $GLOBALS["bl_test_woo"]["account"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce conditional tag stand-in.
		eval( 'function is_cart() { return ! empty( $GLOBALS["bl_test_woo"]["cart"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce conditional tag stand-in.
		eval( 'function is_checkout() { return ! empty( $GLOBALS["bl_test_woo"]["checkout"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce conditional tag stand-in.
	}

	/**
	 * Register a SportsPress map module's show_venue_map() on sp_venue_show_map.
	 *
	 * @param string $class_name SportsPress_OpenStreetMap or SportsPress_Google_Maps.
	 * @return void
	 */
	private function hook_map_module( string $class_name ): void {
		eval( "class {$class_name} { public function show_venue_map() {} }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the SportsPress module's class.
		$GLOBALS['wp_filter']['sp_venue_show_map'] = (object) array(
			'callbacks' => array( 10 => array( 'x' => array( 'function' => array( new $class_name(), 'show_venue_map' ) ) ) ),
		);
	}

	/**
	 * A WP_Scripts-shaped registry.
	 *
	 * @param array<string, array{0: string[], 1: array<string, mixed>}> $handles Handle => [deps, extra data].
	 * @param string[]                                                   $queue   Enqueued handles.
	 * @return object
	 */
	private function fake_scripts( array $handles, array $queue ): object {
		$scripts        = new class() {
			/**
			 * Registered handles.
			 *
			 * @var array<string, object>
			 */
			public array $registered = array();

			/**
			 * Enqueued handles.
			 *
			 * @var string[]
			 */
			public array $queue = array();

			/**
			 * Stand-in for WP_Scripts::get_data().
			 *
			 * @param string $handle Handle.
			 * @param string $key    Key.
			 * @return mixed
			 */
			public function get_data( $handle, $key ) {
				return $this->registered[ $handle ]->extra[ $key ] ?? false;
			}

			/**
			 * Stand-in for WP_Scripts::add_data().
			 *
			 * @param string $handle Handle.
			 * @param string $key    Key.
			 * @param mixed  $value  Value.
			 * @return bool
			 */
			public function add_data( $handle, $key, $value ) {
				if ( ! isset( $this->registered[ $handle ] ) ) {
					return false;
				}

				$this->registered[ $handle ]->extra[ $key ] = $value;

				return true;
			}
		};
		$scripts->queue = $queue;

		foreach ( $handles as $handle => $spec ) {
			$scripts->registered[ $handle ] = (object) array(
				'deps'  => $spec[0],
				'extra' => $spec[1],
			);
		}

		$scripts->registered['jquery-migrate'] = (object) array(
			'deps'  => array(),
			'extra' => array(),
		);

		return $scripts;
	}
}
