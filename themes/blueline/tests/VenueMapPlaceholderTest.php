<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/enqueue.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * The event page's venue map is a fixed-height placeholder that loads
 * Leaflet only when asked (assets/src/js/venue-map.js does the loading).
 */
final class VenueMapPlaceholderTest extends TestCase {

	private const META = array(
		'sp_address'   => '1179 Northside Rd, Burlington, ON L7M, Canada',
		'sp_latitude'  => '43.36113990718071',
		'sp_longitude' => '-79.80409741401672',
	);

	/**
	 * A venue with no coordinates gets no map, the same as SportsPress.
	 */
	public function test_no_coordinates_means_no_placeholder(): void {
		$this->assertNull( blueline_venue_map_placeholder_data( array( 'sp_address' => 'Somewhere' ), 'Red', '15', 'roadmap', 'l.js', 'l.css' ) );
		$this->assertNull(
			blueline_venue_map_placeholder_data(
				array(
					'sp_latitude'  => '',
					'sp_longitude' => '',
				),
				'Red',
				'15',
				'roadmap',
				'l.js',
				'l.css'
			)
		);
	}

	/**
	 * Settings are normalised the way SportsPress's own template does.
	 */
	public function test_settings_are_normalised(): void {
		$data = blueline_venue_map_placeholder_data( self::META, 'Mr. Lube and Tires Arena — Black', '99', 'SATELLITE', 'l.js', 'l.css' );

		$this->assertSame( 15, $data['zoom'] );
		$this->assertSame( 'satellite', $data['maptype'] );
		$this->assertSame( 'roadmap', blueline_venue_map_placeholder_data( self::META, '', '12', 'hybrid', '', '' )['maptype'] );
		$this->assertSame( 12, blueline_venue_map_placeholder_data( self::META, '', '12', 'roadmap', '', '' )['zoom'] );
		$this->assertSame( 'https://www.google.com/maps/search/?api=1&query=43.36113990718071%2C-79.80409741401672', $data['link'] );
	}

	/**
	 * The placeholder shows the venue and address, carries everything the
	 * script needs, and offers both the in-place map and Google Maps.
	 */
	public function test_placeholder_markup(): void {
		$html = blueline_venue_map_placeholder_html(
			blueline_venue_map_placeholder_data( self::META, 'Mr. Lube and Tires Arena — Black', '15', 'roadmap', 'https://example.test/leaflet.js?ver=1.8.0', 'https://example.test/leaflet.css?ver=1.8.0' )
		);

		$this->assertStringContainsString( '<div class="bl-venue-map" data-bl-venue-map ', $html );
		$this->assertStringContainsString( 'data-lat="43.36113990718071"', $html );
		$this->assertStringContainsString( 'data-lng="-79.80409741401672"', $html );
		$this->assertStringContainsString( 'data-leaflet-js="https://example.test/leaflet.js?ver=1.8.0"', $html );
		$this->assertStringContainsString( 'data-map-label="Map of Mr. Lube and Tires Arena — Black (opens Google Maps in a new tab)"', $html );
		$this->assertStringContainsString( '<p class="bl-venue-map__name">Mr. Lube and Tires Arena — Black</p>', $html );
		$this->assertStringContainsString( '<p class="bl-venue-map__address">1179 Northside Rd, Burlington, ON L7M, Canada</p>', $html );
		$this->assertStringContainsString( '<button type="button" class="bl-btn bl-btn--secondary bl-venue-map__open">', $html );
		$this->assertStringContainsString( 'class="bl-venue-map__link" href="https://www.google.com/maps/search/?api=1&query=43.36113990718071%2C-79.80409741401672" target="_blank" rel="noopener"', $html );
	}

	/**
	 * Without Leaflet's URLs the button would do nothing, so only the Google
	 * Maps link is offered.
	 */
	public function test_no_button_without_leaflet(): void {
		$html = blueline_venue_map_placeholder_html( blueline_venue_map_placeholder_data( self::META, 'Red', '15', 'roadmap', '', '' ) );

		$this->assertStringNotContainsString( 'data-bl-venue-map', $html );
		$this->assertStringNotContainsString( '<button', $html );
		$this->assertStringContainsString( 'bl-venue-map__link', $html );
	}

	/**
	 * Venue name and address are escaped.
	 */
	public function test_text_is_escaped(): void {
		$meta               = self::META;
		$meta['sp_address'] = '<script>x</script>';
		$html               = blueline_venue_map_placeholder_html( blueline_venue_map_placeholder_data( $meta, 'A "pad" <b>', '15', 'roadmap', 'l.js', 'l.css' ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'A &quot;pad&quot; &lt;b&gt;', $html );
	}

	/**
	 * A registered asset's URL carries its version; unknown handles give ''.
	 */
	public function test_registered_asset_url(): void {
		$deps = (object) array(
			'registered' => array(
				'leaflet_js' => (object) array(
					'src' => 'https://example.test/leaflet.js',
					'ver' => '1.8.0',
				),
				'no_src'     => (object) array(
					'src' => false,
					'ver' => '1',
				),
			),
		);

		$this->assertSame( 'https://example.test/leaflet.js?ver=1.8.0', blueline_registered_asset_url( $deps, 'leaflet_js' ) );
		$this->assertSame( '', blueline_registered_asset_url( $deps, 'no_src' ) );
		$this->assertSame( '', blueline_registered_asset_url( $deps, 'missing' ) );
	}

	/**
	 * The template override renders the placeholder only on a single event
	 * and otherwise hands over to whichever map module is hooked, unchanged.
	 */
	public function test_template_override_gates_on_single_event(): void {
		$template = (string) file_get_contents( __DIR__ . '/../sportspress/venue-map.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( "is_singular( 'sp_event' ) && is_array( \$meta ) && blueline_venue_map_is_click_to_load()", $template );
		$this->assertStringContainsString( "do_action( 'sp_venue_show_map', \$bl_latitude, \$bl_longitude, \$bl_address, \$bl_zoom, \$bl_maptype )", $template );
	}
}
