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
 * Moved from themes/blueline/inc/social-meta.php. SportsPress data comes from the theme's
 * blueline_sp_* helpers when the theme defines them, else from the plain fallbacks at the end
 * of this file, so the output is the same with any theme.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether an SEO plugin (Yoast, Rank Math, SEOPress, AIOSEO) is active and
 * already prints its own Open Graph and JSON-LD, so this module steps aside.
 *
 * Two filters can override the answer, applied in this order:
 *
 * 1. `blueline_core_seo_plugin_active` -- the plugin-prefixed name; use this one.
 * 2. `blueline_seo_plugin_active` -- DEPRECATED legacy name from when this code lived in the
 *    theme. Still applied (after the new one, so it wins when both are used) so existing
 *    theme or site code keeps working; new code should not use it.
 *
 * @return bool
 */
function blueline_seo_plugin_active(): bool {
	$active = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' );

	/**
	 * Filters whether this module defers its social meta and JSON-LD to an SEO plugin.
	 *
	 * Return true to print nothing (another plugin owns Open Graph/Twitter/JSON-LD), false to
	 * force this module's output on.
	 *
	 * @param bool $active Whether a known SEO plugin is active.
	 */
	$active = (bool) apply_filters( 'blueline_core_seo_plugin_active', $active );

	/**
	 * Deprecated: legacy name of `blueline_core_seo_plugin_active`, kept for backward
	 * compatibility with code written against the theme's version of this module.
	 *
	 * @param bool $active Whether a known SEO plugin is active.
	 */
	return (bool) apply_filters( 'blueline_seo_plugin_active', $active );
}

/**
 * Reduce a title/description fragment to plain text: tags stripped, HTML entities decoded,
 * whitespace collapsed.
 *
 * WordPress hands back titles in "display" form: wptexturize()/convert_chars() leave entities
 * (`&#8217;`, `&amp;`) in them and SportsPress prepends badge markup such as
 * `<strong class="sp-player-number">27</strong>`. Neither belongs in a share-preview tag or in
 * JSON, where nothing decodes them; esc_attr() and wp_json_encode() do the only escaping.
 *
 * @param mixed $text Text that may contain markup or entities.
 * @return string
 */
function blueline_core_seo_plain_text( $text ): string {
	$text = wp_strip_all_tags( (string) $text );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

	return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
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
	printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( blueline_core_seo_plain_text( get_bloginfo( 'name' ) ) ) );

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
			'title'       => blueline_core_seo_plain_text( get_bloginfo( 'name' ) ),
			'description' => blueline_core_seo_plain_text( get_bloginfo( 'description' ) ),
			'image'       => $logo,
			'url'         => home_url( '/' ),
			'type'        => 'website',
		);
	}

	if ( is_singular( 'sp_event' ) && function_exists( 'sp_get_status' ) ) {
		return blueline_social_meta_data_for_event( get_the_ID(), $logo );
	}

	if ( is_singular() ) {
		$id    = (int) get_the_ID();
		$image = blueline_core_real_thumbnail_url( $id );
		if ( '' === $image ) {
			$image = $logo;
		}

		return array(
			// Through the shared helper: player/staff titles carry SportsPress badge markup in get_the_title().
			'title'       => blueline_core_seo_title( $id ),
			'description' => blueline_core_seo_post_description( $id ),
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
		'title'       => blueline_core_seo_plain_text( get_bloginfo( 'name' ) ),
		'description' => blueline_core_seo_plain_text( get_bloginfo( 'description' ) ),
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
	$teams  = blueline_core_seo_event_team_ids( $event_id );
	$team_a = $teams[0] ?? 0;
	$team_b = $teams[1] ?? 0;
	$name_a = $team_a ? blueline_core_seo_title( $team_a ) : __( 'TBD', 'blueline-core' );
	$name_b = $team_b ? blueline_core_seo_title( $team_b ) : __( 'TBD', 'blueline-core' );

	$venue_name = blueline_core_seo_event_venue_label( $event_id );

	$when = sprintf(
		/* translators: 1: event date, 2: event time. */
		__( '%1$s at %2$s', 'blueline-core' ),
		get_the_date( 'D, M j', $event_id ),
		get_the_time( get_option( 'time_format' ), $event_id )
	);

	$description = $venue_name
		? sprintf( '%1$s · %2$s', $when, $venue_name )
		: $when;

	$image = $team_a ? blueline_core_real_thumbnail_url( $team_a ) : '';
	if ( '' === $image && $team_b ) {
		$image = blueline_core_real_thumbnail_url( $team_b );
	}
	if ( '' === $image ) {
		$image = $logo;
	}

	return array(
		/* translators: 1: first team name, 2: second team name. */
		'title'       => sprintf( __( '%1$s vs %2$s', 'blueline-core' ), $name_a, $name_b ),
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

	// HEX_TAG/HEX_AMP: a `<`, `>` or `&` in a title (e.g. `<!--<script>` once entities are decoded)
	// is emitted as a \u escape, so no value can open a comment or close the script block early.
	$json = wp_json_encode(
		array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		),
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);

	if ( ! is_string( $json ) || '' === $json ) {
		return;
	}

	printf( '<script type="application/ld+json">%s</script>' . "\n", $json ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built by wp_json_encode() with HEX_TAG/HEX_AMP.
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
		'name'  => blueline_core_seo_plain_text( get_bloginfo( 'name' ) ),
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
	$start_ts = blueline_core_seo_event_start_timestamp( $event_id );
	if ( ! $start_ts ) {
		return null;
	}

	$teams  = blueline_core_seo_event_team_ids( $event_id );
	$team_a = $teams[0] ?? 0;
	$team_b = $teams[1] ?? 0;

	$schema = array(
		'@type'       => 'SportsEvent',
		'name'        => sprintf(
			/* translators: 1: first team name, 2: second team name. */
			__( '%1$s vs %2$s', 'blueline-core' ),
			$team_a ? blueline_core_seo_title( $team_a ) : __( 'TBD', 'blueline-core' ),
			$team_b ? blueline_core_seo_title( $team_b ) : __( 'TBD', 'blueline-core' )
		),
		'startDate'   => gmdate( DATE_ATOM, $start_ts ),
		'url'         => get_permalink( $event_id ),
		'sport'       => 'Ice Hockey',
		'eventStatus' => blueline_core_seo_event_status_url( $event_id ),
	);

	$venue_name = blueline_core_seo_event_venue_label( $event_id );
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
			'name'  => blueline_core_seo_title( $team_id ),
		);
		$team_logo   = blueline_core_real_thumbnail_url( $team_id );
		if ( $team_logo ) {
			$team_schema['logo'] = $team_logo;
		}
		$competitors[] = $team_schema;
	}
	if ( $competitors ) {
		$schema['competitor'] = $competitors;
	}

	return $schema;
}

/**
 * The schema.org eventStatus URL for an sp_event, from SportsPress's `sp_status` post meta
 * ('ok', 'tbd', 'postponed', 'cancelled').
 *
 * EventScheduled means "taking place, or took place, as scheduled", so it is right for an
 * upcoming preview, a played final and a not-yet-timed ('tbd') game alike; only a postponed or
 * cancelled game needs its own value. Read straight from the meta (no theme function) so the
 * plugin stays independent of the theme.
 *
 * @param int $event_id sp_event post ID.
 * @return string
 */
function blueline_core_seo_event_status_url( int $event_id ): string {
	$status = strtolower( trim( (string) get_post_meta( $event_id, 'sp_status', true ) ) );

	if ( 'postponed' === $status ) {
		return 'https://schema.org/EventPostponed';
	}

	if ( 'cancelled' === $status ) {
		return 'https://schema.org/EventCancelled';
	}

	return 'https://schema.org/EventScheduled';
}

/**
 * Meta description for a singular post, without running the `the_content` filter chain.
 *
 * Core's get_the_excerpt() renders the whole body through `the_content` (blocks, every plugin's
 * callbacks) just to trim it, and this runs in wp_head before the page body renders it again.
 * So: the manual excerpt when there is one, else the first words of the raw content with
 * shortcodes and tags stripped. A password-protected post yields no description (the excerpt
 * would leak its body).
 *
 * @param int $post_id Post ID.
 * @return string Plain text, '' when there is nothing to use.
 */
function blueline_core_seo_post_description( int $post_id ): string {
	$post = get_post( $post_id );

	if ( ! $post || post_password_required( $post ) ) {
		return '';
	}

	$excerpt = blueline_core_seo_plain_text( $post->post_excerpt );
	if ( '' !== $excerpt ) {
		return $excerpt;
	}

	$content = blueline_core_seo_plain_text( strip_shortcodes( (string) $post->post_content ) );

	return wp_trim_words( $content, 30, '…' );
}

/**
 * Team ids on an sp_event's `sp_team` meta: the theme's helper when present, else the same read.
 *
 * @param int $event_id sp_event post ID.
 * @return int[]
 */
function blueline_core_seo_event_team_ids( int $event_id ): array {
	if ( function_exists( 'blueline_sp_event_team_ids' ) ) {
		return blueline_sp_event_team_ids( $event_id );
	}

	return blueline_core_seo_event_team_ids_fallback( $event_id );
}

/**
 * Direct `sp_team` meta read: positive ids only, re-indexed from 0.
 *
 * @param int $event_id sp_event post ID.
 * @return int[]
 */
function blueline_core_seo_event_team_ids_fallback( int $event_id ): array {
	return array_values( array_filter( array_map( 'absint', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) );
}

/**
 * URL of a post's REAL uploaded featured image, or '' when it has none.
 *
 * Not has_post_thumbnail(): the theme reports a placeholder (negative) thumbnail id for a logo-less
 * team so templates can draw a default badge, but that SVG is useless as an og:image / schema logo.
 *
 * @param int    $post_id Post ID.
 * @param string $size    Image size.
 * @return string
 */
function blueline_core_real_thumbnail_url( int $post_id, string $size = 'large' ): string {
	$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
	if ( $thumbnail_id <= 0 ) {
		return '';
	}

	// The attachment itself, not get_the_post_thumbnail_url(): the theme's default-logo filter answers that one.
	return (string) wp_get_attachment_image_url( $thumbnail_id, $size );
}

/**
 * Plain-text title for a post: the theme's helper when present, else the fallback, then
 * reduced to plain text (no markup, entities decoded) -- the single place every Open Graph,
 * Twitter and JSON-LD title goes through.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function blueline_core_seo_title( $post_id ): string {
	$title = function_exists( 'blueline_sp_title' )
		? (string) blueline_sp_title( $post_id )
		: blueline_core_seo_title_fallback( $post_id );

	return blueline_core_seo_plain_text( $title );
}

/**
 * Post title without SportsPress's player/staff badge markup (raw title for those two types).
 * Still display-form text; blueline_core_seo_title() makes it plain.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function blueline_core_seo_title_fallback( $post_id ): string {
	if ( in_array( get_post_type( $post_id ), array( 'sp_player', 'sp_staff' ), true ) ) {
		return (string) get_post_field( 'post_title', $post_id, 'raw' );
	}

	return (string) get_the_title( $post_id );
}

/**
 * Plain-text venue label for an sp_event: the theme's helper when present, else the fallback.
 *
 * @param int $event_id sp_event post ID.
 * @return string
 */
function blueline_core_seo_event_venue_label( int $event_id ): string {
	$label = function_exists( 'blueline_sp_event_venue_label' )
		? blueline_sp_event_venue_label( $event_id )
		: blueline_core_seo_event_venue_label_fallback( $event_id );

	// Term names are stored entity-encoded (`Arena &amp; Pad`); the output wants plain text.
	return blueline_core_seo_plain_text( $label );
}

/**
 * First `sp_venue` term name (the theme's arena/pad label is theme-only), or '' with no venue.
 *
 * @param int $event_id sp_event post ID.
 * @return string
 */
function blueline_core_seo_event_venue_label_fallback( int $event_id ): string {
	if ( ! taxonomy_exists( 'sp_venue' ) ) {
		return '';
	}

	$venue_terms = wp_get_post_terms( $event_id, 'sp_venue' );

	if ( is_wp_error( $venue_terms ) || empty( $venue_terms ) ) {
		return '';
	}

	return (string) $venue_terms[0]->name;
}

/**
 * GMT unix start time of an sp_event: the theme's helper when present, else the fallback.
 *
 * @param int $event_id sp_event post ID.
 * @return int|false
 */
function blueline_core_seo_event_start_timestamp( $event_id ) {
	if ( function_exists( 'blueline_sp_event_start_timestamp' ) ) {
		return blueline_sp_event_start_timestamp( $event_id );
	}

	return blueline_core_seo_event_start_timestamp_fallback( $event_id );
}

/**
 * GMT unix timestamp from the event's local post date, or false when unknown.
 *
 * @param int $event_id sp_event post ID.
 * @return int|false
 */
function blueline_core_seo_event_start_timestamp_fallback( $event_id ) {
	$local = get_the_time( 'Y-m-d H:i:s', $event_id );

	if ( ! $local ) {
		return false;
	}

	$gmt = get_gmt_from_date( $local );

	return $gmt ? strtotime( $gmt . ' +0000' ) : false;
}
