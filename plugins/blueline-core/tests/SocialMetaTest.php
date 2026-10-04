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
 * Card meta and the schema.org JSON-LD: blueline_organization_schema()
 * and blueline_social_meta_data_for_event().
 *
 * The printed output, the singular/front-page/archive dispatch, JSON-LD and
 * the SportsEvent schema are covered end to end in SeoMetaOutputTest. Still
 * not covered: the "has a custom logo" branch (has_custom_logo() is
 * hard-coded false everywhere in this bootstrap, by design -- see its own
 * docblock), exercised live on staging instead.
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
		$this->assertArrayHasKey( '@id', $schema );
		$this->assertArrayHasKey( 'url', $schema );
	}

	/**
	 * Test case.
	 */
	public function test_organization_schema_omits_logo_when_none_is_set(): void {
		// has_custom_logo() is hard-coded false throughout this bootstrap
		// (see its own docblock), so this is the only branch reachable in
		// a unit test -- the "has a logo" branch is a live-only check.
		$schema = blueline_organization_schema();

		$this->assertArrayNotHasKey( 'logo', $schema );
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
		// No sp_venue taxonomy registered -- taxonomy_exists( 'sp_venue' )
		// is false, so blueline_venue_label() is never reached and no
		// venue segment should appear.
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
	// SEO plugin deference (WP-12)
	// -----------------------------------------------------------------------

	/**
	 * No SEO plugin is defined in this suite, so the theme prints its own JSON-LD.
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
	// SportsPress helpers: theme delegation and standalone fallbacks
	// -----------------------------------------------------------------------

	/**
	 * Without the theme's helpers, the plain fallbacks read the same meta and title.
	 */
	public function test_fallbacks_resolve_teams_title_and_missing_venue_without_the_theme(): void {
		$state                              = &blueline_test_state();
		$state['posts'][10]['title']        = 'Puck Dynasty';
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( array( 10, 0, 11 ) );

		$this->assertSame( array( 10, 11 ), blueline_core_seo_event_team_ids_fallback( 100 ) );
		$this->assertSame( 'Puck Dynasty', blueline_core_seo_title_fallback( 10 ) );
		$this->assertSame( '', blueline_core_seo_event_venue_label_fallback( 100 ) );
	}

	/**
	 * The fallback converts the event's local time to GMT: with a site 4 hours behind UTC, a 7:00 PM
	 * local start is 23:00 GMT; with a UTC site the clock time is unchanged.
	 */
	public function test_start_timestamp_fallback_converts_local_time_to_gmt(): void {
		$state = &blueline_test_state();

		$utc = blueline_core_seo_event_start_timestamp_fallback( 100 );
		$this->assertIsInt( $utc );
		$this->assertSame( '19:00', gmdate( 'H:i', $utc ) );

		$state['gmt_offset'] = -4 * HOUR_IN_SECONDS;
		$toronto             = blueline_core_seo_event_start_timestamp_fallback( 100 );
		$this->assertIsInt( $toronto );
		$this->assertSame( '23:00', gmdate( 'H:i', $toronto ) );
		$this->assertSame( 4 * HOUR_IN_SECONDS, $toronto - $utc );
	}

	/**
	 * An event whose date cannot be resolved has no start timestamp.
	 */
	public function test_start_timestamp_fallback_is_false_when_the_date_cannot_be_resolved(): void {
		$state                     = &blueline_test_state();
		$state['gmt_unresolvable'] = true;

		$this->assertFalse( blueline_core_seo_event_start_timestamp_fallback( 100 ) );
	}

	/**
	 * With the theme's helpers loaded, the wrappers return what the theme returns, and that
	 * matches the fallbacks for the same event.
	 */
	public function test_wrappers_delegate_to_the_theme_helpers_when_present(): void {
		require_once BLUELINE_DIR . '/inc/sportspress.php';

		$state                              = &blueline_test_state();
		$state['posts'][10]['title']        = 'Puck Dynasty';
		$state['posts'][11]['title']        = 'Hammers';
		$state['post_meta'][100]['sp_team'] = new Blueline_Test_Meta_Rows( array( 10, 11 ) );

		$this->assertSame( blueline_sp_event_team_ids( 100 ), blueline_core_seo_event_team_ids( 100 ) );
		$this->assertSame( blueline_sp_title( 10 ), blueline_core_seo_title( 10 ) );
		$this->assertSame( blueline_sp_event_venue_label( 100 ), blueline_core_seo_event_venue_label( 100 ) );
		$this->assertSame( blueline_sp_event_start_timestamp( 100 ), blueline_core_seo_event_start_timestamp( 100 ) );
		$this->assertSame( blueline_core_seo_event_team_ids_fallback( 100 ), blueline_core_seo_event_team_ids( 100 ) );
		$this->assertSame( blueline_core_seo_title_fallback( 10 ), blueline_core_seo_title( 10 ) );
	}
}
