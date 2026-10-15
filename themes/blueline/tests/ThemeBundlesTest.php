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
require_once __DIR__ . '/../inc/announcement.php';
require_once __DIR__ . '/../inc/floating-next-game.php';

/**
 * The theme's CSS and JS ship as a small core bundle plus feature bundles,
 * each loaded only on requests that can render its markup.
 */
final class ThemeBundlesTest extends TestCase {

	/**
	 * A page with none of the features loads the core bundle only.
	 */
	public function test_a_plain_page_needs_no_feature_bundle(): void {
		$this->assertSame(
			array(
				'styles'  => array(),
				'scripts' => array(),
			),
			blueline_theme_bundles( array() )
		);
	}

	/**
	 * The homepage modules print SportsPress standings, so the homepage also
	 * loads the SportsPress bundle, ahead of its own in the cascade.
	 */
	public function test_the_homepage_loads_sportspress_before_its_own_bundle(): void {
		$bundles = blueline_theme_bundles( array( 'homepage' => true ) );

		$this->assertSame( array( 'sportspress', 'homepage' ), $bundles['styles'] );
		$this->assertSame( array( 'sportspress', 'homepage' ), $bundles['scripts'] );
	}

	/**
	 * My Account loads the commerce, forms and account bundles and nothing of
	 * SportsPress.
	 */
	public function test_my_account_bundles(): void {
		$bundles = blueline_theme_bundles(
			array(
				'commerce'      => true,
				'cf7'           => true,
				'account'       => true,
				'sponsor_slots' => true,
			)
		);

		$this->assertSame( array( 'woocommerce', 'forms', 'account' ), $bundles['styles'] );
		$this->assertSame( array( 'woocommerce', 'sponsors', 'forms', 'account' ), $bundles['scripts'] );
	}

	/**
	 * Script-only and style-only bundles: the sponsor slots' script has no
	 * stylesheet (core styles the slots), the Sponsors page's styles no script.
	 */
	public function test_script_only_and_style_only_bundles(): void {
		$this->assertSame(
			array(
				'styles'  => array(),
				'scripts' => array( 'sponsors' ),
			),
			blueline_theme_bundles( array( 'sponsor_slots' => true ) )
		);
		$this->assertSame(
			array(
				'styles'  => array( 'sponsors-page' ),
				'scripts' => array(),
			),
			blueline_theme_bundles( array( 'sponsors_page' => true ) )
		);
	}

	/**
	 * With every feature on, the stylesheets keep the cascade order they had
	 * as partials of one file, and each gate maps to its own bundle.
	 */
	public function test_every_bundle_in_cascade_order(): void {
		$bundles = blueline_theme_bundles(
			array(
				'announcement'       => true,
				'floating_next_game' => true,
				'sponsors_page'      => true,
				'sportspress'        => true,
				'sponsor_slots'      => true,
				'occasion'           => true,
				'homepage'           => true,
				'commerce'           => true,
				'cf7'                => true,
				'account'            => true,
			)
		);

		$this->assertSame( blueline_style_bundle_names(), $bundles['styles'] );
		$this->assertSame( array_values( array_diff( blueline_script_bundle_names(), array( 'quotes-llama-alt' ) ) ), $bundles['scripts'] );
	}

	/**
	 * Only the sponsor script needs jQuery (its global ajaxComplete event).
	 */
	public function test_only_the_sponsor_script_depends_on_jquery(): void {
		foreach ( blueline_script_bundle_names() as $bundle ) {
			$extra = blueline_script_bundle_extra_dependencies( $bundle );
			$this->assertSame( 'sponsors' === $bundle ? array( 'jquery' ) : array(), $extra, $bundle );
		}

		$this->assertNotContains( 'jquery', blueline_dist_dependencies( 'index' ) );
		$this->assertSame( array(), blueline_dist_dependencies( 'no-such-bundle' ) );
	}

	/**
	 * A SportsPress widget in any widget area needs the bundle; the Countdown
	 * and Sponsors widgets, inactive widgets and other widgets do not.
	 */
	public function test_sportspress_widgets_that_need_the_bundle(): void {
		$this->assertFalse( blueline_sidebars_hold_sportspress_widget( array() ) );
		$this->assertFalse(
			blueline_sidebars_hold_sportspress_widget(
				array(
					'sidebar-1'           => array( 'sportspress-countdown-2', 'recent-posts-2', 'sportspress-sponsors-3' ),
					'wp_inactive_widgets' => array( 'sportspress-event-list-4' ),
					'footer-1'            => null,
				)
			)
		);
		$this->assertTrue( blueline_sidebars_hold_sportspress_widget( array( 'footer-2' => array( 'block-5', 'sportspress-league-table-7' ) ) ) );
	}

	/**
	 * Every SportsPress and league add-on shortcode this site's content uses
	 * matches one of the needles.
	 */
	public function test_needles_cover_the_sportspress_shortcodes_in_use(): void {
		$needles = blueline_sportspress_content_needles();

		foreach ( array( '[event_list id="1"]', '[league_table 5]', '[team_standings 3]', '[player_list 9]', '[team_gallery 2]', '[countdown]', '[event_scoreboard]', '[arl_schedule]', '[arl_standings]', '<!-- wp:sportspress/event-list /-->' ) as $content ) {
			$found = false;
			foreach ( $needles as $needle ) {
				$found = $found || false !== stripos( $content, $needle );
			}
			$this->assertTrue( $found, $content );
		}

		foreach ( array( '[contact-form-7 id="1"]', '[su_tabs]', '[product_page id="5"]', '[sponsors]' ) as $content ) {
			foreach ( $needles as $needle ) {
				$this->assertFalse( stripos( $content, $needle ), "$content matched $needle" );
			}
		}
	}

	/**
	 * SportsPress post types, taxonomies and archives all start with sp_.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sportspress_views(): void {
		$this->define_query_classes();

		$GLOBALS['bl_test_queried'] = null;
		$this->assertFalse( blueline_is_sportspress_view() );

		foreach ( array( 'sp_event', 'sp_team', 'sp_sponsor', 'sp_calendar' ) as $type ) {
			$GLOBALS['bl_test_queried'] = new WP_Post( $type );
			$this->assertTrue( blueline_is_sportspress_view(), $type );
		}

		$GLOBALS['bl_test_queried'] = new WP_Post( 'page' );
		$this->assertFalse( blueline_is_sportspress_view() );

		$GLOBALS['bl_test_queried']           = new WP_Term();
		$GLOBALS['bl_test_queried']->taxonomy = 'sp_venue';
		$this->assertTrue( blueline_is_sportspress_view() );
		$GLOBALS['bl_test_queried']->taxonomy = 'category';
		$this->assertFalse( blueline_is_sportspress_view() );

		$GLOBALS['bl_test_queried']       = new WP_Post_Type();
		$GLOBALS['bl_test_queried']->name = 'sp_player';
		$this->assertTrue( blueline_is_sportspress_view() );
	}

	/**
	 * A shortcode in the queried page, or in any post of the main query (an
	 * archive printing posts in full), loads the bundle; the filter can force
	 * either answer.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_shortcodes_in_the_page_or_the_main_query(): void {
		$this->define_query_classes();
		blueline_test_reset_state();

		$GLOBALS['bl_test_queried'] = new WP_Post( 'page', 'Intro [event_list id="4"]' );
		$this->assertTrue( blueline_request_needs_sportspress() );

		$GLOBALS['bl_test_queried'] = new WP_Post( 'page', 'Plain text' );
		$this->assertFalse( blueline_request_needs_sportspress() );

		$GLOBALS['bl_test_queried']   = null;
		$GLOBALS['wp_query']          = new WP_Query();
		$GLOBALS['wp_query']->posts[] = new WP_Post( 'post', 'Nothing here' );
		$this->assertFalse( blueline_request_needs_sportspress() );
		$GLOBALS['wp_query']->posts[] = new WP_Post( 'post', 'Standings: [league_table 9]' );
		$this->assertTrue( blueline_request_needs_sportspress() );

		add_filter( 'blueline_load_sportspress_assets', static fn() => false );
		$this->assertFalse( blueline_request_needs_sportspress() );
	}

	/**
	 * A SportsPress template rendering on a page that did not load the bundle
	 * enqueues it then; the sponsor and countdown templates (styled by the
	 * core bundle) and admin/AJAX renders do not.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_sportspress_template_enqueues_the_bundle_as_a_safety_net(): void {
		eval( 'function wp_enqueue_style( $h ) { $GLOBALS["bl_test_enqueued"]["styles"][] = $h; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- records enqueues.
		eval( 'function wp_enqueue_script( $h ) { $GLOBALS["bl_test_enqueued"]["scripts"][] = $h; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- records enqueues.

		foreach ( array( 'countdown.php', 'sponsors.php', 'sponsors-content.php' ) as $template ) {
			blueline_sportspress_template_assets( $template );
		}
		$this->assertArrayNotHasKey( 'bl_test_enqueued', $GLOBALS );

		$GLOBALS['bl_test_is_admin'] = true;
		blueline_sportspress_template_assets( 'event-list.php' );
		$this->assertArrayNotHasKey( 'bl_test_enqueued', $GLOBALS );

		$GLOBALS['bl_test_is_admin'] = false;
		blueline_sportspress_template_assets( 'event-list.php' );
		$this->assertSame(
			array(
				'styles'  => array( 'blueline-sportspress' ),
				'scripts' => array( 'blueline-sportspress' ),
			),
			$GLOBALS['bl_test_enqueued']
		);
	}

	/**
	 * The real enqueue pass on a plain page: core CSS and JS (deferred, in the
	 * footer, without jQuery), every feature bundle registered for late use,
	 * no feature stylesheet enqueued, and the sponsor script declaring jQuery.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_enqueue_pass_on_a_plain_page(): void {
		$this->define_query_classes();
		blueline_test_reset_state();
		$GLOBALS['bl_test_queried'] = new WP_Post( 'page', 'Plain text' );
		$GLOBALS['bl_test_assets']  = array();

		foreach ( array( 'wp_enqueue_style', 'wp_register_style', 'wp_enqueue_script', 'wp_register_script' ) as $fn ) {
			eval( "function {$fn}( \$handle, \$src = '', \$deps = array(), \$ver = false, \$args = null ) { \$GLOBALS['bl_test_assets']['{$fn}'][ \$handle ] = array( 'src' => \$src, 'deps' => \$deps, 'args' => \$args ); }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- records the asset API calls.
		}
		eval( 'function wp_style_add_data( $handle, $key, $value ) { $GLOBALS["bl_test_assets"]["rtl"][ $handle ] = $value; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- records RTL swaps.
		eval( 'function is_page_template( $t = "" ) { return false; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- default template.
		eval( 'function comments_open() { return false; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- no comments.
		if ( ! function_exists( 'blueline_resolve_active_occasion' ) ) {
			eval( 'function blueline_resolve_active_occasion() { return null; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- no occasion running.
		}
		if ( ! defined( 'BLUELINE_URI' ) ) {
			define( 'BLUELINE_URI', 'https://example.test/wp-content/themes/blueline' );
		}

		blueline_enqueue_assets();
		$assets = $GLOBALS['bl_test_assets'];

		$this->assertSame( array( 'blueline-tokens', 'blueline' ), array_keys( $assets['wp_enqueue_style'] ) );
		$this->assertSame( array( 'blueline', 'blueline-sponsors' ), array_keys( $assets['wp_enqueue_script'] ), 'only the sponsor slots (on by default) add a script' );
		$this->assertNotContains( 'jquery', $assets['wp_enqueue_script']['blueline']['deps'] );
		$this->assertSame(
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			),
			$assets['wp_enqueue_script']['blueline']['args']
		);

		foreach ( blueline_style_bundle_names() as $bundle ) {
			$this->assertSame( array( 'blueline' ), $assets['wp_register_style'][ 'blueline-' . $bundle ]['deps'], $bundle );
			$this->assertStringEndsWith( "/assets/dist/{$bundle}.css", $assets['wp_register_style'][ 'blueline-' . $bundle ]['src'] );
			$this->assertSame( 'replace', $assets['rtl'][ 'blueline-' . $bundle ] );
		}

		foreach ( blueline_script_bundle_names() as $bundle ) {
			$registered = $assets['wp_register_script'][ 'blueline-' . $bundle ];
			$this->assertStringEndsWith( "/assets/dist/{$bundle}.js", $registered['src'] );
			$this->assertSame( 'defer', $registered['args']['strategy'], $bundle );
			$this->assertTrue( $registered['args']['in_footer'], $bundle );
			$this->assertSame( 'sponsors' === $bundle, in_array( 'jquery', $registered['deps'], true ), $bundle );
		}
	}

	/**
	 * Every bundle the PHP names is a webpack entry and has been built, so a
	 * renamed or forgotten entry cannot 404 on a page.
	 */
	public function test_every_named_bundle_is_a_built_webpack_entry(): void {
		$config = $this->read( 'webpack.config.js' );
		$dist   = dirname( __DIR__ ) . '/assets/dist/';

		foreach ( blueline_style_bundle_names() as $bundle ) {
			$this->assertMatchesRegularExpression( '/[\s\'"]' . preg_quote( $bundle, '/' ) . '[\'"]?:/', $config, $bundle );
			$this->assertFileExists( $dist . $bundle . '.css' );
			$this->assertFileExists( $dist . $bundle . '-rtl.css' );
			$this->assertFileExists( $dist . $bundle . '.asset.php' );
		}

		foreach ( blueline_script_bundle_names() as $bundle ) {
			$this->assertMatchesRegularExpression( '/[\s\'"]' . preg_quote( $bundle, '/' ) . '[\'"]?:/', $config, $bundle );
			$this->assertGreaterThan( 0, (int) filesize( $dist . $bundle . '.js' ), "$bundle.js is built and not empty" );
		}
	}

	/**
	 * Each CSS partial reaches exactly one bundle, and each feature module
	 * exactly one script: nothing is dropped, and nothing loads twice on a
	 * page that has two bundles.
	 */
	public function test_each_partial_and_module_is_in_exactly_one_bundle(): void {
		$config = $this->read( 'webpack.config.js' );
		$index  = $this->read( 'assets/src/css/index.css' );

		preg_match_all( "/css\\(\\s*'([\\w-]+)'\\s*\\)/", $config, $entry_css );
		preg_match_all( '/@import "\.\/([\w-]+)\.css";/', $index, $imported );
		$css = array_merge( $entry_css[1], $imported[1], array( 'index', 'editor' ) );
		sort( $css );

		$partials = array_map(
			static function ( $path ) {
				return basename( $path, '.css' );
			},
			(array) glob( dirname( __DIR__ ) . '/assets/src/css/*.css' )
		);
		sort( $partials );

		$this->assertSame( $partials, $css );

		preg_match_all( "/js\\(\\s*'([\\w-]+)'\\s*\\)/", $config, $entry_js );
		preg_match_all( "/import '\\.\\/([\\w-]+)\\.js';/", $this->read( 'assets/src/js/index.js' ), $core_js );
		$modules = array_merge( $entry_js[1], $core_js[1] );

		$this->assertSame( array_unique( $modules ), $modules, 'a module is in two bundles' );
	}

	/**
	 * Read a theme file.
	 *
	 * @param string $path Relative to the theme root.
	 * @return string
	 */
	private function read( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file in a unit test.
	}

	/**
	 * WP_Post / WP_Term / WP_Post_Type / WP_Query shapes and
	 * get_queried_object() reading $GLOBALS['bl_test_queried']. Must run in
	 * a separate process.
	 *
	 * @return void
	 */
	private function define_query_classes(): void {
		eval( 'class WP_Post { public $post_type; public $post_content; public function __construct( $type = "post", $content = "" ) { $this->post_type = $type; $this->post_content = $content; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WordPress class shape.
		eval( 'class WP_Term { public $taxonomy = ""; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WordPress class shape.
		eval( 'class WP_Post_Type { public $name = ""; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WordPress class shape.
		eval( 'class WP_Query { public $posts = array(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WordPress class shape.
		eval( 'function get_queried_object() { return $GLOBALS["bl_test_queried"] ?? null; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the queried object.
	}
}
