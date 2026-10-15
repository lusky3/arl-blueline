<?php
/**
 * Unit tests for how the seo-meta module presents pages to search engines and chat unfurls:
 * descriptions, document titles, canonical links, share images and structured data.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/season/season.php';
require_once __DIR__ . '/../includes/seo-meta/seo-meta.php';

/**
 * Covers team/player/archive descriptions, event titles, rel=canonical on archives, Open Graph image
 * and type choices, SportsEvent location/home/away, SportsTeam and BreadcrumbList.
 */
final class SeoMetaDiscoveryTest extends TestCase {

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
		blueline_render_archive_canonical();
		blueline_render_social_meta();
		blueline_render_structured_data();

		return (string) ob_get_clean();
	}

	/**
	 * The JSON-LD graph of rendered head HTML (asserting it is one valid JSON block).
	 *
	 * @param string $html Rendered head HTML.
	 * @return array<int, array<string, mixed>>
	 */
	private function graph( string $html ): array {
		$this->assertSame( 1, preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches ), 'Exactly one JSON-LD block is printed.' );
		$data = json_decode( $matches[1], true );
		$this->assertIsArray( $data, 'The JSON-LD block is valid JSON.' );

		return $data['@graph'];
	}

	/**
	 * The first graph node of a type.
	 *
	 * @param string $html Rendered head HTML.
	 * @param string $type schema.org type.
	 * @return array<string, mixed>
	 */
	private function node( string $html, string $type ): array {
		foreach ( $this->graph( $html ) as $node ) {
			if ( $type === $node['@type'] ) {
				return $node;
			}
		}

		$this->fail( 'No ' . $type . ' node in the graph.' );
	}

	/**
	 * Make the current fake request the single page of an sp_event between two teams.
	 *
	 * @param string[] $team_titles Stored team post titles, home/away order.
	 * @param string   $status      Value of the event's sp_status meta, '' for none.
	 */
	private function set_up_event_request( array $team_titles, string $status = '' ): void {
		$state    = &blueline_test_state();
		$team_ids = array();
		$next_id  = 10;

		foreach ( $team_titles as $title ) {
			$state['posts'][ $next_id ] = array(
				'title'     => $title,
				'type'      => 'sp_team',
				'permalink' => 'https://example.test/team/' . $next_id . '/',
			);
			$team_ids[]                 = $next_id;
			++$next_id;
		}

		blueline_test_register_post( 100, 'publish', 'https://example.test/event/100' );
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( $team_ids );
		if ( '' !== $status ) {
			$state['post_meta'][100]['sp_status'] = $status;
		}

		add_filter( 'blueline_core_seo_short_site_name', static fn() => 'ARL' );

		// A site fixed at UTC-4 and an event at 7:00 PM local, so the formatted date is stable all year.
		$state['timezone']          = 'Etc/GMT+4';
		$state['gmt_offset']        = -4 * HOUR_IN_SECONDS;
		$state['queried_post_type'] = 'sp_event';
		$state['queried_object_id'] = 100;
	}

	/**
	 * Make the current fake request a single team or player page.
	 *
	 * @param int                  $id     Post ID.
	 * @param string               $type   Post type.
	 * @param array<string, mixed> $fields Extra stub fields (title, excerpt, thumbnail_id).
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

	/**
	 * Attach a venue term with an address (and optional coordinates) to event 100.
	 *
	 * @param string               $name Term name.
	 * @param array<string, mixed> $meta Term fields as SportsPress stores them (sp_address, ...).
	 */
	private function set_up_venue( string $name, array $meta ): void {
		blueline_test_register_term( 900, 'sp_venue', $name );
		blueline_test_set_post_terms( 100, 'sp_venue', array( 900 ) );
		$GLOBALS['bl_test_options']['taxonomy_900'] = $meta;
	}

	// -----------------------------------------------------------------------
	// Team descriptions
	// -----------------------------------------------------------------------

	/**
	 * A team page has a meta description even though its body is empty, and it is printed as the meta
	 * description, og:description and twitter:description.
	 */
	public function test_team_page_gets_a_description(): void {
		$this->set_up_singular_request( 300, 'sp_team', array( 'title' => 'Whalers' ) );

		$html = $this->render_head();

		$this->assertSame( 'Whalers roster, schedule and standings in the Adult Recreational League.', blueline_social_meta_data()['description'] );
		$this->assertStringContainsString( '<meta name="description" content="Whalers roster, schedule and standings in the Adult Recreational League.">', $html );
		$this->assertStringContainsString( '<meta property="og:description" content="Whalers roster', $html );
		$this->assertStringContainsString( '<meta name="twitter:description" content="Whalers roster', $html );
	}

	/**
	 * A team page's own excerpt wins over the generated sentence.
	 */
	public function test_team_excerpt_wins_over_the_generated_description(): void {
		$this->set_up_singular_request(
			300,
			'sp_team',
			array(
				'title'   => 'Whalers',
				'excerpt' => 'Founded in 2015.',
			)
		);

		$this->assertSame( 'Founded in 2015.', blueline_social_meta_data()['description'] );
	}

	/**
	 * A very long team name still gives a description within the limit, cut at a word.
	 */
	public function test_team_description_stays_within_the_limit_for_a_long_name(): void {
		$this->set_up_singular_request( 300, 'sp_team', array( 'title' => implode( ' ', array_fill( 0, 30, 'Whalers' ) ) ) );

		$description = blueline_social_meta_data()['description'];

		$this->assertLessThanOrEqual( 160, mb_strlen( $description ) );
		$this->assertStringEndsWith( '…', $description );
	}

	/**
	 * With the theme's current-season division and the current SportsPress season, the team sentence
	 * names them. Runs in its own process because the fake theme functions cannot be un-defined for
	 * later tests.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_team_description_names_the_division_and_the_current_season(): void {
		eval( 'function blueline_player_division_name( int $team_id ): string { return "Division 3"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- defines the theme function only in this isolated process.
		$GLOBALS['bl_test_options']['sportspress_season'] = '700'; // SportsPress's Current Season setting.
		blueline_test_register_term( 700, 'sp_season', 'Fall 2026' );
		$this->set_up_singular_request( 300, 'sp_team', array( 'title' => 'Whalers' ) );

		$this->assertSame(
			'Whalers — Division 3, Fall 2026 roster, schedule and standings in the Adult Recreational League.',
			blueline_social_meta_data()['description']
		);
	}

	// -----------------------------------------------------------------------
	// Player descriptions
	// -----------------------------------------------------------------------

	/**
	 * A player with no team still gets a description.
	 */
	public function test_player_without_a_team_gets_a_plain_description(): void {
		$this->set_up_singular_request( 301, 'sp_player', array( 'title' => 'Matthew Mascola' ) );

		$this->assertSame( 'Matthew Mascola — player profile, stats and schedule in the Adult Recreational League.', blueline_social_meta_data()['description'] );
	}

	/**
	 * The team is the first positive row of the repeated `sp_current_team` key (many players lead
	 * with a '0' placeholder row).
	 */
	public function test_player_description_names_the_team_skipping_placeholder_rows(): void {
		$state                                      = &blueline_test_state();
		$state['posts'][12]                         = array(
			'title' => 'Whalers',
			'type'  => 'sp_team',
		);
		$state['post_meta'][301]['sp_current_team'] = new Blueline_Test_Meta_Rows( array( 0, 12 ) );
		$this->set_up_singular_request( 301, 'sp_player', array( 'title' => 'Matthew Mascola' ) );

		$this->assertSame(
			'Matthew Mascola — Whalers player profile, stats and schedule in the Adult Recreational League.',
			blueline_social_meta_data()['description']
		);
	}

	/**
	 * A player on a team that is on this season's standings table gets the season too. Runs in its own
	 * process because the fake theme functions cannot be un-defined for later tests.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_player_description_names_the_season_for_a_team_playing_now(): void {
		eval( 'function blueline_team_current_table_id( int $team_id ): ?int { return 12 === $team_id ? 99 : null; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- defines the theme function only in this isolated process.
		$GLOBALS['bl_test_options']['sportspress_season'] = '700'; // SportsPress's Current Season setting.
		blueline_test_register_term( 700, 'sp_season', 'Fall 2026' );
		$state                                      = &blueline_test_state();
		$state['posts'][12]                         = array(
			'title' => 'Whalers',
			'type'  => 'sp_team',
		);
		$state['posts'][13]                         = array(
			'title' => 'Kraken',
			'type'  => 'sp_team',
		);
		$state['post_meta'][301]['sp_current_team'] = new Blueline_Test_Meta_Rows( array( 12 ) );
		$state['post_meta'][302]['sp_current_team'] = new Blueline_Test_Meta_Rows( array( 13 ) );

		$this->set_up_singular_request( 301, 'sp_player', array( 'title' => 'Matthew Mascola' ) );
		$this->assertSame(
			'Matthew Mascola plays for Whalers in the Adult Recreational League, Fall 2026. Player profile, stats and schedule.',
			blueline_social_meta_data()['description']
		);

		$this->set_up_singular_request( 302, 'sp_player', array( 'title' => 'Old Timer' ) );
		$this->assertStringNotContainsString( 'Fall 2026', blueline_social_meta_data()['description'], 'A team not on this season\'s table is not claimed as current.' );
	}

	// -----------------------------------------------------------------------
	// Archive descriptions
	// -----------------------------------------------------------------------

	/**
	 * The news index has its own line, not the site tagline.
	 */
	public function test_news_index_has_a_dedicated_description(): void {
		$state                    = &blueline_test_state();
		$state['request']['home'] = true;

		$data = blueline_social_meta_data();

		$this->assertSame( 'News, announcements and updates from the Adult Recreational League.', $data['description'] );
		$this->assertStringNotContainsString( 'Test Site', $data['description'] );
	}

	/**
	 * Search and 404 pages print no description rather than repeating a generic one.
	 */
	public function test_search_and_404_have_no_description(): void {
		$state                      = &blueline_test_state();
		$state['request']['search'] = true;

		$this->assertSame( '', blueline_social_meta_data()['description'] );
		$this->assertStringNotContainsString( 'name="description"', $this->render_head() );

		$state['request'] = array( '404' => true );

		$this->assertSame( '', blueline_social_meta_data()['description'] );
	}

	/**
	 * The front page keeps the site tagline as its description.
	 */
	public function test_front_page_keeps_the_tagline(): void {
		$state                      = &blueline_test_state();
		$state['is_front_page']     = true;
		$state['queried_post_type'] = 'page';

		// The stubbed get_bloginfo() answers 'Blueline Test Site' for the tagline too.
		$this->assertSame( 'Blueline Test Site', blueline_social_meta_data()['description'] );
	}

	/**
	 * A venue page says where the rink is.
	 */
	public function test_venue_archive_description_uses_the_address(): void {
		$GLOBALS['bl_test_options']['taxonomy_900'] = array( 'sp_address' => '1179 Northside Rd, Burlington, ON L7M, Canada' );
		$state                                      = &blueline_test_state();
		$state['request']                           = array(
			'tax'    => 'sp_venue',
			'object' => (object) array(
				'term_id'     => 900,
				'name'        => 'Red',
				'description' => '&nbsp;',
			),
		);

		$this->assertSame( 'Red: 1179 Northside Rd, Burlington, ON L7M, Canada. Blueline Test Site games at this rink.', blueline_social_meta_data()['description'] );
	}

	/**
	 * A term archive without a venue address falls back to the term's own description.
	 */
	public function test_term_archive_description_falls_back_to_the_term_description(): void {
		$state            = &blueline_test_state();
		$state['request'] = array(
			'tax'    => 'sp_league',
			'object' => (object) array(
				'term_id'     => 901,
				'name'        => 'Division 3',
				'description' => '<p>Mid-level   division.</p>',
			),
		);

		$this->assertSame( 'Mid-level division.', blueline_social_meta_data()['description'] );

		$state['request']['object']->description = '';

		$this->assertSame( '', blueline_social_meta_data()['description'] );
	}

	// -----------------------------------------------------------------------
	// Event titles
	// -----------------------------------------------------------------------

	/**
	 * A game's title carries its date, in the site timezone, so rematches are distinct.
	 */
	public function test_event_title_includes_the_date(): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ) );

		$this->assertSame( 'Whalers vs Kraken — ' . gmdate( 'M j, Y', (int) strtotime( '7:00 PM +0000' ) ), blueline_core_seo_event_title( 100 ) );
	}

	/**
	 * The date is formatted in the site timezone, not UTC: 7:00 PM at UTC-4 is 23:00 UTC, which on a
	 * site east of it would be the next day.
	 */
	public function test_event_date_follows_the_site_timezone(): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ) );
		$state             = &blueline_test_state();
		$state['timezone'] = 'Pacific/Auckland';

		$expected = ( new DateTimeImmutable( '@' . strtotime( '23:00 +0000' ) ) )->setTimezone( new DateTimeZone( 'Pacific/Auckland' ) )->format( 'M j, Y' );

		$this->assertSame( 'Whalers vs Kraken — ' . $expected, blueline_core_seo_event_title( 100 ) );
	}

	/**
	 * A sponsor in a team's name is dropped from the title only.
	 */
	public function test_event_title_drops_sponsor_parentheses(): void {
		$this->set_up_event_request( array( 'Kraken Beers (TeamSmulders.com)', 'B-Town Bulldogs' ) );

		$this->assertStringStartsWith( 'Kraken Beers vs B-Town Bulldogs — ', blueline_core_seo_event_title( 100 ) );
		$this->assertSame( 'Kraken Beers (TeamSmulders.com) vs B-Town Bulldogs', $this->node( $this->render_head(), 'SportsEvent' )['name'], 'The schema keeps the full name.' );
	}

	/**
	 * The whole `<title>`, site name included, stays within 60 characters however long the names are.
	 */
	public function test_event_document_title_is_at_most_60_characters(): void {
		$this->set_up_event_request( array( 'The Extraordinarily Long Named Hockey Club Of Burlington', 'Another Remarkably Lengthy Team Name Incorporated' ) );

		$parts = blueline_core_seo_document_title_parts(
			array(
				'title' => 'ignored',
				'site'  => 'Blueline Test Site',
			)
		);
		$title = implode( ' ' . blueline_core_seo_document_title_separator( '-' ) . ' ', array_filter( $parts ) );

		$this->assertLessThanOrEqual( 60, mb_strlen( $title ), $title );
		$this->assertStringContainsString( '…', $title );
		$this->assertStringContainsString( gmdate( 'M j, Y', (int) strtotime( '7:00 PM +0000' ) ), $title, 'The date is never the part that gets cut.' );
	}

	/**
	 * Provider: the missing-team cases.
	 *
	 * @return array<string, array{0: string[]}>
	 */
	public static function missing_team_cases(): array {
		return array(
			'one team' => array( array( 'Whalers' ) ),
			'no teams' => array( array() ),
		);
	}

	/**
	 * A game without both teams yet reads TBD.
	 *
	 * @param string[] $teams Team titles.
	 */
	#[DataProvider( 'missing_team_cases' )]
	public function test_event_title_uses_tbd_for_a_missing_team( array $teams ): void {
		$this->set_up_event_request( $teams );

		$this->assertStringContainsString( 'TBD', blueline_core_seo_event_title( 100 ) );
	}

	/**
	 * The title parts for a game: dated title, short site name, pipe separator.
	 */
	public function test_event_title_parts_use_the_short_site_name_and_a_pipe(): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ) );

		$parts = blueline_core_seo_document_title_parts(
			array(
				'title'   => 'Whalers vs Kraken (Sponsor)',
				'page'    => '2',
				'tagline' => 'x',
				'site'    => 'ARL – Adult Recreational League',
			)
		);

		$this->assertSame( array( 'title', 'site' ), array_keys( $parts ), 'Page number and tagline are not part of a game title.' );
		$this->assertStringStartsWith( 'Whalers vs Kraken — ', $parts['title'] );
		$this->assertSame( 'ARL', $parts['site'] );
		$this->assertSame( '|', blueline_core_seo_document_title_separator( '–' ) );
	}

	/**
	 * Provider: full site name and its short form.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function short_name_cases(): array {
		return array(
			'en dash'                 => array( 'ARL – Adult Recreational League', 'ARL' ),
			'em dash'                 => array( 'ARL — Adult Recreational League', 'ARL' ),
			'hyphen'                  => array( 'ARL - Adult Recreational League', 'ARL' ),
			'no dash'                 => array( 'Blueline Test Site', 'Blueline Test Site' ),
			'hyphenated word is kept' => array( 'Co-ed Hockey', 'Co-ed Hockey' ),
		);
	}

	/**
	 * The short site name is the text before the first dash.
	 *
	 * @param string $name     Full name.
	 * @param string $expected Short name.
	 */
	#[DataProvider( 'short_name_cases' )]
	public function test_short_name_is_the_text_before_the_first_dash( string $name, string $expected ): void {
		$this->assertSame( $expected, blueline_core_seo_short_name( $name ) );
	}

	/**
	 * Other pages keep their titles and separator.
	 */
	public function test_other_pages_keep_their_title_parts_and_separator(): void {
		$this->set_up_singular_request( 300, 'sp_team', array( 'title' => 'Whalers' ) );
		$parts = array(
			'title' => 'Whalers',
			'site'  => 'Blueline Test Site',
		);

		$this->assertSame( $parts, blueline_core_seo_document_title_parts( $parts ) );
		$this->assertSame( '–', blueline_core_seo_document_title_separator( '–' ) );
	}

	/**
	 * With an SEO plugin active the module leaves the title alone.
	 */
	public function test_title_parts_are_untouched_when_an_seo_plugin_is_active(): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ) );
		add_filter( 'blueline_core_seo_plugin_active', '__return_true' );
		$parts = array(
			'title' => 'Original',
			'site'  => 'Site',
		);

		$this->assertSame( $parts, blueline_core_seo_document_title_parts( $parts ) );
		$this->assertSame( '-', blueline_core_seo_document_title_separator( '-' ) );
	}

	/**
	 * The home page drops a tagline that would push its title past 60 characters, and keeps a short one.
	 */
	public function test_front_page_title_drops_an_overlong_tagline_only(): void {
		$state                  = &blueline_test_state();
		$state['is_front_page'] = true;

		$long = array(
			'title'   => 'ARL – Adult Recreational League',
			'tagline' => 'Adult Co-Ed Hockey located in Burlington, On',
		);
		$this->assertArrayNotHasKey( 'tagline', blueline_core_seo_document_title_parts( $long ) );

		$short = array(
			'title'   => 'ARL',
			'tagline' => 'Co-ed hockey in Burlington',
		);
		$this->assertSame( $short, blueline_core_seo_document_title_parts( $short ) );
	}

	/**
	 * The hooks are registered when the module loads. Runs in its own process because the shared setUp
	 * resets the hook store.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_hooks_are_registered_on_load(): void {
		$this->assertNotFalse( has_filter( 'document_title_parts', 'blueline_core_seo_document_title_parts' ) );
		$this->assertNotFalse( has_filter( 'document_title_separator', 'blueline_core_seo_document_title_separator' ) );
		$this->assertNotFalse( has_action( 'wp_head', 'blueline_render_archive_canonical' ) );
	}

	// -----------------------------------------------------------------------
	// Canonical links
	// -----------------------------------------------------------------------

	/**
	 * An archive is its own canonical.
	 */
	public function test_archive_prints_a_self_canonical(): void {
		$state                       = &blueline_test_state();
		$state['request']['archive'] = true;

		$this->assertStringContainsString( '<link rel="canonical" href="https://example.test/archive/">', $this->render_head() );
	}

	/**
	 * A paginated page is its own canonical, not page 1.
	 */
	public function test_paginated_list_canonical_keeps_the_page_number(): void {
		$state                     = &blueline_test_state();
		$state['request']['home']  = true;
		$state['request']['paged'] = 2;

		$this->assertStringContainsString( '<link rel="canonical" href="https://example.test/archive/page/2/">', $this->render_head() );
	}

	/**
	 * Tracking and sorting arguments are dropped from the canonical on a pretty-permalink site.
	 */
	public function test_canonical_drops_query_arguments_with_pretty_permalinks(): void {
		$GLOBALS['bl_test_options']['permalink_structure'] = '/%postname%/';
		$state                     = &blueline_test_state();
		$state['request']['tax']   = 'sp_venue';
		$state['request']['query'] = 'utm_source=chat&orderby=price';

		$this->assertStringContainsString( '<link rel="canonical" href="https://example.test/archive/">', $this->render_head() );
	}

	/**
	 * With plain permalinks the query string is the address, so it stays.
	 */
	public function test_canonical_keeps_the_query_with_plain_permalinks(): void {
		$state                       = &blueline_test_state();
		$state['request']['archive'] = true;
		$state['request']['query']   = 'post_type=sp_team';

		$this->assertStringContainsString( 'href="https://example.test/archive/?post_type=sp_team"', $this->render_head() );
	}

	/**
	 * Search results, 404s, singular pages and plain requests print none (core handles singular pages).
	 */
	public function test_no_canonical_on_search_404_or_singular_pages(): void {
		$state                       = &blueline_test_state();
		$state['request']['search']  = true;
		$state['request']['archive'] = true;
		$this->assertSame( '', blueline_core_seo_archive_canonical_url() );

		$state['request'] = array(
			'404'     => true,
			'archive' => true,
		);
		$this->assertSame( '', blueline_core_seo_archive_canonical_url() );

		$state['request']           = array( 'archive' => true );
		$state['queried_post_type'] = 'sp_team';
		$this->assertSame( '', blueline_core_seo_archive_canonical_url() );

		$state['request']           = array();
		$state['queried_post_type'] = '';
		$this->assertSame( '', blueline_core_seo_archive_canonical_url() );
	}

	/**
	 * Another SEO plugin owns the canonical when it is active.
	 */
	public function test_no_canonical_when_an_seo_plugin_is_active(): void {
		$state                       = &blueline_test_state();
		$state['request']['archive'] = true;
		add_filter( 'blueline_core_seo_plugin_active', '__return_true' );

		$this->assertStringNotContainsString( 'canonical', $this->render_head() );
	}

	// -----------------------------------------------------------------------
	// Open Graph / Twitter
	// -----------------------------------------------------------------------

	/**
	 * Provider: post type and the og:type it gets.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function og_type_cases(): array {
		return array(
			'blog post' => array( 'post', 'article' ),
			'player'    => array( 'sp_player', 'profile' ),
			'team'      => array( 'sp_team', 'website' ),
			'page'      => array( 'page', 'website' ),
		);
	}

	/**
	 * Only posts are articles and only players are profiles.
	 *
	 * @param string $post_type Post type.
	 * @param string $expected  og:type.
	 */
	#[DataProvider( 'og_type_cases' )]
	public function test_og_type_by_post_type( string $post_type, string $expected ): void {
		$this->set_up_singular_request( 300, $post_type, array( 'title' => 'Title' ) );

		$this->assertStringContainsString( '<meta property="og:type" content="' . $expected . '">', $this->render_head() );
	}

	/**
	 * A real landscape featured image gets the large card and its size and alt text.
	 */
	public function test_landscape_image_gets_the_large_card_with_size_and_alt(): void {
		$state              = &blueline_test_state();
		$state['posts'][55] = array(
			'is_image' => true,
			'width'    => 1200,
			'height'   => 630,
		);
		$state['post_meta'][55]['_wp_attachment_image_alt'] = 'The team <b>photo</b>';
		$this->set_up_singular_request( 300, 'post', array( 'thumbnail_id' => 55 ) );

		$html = $this->render_head();

		$this->assertStringContainsString( '<meta property="og:image" content="https://example.test/uploads/photo-55-large.jpg">', $html );
		$this->assertStringContainsString( '<meta property="og:image:width" content="1200">', $html );
		$this->assertStringContainsString( '<meta property="og:image:height" content="630">', $html );
		$this->assertStringContainsString( '<meta property="og:image:alt" content="The team photo">', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image">', $html );
	}

	/**
	 * The large-size dimensions are used when that size exists (the URL is the large one).
	 */
	public function test_image_size_follows_the_generated_large_size(): void {
		$state              = &blueline_test_state();
		$state['posts'][55] = array(
			'is_image' => true,
			'width'    => 4000,
			'height'   => 2000,
			'sizes'    => array(
				'large' => array(
					'width'  => 1024,
					'height' => 512,
				),
			),
		);
		$this->set_up_singular_request( 300, 'post', array( 'thumbnail_id' => 55 ) );

		$html = $this->render_head();

		$this->assertStringContainsString( '<meta property="og:image:width" content="1024">', $html );
		$this->assertStringContainsString( '<meta property="og:image:height" content="512">', $html );
	}

	/**
	 * A square image (a team logo) gets the small card, which does not crop it.
	 */
	public function test_square_image_gets_the_summary_card(): void {
		$state              = &blueline_test_state();
		$state['posts'][55] = array(
			'is_image' => true,
			'width'    => 512,
			'height'   => 512,
		);
		$this->set_up_singular_request( 300, 'sp_team', array( 'thumbnail_id' => 55 ) );

		$this->assertStringContainsString( '<meta name="twitter:card" content="summary">', $this->render_head() );
	}

	/**
	 * The site-logo fallback always gets the small card, even when the logo is landscape.
	 */
	public function test_logo_fallback_gets_the_summary_card(): void {
		$state                              = &blueline_test_state();
		$state['posts'][55]                 = array(
			'is_image' => true,
			'width'    => 800,
			'height'   => 400,
		);
		$state['theme_mods']['custom_logo'] = 55;
		$this->set_up_singular_request( 300, 'post', array( 'title' => 'No image' ) );

		$html = $this->render_head();

		$this->assertStringContainsString( 'content="https://example.test/uploads/photo-55-full.jpg"', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary">', $html );
	}

	/**
	 * A banner-shaped featured image (wider than 3:1) is ignored in favour of the default share image.
	 */
	public function test_banner_image_is_replaced_by_the_default_share_image(): void {
		$state              = &blueline_test_state();
		$state['posts'][55] = array(
			'is_image' => true,
			'width'    => 1024,
			'height'   => 177,
		);
		$state['posts'][66] = array(
			'is_image' => true,
			'width'    => 1200,
			'height'   => 630,
		);
		$GLOBALS['bl_test_options']['blueline_core_default_share_image'] = 66;
		$this->set_up_singular_request( 300, 'page', array( 'thumbnail_id' => 55 ) );

		$html = $this->render_head();

		$this->assertStringNotContainsString( 'photo-55', $html );
		$this->assertStringContainsString( '<meta property="og:image" content="https://example.test/uploads/photo-66-large.jpg">', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image">', $html );
	}

	/**
	 * A banner with no default share image and no logo leaves the page without an image.
	 */
	public function test_banner_image_without_a_fallback_prints_no_image(): void {
		$state              = &blueline_test_state();
		$state['posts'][55] = array(
			'is_image' => true,
			'width'    => 1024,
			'height'   => 177,
		);
		$this->set_up_singular_request( 300, 'page', array( 'thumbnail_id' => 55 ) );

		$html = $this->render_head();

		$this->assertStringNotContainsString( 'og:image', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary">', $html );
	}

	/**
	 * No size or alt is printed when they are unknown.
	 */
	public function test_unknown_image_size_and_alt_are_not_printed(): void {
		$this->set_up_singular_request( 300, 'post', array( 'thumbnail_id' => 55 ) );

		$html = $this->render_head();

		$this->assertStringContainsString( 'og:image"', $html );
		$this->assertStringNotContainsString( 'og:image:width', $html );
		$this->assertStringNotContainsString( 'og:image:alt', $html );
	}

	// -----------------------------------------------------------------------
	// SportsEvent location, home and away
	// -----------------------------------------------------------------------

	/**
	 * Provider: stored venue address and the PostalAddress it parses to.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function address_cases(): array {
		return array(
			'full with postal code and country' => array(
				'1179 Northside Rd, Burlington, ON L7M 1H5, Canada',
				array(
					'streetAddress'   => '1179 Northside Rd',
					'addressLocality' => 'Burlington',
					'addressRegion'   => 'ON',
					'postalCode'      => 'L7M 1H5',
					'addressCountry'  => 'Canada',
				),
			),
			'partial postal code'               => array(
				'1179 Northside Rd, Burlington, ON L7M, Canada',
				array(
					'streetAddress'   => '1179 Northside Rd',
					'addressLocality' => 'Burlington',
					'addressRegion'   => 'ON',
					'postalCode'      => 'L7M',
					'addressCountry'  => 'Canada',
				),
			),
			'no country'                        => array(
				'12 Main St, Hamilton, ON',
				array(
					'streetAddress'   => '12 Main St',
					'addressLocality' => 'Hamilton',
					'addressRegion'   => 'ON',
				),
			),
			'no region'                         => array(
				'12 Main St, Hamilton, Canada',
				array(
					'streetAddress'   => '12 Main St',
					'addressLocality' => 'Hamilton',
					'addressCountry'  => 'Canada',
				),
			),
			'too short to split'                => array(
				'Wave Twin Rinks, Burlington',
				array( 'streetAddress' => 'Wave Twin Rinks, Burlington' ),
			),
			'single part'                       => array(
				'Central Arena',
				array( 'streetAddress' => 'Central Arena' ),
			),
		);
	}

	/**
	 * The one-line address is split into the PostalAddress fields it has, and nothing is invented.
	 *
	 * @param string               $address  Stored address.
	 * @param array<string,string> $expected PostalAddress fields (without @type).
	 */
	#[DataProvider( 'address_cases' )]
	public function test_postal_address_parsing( string $address, array $expected ): void {
		$this->assertSame( array_merge( array( '@type' => 'PostalAddress' ), $expected ), blueline_core_seo_postal_address( $address ) );
	}

	/**
	 * No address means no PostalAddress.
	 */
	public function test_postal_address_is_empty_for_a_blank_address(): void {
		$this->assertSame( array(), blueline_core_seo_postal_address( '  ' ) );
	}

	/**
	 * The SportsEvent location is a Place with a PostalAddress and coordinates from the venue term.
	 */
	public function test_event_location_has_a_postal_address_and_geo(): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ) );
		$this->set_up_venue(
			'Red',
			array(
				'sp_address'   => '1179 Northside Rd, Burlington, ON L7M, Canada',
				'sp_latitude'  => '43.3601',
				'sp_longitude' => '-79.7898',
			)
		);

		$location = $this->node( $this->render_head(), 'SportsEvent' )['location'];

		$this->assertSame( 'Place', $location['@type'] );
		$this->assertSame( 'Red', $location['name'] );
		$this->assertSame( 'PostalAddress', $location['address']['@type'] );
		$this->assertSame( '1179 Northside Rd', $location['address']['streetAddress'] );
		$this->assertSame( 'Burlington', $location['address']['addressLocality'] );
		$this->assertSame( 'ON', $location['address']['addressRegion'] );
		$this->assertSame(
			array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => 43.3601,
				'longitude' => -79.7898,
			),
			$location['geo']
		);
	}

	/**
	 * A venue with no stored address is still a named Place, without an invented address or geo.
	 */
	public function test_event_location_without_an_address_is_a_bare_place(): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ) );
		$this->set_up_venue( 'Red', array() );

		$this->assertSame(
			array(
				'@type' => 'Place',
				'name'  => 'Red',
			),
			$this->node( $this->render_head(), 'SportsEvent' )['location']
		);
	}

	/**
	 * A hostile address stays inside the JSON string and the block stays valid JSON.
	 */
	public function test_hostile_venue_address_cannot_break_the_json_ld(): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ) );
		$this->set_up_venue( 'Red', array( 'sp_address' => '1 &quot;Quote&quot; &lt;/script&gt;&lt;!-- St, Town, ON, Canada' ) );

		$html = $this->render_head();

		$this->assertSame( 1, substr_count( strtolower( $html ), '</script>' ) );
		$this->assertStringNotContainsString( '<!--', $html );
		$this->assertSame( '1 "Quote" </script><!-- St', $this->node( $html, 'SportsEvent' )['location']['address']['streetAddress'] );
	}

	/**
	 * Both teams appear as SportsTeam homeTeam and awayTeam, in stored order, beside the competitors.
	 */
	public function test_event_has_home_and_away_teams(): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ) );

		$event = $this->node( $this->render_head(), 'SportsEvent' );

		$this->assertSame( 'SportsTeam', $event['homeTeam']['@type'] );
		$this->assertSame( 'Whalers', $event['homeTeam']['name'] );
		$this->assertSame( 'https://example.test/team/10/', $event['homeTeam']['url'] );
		$this->assertSame( 'SportsTeam', $event['awayTeam']['@type'] );
		$this->assertSame( 'Kraken', $event['awayTeam']['name'] );
		$this->assertCount( 2, $event['competitor'] );
	}

	/**
	 * With a single team known, only the home side is named.
	 */
	public function test_event_with_one_team_has_no_away_team(): void {
		$this->set_up_event_request( array( 'Whalers' ) );

		$event = $this->node( $this->render_head(), 'SportsEvent' );

		$this->assertArrayHasKey( 'homeTeam', $event );
		$this->assertArrayNotHasKey( 'awayTeam', $event );
	}

	/**
	 * Provider: sp_status and the eventStatus it maps to.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function status_cases(): array {
		return array(
			'postponed' => array( 'postponed', 'https://schema.org/EventPostponed' ),
			'cancelled' => array( 'cancelled', 'https://schema.org/EventCancelled' ),
			'ok'        => array( 'ok', 'https://schema.org/EventScheduled' ),
		);
	}

	/**
	 * The status reaches the printed event node.
	 *
	 * @param string $status   sp_status.
	 * @param string $expected eventStatus.
	 */
	#[DataProvider( 'status_cases' )]
	public function test_event_status_in_the_printed_node( string $status, string $expected ): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ), $status );

		$this->assertSame( $expected, $this->node( $this->render_head(), 'SportsEvent' )['eventStatus'] );
	}

	/**
	 * The event description uses the site-timezone time.
	 */
	public function test_event_description_uses_the_site_timezone_time(): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ) );

		$this->assertStringEndsWith( ' at 7:00 PM', blueline_social_meta_data()['description'] );
	}

	// -----------------------------------------------------------------------
	// SportsTeam and BreadcrumbList
	// -----------------------------------------------------------------------

	/**
	 * A team page adds a SportsTeam node tied to the league organisation.
	 */
	public function test_team_page_adds_a_sports_team_node(): void {
		$state              = &blueline_test_state();
		$state['posts'][55] = array(
			'is_image' => true,
			'width'    => 512,
			'height'   => 512,
		);
		$this->set_up_singular_request(
			300,
			'sp_team',
			array(
				'title'        => 'Whalers',
				'thumbnail_id' => 55,
			)
		);

		$team = $this->node( $this->render_head(), 'SportsTeam' );

		$this->assertSame( 'Whalers', $team['name'] );
		$this->assertSame( 'https://example.test/sp_team/300/', $team['url'] );
		$this->assertSame( 'https://example.test/uploads/photo-55-large.jpg', $team['logo'] );
		$this->assertSame( 'Ice Hockey', $team['sport'] );
		$this->assertSame( array( '@id' => 'https://example.test/#organization' ), $team['memberOf'] );
	}

	/**
	 * A team page has a Home, then team breadcrumb, and a Teams level when the archive exists.
	 */
	public function test_team_page_breadcrumbs(): void {
		$this->set_up_singular_request( 300, 'sp_team', array( 'title' => 'Whalers' ) );

		$crumbs = $this->node( $this->render_head(), 'BreadcrumbList' )['itemListElement'];

		$this->assertCount( 2, $crumbs );
		$this->assertSame( 'ListItem', $crumbs[0]['@type'] );
		$this->assertSame( 1, $crumbs[0]['position'] );
		$this->assertSame( 'https://example.test/', $crumbs[0]['item'] );
		$this->assertSame( 2, $crumbs[1]['position'] );
		$this->assertSame( 'Whalers', $crumbs[1]['name'] );

		$state                                  = &blueline_test_state();
		$state['post_type_archives']['sp_team'] = 'https://example.test/teams/';
		$state['post_type_labels']['sp_team']   = 'Teams';
		$crumbs                                 = $this->node( $this->render_head(), 'BreadcrumbList' )['itemListElement'];

		$this->assertSame( array( 'Home', 'Teams', 'Whalers' ), array_column( $crumbs, 'name' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $crumbs, 'position' ) );
	}

	/**
	 * An event page has a breadcrumb ending in its dated title, and no SportsTeam node.
	 */
	public function test_event_page_breadcrumbs(): void {
		$this->set_up_event_request( array( 'Whalers', 'Kraken' ) );

		$html   = $this->render_head();
		$crumbs = $this->node( $html, 'BreadcrumbList' )['itemListElement'];

		$this->assertSame( 'https://example.test/event/100', end( $crumbs )['item'] );
		$this->assertStringStartsWith( 'Whalers vs Kraken — ', end( $crumbs )['name'] );
		$this->assertSame( array( 'SportsOrganization', 'SportsEvent', 'BreadcrumbList' ), array_column( $this->graph( $html ), '@type' ) );
	}

	/**
	 * A venue archive has a Home, then venue breadcrumb.
	 */
	public function test_venue_archive_breadcrumbs(): void {
		$state                    = &blueline_test_state();
		$state['request']         = array(
			'tax'    => 'sp_venue',
			'object' => (object) array(
				'term_id' => 900,
				'name'    => 'Red',
			),
		);
		$state['term_links'][900] = 'https://example.test/venue/red/';

		$crumbs = $this->node( $this->render_head(), 'BreadcrumbList' )['itemListElement'];

		$this->assertSame( array( 'Home', 'Red' ), array_column( $crumbs, 'name' ) );
		$this->assertSame( 'https://example.test/venue/red/', $crumbs[1]['item'] );
	}

	/**
	 * Pages that are not a team, event or venue have neither extra node.
	 */
	public function test_other_pages_have_only_the_organization_node(): void {
		$this->set_up_singular_request( 302, 'page', array( 'title' => 'Rules' ) );

		$this->assertSame( array( 'SportsOrganization' ), array_column( $this->graph( $this->render_head() ), '@type' ) );
	}
}
