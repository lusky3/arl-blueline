<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/social-meta.php';

/**
 * Covers the pure data-shaping functions behind the Open Graph/Twitter
 * Card meta and the schema.org JSON-LD: blueline_organization_schema()
 * and blueline_social_meta_data_for_event().
 *
 * Deliberately does NOT cover blueline_social_meta_data()'s is_singular()/
 * is_front_page() dispatch, blueline_sports_event_schema()'s startDate
 * (which resolves through blueline_sp_event_start_timestamp() ->
 * get_gmt_from_date(), a real-timezone function this suite has never
 * stubbed anywhere), or the "has a custom logo" branch (has_custom_logo()
 * is hard-coded false everywhere in this bootstrap, by design -- see its
 * own docblock). All three are exercised live on staging instead, the
 * same choice this codebase already made for blueline_venue_label()'s
 * full (arena + get_term() pad name) form -- see VenueLabelTest's own
 * docblock.
 */
final class SocialMetaTest extends TestCase {

	/**
	 * Test case.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
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

		$this->assertStringContainsString( 'thumb-55', $data['image'] );
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

		$this->assertStringContainsString( 'thumb-66', $data['image'] );
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
	public function test_event_meta_type_is_website_and_url_is_the_permalink(): void {
		blueline_test_register_post( 100, 'publish', 'https://example.test/event/100' );

		$data = blueline_social_meta_data_for_event( 100, '' );

		$this->assertSame( 'website', $data['type'] );
		$this->assertSame( 'https://example.test/event/100', $data['url'] );
	}
}
