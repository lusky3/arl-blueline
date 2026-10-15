<?php
/**
 * SportsPress integration: venue archive query fix, arena names and
 * venue labels, the venue archive title, and the event page's
 * click-to-load venue map.
 *
 * Loaded by inc/sportspress.php; every SportsPress touchpoint is guarded
 * so the theme never fatals with SportsPress deactivated.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_action( 'pre_get_posts', 'blueline_sp_venue_archive_include_future' );
/**
 * WordPress's default main-query post_status is 'publish' only, which would
 * silently hide every upcoming (future-status) game from a venue's own
 * archive page, which is the exact under-counting bug this project has already
 * shipped twice in custom queries (Tasks 6/7). This is the equivalent fix
 * for the one query Task 8 does not build itself: the taxonomy-venue.php
 * main loop, which WordPress core populates before the template ever runs.
 *
 * @param WP_Query $query The main query, passed by reference.
 */
function blueline_sp_venue_archive_include_future( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( $query->is_tax( 'sp_venue' ) ) {
		$query->set( 'post_status', array( 'publish', 'future' ) );
	}
}

/**
 * The known street-address -> arena-name map, filterable so a site owner
 * can correct or extend it without a code deploy. P0 finding 10: SportsPress
 * venue *terms* only ever carry a pad name ("Red", "Black") plus a street
 * address (`sp_address`, in `get_option( 'taxonomy_' . $term_id )`); there
 * is no structured "arena name" field anywhere in the taxonomy. The arena
 * itself was also just renamed: /register (post 11113, live copy) now reads
 * "Mr. Lube and Tires Arena (formerly known as the Wave Twin Rinks)", but
 * the venue terms' own `description` fields (site content, not theme code)
 * still say only "Wave Twin Rinks"; confirmed live for terms 13/14/151/152.
 *
 * This intentionally does NOT try to parse that description text for a name:
 * reading every sp_venue term on this site showed the description's
 * opening line is sometimes a clean arena name ("Wave Twin Rinks", stripped:
 * "APPLEBY ICE CENTRE"), sometimes a rink-specific heading that would
 * duplicate the pad name if reused ("Mainway Recreation Centre - Rink A"),
 * sometimes a full sentence, and sometimes blank/decorative markup
 * (`&nbsp;`); there is no reliable convention to parse, so guessing would
 * ship wrong names as confidently as right ones. A small address-keyed map
 * is auditable and correct for the one rename this task has confirmed;
 * everywhere else this returns '' and blueline_venue_label() falls back to
 * the term's own name exactly as before.
 *
 * The real fix is a content one: give sp_venue terms an actual "arena name"
 * field (or at minimum update the description's opening line) so this map
 * can shrink to nothing. Recorded in this task's report as a content
 * follow-up for the site owner, not fixed here.
 *
 * @param string $address Raw `sp_address` value.
 * @return string Arena name, or '' when this address is not confidently known.
 */
function blueline_venue_arena_name_for_address( $address ) {
	$address = trim( (string) $address );

	if ( '' === $address ) {
		return '';
	}

	$known = apply_filters(
		'blueline_venue_arena_names',
		array(
			// Both forms seen live: Red/Black (term 14/13) store the address
			// without a postal code, StoneRidge Red/Wave Twin Rinks Blue
			// (term 151/152) store it with one; same building, two strings.
			'1179 northside rd, burlington, on l7m, canada'      => 'Mr. Lube and Tires Arena',
			'1179 northside rd, burlington, on l7m 1h5, canada'  => 'Mr. Lube and Tires Arena',
		)
	);

	$key = strtolower( $address );

	return isset( $known[ $key ] ) ? (string) $known[ $key ] : '';
}

/**
 * The arena name for a given sp_venue term, or '' when not confidently known.
 *
 * @param int $term_id sp_venue term ID.
 * @return string
 */
function blueline_venue_arena_name( $term_id ) {
	$term_id = absint( $term_id );

	if ( ! $term_id ) {
		return '';
	}

	$venue_meta = get_option( 'taxonomy_' . $term_id );
	$address    = ( is_array( $venue_meta ) && ! empty( $venue_meta['sp_address'] ) ) ? (string) $venue_meta['sp_address'] : '';

	return blueline_venue_arena_name_for_address( $address );
}

/**
 * The player-facing venue label: "{Arena name} — {Pad name}", per P0 finding
 * 10, PRODUCT.md principle 4 ("the pad, not just the arena"). Falls back to
 * the term's own name alone (today's behaviour, unchanged) whenever no
 * confidently-known arena name exists for that venue's address, or when the
 * arena name and the pad name are the same string (a single-pad venue whose
 * own term name already IS the full arena name, e.g. "Central Arena").
 *
 * Used by the scoreboard and event teaser (both in heroes.php), the venue
 * archive (sportspress/taxonomy-venue.php, via the get_the_archive_title
 * filter below), and the schedule table's Arena column
 * (sportspress/event-list.php). Package 2 can call this directly for the
 * homepage.
 *
 * @param int $term_id sp_venue term ID.
 * @return string
 */
function blueline_venue_label( $term_id ) {
	$term_id = absint( $term_id );

	if ( ! $term_id || ! taxonomy_exists( 'sp_venue' ) ) {
		return '';
	}

	$term = get_term( $term_id, 'sp_venue' );

	if ( ! ( $term instanceof WP_Term ) ) {
		return '';
	}

	$pad_name   = $term->name;
	$arena_name = blueline_venue_arena_name( $term_id );

	if ( '' === $arena_name || 0 === strcasecmp( $arena_name, $pad_name ) ) {
		return $pad_name;
	}

	return sprintf(
		/* translators: 1: arena name, 2: pad/sheet name. */
		__( '%1$s — %2$s', 'blueline' ),
		$arena_name,
		$pad_name
	);
}

add_filter( 'get_the_archive_title', 'blueline_sp_venue_archive_title' );
/**
 * The venue archive's own page title, via blueline_venue_label(), per P0
 * finding 10. Scoped strictly to the sp_venue taxonomy archive so every
 * other archive/page title on the site is untouched.
 *
 * @param string $title Default archive title.
 * @return string
 */
function blueline_sp_venue_archive_title( $title ) {
	if ( ! is_tax( 'sp_venue' ) ) {
		return $title;
	}

	$term = get_queried_object();

	if ( ! ( $term instanceof WP_Term ) ) {
		return $title;
	}

	$label = blueline_venue_label( $term->term_id );

	return '' !== $label ? esc_html( $label ) : $title;
}

/**
 * Whether the single-event venue map is click-to-load: only when SportsPress
 * draws venue maps with its OpenStreetMap (Leaflet) module, which is what the
 * placeholder loads. With the Google Maps module, or maps switched off, the
 * event page renders exactly what SportsPress does.
 *
 * @return bool
 */
function blueline_venue_map_is_click_to_load(): bool {
	global $wp_filter;

	if ( 'no' === get_option( 'sportspress_event_show_maps', 'yes' ) || ! isset( $wp_filter['sp_venue_show_map'] ) ) {
		return false;
	}

	return array() !== blueline_hooked_methods( $wp_filter['sp_venue_show_map']->callbacks, 'SportsPress_OpenStreetMap', 'show_venue_map' );
}

/**
 * Everything the click-to-load venue map needs, from a venue's SportsPress
 * meta (`taxonomy_{$term_id}`): null when the venue has no coordinates, the
 * same case in which SportsPress draws no map at all.
 *
 * @param array  $meta        Venue meta: sp_address, sp_latitude, sp_longitude.
 * @param string $label       Venue label (blueline_venue_label()).
 * @param string $zoom        sportspress_map_zoom option.
 * @param string $maptype     sportspress_map_type option.
 * @param string $leaflet_js  Leaflet script URL, '' when not registered.
 * @param string $leaflet_css Leaflet stylesheet URL, '' when not registered.
 * @return array{label: string, address: string, lat: string, lng: string, zoom: int, maptype: string, link: string, leaflet_js: string, leaflet_css: string}|null
 */
function blueline_venue_map_placeholder_data( array $meta, string $label, string $zoom, string $maptype, string $leaflet_js, string $leaflet_css ): ?array {
	$lat = trim( (string) ( $meta['sp_latitude'] ?? '' ) );
	$lng = trim( (string) ( $meta['sp_longitude'] ?? '' ) );

	if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
		return null;
	}

	$zoom = (int) $zoom;

	return array(
		'label'       => $label,
		'address'     => trim( (string) ( $meta['sp_address'] ?? '' ) ),
		'lat'         => $lat,
		'lng'         => $lng,
		'zoom'        => ( $zoom >= 1 && $zoom <= 18 ) ? $zoom : 15,
		'maptype'     => ( 'satellite' === strtolower( $maptype ) ) ? 'satellite' : 'roadmap',
		'link'        => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $lat . ',' . $lng ),
		'leaflet_js'  => $leaflet_js,
		'leaflet_css' => $leaflet_css,
	);
}

/**
 * A registered script or style's URL with its version, or '' when the
 * handle is not registered.
 *
 * @param WP_Dependencies $dependencies wp_scripts() or wp_styles().
 * @param string          $handle       Handle.
 * @return string
 */
function blueline_registered_asset_url( $dependencies, string $handle ): string {
	$item = $dependencies->registered[ $handle ] ?? null;

	if ( ! $item || ! is_string( $item->src ) || '' === $item->src ) {
		return '';
	}

	return $item->ver ? add_query_arg( 'ver', $item->ver, $item->src ) : $item->src;
}

/**
 * The label of the event venue whose SportsPress meta is $meta: the venue map
 * template is handed only the meta, and an event can list more than one venue.
 *
 * @param int   $event_id sp_event post ID.
 * @param array $meta     Venue meta passed to venue-map.php.
 * @return string
 */
function blueline_event_venue_label_for_meta( int $event_id, array $meta ): string {
	$terms = wp_get_post_terms( $event_id, 'sp_venue' );

	if ( is_wp_error( $terms ) ) {
		return '';
	}

	foreach ( $terms as $term ) {
		if ( get_option( 'taxonomy_' . $term->term_id ) === $meta ) {
			return blueline_venue_label( $term->term_id );
		}
	}

	return '';
}

/**
 * The single-event venue map as a fixed-height placeholder: venue, address,
 * an "Open map" button that loads Leaflet and the tiles in place
 * (assets/src/js/venue-map.js), and a Google Maps link, which is where the
 * SportsPress map itself linked. The live map was the largest thing to load
 * on an event page, and most visitors only need the address.
 *
 * @param array $meta Venue meta from SportsPress's venue-map.php.
 * @return void
 */
function blueline_render_venue_map_placeholder( array $meta ): void {
	$data = blueline_venue_map_placeholder_data(
		$meta,
		blueline_event_venue_label_for_meta( (int) get_the_ID(), $meta ),
		(string) get_option( 'sportspress_map_zoom', 15 ),
		(string) get_option( 'sportspress_map_type', 'roadmap' ),
		blueline_registered_asset_url( wp_scripts(), 'leaflet_js' ),
		blueline_registered_asset_url( wp_styles(), 'leaflet_stylesheet' )
	);

	if ( null === $data ) {
		return;
	}

	echo blueline_venue_map_placeholder_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece in blueline_venue_map_placeholder_html().
}

/**
 * The placeholder's markup for blueline_venue_map_placeholder_data()'s array.
 * The button is only printed when Leaflet's URLs are known.
 *
 * @param array $data From blueline_venue_map_placeholder_data().
 * @return string
 */
function blueline_venue_map_placeholder_html( array $data ): string {
	$can_load = '' !== $data['leaflet_js'] && '' !== $data['leaflet_css'];
	$attrs    = '';

	if ( $can_load ) {
		$place = '' !== $data['label'] ? $data['label'] : $data['address'];
		$attrs = sprintf(
			' data-bl-venue-map data-lat="%1$s" data-lng="%2$s" data-zoom="%3$s" data-maptype="%4$s" data-link="%5$s" data-map-label="%6$s" data-leaflet-js="%7$s" data-leaflet-css="%8$s"',
			esc_attr( $data['lat'] ),
			esc_attr( $data['lng'] ),
			esc_attr( (string) $data['zoom'] ),
			esc_attr( $data['maptype'] ),
			esc_url( $data['link'] ),
			esc_attr(
				'' !== $place
					/* translators: %s: venue name or address. */
					? sprintf( __( 'Map of %s (opens Google Maps in a new tab)', 'blueline' ), $place )
					: __( 'Map (opens Google Maps in a new tab)', 'blueline' )
			),
			esc_url( $data['leaflet_js'] ),
			esc_url( $data['leaflet_css'] )
		);
	}

	$html = '<div class="bl-venue-map"' . $attrs . '>';

	if ( '' !== $data['label'] ) {
		$html .= '<p class="bl-venue-map__name">' . esc_html( $data['label'] ) . '</p>';
	}

	if ( '' !== $data['address'] ) {
		$html .= '<p class="bl-venue-map__address">' . esc_html( $data['address'] ) . '</p>';
	}

	$html .= '<p class="bl-venue-map__actions">';

	if ( $can_load ) {
		$html .= '<button type="button" class="bl-btn bl-btn--secondary bl-venue-map__open"><span class="bl-skew"><span>'
			. esc_html__( 'Open map', 'blueline' )
			. '</span></span></button>';
	}

	$html .= '<a class="bl-venue-map__link" href="' . esc_url( $data['link'] ) . '" target="_blank" rel="noopener">'
		. esc_html__( 'Google Maps', 'blueline' )
		. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'blueline' ) . '</span></a>';

	return $html . '</p></div>';
}
