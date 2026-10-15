<?php
/**
 * Unit tests for the version-disclosure half of includes/privacy/privacy.php.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/privacy/privacy.php';

/**
 * Covers the generator tags (WordPress, WooCommerce, SportsPress) and the WordPress version in core
 * asset URLs.
 */
final class VersionDisclosureTest extends TestCase {

	/**
	 * Fresh hooks and request, a known core version.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_wp_version_saved'] = $GLOBALS['wp_version'] ?? null;
		$GLOBALS['wp_version']               = '7.1.3';
	}

	/**
	 * Restore globals.
	 */
	protected function tearDown(): void {
		$GLOBALS['wp_version'] = $GLOBALS['bl_test_wp_version_saved'];
		unset( $GLOBALS['bl_test_wp_version_saved'], $GLOBALS['bl_test_is_admin'] );
	}

	/**
	 * Every generator tag goes, in the page head and in feeds. WooCommerce and SportsPress (and its Pro
	 * add-on) append their own tags to core's through the get_the_generator_{type} filters, so emptying
	 * the_generator, which runs on the combined string, removes all of them.
	 */
	public function test_generator_output_is_empty(): void {
		$combined = "<meta name=\"generator\" content=\"WordPress 7.1.3\" />\n<meta name=\"generator\" content=\"SportsPress 2.7.31\" />\n<meta name=\"generator\" content=\"WooCommerce 11.2.1\" />";

		$this->assertSame( '', apply_filters( 'the_generator', $combined, 'xhtml' ) );
		$this->assertSame( '', apply_filters( 'the_generator', '<generator>https://wordpress.org/?v=7.1.3</generator>', 'rss2' ) );
	}

	/**
	 * Core's wp_head generator action is removed.
	 */
	public function test_wp_head_generator_action_is_removed(): void {
		add_action( 'wp_head', 'wp_generator' );

		blueline_core_hide_generator_tags();

		$this->assertFalse( has_action( 'wp_head', 'wp_generator' ) );
	}

	/**
	 * The core version in a front-end asset URL becomes an opaque token that still changes with every
	 * core update, so browsers and caches still fetch the new file after an upgrade.
	 */
	public function test_core_version_in_asset_url_is_masked(): void {
		$src    = 'https://example.test/wp-includes/css/dist/block-library/style.min.css?ver=7.1.3';
		$masked = blueline_core_mask_core_version( $src );

		$this->assertStringNotContainsString( '7.1.3', $masked );
		$this->assertMatchesRegularExpression( '~^https://example\.test/wp-includes/css/dist/block-library/style\.min\.css\?ver=[0-9a-f]{12}$~', $masked );
		$this->assertSame( $masked, blueline_core_mask_core_version( $src ), 'stable for one version' );

		$GLOBALS['wp_version'] = '7.1.4';
		$this->assertNotSame( $masked, blueline_core_mask_core_version( 'https://example.test/wp-includes/css/dist/block-library/style.min.css?ver=7.1.4' ), 'changes with the core version' );
	}

	/**
	 * The version is matched as a whole query value wherever it sits in the query; the rest of the URL
	 * is kept.
	 */
	public function test_core_version_masking_keeps_the_rest_of_the_url(): void {
		$masked = blueline_core_mask_core_version( 'https://example.test/wp-includes/js/jquery/jquery.min.js?a=1&ver=7.1.3&b=2#x' );

		$this->assertMatchesRegularExpression( '~^https://example\.test/wp-includes/js/jquery/jquery\.min\.js\?a=1&ver=[0-9a-f]{12}&b=2#x$~', $masked );
	}

	/**
	 * Theme and plugin assets keep their own versions: a content hash, a plugin version, or a version
	 * that only starts with the core version's digits.
	 *
	 * @return void
	 */
	public function test_other_versions_are_untouched(): void {
		foreach ( array(
			'https://example.test/wp-content/themes/blueline/assets/dist/index.css?ver=3f9a1c2b7d4e',
			'https://example.test/wp-content/plugins/woocommerce/assets/css/woocommerce.css?ver=11.2.1',
			'https://example.test/wp-content/plugins/x/x.js?ver=7.1.30',
			'https://example.test/wp-content/plugins/x/x.js?version=7.1.3',
			'https://example.test/wp-includes/js/no-version.js',
		) as $src ) {
			$this->assertSame( $src, blueline_core_mask_core_version( $src ), $src );
		}
	}

	/**
	 * The admin keeps core's own versions (admins can see the version anyway).
	 */
	public function test_admin_urls_are_untouched(): void {
		$GLOBALS['bl_test_is_admin'] = true;
		$src                         = 'https://example.test/wp-includes/css/dashicons.min.css?ver=7.1.3';

		$this->assertSame( $src, blueline_core_mask_core_version( $src ) );
	}

	/**
	 * Anything other than a string passes through.
	 */
	public function test_non_string_src_passes_through(): void {
		$this->assertFalse( blueline_core_mask_core_version( false ) );
	}

	/**
	 * Hooked on both script and stylesheet URLs.
	 */
	public function test_mask_is_hooked_on_script_and_style_urls(): void {
		$this->assertNotFalse( has_filter( 'script_loader_src', 'blueline_core_mask_core_version' ) );
		$this->assertNotFalse( has_filter( 'style_loader_src', 'blueline_core_mask_core_version' ) );
	}
}
