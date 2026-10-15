<?php
/**
 * Venue Map: override of SportsPress's templates/venue-map.php.
 *
 * On a single event the map is a click-to-load placeholder
 * (blueline_render_venue_map_placeholder(), inc/sportspress/venues.php): the
 * Leaflet map was the slowest thing on the page to load. Everywhere else,
 * including the venue's own page, this is SportsPress's template unchanged.
 *
 * @package blueline
 * @version 2.7.18 (copied from SportsPress Pro 2.7.29: sportspress-pro/includes/sportspress/templates/venue-map.php)
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $meta ) ) {
	return;
}

if ( is_singular( 'sp_event' ) && is_array( $meta ) && blueline_venue_map_is_click_to_load() ) {
	blueline_render_venue_map_placeholder( $meta );
	return;
}

if ( is_tax( 'sp_venue' ) ) {
	do_action( 'sportspress_before_venue_map' );
}

$bl_address = sp_array_value( $meta, 'sp_address', null );
if ( ! is_null( $bl_address ) ) {
	$bl_address = urlencode( $bl_address ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- SportsPress's own encoding, kept so the map modules receive the exact string they always have.
}
$bl_latitude  = sp_array_value( $meta, 'sp_latitude', null );
$bl_longitude = sp_array_value( $meta, 'sp_longitude', null );
$bl_zoom      = get_option( 'sportspress_map_zoom', 15 );
$bl_maptype   = strtolower( (string) get_option( 'sportspress_map_type', 'roadmap' ) );

if ( '' === $bl_address ) {
	$bl_address = '+';
}
if ( 'satellite' !== $bl_maptype ) {
	$bl_maptype = 'roadmap';
}

if ( null !== $bl_latitude && null !== $bl_longitude && '' !== $bl_latitude && '' !== $bl_longitude ) {
	do_action( 'sp_venue_show_map', $bl_latitude, $bl_longitude, $bl_address, $bl_zoom, $bl_maptype );
}
if ( is_tax( 'sp_venue' ) ) {
	do_action( 'sportspress_after_venue_map' );
}
