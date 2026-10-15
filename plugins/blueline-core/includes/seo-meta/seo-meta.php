<?php
/**
 * Social share preview tags (Open Graph, Twitter Card), meta descriptions, document titles and
 * schema.org structured data (JSON-LD).
 *
 * Most arrivals on this site are deep links shared into a team chat, so these hooks control
 * what a shared schedule/team/event link looks like when it unfurls, and what a search engine can
 * extract about a game (SportsEvent) or a team (SportsTeam). All of it steps aside when an SEO
 * plugin is active (blueline_seo_plugin_active()).
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
 * 2. `blueline_seo_plugin_active` -- DEPRECATED legacy name. Still applied (after the new one,
 *    so it wins when both are used) so existing site code keeps working; new code should not use it.
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
	 * Deprecated: legacy name of `blueline_core_seo_plugin_active`.
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

/**
 * Plain text capped at $limit characters, cut at a word boundary with an ellipsis.
 *
 * Shortcode tokens that survive strip_shortcodes() (a shortcode from a deactivated plugin is no
 * longer registered, so core leaves `[wc_frontend_manager]` in the text) are removed first: a
 * snippet must never show one. The result, ellipsis included, is never longer than $limit.
 *
 * @param string $text  Text that may contain markup, entities or leftover shortcode tokens.
 * @param int    $limit Maximum length in characters.
 * @return string
 */
function blueline_core_seo_limit_text( string $text, int $limit = 155 ): string {
	$text = blueline_core_seo_plain_text( $text );
	$text = blueline_core_seo_plain_text( preg_replace( '/\[[^\]]*\]/', ' ', $text ) );

	if ( mb_strlen( $text ) <= $limit ) {
		return $text;
	}

	$cut = mb_substr( $text, 0, $limit - 1 );

	// Back up to the last space unless the cut already landed on a word edge, but never discard
	// more than half of a snippet because of one very long word.
	if ( ' ' !== mb_substr( $text, $limit - 1, 1 ) ) {
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > $limit / 2 ) {
			$cut = mb_substr( $cut, 0, $space );
		}
	}

	return rtrim( $cut, " \t,;:.-–—(" ) . '…';
}

/**
 * The site name as plain text.
 *
 * @return string
 */
function blueline_core_seo_site_name(): string {
	return blueline_core_seo_plain_text( get_bloginfo( 'name' ) );
}

/**
 * The short form of the site name used in titles and descriptions: the text before the first
 * dash of the full name ("ARL – Adult Recreational League" becomes "ARL"), or the whole name.
 *
 * @return string
 */
function blueline_core_seo_short_site_name(): string {
	$name  = blueline_core_seo_site_name();
	$short = blueline_core_seo_short_name( $name );

	/**
	 * Filters the short site name used in event titles and venue descriptions.
	 *
	 * @param string $short Short name.
	 * @param string $name  Full site name.
	 */
	return (string) apply_filters( 'blueline_core_seo_short_site_name', $short, $name );
}

/**
 * The text of a name before its first dash, or the whole name when it has none.
 *
 * @param string $name Full name, e.g. "ARL – Adult Recreational League".
 * @return string
 */
function blueline_core_seo_short_name( string $name ): string {
	$parts = preg_split( '/\s+[–—-]\s+/u', $name, 2 );
	$short = trim( (string) ( $parts[0] ?? '' ) );

	return '' !== $short ? $short : $name;
}

/**
 * The league's name as it reads in a description.
 *
 * @return string
 */
function blueline_core_seo_league_name(): string {
	return __( 'Adult Recreational League', 'blueline-core' );
}

add_action( 'wp_head', 'blueline_render_archive_canonical', 1 );
/**
 * Print a self-referencing rel=canonical on archives and paginated lists.
 *
 * Core prints one only on singular pages, so `/news?utm_source=x` or a sorted shop listing could be
 * indexed as a duplicate of the plain archive.
 */
function blueline_render_archive_canonical() {
	if ( blueline_seo_plugin_active() ) {
		return;
	}

	$url = blueline_core_seo_archive_canonical_url();

	if ( '' !== $url ) {
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
	}
}

/**
 * The canonical URL of the current archive page, or '' where this module should not print one
 * (singular pages, whose canonical core prints; search results; 404s; anything that is not a list).
 *
 * The page number is kept, so page 2 is its own canonical; every other query argument is dropped
 * when the site uses pretty permalinks (with plain permalinks the query string IS the address).
 *
 * @return string
 */
function blueline_core_seo_archive_canonical_url(): string {
	if ( is_singular() || is_search() || is_404() || ! ( is_home() || is_archive() || is_tax() ) ) {
		return '';
	}

	$url = (string) get_pagenum_link( max( 1, (int) get_query_var( 'paged' ) ) );

	if ( '' !== (string) get_option( 'permalink_structure' ) ) {
		$query = strpos( $url, '?' );
		$url   = false === $query ? $url : substr( $url, 0, $query );
	}

	return $url;
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
	printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( blueline_core_seo_site_name() ) );

	if ( $data['description'] ) {
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $data['description'] ) );
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $data['description'] ) );
	}

	if ( $data['image'] ) {
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $data['image'] ) );
		if ( $data['image_width'] > 0 && $data['image_height'] > 0 ) {
			printf( '<meta property="og:image:width" content="%d">' . "\n", (int) $data['image_width'] );
			printf( '<meta property="og:image:height" content="%d">' . "\n", (int) $data['image_height'] );
		}
		if ( '' !== $data['image_alt'] ) {
			printf( '<meta property="og:image:alt" content="%s">' . "\n", esc_attr( $data['image_alt'] ) );
		}
	}

	printf( '<meta name="twitter:card" content="%s">' . "\n", esc_attr( $data['card'] ) );
	printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $data['title'] ) );
	if ( $data['description'] ) {
		printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $data['description'] ) );
	}
}

/**
 * The site's custom logo attachment's full-size URL, or '' when none is set: the fallback image
 * for any context (an archive, a page with no featured image) with nothing more specific to offer.
 *
 * @return string
 */
function blueline_social_logo_url() {
	$logo_id = (int) get_theme_mod( 'custom_logo' );

	// The id check is not redundant: wp_get_attachment_image_url( 0 ) falls back to the global
	// post, which on an attachment page would be that attachment rather than "no logo".
	return $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '';
}

/**
 * Url, pixel size and alt text of an image attachment, or an empty array when it is not an image.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size          Image size.
 * @return array{url:string,width:int,height:int,alt:string}|array{}
 */
function blueline_core_seo_attachment_image( int $attachment_id, string $size = 'large' ): array {
	if ( $attachment_id <= 0 ) {
		return array();
	}

	$url = wp_get_attachment_image_url( $attachment_id, $size );

	if ( ! $url ) {
		return array();
	}

	// The size's own dimensions when it was generated, else the original's (core serves the
	// original when no smaller size exists, so the two always agree with the URL).
	$metadata = wp_get_attachment_metadata( $attachment_id );
	$metadata = is_array( $metadata ) ? $metadata : array();
	$sized    = $metadata['sizes'][ $size ] ?? $metadata;

	return array(
		'url'    => (string) $url,
		'width'  => (int) ( $sized['width'] ?? 0 ),
		'height' => (int) ( $sized['height'] ?? 0 ),
		'alt'    => blueline_core_seo_plain_text( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
	);
}

/**
 * The attachment id of the site's default share image (a purpose-made landscape image used when a
 * page has no usable image of its own), or 0 for none.
 *
 * Set the `blueline_core_default_share_image` option to an attachment id, or return one from the
 * filter of the same name.
 *
 * @return int
 */
function blueline_core_seo_default_share_image_id(): int {
	/**
	 * Filters the attachment id of the default share image.
	 *
	 * @param int $attachment_id Attachment id from the `blueline_core_default_share_image` option, 0 for none.
	 */
	return absint( apply_filters( 'blueline_core_default_share_image', (int) get_option( 'blueline_core_default_share_image', 0 ) ) );
}

/**
 * Choose the share image: the first of $featured_ids that is not a banner, else the default share
 * image, else the site logo.
 *
 * A featured image wider than 3:1 (a page-header banner) is ignored: every unfurl crops it to an
 * unrecognisable sliver. The card type follows the image: `summary_large_image` only for a real
 * landscape image, `summary` for the logo fallback and for square or portrait images, which the
 * large card would crop.
 *
 * @param int[] $featured_ids Attachment ids to prefer, in order (0 entries are skipped).
 * @return array{image:string,image_width:int,image_height:int,image_alt:string,card:string}
 */
function blueline_core_seo_image_choice( array $featured_ids = array() ): array {
	$candidates = array();
	foreach ( $featured_ids as $attachment_id ) {
		$candidates[] = array( (int) $attachment_id, false );
	}
	$candidates[] = array( blueline_core_seo_default_share_image_id(), false );
	$candidates[] = array( (int) get_theme_mod( 'custom_logo' ), true );

	foreach ( $candidates as list( $attachment_id, $is_logo ) ) {
		$image = blueline_core_seo_attachment_image( $attachment_id, $is_logo ? 'full' : 'large' );

		if ( ! $image ) {
			continue;
		}

		$ratio = $image['height'] > 0 ? $image['width'] / $image['height'] : 0;
		if ( $ratio > 3 ) {
			continue;
		}

		return array(
			'image'        => $image['url'],
			'image_width'  => $image['width'],
			'image_height' => $image['height'],
			'image_alt'    => $image['alt'],
			'card'         => ( ! $is_logo && $image['width'] > $image['height'] ) ? 'summary_large_image' : 'summary',
		);
	}

	return array(
		'image'        => '',
		'image_width'  => 0,
		'image_height' => 0,
		'image_alt'    => '',
		'card'         => 'summary',
	);
}

/**
 * The site's own identity as a "website" meta record, for the front page, archives, search and 404.
 *
 * @param string $url         Canonical URL of the current request.
 * @param string $description Description to use ('' for none).
 * @return array{title:string,description:string,image:string,image_width:int,image_height:int,image_alt:string,card:string,url:string,type:string}
 */
function blueline_core_seo_site_identity( string $url, string $description ): array {
	return array_merge(
		array(
			'title'       => blueline_core_seo_site_name(),
			'description' => $description,
			'url'         => $url,
			'type'        => 'website',
		),
		blueline_core_seo_image_choice()
	);
}

/**
 * Meta description for the current non-singular request: the venue's address line, a term's own
 * description, or a dedicated line for the news index; '' for everything else.
 *
 * The site tagline is deliberately NOT a fallback here: it describes the home page, and repeating
 * it on every archive, search result and 404 gives them all the same snippet.
 *
 * @return string
 */
function blueline_core_seo_archive_description(): string {
	if ( is_search() || is_404() ) {
		return '';
	}

	if ( is_home() ) {
		return sprintf(
			/* translators: %s: league name. */
			__( 'News, announcements and updates from the %s.', 'blueline-core' ),
			blueline_core_seo_league_name()
		);
	}

	// A term archive's queried object is the term; a post-type or author archive has no term_id.
	$term = is_archive() ? get_queried_object() : null;

	if ( ! is_object( $term ) || empty( $term->term_id ) ) {
		return '';
	}

	if ( is_tax( 'sp_venue' ) ) {
		$address = blueline_core_seo_venue_address( (int) $term->term_id );

		if ( '' !== $address ) {
			return blueline_core_seo_limit_text(
				sprintf(
					/* translators: 1: venue name, 2: street address, 3: short site name. */
					__( '%1$s: %2$s. %3$s games at this rink.', 'blueline-core' ),
					blueline_core_seo_venue_term_label( $term ),
					rtrim( $address, '. ' ),
					blueline_core_seo_short_site_name()
				)
			);
		}
	}

	return blueline_core_seo_limit_text( (string) ( $term->description ?? '' ) );
}

/**
 * Resolve title/description/image/url/type for the current request, in
 * one place so blueline_render_social_meta() (Open Graph/Twitter) and
 * blueline_render_structured_data() (JSON-LD) never compute two different
 * answers for the same page.
 *
 * @return array{title:string,description:string,image:string,image_width:int,image_height:int,image_alt:string,card:string,url:string,type:string}
 */
function blueline_social_meta_data() {
	// Front page first: it is a static Page, so is_singular() is also true there and would
	// otherwise report og:type "article" titled with the page's own title ("Home").
	if ( is_front_page() ) {
		return blueline_core_seo_site_identity( home_url( '/' ), blueline_core_seo_plain_text( get_bloginfo( 'description' ) ) );
	}

	if ( is_singular( 'sp_event' ) && function_exists( 'sp_get_status' ) ) {
		return blueline_social_meta_data_for_event( get_the_ID() );
	}

	if ( is_singular() ) {
		$id        = (int) get_the_ID();
		$post_type = (string) get_post_type( $id );

		if ( 'post' === $post_type ) {
			$type = 'article';
		} elseif ( 'sp_player' === $post_type ) {
			$type = 'profile';
		} else {
			$type = 'website';
		}

		return array_merge(
			array(
				// Through the shared helper: player/staff titles carry SportsPress badge markup in get_the_title().
				'title'       => blueline_core_seo_title( $id ),
				'description' => blueline_core_seo_singular_description( $id, $post_type ),
				'url'         => get_permalink( $id ),
				'type'        => $type,
			),
			blueline_core_seo_image_choice( array( blueline_core_real_thumbnail_id( $id ) ) )
		);
	}

	// An archive page is named by the address its canonical prints, page number included. Search
	// results have no canonical, so they keep get_pagenum_link( 1 ): the query's own URL without a page-2+ suffix.
	$archive_url = blueline_core_seo_archive_canonical_url();

	return blueline_core_seo_site_identity( '' !== $archive_url ? $archive_url : get_pagenum_link( 1 ), blueline_core_seo_archive_description() );
}

/**
 * "TeamA vs TeamB" for an event's team ids; a missing team reads "TBD".
 *
 * @param int[] $team_ids Team post IDs, home/away order.
 * @return string
 */
function blueline_core_seo_versus_title( array $team_ids ): string {
	$name = static fn( int $team_id ): string => $team_id ? blueline_core_seo_title( $team_id ) : __( 'TBD', 'blueline-core' );

	return blueline_core_seo_versus_text( $name( $team_ids[0] ?? 0 ), $name( $team_ids[1] ?? 0 ) );
}

/**
 * "A vs B" for two already-resolved names.
 *
 * @param string $home Home team name.
 * @param string $away Away team name.
 * @return string
 */
function blueline_core_seo_versus_text( string $home, string $away ): string {
	return sprintf(
		/* translators: 1: first team name, 2: second team name. */
		__( '%1$s vs %2$s', 'blueline-core' ),
		$home,
		$away
	);
}

/**
 * The page title of an sp_event: "Whalers vs Kraken — Oct 16, 2026", in at most the length that
 * leaves room for the site name within a 60-character title.
 *
 * Every game's title carries its date, so rematches no longer share one. A sponsor in a team
 * name's parentheses ("Tsunami (Wave Hockey)") is dropped here only (the JSON-LD keeps the full
 * name), and if the title is still too long the longer team name is shortened.
 *
 * @param int $event_id sp_event post ID.
 * @return string
 */
function blueline_core_seo_event_title( int $event_id ): string {
	$team_ids = blueline_core_seo_event_team_ids( $event_id );
	$names    = array();

	foreach ( array( 0, 1 ) as $index ) {
		$team_id = $team_ids[ $index ] ?? 0;
		$full    = $team_id ? blueline_core_seo_title( $team_id ) : __( 'TBD', 'blueline-core' );
		$short   = trim( (string) preg_replace( '/\s*\([^)]*\)/u', '', $full ) );

		$names[ $index ] = '' !== $short ? $short : $full;
	}

	$start_ts = blueline_core_seo_event_start_timestamp( $event_id );
	$date     = $start_ts ? wp_date( 'M j, Y', $start_ts ) : '';
	$budget   = 60 - mb_strlen( ' | ' . blueline_core_seo_short_site_name() );
	$keep     = array_map( 'mb_strlen', $names );

	do {
		$shown = array();
		foreach ( $names as $index => $name ) {
			$shown[ $index ] = $keep[ $index ] < mb_strlen( $name ) ? rtrim( mb_substr( $name, 0, $keep[ $index ] ) ) . '…' : $name;
		}

		$title = blueline_core_seo_versus_text( $shown[0], $shown[1] );
		if ( '' !== $date ) {
			$title = sprintf(
				/* translators: 1: "Team vs Team", 2: game date. */
				__( '%1$s — %2$s', 'blueline-core' ),
				$title,
				$date
			);
		}

		$longer = $keep[0] >= $keep[1] ? 0 : 1;
		if ( mb_strlen( $title ) <= $budget || $keep[ $longer ] <= 4 ) {
			break;
		}
		--$keep[ $longer ];
	} while ( true );

	return $title;
}

/**
 * The sp_event branch of blueline_social_meta_data(): the dated "TeamA vs TeamB" title plus a
 * date/venue summary.
 *
 * @param int $event_id sp_event post ID.
 * @return array{title:string,description:string,image:string,image_width:int,image_height:int,image_alt:string,card:string,url:string,type:string}
 */
function blueline_social_meta_data_for_event( $event_id ) {
	$teams    = blueline_core_seo_event_team_ids( $event_id );
	$start_ts = blueline_core_seo_event_start_timestamp( $event_id );

	$description = '';
	if ( $start_ts ) {
		$time_format = (string) get_option( 'time_format' );
		$description = sprintf(
			/* translators: 1: event date, 2: event time. */
			__( '%1$s at %2$s', 'blueline-core' ),
			wp_date( 'D, M j', $start_ts ),
			wp_date( '' !== $time_format ? $time_format : 'g:i A', $start_ts )
		);
	}

	$venue_name = blueline_core_seo_event_venue_label( $event_id );
	if ( '' !== $venue_name ) {
		$description = '' !== $description ? sprintf( '%1$s · %2$s', $description, $venue_name ) : $venue_name;
	}

	// The first team with a real logo, else the default share image or the site logo.
	$logo_ids = array_map( 'blueline_core_real_thumbnail_id', array_slice( $teams, 0, 2 ) );

	return array_merge(
		array(
			'title'       => blueline_core_seo_event_title( (int) $event_id ),
			'description' => $description,
			'url'         => get_permalink( $event_id ),
			'type'        => 'website',
		),
		blueline_core_seo_image_choice( $logo_ids )
	);
}

add_filter( 'document_title_parts', 'blueline_core_seo_document_title_parts' );
/**
 * The browser/search `<title>` parts: a game's page is "Home vs Away — Oct 16, 2026 | ARL" (unique
 * per game and within 60 characters), and the home page drops its tagline when the full title would
 * run past 60.
 *
 * @param array<string,string> $parts Title parts from wp_get_document_title().
 * @return array<string,string>
 */
function blueline_core_seo_document_title_parts( $parts ) {
	if ( ! is_array( $parts ) || blueline_seo_plugin_active() ) {
		return $parts;
	}

	if ( is_singular( 'sp_event' ) && function_exists( 'sp_get_status' ) ) {
		$parts['title'] = blueline_core_seo_event_title( (int) get_the_ID() );
		$parts['site']  = blueline_core_seo_short_site_name();
		unset( $parts['page'], $parts['tagline'] );

		return $parts;
	}

	if ( is_front_page() && ! empty( $parts['tagline'] ) && mb_strlen( ( $parts['title'] ?? '' ) . ' - ' . $parts['tagline'] ) > 60 ) {
		unset( $parts['tagline'] );
	}

	return $parts;
}

add_filter( 'document_title_separator', 'blueline_core_seo_document_title_separator' );
/**
 * A game's title reads "Home vs Away — Oct 16, 2026 | ARL": a pipe before the site name, since
 * the title itself already carries a dash. Every other page keeps the theme's separator.
 *
 * @param mixed $separator Current separator.
 * @return mixed
 */
function blueline_core_seo_document_title_separator( $separator ) {
	if ( is_singular( 'sp_event' ) && function_exists( 'sp_get_status' ) && ! blueline_seo_plugin_active() ) {
		return '|';
	}

	return $separator;
}

add_action( 'wp_head', 'blueline_render_structured_data', 3 );
/**
 * Print schema.org JSON-LD: an Organization block on every page (so the league itself is a known
 * entity), a SportsEvent block on single sp_event pages (the shape Google's sports-event
 * rich-result feature keys off), a SportsTeam block on single sp_team pages and a BreadcrumbList
 * on team, event and venue pages.
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

	if ( is_singular( 'sp_team' ) ) {
		$graph[] = blueline_core_seo_team_page_schema( (int) get_the_ID() );
	}

	$breadcrumbs = blueline_core_seo_breadcrumb_schema();
	if ( $breadcrumbs ) {
		$graph[] = $breadcrumbs;
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

	// A data block, not code, so it needs no enqueue timing; the core helper lets a CSP plugin tag it.
	wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
}

/**
 * The site's own Organization schema: one block on every page, a stable identity for search
 * engines to attach event/team data to.
 *
 * @return array
 */
function blueline_organization_schema() {
	$schema = array(
		'@type' => 'SportsOrganization',
		'@id'   => home_url( '/#organization' ),
		'name'  => blueline_core_seo_site_name(),
		'url'   => home_url( '/' ),
	);

	$logo = blueline_social_logo_url();
	if ( $logo ) {
		$schema['logo'] = $logo;
	}

	return $schema;
}

/**
 * SportsTeam schema node for one team: name, page URL, plus its real (non-placeholder) logo when it has one.
 *
 * @param int $team_id sp_team post ID.
 * @return array
 */
function blueline_core_seo_team_schema( int $team_id ): array {
	$schema = array(
		'@type' => 'SportsTeam',
		'name'  => blueline_core_seo_title( $team_id ),
	);

	$url = get_permalink( $team_id );
	if ( $url ) {
		$schema['url'] = $url;
	}

	$logo = blueline_core_real_thumbnail_url( $team_id );
	if ( $logo ) {
		$schema['logo'] = $logo;
	}

	return $schema;
}

/**
 * The SportsTeam node for a team's own page: the team node plus its sport and the league it plays in.
 *
 * @param int $team_id sp_team post ID.
 * @return array
 */
function blueline_core_seo_team_page_schema( int $team_id ): array {
	return array_merge(
		blueline_core_seo_team_schema( $team_id ),
		array(
			'sport'    => 'Ice Hockey',
			'memberOf' => array( '@id' => home_url( '/#organization' ) ),
		)
	);
}

/**
 * BreadcrumbList schema for a team page, an event page or a venue page; null elsewhere.
 *
 * The trail is Home, then the post type's archive when it has one, then the page itself.
 *
 * @return array|null
 */
function blueline_core_seo_breadcrumb_schema(): ?array {
	$trail = array(
		array( __( 'Home', 'blueline-core' ), home_url( '/' ) ),
	);

	if ( is_singular( array( 'sp_team', 'sp_event' ) ) ) {
		$id        = (int) get_the_ID();
		$post_type = is_singular( 'sp_event' ) ? 'sp_event' : 'sp_team';
		$archive   = get_post_type_archive_link( $post_type );

		if ( $archive ) {
			$post_type_object = get_post_type_object( $post_type );
			$label            = is_object( $post_type_object ) && ! empty( $post_type_object->labels->name ) ? (string) $post_type_object->labels->name : '';

			if ( '' !== $label ) {
				$trail[] = array( blueline_core_seo_plain_text( $label ), $archive );
			}
		}

		$trail[] = array(
			'sp_event' === $post_type ? blueline_core_seo_event_title( $id ) : blueline_core_seo_title( $id ),
			(string) get_permalink( $id ),
		);
	} elseif ( is_tax( 'sp_venue' ) && is_object( get_queried_object() ) ) {
		$term    = get_queried_object();
		$link    = get_term_link( $term );
		$trail[] = array( blueline_core_seo_venue_term_label( $term ), is_string( $link ) ? $link : '' );
	} else {
		return null;
	}

	$items = array();
	foreach ( $trail as $position => list( $name, $url ) ) {
		if ( '' === $name ) {
			continue;
		}

		$item = array(
			'@type'    => 'ListItem',
			'position' => $position + 1,
			'name'     => $name,
		);
		if ( '' !== $url ) {
			$item['item'] = $url;
		}
		$items[] = $item;
	}

	if ( count( $items ) < 2 ) {
		return null;
	}

	return array(
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	);
}

/**
 * SportsEvent schema for a single sp_event, or null if the event has no resolvable start time
 * (schema.org requires startDate).
 *
 * @param int $event_id sp_event post ID.
 * @return array|null
 */
function blueline_sports_event_schema( $event_id ) {
	$start_ts = blueline_core_seo_event_start_timestamp( $event_id );
	if ( ! $start_ts ) {
		return null;
	}

	$teams = blueline_core_seo_event_team_ids( $event_id );

	$schema = array(
		'@type'       => 'SportsEvent',
		'name'        => blueline_core_seo_versus_title( $teams ),
		'startDate'   => gmdate( DATE_ATOM, $start_ts ),
		'url'         => get_permalink( $event_id ),
		'sport'       => 'Ice Hockey',
		'eventStatus' => blueline_core_seo_event_status_url( $event_id ),
	);

	$location = blueline_core_seo_event_location( $event_id );
	if ( $location ) {
		$schema['location'] = $location;
	}

	// SportsPress stores an event's teams in home/away order.
	if ( ! empty( $teams[0] ) ) {
		$schema['homeTeam'] = blueline_core_seo_team_schema( $teams[0] );
	}
	if ( ! empty( $teams[1] ) ) {
		$schema['awayTeam'] = blueline_core_seo_team_schema( $teams[1] );
	}

	$competitors = array_map( 'blueline_core_seo_team_schema', array_slice( $teams, 0, 2 ) );
	if ( $competitors ) {
		$schema['competitor'] = $competitors;
	}

	return $schema;
}

/**
 * The schema.org Place for an sp_event's venue: the arena/pad label, a PostalAddress from the venue
 * term's `sp_address`, and coordinates when the term stores them. Empty when the event has no venue.
 *
 * Google's event rich result requires `location.address`.
 *
 * @param int $event_id sp_event post ID.
 * @return array
 */
function blueline_core_seo_event_location( int $event_id ): array {
	$name = blueline_core_seo_event_venue_label( $event_id );

	if ( '' === $name ) {
		return array();
	}

	$place = array(
		'@type' => 'Place',
		'name'  => $name,
	);

	$term = blueline_core_seo_event_venue_term( $event_id );
	if ( ! $term ) {
		return $place;
	}

	$address = blueline_core_seo_postal_address( blueline_core_seo_venue_address( (int) $term->term_id ) );
	if ( $address ) {
		$place['address'] = $address;
	}

	$venue_meta = get_option( 'taxonomy_' . (int) $term->term_id );
	$latitude   = is_array( $venue_meta ) ? ( $venue_meta['sp_latitude'] ?? '' ) : '';
	$longitude  = is_array( $venue_meta ) ? ( $venue_meta['sp_longitude'] ?? '' ) : '';
	if ( is_numeric( $latitude ) && is_numeric( $longitude ) ) {
		$place['geo'] = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $latitude,
			'longitude' => (float) $longitude,
		);
	}

	return $place;
}

/**
 * A schema.org PostalAddress from a one-line address as SportsPress venues store it
 * ("1179 Northside Rd, Burlington, ON L7M, Canada").
 *
 * Street, city, province/state with postal code and country are told apart by position; a line
 * that does not have at least a street, city and region part becomes the street address as it is,
 * rather than a guess.
 *
 * @param string $address One-line address, already plain text.
 * @return array Empty when there is no address.
 */
function blueline_core_seo_postal_address( string $address ): array {
	$address = trim( $address );

	if ( '' === $address ) {
		return array();
	}

	$schema = array( '@type' => 'PostalAddress' );
	$parts  = array_values( array_filter( array_map( 'trim', explode( ',', $address ) ), 'strlen' ) );

	if ( count( $parts ) < 3 ) {
		$schema['streetAddress'] = $address;

		return $schema;
	}

	// A trailing part that is not "ON" / "ON L7M 1H5" is the country.
	$region_pattern = '/^([A-Za-z]{2})(?:\s+(.+))?$/';
	$country        = '';
	if ( count( $parts ) >= 4 || ! preg_match( $region_pattern, end( $parts ) ) ) {
		$country = (string) array_pop( $parts );
	}

	$schema['streetAddress']   = $parts[0];
	$schema['addressLocality'] = $parts[1];

	if ( isset( $parts[2] ) && preg_match( $region_pattern, $parts[2], $matches ) ) {
		$schema['addressRegion'] = strtoupper( $matches[1] );
		if ( ! empty( $matches[2] ) ) {
			$schema['postalCode'] = strtoupper( $matches[2] );
		}
	}

	if ( '' !== $country ) {
		$schema['addressCountry'] = $country;
	}

	return $schema;
}

/**
 * The street address stored on an sp_venue term (`sp_address`, in the `taxonomy_<id>` option
 * SportsPress keeps its term fields in), as plain text; '' when there is none.
 *
 * @param int $term_id sp_venue term ID.
 * @return string
 */
function blueline_core_seo_venue_address( int $term_id ): string {
	$venue_meta = get_option( 'taxonomy_' . $term_id );

	return ( is_array( $venue_meta ) && ! empty( $venue_meta['sp_address'] ) ) ? blueline_core_seo_plain_text( $venue_meta['sp_address'] ) : '';
}

/**
 * The schema.org eventStatus URL for an sp_event, from SportsPress's `sp_status` post meta
 * ('ok', 'tbd', 'postponed', 'cancelled').
 *
 * EventScheduled means "taking place, or took place, as scheduled", so it is right for an
 * upcoming preview, a played final and a not-yet-timed ('tbd') game alike; only a postponed or
 * cancelled game needs its own value.
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
 * So: the manual excerpt when there is one, else the first characters of the raw content with
 * shortcodes and tags stripped, cut at a word boundary. A password-protected post yields no
 * description (the excerpt would leak its body).
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
		return blueline_core_seo_limit_text( $excerpt );
	}

	return blueline_core_seo_limit_text( strip_shortcodes( (string) $post->post_content ) );
}

/**
 * The description of a singular page: its own excerpt or text when it has any, else a sentence
 * built from what is known about a team or a player (their pages have no body of their own).
 *
 * @param int    $post_id   Post ID.
 * @param string $post_type Post type.
 * @return string
 */
function blueline_core_seo_singular_description( int $post_id, string $post_type ): string {
	$description = blueline_core_seo_post_description( $post_id );

	if ( '' !== $description || post_password_required( $post_id ) ) {
		return $description;
	}

	if ( 'sp_team' === $post_type ) {
		return blueline_core_seo_team_description( $post_id );
	}

	if ( 'sp_player' === $post_type ) {
		return blueline_core_seo_player_description( $post_id );
	}

	return '';
}

/**
 * Pick the richest sentence that fits a meta description, else the leanest one cut to fit.
 *
 * @param string[] $candidates Sentences, richest first.
 * @return string
 */
function blueline_core_seo_first_that_fits( array $candidates ): string {
	$candidates = array_values( array_filter( $candidates, 'strlen' ) );

	foreach ( $candidates as $candidate ) {
		if ( mb_strlen( $candidate ) <= 155 ) {
			return $candidate;
		}
	}

	return $candidates ? blueline_core_seo_limit_text( (string) end( $candidates ) ) : '';
}

/**
 * Meta description for an sp_team page: the team, its division and season when they are known,
 * and what the page holds.
 *
 * @param int $team_id sp_team post ID.
 * @return string
 */
function blueline_core_seo_team_description( int $team_id ): string {
	$name = blueline_core_seo_title( $team_id );

	if ( '' === $name ) {
		return '';
	}

	$division = blueline_core_seo_team_division( $team_id );
	$season   = blueline_core_seo_current_season_name();
	$league   = blueline_core_seo_league_name();

	$with = static fn( string $qualifier ): string => sprintf(
		/* translators: 1: team name, 2: division and/or season, 3: league name. */
		__( '%1$s — %2$s roster, schedule and standings in the %3$s.', 'blueline-core' ),
		$name,
		$qualifier,
		$league
	);

	return blueline_core_seo_first_that_fits(
		array(
			'' !== $division && '' !== $season ? $with( $division . ', ' . $season ) : '',
			'' !== $division ? $with( $division ) : '',
			sprintf(
				/* translators: 1: team name, 2: league name. */
				__( '%1$s roster, schedule and standings in the %2$s.', 'blueline-core' ),
				$name,
				$league
			),
		)
	);
}

/**
 * Meta description for an sp_player page: the player, their team and the season when they are
 * known to be on that team now, and what the page holds.
 *
 * @param int $player_id sp_player post ID.
 * @return string
 */
function blueline_core_seo_player_description( int $player_id ): string {
	$name = blueline_core_seo_title( $player_id );

	if ( '' === $name ) {
		return '';
	}

	$league  = blueline_core_seo_league_name();
	$team_id = blueline_core_seo_player_team_id( $player_id );
	$team    = $team_id ? blueline_core_seo_title( $team_id ) : '';
	$season  = $team_id ? blueline_core_seo_team_current_season_name( $team_id ) : '';

	return blueline_core_seo_first_that_fits(
		array(
			'' !== $team && '' !== $season ? sprintf(
				/* translators: 1: player name, 2: team name, 3: league name, 4: season name. */
				__( '%1$s plays for %2$s in the %3$s, %4$s. Player profile, stats and schedule.', 'blueline-core' ),
				$name,
				$team,
				$league,
				$season
			) : '',
			'' !== $team ? sprintf(
				/* translators: 1: player name, 2: team name, 3: league name. */
				__( '%1$s — %2$s player profile, stats and schedule in the %3$s.', 'blueline-core' ),
				$name,
				$team,
				$league
			) : '',
			sprintf(
				/* translators: 1: player name, 2: league name. */
				__( '%1$s — player profile, stats and schedule in the %2$s.', 'blueline-core' ),
				$name,
				$league
			),
		)
	);
}

/**
 * The first team stored against a player (`sp_current_team` is a repeated meta key, with '0'
 * placeholder rows on many players), or 0 when the player has none.
 *
 * @param int $player_id sp_player post ID.
 * @return int
 */
function blueline_core_seo_player_team_id( int $player_id ): int {
	foreach ( (array) get_post_meta( $player_id, 'sp_current_team', false ) as $row ) {
		if ( is_scalar( $row ) && absint( $row ) > 0 ) {
			return absint( $row );
		}
	}

	return 0;
}

/**
 * The division a team plays in this season, from the theme's current-season standings table; ''
 * when the theme cannot say.
 *
 * Not read from the team's own sp_league terms: those pile up every division the team was ever
 * tagged with and have no "current" flag.
 *
 * @param int $team_id sp_team post ID.
 * @return string
 */
function blueline_core_seo_team_division( int $team_id ): string {
	return function_exists( 'blueline_player_division_name' ) ? blueline_core_seo_plain_text( blueline_player_division_name( $team_id ) ) : '';
}

/**
 * The name of the season currently being played, as the theme resolves it; '' when unknown.
 *
 * @return string
 */
function blueline_core_seo_current_season_name(): string {
	if ( ! function_exists( 'blueline_current_sp_season_term_id' ) ) {
		return '';
	}

	$term_id = (int) blueline_current_sp_season_term_id();
	$term    = $term_id ? get_term( $term_id, 'sp_season' ) : null;

	return is_object( $term ) && ! empty( $term->name ) ? blueline_core_seo_plain_text( $term->name ) : '';
}

/**
 * The current season's name when the team is on this season's standings table (so it really plays
 * now), else ''.
 *
 * @param int $team_id sp_team post ID.
 * @return string
 */
function blueline_core_seo_team_current_season_name( int $team_id ): string {
	if ( ! function_exists( 'blueline_team_current_table_id' ) || ! blueline_team_current_table_id( $team_id ) ) {
		return '';
	}

	return blueline_core_seo_current_season_name();
}

/**
 * Team ids on an sp_event's `sp_team` meta: positive ids only, re-indexed from 0, in stored order.
 *
 * @param int $event_id sp_event post ID.
 * @return int[]
 */
function blueline_core_seo_event_team_ids( int $event_id ): array {
	return array_values( array_filter( array_map( 'absint', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) );
}

/**
 * Attachment id of a post's REAL uploaded featured image, or 0 when it has none.
 *
 * Not has_post_thumbnail(): the theme reports a placeholder (negative) thumbnail id for a logo-less
 * team so templates can draw a default badge, but that SVG is useless as an og:image / schema logo.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function blueline_core_real_thumbnail_id( int $post_id ): int {
	return max( 0, (int) get_post_thumbnail_id( $post_id ) );
}

/**
 * URL of a post's REAL uploaded featured image, or '' when it has none.
 *
 * @param int    $post_id Post ID.
 * @param string $size    Image size.
 * @return string
 */
function blueline_core_real_thumbnail_url( int $post_id, string $size = 'large' ): string {
	$thumbnail_id = blueline_core_real_thumbnail_id( $post_id );
	if ( $thumbnail_id <= 0 ) {
		return '';
	}

	// The attachment itself, not get_the_post_thumbnail_url(): the theme's default-logo filter answers that one.
	return (string) wp_get_attachment_image_url( $thumbnail_id, $size );
}

/**
 * Plain-text title for a post (no markup, entities decoded) -- the single place every Open Graph,
 * Twitter and JSON-LD title goes through. Players and staff use the raw post title, which has no
 * SportsPress badge markup; everything else uses the display title.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function blueline_core_seo_title( $post_id ): string {
	$title = in_array( get_post_type( $post_id ), array( 'sp_player', 'sp_staff' ), true )
		? (string) get_post_field( 'post_title', $post_id, 'raw' )
		: (string) get_the_title( $post_id );

	return blueline_core_seo_plain_text( $title );
}

/**
 * The first `sp_venue` term of an sp_event, or null with no venue.
 *
 * @param int $event_id sp_event post ID.
 * @return object|null
 */
function blueline_core_seo_event_venue_term( int $event_id ): ?object {
	$venue_terms = taxonomy_exists( 'sp_venue' ) ? wp_get_post_terms( $event_id, 'sp_venue' ) : array();

	return ( is_wp_error( $venue_terms ) || empty( $venue_terms ) ) ? null : $venue_terms[0];
}

/**
 * Plain-text label for an sp_venue term: the theme's arena/pad label (`blueline_venue_label`) when the
 * theme provides one, else the term name.
 *
 * @param object $term sp_venue term.
 * @return string
 */
function blueline_core_seo_venue_term_label( object $term ): string {
	$label = function_exists( 'blueline_venue_label' ) ? blueline_venue_label( (int) $term->term_id ) : ( $term->name ?? '' );

	return blueline_core_seo_plain_text( $label );
}

/**
 * Plain-text venue label for an sp_event: the theme's arena/pad label (`blueline_sp_event_venue_label`)
 * when the theme provides one, else the first `sp_venue` term name; '' with no venue.
 *
 * This is the one SportsPress lookup that is NOT mirrored here: the theme's label adds the arena
 * name ("Arena — Pad"), which the plugin has no way to compute.
 *
 * @param int $event_id sp_event post ID.
 * @return string
 */
function blueline_core_seo_event_venue_label( int $event_id ): string {
	if ( function_exists( 'blueline_sp_event_venue_label' ) ) {
		$label = blueline_sp_event_venue_label( $event_id );
	} else {
		$term  = blueline_core_seo_event_venue_term( $event_id );
		$label = $term ? $term->name : '';
	}

	// Term names are stored entity-encoded (`Arena &amp; Pad`); the output wants plain text.
	return blueline_core_seo_plain_text( $label );
}

/**
 * GMT unix timestamp from the event's local post date, or false when unknown.
 *
 * @param int $event_id sp_event post ID.
 * @return int|false
 */
function blueline_core_seo_event_start_timestamp( $event_id ) {
	$local = get_the_time( 'Y-m-d H:i:s', $event_id );

	if ( ! $local ) {
		return false;
	}

	$gmt = get_gmt_from_date( $local );

	return $gmt ? strtotime( $gmt . ' +0000' ) : false;
}
