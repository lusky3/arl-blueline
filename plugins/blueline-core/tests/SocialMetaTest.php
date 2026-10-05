<?php
/**
 * Unit tests.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/seo-meta/seo-meta.php';

/**
 * Covers the pure data-shaping functions behind the Open Graph/Twitter
 * Card meta and the schema.org JSON-LD: blueline_organization_schema(),
 * blueline_social_meta_data_for_event(), the logo lookup and the SportsPress helpers.
 *
 * The printed output, the singular/front-page/archive dispatch, JSON-LD and
 * the SportsEvent schema are covered end to end in SeoMetaOutputTest.
 */
final class SocialMetaTest extends TestCase {

	/**
	 * Test case.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		// Attachments that exist as real images; thumbnail ids elsewhere in this file point at these.
		$state              = &blueline_test_state();
		$state['posts'][55] = array( 'is_image' => true );
		$state['posts'][66] = array( 'is_image' => true );
	}

	// -----------------------------------------------------------------------
	// blueline_organization_schema()
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_organization_schema_has_the_required_sports_organization_shape(): void {
		$schema = blueline_organization_schema();

		$this->assertSame( 'SportsOrganization', $schema['@type'] );
		$this->assertSame( 'Blueline Test Site', $schema['name'] );
		$this->assertSame( 'https://example.test/#organization', $schema['@id'] );
		$this->assertSame( 'https://example.test/', $schema['url'] );
	}

	/**
	 * Test case.
	 */
	public function test_organization_schema_omits_logo_when_none_is_set(): void {
		$this->assertSame( '', blueline_social_logo_url() );
		$this->assertArrayNotHasKey( 'logo', blueline_organization_schema() );
	}

	/**
	 * The custom-logo theme mod flows into the logo URL, the Organization schema and the og:image
	 * fallback of a page with no featured image.
	 */
	public function test_custom_logo_feeds_the_logo_url_organization_schema_and_og_image_fallback(): void {
		$state                              = &blueline_test_state();
		$state['theme_mods']['custom_logo'] = 55;

		$this->assertSame( 'https://example.test/uploads/photo-55-full.jpg', blueline_social_logo_url() );
		$this->assertSame( 'https://example.test/uploads/photo-55-full.jpg', blueline_organization_schema()['logo'] );
		$this->assertSame( 'https://example.test/uploads/photo-55-full.jpg', blueline_social_meta_data()['image'] );
	}

	/**
	 * A custom_logo id that no longer points at an image (deleted attachment) yields no logo.
	 */
	public function test_custom_logo_pointing_at_a_missing_attachment_yields_no_logo(): void {
		$state                              = &blueline_test_state();
		$state['theme_mods']['custom_logo'] = 999;

		$this->assertSame( '', blueline_social_logo_url() );
		$this->assertArrayNotHasKey( 'logo', blueline_organization_schema() );
	}

	// -----------------------------------------------------------------------
	// blueline_social_meta_data_for_event()
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_event_meta_names_both_teams_and_includes_the_venue(): void {
		$state                              = &blueline_test_state();
		$state['posts'][10]['title']        = 'Puck Dynasty';
		$state['posts'][11]['title']        = 'Hammers';
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( array( 10, 11 ) );

		$data = blueline_social_meta_data_for_event( 100, 'https://example.test/logo.png' );

		$this->assertSame( 'Puck Dynasty vs Hammers', $data['title'] );
		$this->assertStringContainsString( 'Aug 20', $data['description'] );
		$this->assertStringContainsString( '7:00 PM', $data['description'] );
		// No sp_venue taxonomy registered, so there is no venue segment.
		$this->assertStringNotContainsString( '·', $data['description'] );
	}

	/**
	 * Test case.
	 */
	public function test_event_meta_falls_back_to_tbd_for_a_missing_team(): void {
		$state                              = &blueline_test_state();
		$state['posts'][10]['title']        = 'Puck Dynasty';
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( array( 10 ) );

		$data = blueline_social_meta_data_for_event( 100, '' );

		$this->assertSame( 'Puck Dynasty vs TBD', $data['title'] );
	}

	/**
	 * Test case.
	 */
	public function test_event_meta_uses_the_first_teams_thumbnail_when_present(): void {
		$state                              = &blueline_test_state();
		$state['posts'][10]                 = array(
			'title'        => 'Puck Dynasty',
			'thumbnail_id' => 55,
		);
		$state['posts'][11]                 = array(
			'title'        => 'Hammers',
			'thumbnail_id' => 66,
		);
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( array( 10, 11 ) );

		$data = blueline_social_meta_data_for_event( 100, 'https://example.test/logo.png' );

		$this->assertStringContainsString( 'photo-55-large', $data['image'] );
	}

	/**
	 * Test case.
	 */
	public function test_event_meta_falls_back_to_the_second_teams_thumbnail(): void {
		$state                              = &blueline_test_state();
		$state['posts'][10]                 = array( 'title' => 'Puck Dynasty' ); // No thumbnail.
		$state['posts'][11]                 = array(
			'title'        => 'Hammers',
			'thumbnail_id' => 66,
		);
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( array( 10, 11 ) );

		$data = blueline_social_meta_data_for_event( 100, 'https://example.test/logo.png' );

		$this->assertStringContainsString( 'photo-66-large', $data['image'] );
	}

	/**
	 * Test case.
	 */
	public function test_event_meta_falls_back_to_the_site_logo_with_no_team_thumbnails(): void {
		$state                              = &blueline_test_state();
		$state['posts'][10]['title']        = 'Puck Dynasty';
		$state['posts'][11]['title']        = 'Hammers';
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( array( 10, 11 ) );

		$data = blueline_social_meta_data_for_event( 100, 'https://example.test/logo.png' );

		$this->assertSame( 'https://example.test/logo.png', $data['image'] );
	}

	/**
	 * Test case.
	 */
	public function test_event_meta_never_uses_the_themes_placeholder_team_logo(): void {
		// The theme reports a negative placeholder thumbnail id for a logo-less team (default SVG badge).
		$state                              = &blueline_test_state();
		$state['posts'][10]                 = array(
			'title'        => 'Puck Dynasty',
			'thumbnail_id' => -1,
		);
		$state['posts'][11]                 = array(
			'title'        => 'Hammers',
			'thumbnail_id' => 66,
		);
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( array( 10, 11 ) );

		$data = blueline_social_meta_data_for_event( 100, 'https://example.test/logo.png' );

		$this->assertStringContainsString( 'photo-66-large', $data['image'], 'The placeholder team is skipped for the next real logo.' );

		$state['posts'][11]['thumbnail_id'] = -1;
		$data                               = blueline_social_meta_data_for_event( 100, 'https://example.test/logo.png' );

		$this->assertSame( 'https://example.test/logo.png', $data['image'], 'Two placeholders fall back to the site logo.' );
	}

	/**
	 * Test case.
	 */
	public function test_real_thumbnail_url_rejects_the_placeholder_and_missing_logos(): void {
		$state              = &blueline_test_state();
		$state['posts'][10] = array( 'thumbnail_id' => -1 );
		$state['posts'][11] = array( 'thumbnail_id' => 66 );
		$state['posts'][12] = array();

		$this->assertSame( '', blueline_core_real_thumbnail_url( 10 ) );
		$this->assertSame( '', blueline_core_real_thumbnail_url( 12 ) );
		$this->assertStringContainsString( 'photo-66-large', blueline_core_real_thumbnail_url( 11 ) );
	}

	/**
	 * Test case.
	 */
	public function test_event_meta_type_is_website_and_url_is_the_permalink(): void {
		blueline_test_register_post( 100, 'publish', 'https://example.test/event/100' );

		$data = blueline_social_meta_data_for_event( 100, '' );

		$this->assertSame( 'website', $data['type'] );
		$this->assertSame( 'https://example.test/event/100', $data['url'] );
	}

	// -----------------------------------------------------------------------
	// SEO plugin deference
	// -----------------------------------------------------------------------

	/**
	 * No SEO plugin is defined in this suite, so the module prints its own JSON-LD.
	 */
	public function test_structured_data_prints_without_an_seo_plugin(): void {
		blueline_test_reset_hooks();

		$this->assertFalse( blueline_seo_plugin_active() );

		ob_start();
		blueline_render_structured_data();
		$this->assertStringContainsString( 'application/ld+json', (string) ob_get_clean() );
	}

	/**
	 * With an SEO plugin active, neither the meta tags nor the JSON-LD print.
	 */
	public function test_social_output_steps_aside_for_an_seo_plugin(): void {
		blueline_test_reset_hooks();
		add_filter( 'blueline_seo_plugin_active', static fn() => true );

		ob_start();
		blueline_render_social_meta();
		blueline_render_structured_data();
		$output = (string) ob_get_clean();

		blueline_test_reset_hooks();

		$this->assertSame( '', $output );
	}

	// -----------------------------------------------------------------------
	// SportsPress helpers
	// -----------------------------------------------------------------------

	/**
	 * Team ids: positive ids only, re-indexed, in stored order.
	 */
	public function test_event_team_ids_drops_empty_ids_and_reindexes(): void {
		$state                              = &blueline_test_state();
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( array( 10, 0, 11 ) );

		$this->assertSame( array( 10, 11 ), blueline_core_seo_event_team_ids( 100 ) );
	}

	/**
	 * Players and staff use the raw post title; everything else the display title, all plain text.
	 */
	public function test_title_uses_the_raw_title_for_players_and_the_display_title_otherwise(): void {
		$state                       = &blueline_test_state();
		$state['posts'][10]['title'] = 'Puck Dynasty';

		$this->assertSame( 'Puck Dynasty', blueline_core_seo_title( 10 ) );

		$state['posts'][20] = array(
			'title' => '<strong class="sp-player-number">27</strong> Matthew',
			'type'  => 'sp_player',
		);

		$this->assertSame( '27 Matthew', blueline_core_seo_title( 20 ) );
	}

	/**
	 * The venue label is '' for an event with no venue.
	 */
	public function test_venue_label_is_empty_without_a_venue(): void {
		$this->assertSame( '', blueline_core_seo_event_venue_label( 100 ) );
	}

	/**
	 * The start timestamp is the event's local time converted to GMT (here a site 4 hours behind UTC).
	 */
	public function test_start_timestamp_converts_local_time_to_gmt(): void {
		$state               = &blueline_test_state();
		$state['gmt_offset'] = -4 * HOUR_IN_SECONDS;

		$timestamp = blueline_core_seo_event_start_timestamp( 100 );

		$this->assertIsInt( $timestamp );
		$this->assertSame( '23:00', gmdate( 'H:i', $timestamp ), '7:00 PM local is 23:00 GMT.' );
	}

	/**
	 * An event whose date cannot be resolved has no start timestamp.
	 */
	public function test_start_timestamp_is_false_when_the_date_cannot_be_resolved(): void {
		$state                     = &blueline_test_state();
		$state['gmt_unresolvable'] = true;

		$this->assertFalse( blueline_core_seo_event_start_timestamp( 100 ) );
	}
}
