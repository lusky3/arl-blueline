<?php
/**
 * Mail links use the primary host when the stored site address is an old one.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/mu-plugins/arl-domain-router.php';
require_once __DIR__ . '/../includes/mail/mail.php';
require_once __DIR__ . '/../includes/seo-meta/seo-meta.php';

/**
 * Covers blueline_core_mail_url() against the real domain router configuration.
 */
final class MailPrimaryHostTest extends TestCase {

	/**
	 * Reset the stub stores and any filters a case added.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Drop the router-config filter a case may have added.
	 */
	protected function tearDown(): void {
		blueline_test_reset_hooks();
	}

	/**
	 * A legacy-host URL becomes the primary host's, keeping path and query.
	 */
	public function test_a_legacy_host_url_moves_to_the_primary_host(): void {
		$this->assertSame( 'https://arlhockey.ca/schedule/?team=3', blueline_core_mail_url( 'https://www.rookiehockey.ca/schedule/?team=3' ) );
		$this->assertSame( 'https://arlhockey.ca/', blueline_core_mail_url( 'http://rookiehockey.com/' ) );
		$this->assertSame( 'https://arlhockey.ca', blueline_core_mail_url( 'https://www.rookiehockey.ca' ) );
	}

	/**
	 * An alias host (an old brand name) does too.
	 */
	public function test_an_alias_host_url_moves_to_the_primary_host(): void {
		$this->assertSame( 'https://arlhockey.ca/faqs/', blueline_core_mail_url( 'https://coedhockey.ca/faqs/' ) );
	}

	/**
	 * Everything else is left alone: the primary host, a staging host, an external asset host,
	 * and a URL with no host.
	 */
	public function test_other_hosts_are_untouched(): void {
		foreach ( array(
			'https://arlhockey.ca/x',
			'https://tikal.tailnet-fea3.ts.net/x',
			'https://cdn.rookiehockey.ca/logo.png',
			'/relative/path',
			'',
		) as $url ) {
			$this->assertSame( $url, blueline_core_mail_url( $url ), $url );
		}
	}

	/**
	 * With the legacy side switched off the primary face is not live, so nothing is rewritten.
	 */
	public function test_nothing_is_rewritten_while_the_legacy_hosts_are_switched_off(): void {
		add_filter(
			'arl_domain_router_config',
			static function ( array $cfg ): array {
				$cfg['legacy_enabled'] = false;
				return $cfg;
			}
		);

		$this->assertSame( 'https://www.rookiehockey.ca/x', blueline_core_mail_url( 'https://www.rookiehockey.ca/x' ) );
	}

	/**
	 * The wrapper's site link and logo follow the primary host when the stored address (here the
	 * test site's, made a legacy host) is an old one.
	 */
	public function test_the_wrapped_mail_links_to_the_primary_host(): void {
		add_filter(
			'arl_domain_router_config',
			static function ( array $cfg ): array {
				$cfg['legacy_hosts'][] = 'example.test';
				return $cfg;
			}
		);
		add_filter(
			'blueline_core_email_brand',
			static function ( array $brand ): array {
				$brand['logo'] = 'https://example.test/wp-content/uploads/logo.png';
				return $brand;
			}
		);

		$wrapped = blueline_maybe_wrap_plain_text_email(
			array(
				'subject' => 'Hi',
				'message' => 'plain body',
				'headers' => array(),
			)
		);

		$this->assertStringContainsString( 'href="https://arlhockey.ca/"', $wrapped['message'] );
		$this->assertStringContainsString( 'src="https://arlhockey.ca/wp-content/uploads/logo.png"', $wrapped['message'] );
		$this->assertStringNotContainsString( 'example.test', $wrapped['message'] );
	}

	/**
	 * On a host the router does not know (staging, a dev site) the wrapper keeps the site's own address.
	 */
	public function test_the_wrapped_mail_keeps_an_unknown_host(): void {
		$wrapped = blueline_maybe_wrap_plain_text_email(
			array(
				'subject' => 'Hi',
				'message' => 'plain body',
				'headers' => array(),
			)
		);

		$this->assertStringContainsString( 'href="https://example.test/"', $wrapped['message'] );
		$this->assertStringNotContainsString( 'arlhockey.ca', $wrapped['message'] );
	}
}
