<?php
/**
 * SportsPress integration: venue archive query fix, arena names and
 * venue labels, and the venue archive title.
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
