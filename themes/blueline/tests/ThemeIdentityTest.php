<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * What Appearance > Themes shows for the theme: a screenshot and a complete header.
 */
final class ThemeIdentityTest extends TestCase {

	/**
	 * The style.css header, as WordPress parses it (first comment block, "Key: value" lines).
	 *
	 * @return array<string, string>
	 */
	private function header(): array {
		$css = (string) file_get_contents( __DIR__ . '/../style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test.
		preg_match( '#/\*(.*?)\*/#s', $css, $block );

		$fields = array();
		foreach ( preg_split( '/\R/', $block[1] ?? '' ) as $line ) {
			if ( preg_match( '/^\s*([A-Za-z ]+):\s*(.+?)\s*$/', $line, $m ) ) {
				$fields[ $m[1] ] = $m[2];
			}
		}

		return $fields;
	}

	/**
	 * Every field the Themes screen and its details pop-up read is present.
	 */
	public function test_header_is_complete(): void {
		$header = $this->header();

		foreach ( array( 'Theme Name', 'Theme URI', 'Author', 'Author URI', 'Description', 'Version', 'Requires at least', 'Tested up to', 'Requires PHP', 'Tags', 'License', 'Text Domain' ) as $field ) {
			$this->assertArrayHasKey( $field, $header, "style.css is missing {$field}." );
			$this->assertNotSame( '', $header[ $field ] );
		}
	}

	/**
	 * Tags are real WordPress.org theme tags (no typos), each backed by something the theme does.
	 */
	public function test_tags_are_known_and_supported(): void {
		$tags    = array_map( 'trim', explode( ',', $this->header()['Tags'] ) );
		$allowed = array( 'e-commerce', 'custom-logo', 'custom-menu', 'featured-images', 'editor-style', 'translation-ready', 'threaded-comments', 'sticky-post', 'theme-options', 'footer-widgets' );

		$this->assertSame( array(), array_values( array_diff( $tags, $allowed ) ) );

		$setup = (string) file_get_contents( __DIR__ . '/../inc/setup.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test.
		$proof = array(
			'e-commerce'      => "add_theme_support( 'woocommerce' )",
			'custom-logo'     => "'custom-logo'",
			'custom-menu'     => 'register_nav_menus',
			'featured-images' => "add_theme_support( 'post-thumbnails' )",
			'editor-style'    => 'add_editor_style',
		);
		foreach ( $proof as $tag => $needle ) {
			if ( in_array( $tag, $tags, true ) ) {
				$this->assertStringContainsString( $needle, $setup, "Tag {$tag} is not backed by inc/setup.php." );
			}
		}
	}

	/**
	 * The theme card image: a PNG at WordPress's documented 1200x900, small enough to load fast.
	 */
	public function test_screenshot_is_a_1200_by_900_png(): void {
		$path = __DIR__ . '/../screenshot.png';

		$this->assertFileExists( $path );
		$info = getimagesize( $path );
		$this->assertIsArray( $info );
		$this->assertSame( array( 1200, 900, 'image/png' ), array( $info[0], $info[1], $info['mime'] ) );
		$this->assertLessThan( 1024 * 1024, filesize( $path ), 'WordPress asks for a screenshot under 1MB.' );
	}

	/**
	 * It ships: the distignore list does not drop the screenshot.
	 */
	public function test_screenshot_is_not_excluded_from_the_package(): void {
		$ignore = (string) file_get_contents( __DIR__ . '/../.distignore' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test.

		$this->assertDoesNotMatchRegularExpression( '/^\/?screenshot/m', $ignore );
	}
}
