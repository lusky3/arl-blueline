<?php
/**
 * The theme's composer.json and phpcs ruleset must agree with style.css.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Keeps the declared PHP floor, the lint's minimum WordPress version and the
 * lint's text domain from drifting away from what the theme says it needs.
 */
final class ThemeToolingConfigTest extends TestCase {

	/**
	 * One header from style.css.
	 *
	 * @param string $key Header name.
	 * @return string
	 */
	private function style_header( string $key ): string {
		$css = (string) file_get_contents( __DIR__ . '/../style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test.

		return 1 === preg_match( '/^' . preg_quote( $key, '/' ) . ':[ \t]*(.+?)[ \t]*$/mi', $css, $m ) ? $m[1] : '';
	}

	/**
	 * The ruleset, parsed.
	 *
	 * @return SimpleXMLElement
	 */
	private function ruleset(): SimpleXMLElement {
		$xml = simplexml_load_file( __DIR__ . '/../phpcs.xml.dist' );

		$this->assertInstanceOf( SimpleXMLElement::class, $xml, 'phpcs.xml.dist must exist and parse' );

		return $xml;
	}

	/**
	 * The composer.json PHP constraint is the one style.css promises.
	 */
	public function test_composer_requires_the_php_version_style_css_declares(): void {
		$composer = json_decode( (string) file_get_contents( __DIR__ . '/../composer.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test.
		$php      = $this->style_header( 'Requires PHP' );

		$this->assertNotSame( '', $php, 'premise: style.css declares Requires PHP' );
		$this->assertSame( '>=' . $php, $composer['require']['php'] ?? null );
	}

	/**
	 * The lint runs the ruleset, not a bare `--standard=WordPress`.
	 */
	public function test_composer_lint_uses_the_ruleset(): void {
		$composer = json_decode( (string) file_get_contents( __DIR__ . '/../composer.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local theme file in a unit test.

		$this->assertSame( 'phpcs', $composer['scripts']['lint'] ?? null );
	}

	/**
	 * The lint's minimum WordPress version is the theme's.
	 */
	public function test_ruleset_minimum_wp_version_matches_style_css(): void {
		$found = null;

		foreach ( $this->ruleset()->config as $config ) {
			if ( 'minimum_wp_version' === (string) $config['name'] ) {
				$found = (string) $config['value'];
			}
		}

		$this->assertSame( $this->style_header( 'Requires at least' ), $found );
	}

	/**
	 * Strings must use the theme's text domain, and globals its prefix.
	 */
	public function test_ruleset_enforces_the_blueline_text_domain_and_prefix(): void {
		$xml = $this->ruleset();

		$domains  = $xml->xpath( '//rule[@ref="WordPress.WP.I18n"]//property[@name="text_domain"]/element/@value' );
		$prefixes = $xml->xpath( '//rule[@ref="WordPress.NamingConventions.PrefixAllGlobals"]//property[@name="prefixes"]/element/@value' );

		$this->assertSame( array( 'blueline' ), array_map( 'strval', (array) $domains ) );
		$this->assertSame( array( 'blueline' ), array_map( 'strval', (array) $prefixes ) );
	}

	/**
	 * The vendor-code exclusions that remain are still there: build output and the two edited
	 * vendor templates that are too far from WPCS to reformat here.
	 */
	public function test_ruleset_keeps_the_vendor_template_exclusions(): void {
		$patterns = array_map( 'strval', (array) $this->ruleset()->xpath( '/ruleset/exclude-pattern' ) );

		foreach ( array(
			'/vendor/*',
			'/node_modules/*',
			'/assets/dist/*',
			'/woocommerce/checkout/form-coupon\.php',
			'/woocommerce/myaccount/my-refund-requests\.php',
		) as $expected ) {
			$this->assertContains( $expected, $patterns, $expected );
		}
	}

	/**
	 * The email templates and the Store Credit dashboard section carry theme-authored copy re-applied
	 * on top of the plugins' current originals, so they are linted like theme code rather than excluded.
	 */
	public function test_ruleset_lints_the_edited_email_and_store_credit_overrides(): void {
		$patterns = array_map( 'strval', (array) $this->ruleset()->xpath( '/ruleset/exclude-pattern' ) );

		foreach ( array(
			'/woocommerce/emails/*',
			'/woocommerce/myaccount/store-credit\.php',
			'/woocommerce/myaccount/dashboard-store-credit\.php',
		) as $linted ) {
			$this->assertNotContains( $linted, $patterns, $linted . ' must be linted, not excluded' );
		}
	}
}
