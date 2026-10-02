<?php
/**
 * Social share preview tags (Open Graph, Twitter Card) and schema.org
 * structured data (JSON-LD).
 *
 * This site's actual traffic pattern, per header.php's own comment above
 * blueline_render_announcement(): most arrivals are deep links shared
 * into a team chat, not homepage visits. That is exactly the surface these
 * two hooks control -- what a shared schedule/team/event link looks like
 * when it unfurls in Slack/iMessage/a group chat, and what a search engine
 * can extract about a game (SportsEvent rich results). Neither existed
 * before this file; no plugin on this install (Yoast/RankMath/AIOSEO) was
 * already filling either gap. Both step aside if one is activated
 * (blueline_seo_plugin_active()).
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether an SEO plugin (Yoast, Rank Math, SEOPress, AIOSEO) is active and
 * already prints its own Open Graph and JSON-LD, so the theme's step aside.
 *
 * @return bool
 */
function blueline_seo_plugin_active(): bool {
	$active = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' );

	/**
	 * Filters whether the theme defers its social meta and JSON-LD to an SEO plugin.
	 *
	 * @param bool $active Whether a known SEO plugin is active.
	 */
	return (bool) apply_filters( 'blueline_seo_plugin_active', $active );
}

add_action( 'wp_head', 'blueline_render_social_meta', 2 );
/**
 * Print Open Graph and Twitter Card meta tags for the current request.
 */
function blueline_render_social_meta() {
	if ( blueline_seo_plugin_active() ) {
		return;
	}

	$data = blueline_social_meta_data();

	printf( '<meta property="og:type" content="%s">' . "\n", esc_attr( $data['type'] ) );
	printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $data['title'] ) );
	printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $data['url'] ) );
	printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );

	if ( $data['description'] ) {
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $data['description'] ) );
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $data['description'] ) );
	}

	if ( $data['image'] ) {
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $data['image'] ) );
		printf( '<meta name="twitter:card" content="summary_large_image">' . "\n" );
	} else {
		printf( '<meta name="twitter:card" content="summary">' . "\n" );
	}

	printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $data['title'] ) );
	if ( $data['description'] ) {
		printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $data['description'] ) );
	}
}

/**
 * Resolve the site's custom logo attachment's full-size URL, or an empty
 * string when none is set -- the fallback image for any context (an
 * archive, a page with no featured image) that has nothing more specific
 * to offer.
 *
 * @return string
 */
function blueline_social_logo_url() {
	if ( ! has_custom_logo() ) {
		return '';
	}

	$logo_id = get_theme_mod( 'custom_logo' );
	$src     = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : false;

	return $src ? $src : '';
}

/**
 * Resolve title/description/image/url/type for the current request, in
 * one place so blueline_render_social_meta() (Open Graph/Twitter) and
 * blueline_render_structured_data() (JSON-LD) never compute two different
 * answers for the same page.
 *
 * @return array{title:string,description:string,image:string,url:string,type:string}
 */
function blueline_social_meta_data() {
	$logo = blueline_social_logo_url();

	// is_front_page() must be checked before the generic is_singular()
	// branch below: this site's front page is a "page" (show_on_front =
	// 'page', a real Page titled "Home" assigned as the front page), so
	// is_singular() is ALSO true there. Checked in the other order, the
	// homepage would render as og:type="article"/og:title="Home" (the
	// page's own title) instead of the site's own identity -- confirmed
	// live on staging before this ordering was corrected.
	if ( is_front_page() ) {
		return array(
			'title'       => get_bloginfo( 'name' ),
			'description' => wp_strip_all_tags( get_bloginfo( 'description' ) ),
			'image'       => $logo,
			'url'         => home_url( '/' ),
			'type'        => 'website',
		);
	}

	if ( is_singular( 'sp_event' ) && function_exists( 'sp_get_status' ) ) {
		return blueline_social_meta_data_for_event( get_the_ID(), $logo );
	}

	if ( is_singular() ) {
		$id          = get_the_ID();
		$description = get_the_excerpt( $id );
		$image       = has_post_thumbnail( $id ) ? get_the_post_thumbnail_url( $id, 'large' ) : $logo;

		return array(
			'title'       => get_the_title( $id ),
			'description' => $description ? wp_strip_all_tags( $description ) : '',
			'image'       => $image ? $image : '',
			'url'         => get_permalink( $id ),
			'type'        => 'article',
		);
	}

	// Archives, search, 404: the site's own identity as the fallback.
	// get_pagenum_link( 1 ) resolves the current archive/search query's
	// own base URL (query vars included) without a page-2+ suffix -- the
	// same function theme pagination already relies on for this.
	return array(
		'title'       => get_bloginfo( 'name' ),
		'description' => wp_strip_all_tags( get_bloginfo( 'description' ) ),
		'image'       => $logo,
		'url'         => get_pagenum_link( 1 ),
		'type'        => 'website',
	);
}

/**
 * The sp_event branch of blueline_social_meta_data(): "TeamA vs TeamB"
 * plus a date/venue summary, reusing the exact same team-id/venue
 * resolution blueline_sp_event_hero() already computes for the on-page
 * scoreboard, so the two never disagree about what a given event's own
 * teams/date/venue are.
 *
 * @param int    $event_id sp_event post ID.
 * @param string $logo     Site logo URL fallback.
 * @return array{title:string,description:string,image:string,url:string,type:string}
 */
function blueline_social_meta_data_for_event( $event_id, $logo ) {
	$teams  = blueline_sp_event_team_ids( $event_id );
	$team_a = $teams[0] ?? 0;
	$team_b = $teams[1] ?? 0;
	$name_a = $team_a ? blueline_sp_title( $team_a ) : __( 'TBD', 'blueline' );
	$name_b = $team_b ? blueline_sp_title( $team_b ) : __( 'TBD', 'blueline' );

	$venue_name = blueline_sp_event_venue_label( $event_id );

	$when = sprintf(
		/* translators: 1: event date, 2: event time. */
		__( '%1$s at %2$s', 'blueline' ),
		get_the_date( 'D, M j', $event_id ),
		get_the_time( get_option( 'time_format' ), $event_id )
	);

	$description = $venue_name
		? sprintf( '%1$s · %2$s', $when, $venue_name )
		: $when;

	$image = $logo;
	if ( $team_a && has_post_thumbnail( $team_a ) ) {
		$image = get_the_post_thumbnail_url( $team_a, 'large' );
	} elseif ( $team_b && has_post_thumbnail( $team_b ) ) {
		$image = get_the_post_thumbnail_url( $team_b, 'large' );
	}

	return array(
		/* translators: 1: first team name, 2: second team name. */
		'title'       => sprintf( __( '%1$s vs %2$s', 'blueline' ), $name_a, $name_b ),
		'description' => $description,
		'image'       => $image ? $image : '',
		'url'         => get_permalink( $event_id ),
		'type'        => 'website',
	);
}

add_action( 'wp_head', 'blueline_render_structured_data', 3 );
/**
 * Print schema.org JSON-LD: an Organization block on every page (so the
 * league itself is a known entity), and a SportsEvent block on single
 * sp_event pages -- the shape Google's sports-event rich-result feature
 * keys off, built entirely from data blueline_sp_event_hero() already
 * computes for the on-page scoreboard.
 */
function blueline_render_structured_data() {
	if ( blueline_seo_plugin_active() ) {
		return;
	}

	$graph = array( blueline_organization_schema() );

	if ( is_singular( 'sp_event' ) && function_exists( 'sp_get_status' ) ) {
		$event_schema = blueline_sports_event_schema( get_the_ID() );
		if ( $event_schema ) {
			$graph[] = $event_schema;
		}
	}

	printf(
		'<script type="application/ld+json">%s</script>' . "\n",
		wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			)
		)
	);
}

/**
 * The site's own Organization schema -- one block, present on every page,
 * so search engines have a stable identity to attach event/team data to.
 *
 * @return array
 */
function blueline_organization_schema() {
	$schema = array(
		'@type' => 'SportsOrganization',
		'@id'   => home_url( '/#organization' ),
		'name'  => get_bloginfo( 'name' ),
		'url'   => home_url( '/' ),
	);

	$logo = blueline_social_logo_url();
	if ( $logo ) {
		$schema['logo'] = $logo;
	}

	return $schema;
}

/**
 * SportsEvent schema for a single sp_event, or null if the event has no
 * resolvable start time (schema.org requires startDate; an event with an
 * unparseable date has nothing valid to publish).
 *
 * @param int $event_id sp_event post ID.
 * @return array|null
 */
function blueline_sports_event_schema( $event_id ) {
	$start_ts = blueline_sp_event_start_timestamp( $event_id );
	if ( ! $start_ts ) {
		return null;
	}

	$teams  = blueline_sp_event_team_ids( $event_id );
	$team_a = $teams[0] ?? 0;
	$team_b = $teams[1] ?? 0;

	$schema = array(
		'@type'       => 'SportsEvent',
		'name'        => sprintf(
			/* translators: 1: first team name, 2: second team name. */
			__( '%1$s vs %2$s', 'blueline' ),
			$team_a ? blueline_sp_title( $team_a ) : __( 'TBD', 'blueline' ),
			$team_b ? blueline_sp_title( $team_b ) : __( 'TBD', 'blueline' )
		),
		'startDate'   => gmdate( DATE_ATOM, $start_ts ),
		'url'         => get_permalink( $event_id ),
		'sport'       => 'Ice Hockey',
		// schema.org's EventScheduled means "taking place, or took place,
		// as scheduled" -- correct for both an upcoming preview and an
		// already-played final; this theme has no cancelled/postponed
		// state to report, so there is no other value it could be.
		'eventStatus' => 'https://schema.org/EventScheduled',
	);

	$venue_name = blueline_sp_event_venue_label( $event_id );
	if ( '' !== $venue_name ) {
		$schema['location'] = array(
			'@type' => 'Place',
			'name'  => $venue_name,
		);
	}

	$competitors = array();
	foreach ( array( $team_a, $team_b ) as $team_id ) {
		if ( ! $team_id ) {
			continue;
		}
		$team_schema = array(
			'@type' => 'SportsTeam',
			'name'  => blueline_sp_title( $team_id ),
		);
		if ( has_post_thumbnail( $team_id ) ) {
			$team_schema['logo'] = get_the_post_thumbnail_url( $team_id, 'large' );
		}
		$competitors[] = $team_schema;
	}
	if ( $competitors ) {
		$schema['competitor'] = $competitors;
	}

	return $schema;
}
