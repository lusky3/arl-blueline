<?php
/**
 * Unit tests for the seo-meta module's printed output: Open Graph / Twitter tags and JSON-LD.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/seo-meta/seo-meta.php';

/**
 * Covers blueline_render_social_meta(), blueline_render_structured_data(), the plain-text
 * title/description handling, the event status mapping, JSON hardening and the SEO-plugin
 * deference filters, end to end through the printed HTML.
 */
final class SeoMetaOutputTest extends TestCase {

	/**
	 * Reset the stub stores; two real attachment images exist for thumbnail ids.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		unset( $GLOBALS['shortcode_tags'] );

		$state              = &blueline_test_state();
		$state['posts'][55] = array( 'is_image' => true );
		$state['posts'][66] = array( 'is_image' => true );
	}

	/**
	 * Print both renderers for the current fake request and return the HTML.
	 *
	 * @return string
	 */
	private function render_head(): string {
		ob_start();
		blueline_render_social_meta();
		blueline_render_structured_data();

		return (string) ob_get_clean();
	}

	/**
	 * Decode the JSON-LD block out of rendered head HTML.
	 *
	 * @param string $html Rendered head HTML.
	 * @return array<string, mixed>
	 */
	private function json_ld( string $html ): array {
		$this->assertSame( 1, preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches ), 'Exactly one JSON-LD block is printed.' );
		$data = json_decode( $matches[1], true );
		$this->assertIsArray( $data, 'The JSON-LD block is valid JSON.' );

		return $data;
	}

	/**
	 * The SportsEvent node of the printed JSON-LD graph.
	 *
	 * @param string $html Rendered head HTML.
	 * @return array<string, mixed>
	 */
	private function sports_event( string $html ): array {
		foreach ( $this->json_ld( $html )['@graph'] as $node ) {
			if ( 'SportsEvent' === $node['@type'] ) {
				return $node;
			}
		}

		$this->fail( 'No SportsEvent node in the graph.' );
	}

	/**
	 * Make the current fake request the single page of an sp_event between two teams.
	 *
	 * @param string[] $team_titles Stored team post titles, in home/away order.
	 * @param string   $status      Value of the event's sp_status meta, '' for none.
	 */
	private function set_up_event_request( array $team_titles, string $status = '' ): void {
		$state    = &blueline_test_state();
		$team_ids = array();
		$next_id  = 10;

		foreach ( $team_titles as $title ) {
			$state['posts'][ $next_id ] = array(
				'title' => $title,
				'type'  => 'sp_team',
			);
			$team_ids[]                 = $next_id;
			++$next_id;
		}

		blueline_test_register_post( 100, 'publish', 'https://example.test/event/100' );
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( $team_ids );
		if ( '' !== $status ) {
			$state['post_meta'][100]['sp_status'] = $status;
		}

		$state['queried_post_type'] = 'sp_event';
		$state['queried_object_id'] = 100;
	}

	/**
	 * Make the current fake request a single page of the given type.
	 *
	 * @param int                  $id     Post ID.
	 * @param string               $type   Post type.
	 * @param array<string, mixed> $fields Extra stub fields (title, excerpt, content, thumbnail_id, protected).
	 */
	private function set_up_singular_request( int $id, string $type, array $fields ): void {
		$state = &blueline_test_state();

		$state['posts'][ $id ]      = array_merge(
			array(
				'type'      => $type,
				'status'    => 'publish',
				'permalink' => 'https://example.test/' . $type . '/' . $id . '/',
			),
			$fields
		);
		$state['queried_post_type'] = $type;
		$state['queried_object_id'] = $id;
	}

	// -----------------------------------------------------------------------
	// Plain-text titles
	// -----------------------------------------------------------------------

	/**
	 * Provider: display-form text and its plain-text equivalent.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function plain_text_cases(): array {
		return array(
			'player badge markup'   => array( '<strong class="sp-player-number">27</strong> Matthew Mascola', '27 Matthew Mascola' ),
			'curly apostrophe'      => array( 'Bob&#8217;s Hammers', 'Bob’s Hammers' ),
			'ampersand'             => array( 'Pucks &amp; Co', 'Pucks & Co' ),
			'quotes'                => array( '&quot;Quoted&quot; &#039;x&#039;', '"Quoted" \'x\'' ),
			'whitespace and nbsp'   => array( "  Two\n\n  spaces&nbsp;here\t", 'Two spaces here' ),
			'entity-encoded markup' => array( '&lt;b&gt;bold&lt;/b&gt;', '<b>bold</b>' ),
			'empty'                 => array( '', '' ),
		);
	}

	/**
	 * Tags are stripped, entities decoded once, whitespace collapsed.
	 *
	 * @param string $input    Display-form text.
	 * @param string $expected Plain text.
	 */
	#[DataProvider( 'plain_text_cases' )]
	public function test_plain_text_helper_strips_tags_decodes_entities_and_collapses_whitespace( string $input, string $expected ): void {
		$this->assertSame( $expected, blueline_core_seo_plain_text( $input ) );
	}

	/**
	 * A team title with entities comes back decoded from the title helper.
	 */
	public function test_seo_title_is_decoded_plain_text(): void {
		$state                       = &blueline_test_state();
		$state['posts'][10]['title'] = 'Bob&#8217;s &amp; Co';

		$this->assertSame( 'Bob’s & Co', blueline_core_seo_title( 10 ) );
	}

	/**
	 * A decorated title on a generic singular page reaches og:title and twitter:title without markup.
	 */
	public function test_singular_page_titles_carry_no_markup(): void {
		$this->set_up_singular_request( 300, 'sp_team', array( 'title' => '<strong class="sp-player-number">27</strong> Matthew &amp; Co' ) );

		$data = blueline_social_meta_data();
		$html = $this->render_head();

		$this->assertSame( '27 Matthew & Co', $data['title'] );
		$this->assertStringContainsString( '<meta property="og:title" content="27 Matthew &amp; Co">', $html );
		$this->assertStringContainsString( '<meta name="twitter:title" content="27 Matthew &amp; Co">', $html );
		$this->assertStringNotContainsString( 'sp-player-number', $html );
	}

	/**
	 * A player page uses the raw post title (no badge), plain.
	 */
	public function test_player_page_uses_the_clean_title_and_article_type(): void {
		$this->set_up_singular_request(
			301,
			'sp_player',
			array(
				'title'        => 'Matthew Mascola',
				'thumbnail_id' => 55,
				'excerpt'      => 'Center, #27.',
			)
		);

		$html = $this->render_head();

		$this->assertStringContainsString( '<meta property="og:type" content="article">', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="Matthew Mascola">', $html );
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.test/sp_player/301/">', $html );
		$this->assertStringContainsString( '<meta property="og:description" content="Center, #27.">', $html );
		$this->assertStringContainsString( '<meta name="description" content="Center, #27.">', $html );
		$this->assertStringContainsString( '<meta property="og:image" content="https://example.test/uploads/photo-55-large.jpg">', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image">', $html );
	}

	/**
	 * Quotes in a title are attribute-escaped exactly once.
	 */
	public function test_titles_are_escaped_once_in_attributes(): void {
		$this->set_up_singular_request( 302, 'post', array( 'title' => 'Say &quot;hi&quot; &amp; leave' ) );

		$html = $this->render_head();

		$this->assertStringContainsString( '<meta property="og:title" content="Say &quot;hi&quot; &amp; leave">', $html );
		$this->assertStringNotContainsString( '&amp;quot;', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
	}

	/**
	 * Event page: team names are decoded in og:title and in every JSON-LD name.
	 */
	public function test_event_page_decodes_team_names_in_meta_and_json_ld(): void {
		$this->set_up_event_request( array( 'Bob&#8217;s &amp; Co', 'Hammers' ) );

		$html = $this->render_head();

		$this->assertStringContainsString( '<meta property="og:title" content="Bob’s &amp; Co vs Hammers">', $html );
		$this->assertStringContainsString( '<meta property="og:type" content="website">', $html );
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.test/event/100">', $html );
		$this->assertStringContainsString( 'Aug 20 at 7:00 PM', $html );
		$this->assertStringNotContainsString( '&#8217;', $html );

		$event = $this->sports_event( $html );
		$this->assertSame( 'Bob’s & Co vs Hammers', $event['name'] );
		$this->assertSame( 'Bob’s & Co', $event['competitor'][0]['name'] );
		$this->assertSame( 'Hammers', $event['competitor'][1]['name'] );
	}

	/**
	 * Without the theme's label helper the venue is the first sp_venue term name, as plain text
	 * (term names are stored entity-encoded).
	 */
	public function test_venue_label_is_the_decoded_term_name(): void {
		blueline_test_register_term( 900, 'sp_venue', 'Arena &amp; Pad' );
		blueline_test_set_post_terms( 100, 'sp_venue', array( 900 ) );

		$this->assertSame( 'Arena & Pad', blueline_core_seo_event_venue_label( 100 ) );
	}

	/**
	 * When the theme provides its arena/pad label, that wins, still reduced to plain text. Runs in
	 * its own process because the fake theme function cannot be un-defined for later tests.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_venue_label_prefers_the_themes_label_as_plain_text(): void {
		eval( 'function blueline_sp_event_venue_label( int $event_id ): string { return "Central Arena &amp; Rink &mdash; Pad 2"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- defines the theme function only in this isolated process.

		blueline_test_register_term( 900, 'sp_venue', 'Pad 2' );
		blueline_test_set_post_terms( 100, 'sp_venue', array( 900 ) );

		$this->assertSame( 'Central Arena & Rink — Pad 2', blueline_core_seo_event_venue_label( 100 ) );
	}

	// -----------------------------------------------------------------------
	// eventStatus
	// -----------------------------------------------------------------------

	/**
	 * Provider: sp_status meta value and the schema.org status it maps to.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function event_status_cases(): array {
		return array(
			'postponed'              => array( 'postponed', 'https://schema.org/EventPostponed' ),
			'cancelled'              => array( 'cancelled', 'https://schema.org/EventCancelled' ),
			'cancelled any case'     => array( ' Cancelled ', 'https://schema.org/EventCancelled' ),
			'ok'                     => array( 'ok', 'https://schema.org/EventScheduled' ),
			'tbd'                    => array( 'tbd', 'https://schema.org/EventScheduled' ),
			'unset (played/preview)' => array( '', 'https://schema.org/EventScheduled' ),
		);
	}

	/**
	 * The status URL follows the event's sp_status meta, and reaches the printed JSON-LD.
	 *
	 * @param string $meta     sp_status value.
	 * @param string $expected schema.org URL.
	 */
	#[DataProvider( 'event_status_cases' )]
	public function test_event_status_reflects_sp_status( string $meta, string $expected ): void {
		$this->set_up_event_request( array( 'Puck Dynasty', 'Hammers' ), $meta );

		$this->assertSame( $expected, blueline_core_seo_event_status_url( 100 ) );
		$this->assertSame( $expected, $this->sports_event( $this->render_head() )['eventStatus'] );
	}

	// -----------------------------------------------------------------------
	// SportsEvent JSON-LD shape and the other main output paths
	// -----------------------------------------------------------------------

	/**
	 * SportsEvent node: type, ISO start date, url, sport and team logos.
	 */
	public function test_sports_event_json_ld_shape(): void {
		$state = &blueline_test_state();
		$this->set_up_event_request( array( 'Puck Dynasty', 'Hammers' ) );
		$state['posts'][10]['thumbnail_id'] = 55;

		$html  = $this->render_head();
		$graph = $this->json_ld( $html )['@graph'];
		$event = $this->sports_event( $html );

		$this->assertSame( 'SportsOrganization', $graph[0]['@type'], 'Organization is always first.' );
		$this->assertSame( 'https://schema.org', $this->json_ld( $html )['@context'] );
		$this->assertSame( 'Puck Dynasty vs Hammers', $event['name'] );
		$this->assertSame( gmdate( DATE_ATOM, (int) strtotime( '7:00 PM +0000' ) ), $event['startDate'] );
		$this->assertSame( 'https://example.test/event/100', $event['url'] );
		$this->assertSame( 'Ice Hockey', $event['sport'] );
		$this->assertSame( 'https://example.test/uploads/photo-55-large.jpg', $event['competitor'][0]['logo'] );
		$this->assertArrayNotHasKey( 'logo', $event['competitor'][1] );
		$this->assertArrayNotHasKey( 'location', $event, 'No venue term, no location.' );
	}

	/**
	 * Without a start time there is no SportsEvent node (schema.org requires startDate).
	 */
	public function test_event_without_a_start_time_has_no_sports_event_node(): void {
		$this->set_up_event_request( array( 'Puck Dynasty', 'Hammers' ) );
		$state                     = &blueline_test_state();
		$state['gmt_unresolvable'] = true;

		$this->assertNull( blueline_sports_event_schema( 100 ) );
		$this->assertCount( 1, $this->json_ld( $this->render_head() )['@graph'], 'Only the Organization node.' );
	}

	/**
	 * Front page: site identity, website type, Organization-only graph.
	 */
	public function test_front_page_prints_site_identity_and_organization_only(): void {
		$state                      = &blueline_test_state();
		$state['is_front_page']     = true;
		$state['queried_post_type'] = 'page';

		$html = $this->render_head();

		$this->assertStringContainsString( '<meta property="og:type" content="website">', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="Blueline Test Site">', $html );
		$this->assertStringContainsString( '<meta property="og:site_name" content="Blueline Test Site">', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary">', $html );
		$this->assertStringNotContainsString( 'og:image', $html );
		$this->assertCount( 1, $this->json_ld( $html )['@graph'] );
	}

	/**
	 * An archive falls back to the site identity and its own paging-free URL.
	 */
	public function test_archive_prints_site_identity_with_the_archive_url(): void {
		$html = $this->render_head();

		$this->assertStringContainsString( '<meta property="og:type" content="website">', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="Blueline Test Site">', $html );
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.test/archive/">', $html );
	}

	/**
	 * With another SEO plugin active nothing is printed.
	 */
	public function test_nothing_prints_when_an_seo_plugin_is_active(): void {
		$this->set_up_event_request( array( 'Puck Dynasty', 'Hammers' ) );
		add_filter( 'blueline_core_seo_plugin_active', '__return_true' );

		$this->assertSame( '', $this->render_head() );
	}

	// -----------------------------------------------------------------------
	// SEO-plugin filters
	// -----------------------------------------------------------------------

	/**
	 * The legacy filter name is still honoured.
	 */
	public function test_legacy_seo_plugin_filter_still_works(): void {
		$this->assertFalse( blueline_seo_plugin_active() );

		add_filter( 'blueline_seo_plugin_active', '__return_true' );

		$this->assertTrue( blueline_seo_plugin_active() );
	}

	/**
	 * The prefixed filter works, and the legacy filter is applied after it (so it wins on conflict).
	 */
	public function test_prefixed_seo_plugin_filter_is_applied_before_the_legacy_one(): void {
		add_filter( 'blueline_core_seo_plugin_active', '__return_true' );
		$this->assertTrue( blueline_seo_plugin_active() );

		add_filter( 'blueline_seo_plugin_active', '__return_false' );
		$this->assertFalse( blueline_seo_plugin_active(), 'Legacy runs last.' );
	}

	// -----------------------------------------------------------------------
	// JSON hardening
	// -----------------------------------------------------------------------

	/**
	 * A title that decodes to `</script><!--<script>` cannot break out of the JSON-LD block.
	 */
	public function test_hostile_title_cannot_break_out_of_the_script_block(): void {
		$hostile = '&lt;/script&gt;&lt;!--&lt;script&gt;alert(1)&amp;';
		$this->set_up_event_request( array( $hostile, 'Hammers' ) );

		$html = $this->render_head();

		$this->assertSame( 1, substr_count( strtolower( $html ), '</script>' ), 'Only the block\'s own closing tag.' );
		$this->assertStringNotContainsString( '<!--', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertSame( '</script><!--<script>alert(1)& vs Hammers', $this->sports_event( $html )['name'], 'The value survives the round trip.' );
		$backslash = chr( 92 );
		$this->assertStringContainsString( $backslash . 'u003C/script' . $backslash . 'u003E' . $backslash . 'u003C!--', $html, 'Escaped as unicode sequences.' );
	}

	/**
	 * Slashes and unicode stay readable in the JSON (flags are UNESCAPED_SLASHES | UNESCAPED_UNICODE).
	 */
	public function test_json_keeps_urls_and_unicode_readable(): void {
		$this->set_up_event_request( array( 'Café Pucks', 'Hammers' ) );

		$html = $this->render_head();

		$this->assertStringContainsString( '"url":"https://example.test/event/100"', $html );
		$this->assertStringContainsString( 'Café Pucks', $html );
	}

	// -----------------------------------------------------------------------
	// Description without the content filter chain
	// -----------------------------------------------------------------------

	/**
	 * A manual excerpt is used (plain text), and the content is never rendered.
	 */
	public function test_manual_excerpt_is_the_description(): void {
		$rendered = new \ArrayObject( array( 0 ) );
		add_filter(
			'the_content',
			static function ( $content ) use ( $rendered ) {
				++$rendered[0];

				return $content;
			}
		);
		$this->set_up_singular_request(
			310,
			'post',
			array(
				'excerpt' => 'Season <b>opener</b> &amp; party',
				'content' => 'Body that must not be used.',
			)
		);

		$this->assertSame( 'Season opener & party', blueline_core_seo_post_description( 310 ) );
		$this->assertSame( 'Season opener & party', blueline_social_meta_data()['description'] );
		$this->assertSame( 0, $rendered[0], 'No the_content filtering in wp_head.' );
	}

	/**
	 * Without an excerpt the raw content is stripped of shortcodes/tags and trimmed to 30 words,
	 * without the content filters.
	 */
	public function test_content_description_strips_shortcodes_and_markup_and_trims(): void {
		$rendered = new \ArrayObject( array( 0 ) );
		add_filter(
			'the_content',
			static function ( $content ) use ( $rendered ) {
				++$rendered[0];

				return $content;
			}
		);
		$GLOBALS['shortcode_tags']['gallery'] = '__return_empty_string'; // Registered, so strip_shortcodes() removes it.
		$words                                = implode( ' ', array_map( static fn( $i ) => 'word' . $i, range( 1, 40 ) ) );
		$this->set_up_singular_request(
			311,
			'post',
			array( 'content' => '<!-- wp:paragraph --><p>[gallery ids="1,2"]Hello <strong>there</strong> &amp; welcome. ' . $words . '</p><!-- /wp:paragraph -->' )
		);

		$description = blueline_core_seo_post_description( 311 );

		$this->assertStringStartsWith( 'Hello there & welcome. word1 word2', $description );
		$this->assertStringEndsWith( '…', $description );
		$this->assertStringNotContainsString( 'gallery', $description );
		$this->assertStringNotContainsString( '<', $description );
		$this->assertCount( 30, explode( ' ', rtrim( $description, '…' ) ) );
		$this->assertSame( 0, $rendered[0], 'No the_content filtering in wp_head.' );
	}

	/**
	 * Only registered shortcodes are stripped, as in core; an unregistered tag's text stays.
	 */
	public function test_unregistered_shortcodes_are_left_in_the_description(): void {
		$this->set_up_singular_request( 314, 'post', array( 'content' => 'Hello [unknownshort] world' ) );

		$this->assertSame( 'Hello [unknownshort] world', blueline_core_seo_post_description( 314 ) );
	}

	/**
	 * Short content is not suffixed.
	 */
	public function test_short_content_is_returned_whole(): void {
		$this->set_up_singular_request( 312, 'post', array( 'content' => '<p>Just a few words.</p>' ) );

		$this->assertSame( 'Just a few words.', blueline_core_seo_post_description( 312 ) );
	}

	/**
	 * Password-protected and missing posts give no description (nothing leaks).
	 */
	public function test_protected_and_missing_posts_have_no_description(): void {
		$this->set_up_singular_request(
			313,
			'post',
			array(
				'content'   => 'Secret body.',
				'protected' => true,
			)
		);

		$this->assertSame( '', blueline_core_seo_post_description( 313 ) );
		$this->assertSame( '', blueline_core_seo_post_description( 999 ) );
		$this->assertStringNotContainsString( 'og:description', $this->render_head() );
	}
}
