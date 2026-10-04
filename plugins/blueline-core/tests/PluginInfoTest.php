<?php
/**
 * Unit tests for the plugin-info module (View details, info pop-up, icons without WordPress.org).
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/plugin-info/plugin-info.php';

/**
 * Covers includes/plugin-info/plugin-info.php.
 */
final class PluginInfoTest extends TestCase {

	/**
	 * Temp files created by a test.
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	/**
	 * Remove temp files.
	 */
	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the test's own temp file.
			}
		}
		$this->temp_files = array();
	}

	/**
	 * Write a readme to a unique temp path (the parser caches per path).
	 *
	 * @param string $contents Readme text.
	 * @return string Path.
	 */
	private function temp_readme( string $contents ): string {
		$path = sys_get_temp_dir() . '/blueline-readme-' . uniqid( '', true ) . '.txt';
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture in the temp dir.

		$this->temp_files[] = $path;

		return $path;
	}

	/**
	 * The hooks are registered.
	 */
	public function test_it_registers_both_filters(): void {
		$this->assertNotFalse( has_filter( 'plugins_api', 'blueline_core_plugin_info_api' ) );
		$this->assertNotFalse( has_filter( 'site_transient_update_plugins', 'blueline_core_plugin_info_known_plugin' ) );
	}

	/**
	 * Our slug and the plugin_information action get the plugin's data.
	 */
	public function test_plugins_api_answers_for_our_slug(): void {
		$info = blueline_core_plugin_info_api( false, 'plugin_information', (object) array( 'slug' => 'blueline-core' ) );

		$this->assertInstanceOf( stdClass::class, $info );
		$this->assertSame( 'Blueline Core', $info->name );
		$this->assertSame( 'blueline-core', $info->slug );
		$this->assertSame( BLUELINE_CORE_VERSION, $info->version );
		$this->assertSame( '6.9', $info->requires );
		$this->assertSame( '8.3', $info->requires_php );
		$this->assertSame( '7.1', $info->tested );
		$this->assertSame( 'https://github.com/lusky3/rookiehockey-blueline', $info->homepage );
		$this->assertStringContainsString( 'Adult Recreational League', $info->author );
		// The author links to the Author URI, not the Plugin URI.
		$this->assertStringContainsString( '<a href="https://www.rookiehockey.ca">', $info->author );
		$this->assertSame( 'https://www.rookiehockey.ca', $info->author_profile );
		$this->assertSame( '', $info->download_link );
		$this->assertObjectNotHasProperty( 'last_updated', $info );
		$this->assertSame( array( 'description', 'installation', 'changelog' ), array_keys( $info->sections ) );
		$this->assertStringContainsString( '<h4>0.1.0</h4>', $info->sections['changelog'] );
		$this->assertStringContainsString( '<ol><li>Upload the zip', $info->sections['installation'] );
	}

	/**
	 * The args may arrive as an array too.
	 */
	public function test_plugins_api_accepts_array_args(): void {
		$this->assertInstanceOf( stdClass::class, blueline_core_plugin_info_api( false, 'plugin_information', array( 'slug' => 'blueline-core' ) ) );
	}

	/**
	 * Other slugs and other actions pass through untouched.
	 */
	public function test_plugins_api_passes_everything_else_through(): void {
		$previous = (object) array( 'name' => 'Someone else' );

		$this->assertFalse( blueline_core_plugin_info_api( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
		$this->assertSame( $previous, blueline_core_plugin_info_api( $previous, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
		$this->assertFalse( blueline_core_plugin_info_api( false, 'query_plugins', (object) array( 'slug' => 'blueline-core' ) ) );
		$this->assertFalse( blueline_core_plugin_info_api( false, 'hot_tags', (object) array( 'slug' => 'blueline-core' ) ) );
		$this->assertFalse( blueline_core_plugin_info_api( false, 'plugin_information', (object) array() ) );
		$this->assertFalse( blueline_core_plugin_info_api( false, 'plugin_information', null ) );
	}

	/**
	 * The dispatch goes through the registered filter.
	 */
	public function test_the_registered_filter_answers_through_apply_filters(): void {
		$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'blueline-core' ) );

		$this->assertSame( 'blueline-core', $info->slug );
	}

	/**
	 * Icon and banner URLs point at the fixed names in assets/ through plugins_url().
	 */
	public function test_icons_and_banners_point_at_the_assets_names(): void {
		$info = blueline_core_plugin_info_data();
		$base = 'https://example.test/wp-content/plugins/' . basename( BLUELINE_CORE_DIR ) . '/assets/';

		// Each URL is the asset's name plus ?ver=<mtime>, so a redrawn image is never served stale by a CDN.
		$names = static function ( array $urls ) use ( $base ): array {
			return array_map(
				static function ( string $url ) use ( $base ): string {
					$this_is = explode( '?ver=', $url );
					self::assertStringStartsWith( $base, $this_is[0] );
					self::assertMatchesRegularExpression( '/^\d+$/', $this_is[1] ?? '', "{$url} must carry a numeric ?ver=." );

					return substr( $this_is[0], strlen( $base ) );
				},
				$urls
			);
		};

		$this->assertSame(
			array(
				'1x'      => 'icon-128x128.png',
				'2x'      => 'icon-256x256.png',
				'svg'     => 'icon.svg',
				'default' => 'icon-256x256.png',
			),
			$names( $info->icons )
		);
		$this->assertSame(
			array(
				'low'  => 'banner-772x250.png',
				'high' => 'banner-1544x500.png',
			),
			$names( $info->banners )
		);
	}

	/**
	 * The bundled readme.txt parses: headers, three sections, and its Stable tag matches the plugin.
	 */
	public function test_the_bundled_readme_matches_the_plugin(): void {
		$readme = blueline_core_plugin_info_readme();

		$this->assertSame( 'Blueline Core', $readme['headers']['name'] );
		$this->assertSame( BLUELINE_CORE_VERSION, $readme['headers']['stable tag'] );
		$this->assertSame( 'lusky3', $readme['headers']['contributors'] );
		$this->assertArrayHasKey( 'description', $readme['sections'] );
		$this->assertArrayHasKey( 'installation', $readme['sections'] );
		$this->assertArrayHasKey( 'changelog', $readme['sections'] );
	}

	/**
	 * Markup: headings, lists, paragraphs and inline formatting.
	 */
	public function test_markup_builds_headings_lists_paragraphs_and_inline(): void {
		$body = "= 1.2.3 =\n* first **bold** item\n* second `code` item\n\nA paragraph\ncontinued here.\n\n1. one\n2. two";

		$this->assertSame(
			'<h4>1.2.3</h4><ul><li>first <strong>bold</strong> item</li><li>second <code>code</code> item</li></ul>'
			. '<p>A paragraph continued here.</p><ol><li>one</li><li>two</li></ol>',
			blueline_core_plugin_info_markup( $body )
		);
		$this->assertSame( '', blueline_core_plugin_info_markup( '' ) );
	}

	/**
	 * Hostile readme content is escaped, never emitted as markup.
	 */
	public function test_hostile_readme_lines_are_escaped(): void {
		$path = $this->temp_readme(
			"=== Evil ===\nTested up to: 7.1\n\n== Description ==\n<script>alert(1)</script>\n* <img src=x onerror=alert(1)> **b** `<b>`\n\n"
			. "== Changelog ==\n= <i>1.0</i> =\n* [click](javascript:alert(1)) <a href=\"javascript:alert(1)\">x</a>\n"
		);

		$info = blueline_core_plugin_info_data( $path );
		$all  = implode( '', $info->sections );

		$this->assertStringNotContainsString( '<script', $all );
		$this->assertStringNotContainsString( '<img', $all );
		$this->assertStringNotContainsString( '<a ', $all );
		$this->assertStringNotContainsString( '<i>', $all );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $info->sections['description'] );
		$this->assertStringContainsString( '<strong>b</strong>', $info->sections['description'] );
		$this->assertStringContainsString( '<code>&lt;b&gt;</code>', $info->sections['description'] );
	}

	/**
	 * A missing readme degrades to the plugin header description, with no warning.
	 */
	public function test_a_missing_readme_degrades_without_warnings(): void {
		$info = blueline_core_plugin_info_data( sys_get_temp_dir() . '/blueline-no-such-readme-' . uniqid( '', true ) . '.txt' );

		$this->assertSame( array( 'description' ), array_keys( $info->sections ) );
		$this->assertStringContainsString( 'League functionality for the Blueline theme', $info->sections['description'] );
		$this->assertSame( '', $info->tested );
		$this->assertSame( '6.9', $info->requires, 'The header still supplies the requirements.' );
		$this->assertSame( BLUELINE_CORE_VERSION, $info->version );
	}

	/**
	 * Garbage in the readme neither warns nor yields sections.
	 */
	public function test_an_empty_readme_yields_no_sections_but_the_header_description(): void {
		$info = blueline_core_plugin_info_data( $this->temp_readme( '' ) );

		$this->assertSame( array( 'description' ), array_keys( $info->sections ) );
	}

	/**
	 * A readme is read once per path in a request.
	 */
	public function test_the_readme_is_parsed_once_per_path(): void {
		$path  = $this->temp_readme( "=== X ===\nTested up to: 6.9\n\n== Description ==\nFirst.\n" );
		$first = blueline_core_plugin_info_readme( $path );

		file_put_contents( $path, "=== X ===\nTested up to: 1.0\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture in the temp dir.

		$this->assertSame( $first, blueline_core_plugin_info_readme( $path ) );
	}

	/**
	 * A false or non-object transient is returned unchanged.
	 */
	public function test_the_transient_filter_tolerates_non_objects(): void {
		$this->assertFalse( blueline_core_plugin_info_known_plugin( false ) );
		$this->assertNull( blueline_core_plugin_info_known_plugin( null ) );
		$this->assertSame( 'x', blueline_core_plugin_info_known_plugin( 'x' ) );
		$this->assertSame( array( 'a' ), blueline_core_plugin_info_known_plugin( array( 'a' ) ) );
	}

	/**
	 * The entry is added to no_update when absent, and everything else is kept.
	 */
	public function test_the_transient_filter_adds_the_entry_when_absent(): void {
		$file  = plugin_basename( BLUELINE_CORE_FILE );
		$other = (object) array( 'slug' => 'akismet' );
		$value = (object) array(
			'last_checked' => 123,
			'response'     => array( 'hello/hello.php' => (object) array( 'slug' => 'hello' ) ),
			'no_update'    => array( 'akismet/akismet.php' => $other ),
		);

		$result = blueline_core_plugin_info_known_plugin( $value );

		$this->assertArrayHasKey( $file, $result->no_update );
		$this->assertSame( $other, $result->no_update['akismet/akismet.php'] );
		$this->assertSame( $value->response, $result->response );
		$this->assertSame( 123, $result->last_checked );

		$entry = $result->no_update[ $file ];
		$this->assertSame( 'blueline-core', $entry->slug );
		$this->assertSame( $file, $entry->plugin );
		$this->assertSame( BLUELINE_CORE_VERSION, $entry->new_version );
		$this->assertSame( 'https://github.com/lusky3/rookiehockey-blueline', $entry->url );
		$this->assertSame( '', $entry->package );
		$this->assertSame( '6.9', $entry->requires );
		$this->assertSame( '8.3', $entry->requires_php );
		$this->assertSame( blueline_core_plugin_info_assets()['icons'], $entry->icons );
		$this->assertSame( blueline_core_plugin_info_assets()['banners'], $entry->banners );
		$this->assertNotEmpty( $entry->id );

		$this->assertArrayNotHasKey( $file, $value->no_update, 'The original transient object is not mutated.' );
	}

	/**
	 * A transient without a no_update list gets one.
	 */
	public function test_the_transient_filter_creates_a_missing_no_update_list(): void {
		$result = blueline_core_plugin_info_known_plugin( new stdClass() );

		$this->assertArrayHasKey( plugin_basename( BLUELINE_CORE_FILE ), $result->no_update );
	}

	/**
	 * An entry in response (a real update) is never overridden or duplicated.
	 */
	public function test_the_transient_filter_never_overrides_response(): void {
		$file  = plugin_basename( BLUELINE_CORE_FILE );
		$value = (object) array(
			'response'  => array( $file => (object) array( 'new_version' => '9.9.9' ) ),
			'no_update' => array(),
		);

		$result = blueline_core_plugin_info_known_plugin( $value );

		$this->assertSame( $value, $result );
		$this->assertSame( array(), $result->no_update );
	}

	/**
	 * An existing no_update entry is kept as it is.
	 */
	public function test_the_transient_filter_never_overrides_an_existing_entry(): void {
		$file     = plugin_basename( BLUELINE_CORE_FILE );
		$existing = (object) array(
			'slug'  => 'blueline-core',
			'icons' => array( 'default' => 'https://example.test/custom.png' ),
		);
		$value    = (object) array( 'no_update' => array( $file => $existing ) );

		$result = blueline_core_plugin_info_known_plugin( $value );

		$this->assertSame( $existing, $result->no_update[ $file ] );
	}

	/**
	 * The registered transient filter works through get-style dispatch.
	 */
	public function test_the_transient_filter_runs_through_apply_filters(): void {
		$result = apply_filters( 'site_transient_update_plugins', (object) array( 'response' => array() ) );

		$this->assertArrayHasKey( plugin_basename( BLUELINE_CORE_FILE ), $result->no_update );
		$this->assertFalse( apply_filters( 'site_transient_update_plugins', false ) );
	}

	/**
	 * The module is listed.
	 */
	public function test_the_module_is_listed(): void {
		$modules = require __DIR__ . '/../includes/modules.php';

		$this->assertSame( 'plugin-info/plugin-info.php', $modules['plugin-info'] );
		$this->assertFileExists( __DIR__ . '/../includes/plugin-info/plugin-info.php' );
	}
}
