<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/sponsors-stubs.php';

/**
 * Sponsors in the [sponsors] list (header strip, footer band, widget): a sponsor with a website is a real,
 * crawlable link marked as sponsored; one without a website is not a link at all.
 */
final class SponsorLinkTest extends TestCase {

	/**
	 * Fresh state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		$GLOBALS['bl_test_sponsor_impressions'] = array();
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_test_get_posts_model'], $GLOBALS['bl_test_sponsor_impressions'] );
	}

	/**
	 * A sponsor post shaped like SportsPress's.
	 *
	 * @param int    $id      Post ID.
	 * @param string $title   Title.
	 * @param string $content Post content.
	 * @return object
	 */
	private function sponsor( int $id, string $title, string $content = '' ): object {
		return (object) array(
			'ID'           => $id,
			'post_title'   => $title,
			'post_content' => $content,
		);
	}

	/**
	 * Set post meta.
	 *
	 * @param int    $id    Post ID.
	 * @param string $key   Meta key.
	 * @param string $value Value.
	 */
	private function meta( int $id, string $key, string $value ): void {
		$state                             = &blueline_test_state();
		$state['post_meta'][ $id ][ $key ] = $value;
	}

	/**
	 * A sponsor with a website links to it with a real href, rel="sponsored noopener", and keeps
	 * SportsPress's click-count attributes.
	 */
	public function test_sponsor_with_url_is_a_sponsored_link(): void {
		$this->meta( 2104, 'sp_url', 'http://www.arcturusnetworks.com/' );

		list( $open, $close ) = blueline_sp_sponsor_tags( $this->sponsor( 2104, 'Arcturus' ) );

		$this->assertSame(
			'<a class="sponsor sp-sponsor" href="http://www.arcturusnetworks.com/" rel="sponsored noopener" data-nonce="' . wp_create_nonce( 'sp_clicks_2104' ) . '" data-ajaxurl="https://example.test/wp-admin/admin-ajax.php" data-post="2104">',
			$open
		);
		$this->assertSame( '</a>', $close );
	}

	/**
	 * The "open sponsor sites in a new tab" setting adds target="_blank".
	 */
	public function test_new_tab_setting(): void {
		$this->meta( 2104, 'sp_url', 'https://example.org/' );
		update_option( 'sportspress_sponsor_site_target_blank', 'yes' );

		list( $open, $close ) = blueline_sp_sponsor_tags( $this->sponsor( 2104, 'Arcturus' ) );

		$this->assertStringContainsString( ' href="https://example.org/"', $open );
		$this->assertStringEndsWith( ' target="_blank">', $open );

		// Said, not only done: the link's name ends "(opens in a new tab)".
		$this->assertSame( '<span class="screen-reader-text bl-new-tab-hint"> (opens in a new tab)</span></a>', $close );
	}

	/**
	 * A sponsor with no website and no page of its own is not a link: an <a> without href is
	 * neither crawlable nor reachable by keyboard.
	 */
	public function test_sponsor_without_url_is_not_a_link(): void {
		update_option( 'sportspress_sponsor_site_target_blank', 'yes' );

		$this->assertSame( array( '<span class="sponsor sp-sponsor">', '</span>' ), blueline_sp_sponsor_tags( $this->sponsor( 2105, 'Baranowski Plumbing' ) ) );

		$this->meta( 2105, 'sp_url', '   ' );
		$this->assertSame( array( '<span class="sponsor sp-sponsor">', '</span>' ), blueline_sp_sponsor_tags( $this->sponsor( 2105, 'Baranowski Plumbing' ) ) );
	}

	/**
	 * A sponsor with its own page content links to that page, as stock SportsPress does.
	 */
	public function test_sponsor_with_content_links_to_its_page(): void {
		$state                          = &blueline_test_state();
		$state['posts'][7]['permalink'] = 'https://example.test/sponsor/acme/';
		$this->meta( 7, 'sp_url', 'https://acme.example/' );

		$this->assertSame(
			array( '<a class="sponsor sp-sponsor" href="https://example.test/sponsor/acme/">', '</a>' ),
			blueline_sp_sponsor_tags( $this->sponsor( 7, 'Acme', 'About Acme.' ) )
		);
	}

	/**
	 * The stored URL is escaped (SportsPress printed it raw).
	 */
	public function test_url_is_escaped(): void {
		$this->meta( 9, 'sp_url', 'https://example.org/a"><script>x</script>' );

		list( $open ) = blueline_sp_sponsor_tags( $this->sponsor( 9, 'X' ) );

		$this->assertStringNotContainsString( '<script', $open );
		$this->assertStringNotContainsString( '">"', $open );
	}

	/**
	 * The template renders each sponsor through those tags, logo or name inside, and still counts an
	 * impression for each.
	 */
	public function test_template_renders_links_and_plain_sponsors(): void {
		$this->meta( 2104, 'sp_url', 'https://example.org/' );
		$state                                = &blueline_test_state();
		$state['posts'][2104]['thumbnail_id'] = 55;

		$sponsors                           = array( $this->sponsor( 2104, 'Arcturus' ), $this->sponsor( 2105, 'Baranowski Plumbing' ) );
		$GLOBALS['bl_test_get_posts_model'] = static function ( $args ) use ( $sponsors ) {
			return 'sp_sponsor' === ( $args['post_type'] ?? '' ) ? $sponsors : null;
		};

		$html = $this->render( array( 'limit' => 3 ) );

		$this->assertMatchesRegularExpression( '~<a class="sponsor sp-sponsor" href="https://example\.org/" rel="sponsored noopener"[^>]*>\s*<img [^>]*src="https://example\.test/thumb-55\.jpg"[^>]*>\s*</a>~', $html );
		$this->assertMatchesRegularExpression( '~<span class="sponsor sp-sponsor">\s*Baranowski Plumbing\s*</span>~', $html );
		$this->assertStringNotContainsString( '<a class="sponsor sp-sponsor" target', $html );
		$this->assertDoesNotMatchRegularExpression( '~<a(?![^>]*\shref=)[^>]*>~', $html, 'every anchor has an href' );
		$this->assertSame( array( 2104, 2105 ), $GLOBALS['bl_test_sponsor_impressions'] );
	}

	/**
	 * Render sportspress/sponsors-content.php with the given template args, as sp_get_template() would.
	 *
	 * @param array $args Template args.
	 * @return string
	 */
	private function render( array $args ): string {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/' );
		}
		extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- sp_get_template() hands a template its args the same way.
		ob_start();
		include __DIR__ . '/../sportspress/sponsors-content.php';
		return (string) ob_get_clean();
	}
}
