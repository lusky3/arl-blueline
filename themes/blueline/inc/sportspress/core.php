<?php
/**
 * SportsPress integration, shared core: titles, sidebar logic, the
 * single-entity page shell, roster stats and their cache, kses rules, and
 * the league-table / team-events season helpers.
 *
 * Loaded by inc/sportspress.php; every SportsPress touchpoint is guarded
 * so the theme never fatals with SportsPress deactivated.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * A post's display title, with SportsPress's own number/role badge removed
 * for exactly the two post types that get one.
 *
 * SportsPress hooks 'the_title' for sp_player/sp_staff only, to prepend a
 * "<strong class=\"sp-player-number\">27</strong> " / "<strong
 * class=\"sp-staff-role\">Referee</strong> " badge (confirmed live:
 * get_the_title() on a player named "Matthew Mascola" returns that literal
 * markup plus text), and this theme's own hero markup already shows that same
 * number/role in its own badge, so the prefix would both duplicate it and,
 * if merely HTML-escaped, print the literal tags as visible text.
 *
 * Review finding: an earlier version of this function used
 * get_post_field( 'post_title', $id, 'raw' ) for every post type, which
 * bypasses the ENTIRE 'the_title' filter chain, not just SportsPress's
 * badge, but also wptexturize()/convert_chars() (smart quotes, em-dashes)
 * that every other post type's title still needs. Narrowed to only the two
 * post types with the actual badge problem; sp_event/sp_team (and anything
 * else) go through the normal, fully-filtered get_the_title().
 *
 * @param int $post_id Post ID.
 * @return string Plain title, ready for esc_html().
 */
function blueline_sp_title( $post_id ) {
	if ( in_array( get_post_type( $post_id ), array( 'sp_player', 'sp_staff' ), true ) ) {
		return (string) get_post_field( 'post_title', $post_id, 'raw' );
	}

	return get_the_title( $post_id );
}

/**
 * Whether SportsPress templates should reserve a sidebar column. Mirrors the
 * is_active_sidebar('sidebar-1') check page.php/archive.php already use,
 * promoted to a named, filterable function because all seven sportspress/
 * templates need the same decision (sidebar-1 historically carries SP-
 * relevant widgets, e.g. countdown, recent posts, so showing it here is
 * intentional, not an oversight).
 *
 * @return bool
 */
function blueline_sp_has_sidebar(): bool {
	return (bool) apply_filters( 'blueline_sp_has_sidebar', is_active_sidebar( 'sidebar-1' ) );
}

/**
 * The shared skeleton behind sportspress/single-event.php, single-player.php,
 * single-staff.php, and single-team.php: those four templates were
 * byte-identical (get_header() -> the sidebar-active check above -> the
 * entity's own hero -> `.bl-content-layout` wrapper -> the_content() ->
 * comments -> sidebar -> get_footer()) except for which hero function runs
 * and, on single-team.php alone, one extra colour-related attribute on the
 * `<main>` tag. This is that shared body; each of the four templates is now
 * just get_header(), one call here, and get_footer().
 *
 * @param callable      $hero_callback     The entity's own hero renderer (e.g.
 *                                          'blueline_sp_event_hero'), called with the
 *                                          current post's ID at the point the hero used
 *                                          to render inline. All four hero functions are
 *                                          declared unconditionally in heroes.php, which
 *                                          inc/sportspress.php always loads alongside
 *                                          this file, so they are always callable here.
 * @param string        $extra_main_attr   Extra, already-escaped markup echoed into
 *                                          `<main>`'s opening tag: single-team.php's own
 *                                          team-colour custom properties
 *                                          (blueline_team_color_style_attr()). Empty for
 *                                          the other three templates.
 * @param callable|null $before_content_cb Optional, called with no arguments
 *                                          immediately before the_content() (and
 *                                          therefore above every SportsPress
 *                                          auto-injected section, including the
 *                                          roster) -- single-team.php's own claim
 *                                          nudge (blueline_render_claim_nudge()).
 *                                          Null (the default) for the other three
 *                                          templates, which need no such content.
 */
function blueline_render_sp_single( callable $hero_callback, string $extra_main_attr = '', ?callable $before_content_cb = null ): void {
	$has_sidebar = blueline_sp_has_sidebar();
	?>
	<main id="main" class="bl-main bl-main--sp bl-main--sp-hero" tabindex="-1"<?php echo $extra_main_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-supplied markup is already escaped at its own source; see single-team.php. ?>>
		<?php
		while ( have_posts() ) :
			the_post();

			$hero_callback( get_the_ID() );
			?>
			<div class="bl-container">
				<div class="bl-content-layout<?php echo $has_sidebar ? ' bl-content-layout--has-sidebar' : ''; ?>">
					<div class="bl-content-layout__primary">
						<?php if ( $before_content_cb ) : ?>
							<?php $before_content_cb(); ?>
						<?php endif; ?>
						<div class="entry-content bl-entry__content">
							<?php the_content(); ?>
						</div>
						<?php
						if ( comments_open() || get_comments_number() ) :
							comments_template();
						endif;
						?>
					</div>
					<?php if ( $has_sidebar ) : ?>
						<?php get_sidebar(); ?>
					<?php endif; ?>
				</div>
			</div>
			<?php
		endwhile;
		?>
	</main>
	<?php
}

/**
 * The heading level SportsPress's own auto-injected table/list captions
 * ("Division 1 | S2026", "Upcoming Games", a roster list's own title, a
 * player's per-league stats caption -- sportspress/league-table.php,
 * event-list.php, team-lists.php and player-statistics-league.php) should
 * render at.
 *
 * A11y finding: these four templates are shared across two structurally
 * different contexts, and neither can be known when the markup is written
 * -- only at request time:
 *
 *   - On a SportsPress singular view (sp_team/sp_player/sp_staff/sp_event),
 *     the theme's own hero prints the page's ONE <h1> (blueline_sp_title(),
 *     e.g. sportspress/single-team.php) and then the_content() drops
 *     SportsPress's auto-injected sections straight in below it with
 *     NOTHING else in between -- confirmed live: a team page's outline
 *     went h1 -> [this caption], not h1 -> h2 -> [this caption]. The
 *     correct next level there is h2.
 *   - Everywhere else these four templates are used today (the homepage's
 *     standings_snippet module, via [league_table] --
 *     blueline_homepage_module_standings_snippet()), an h2 section title
 *     ("Standings") already precedes them, so the correct next level is
 *     h3. Exceptions (A11Y-09): a widget area, and a non-front page with
 *     no h2 of its own (e.g. /standings' bare [team_standings]), get h2.
 *
 * Hardcoding h4 regardless of context (the bug this fixes) skipped a level
 * in BOTH cases: h2 -> h4 on the homepage, and h1 -> h4 -- skipping two
 * levels at once -- on every SportsPress singular page.
 *
 * @return int Either 2 or 3.
 */
function blueline_sp_caption_heading_level(): int {
	if ( function_exists( 'sp_post_types' ) && is_singular( sp_post_types() ) ) {
		return 2;
	}

	// A11Y-09: in a widget area, a sibling of the h2 widget titles.
	if ( blueline_sp_in_sidebar() ) {
		return 2;
	}

	// A11Y-09: straight under the h1 of a page with no h2 of its own (/standings).
	if ( is_page() && ! is_front_page() && ! blueline_content_has_h2( (string) get_post_field( 'post_content', get_queried_object_id() ) ) ) {
		return 2;
	}

	return 3;
}

/**
 * Archive-card type line for a SportsPress post ("Team", "Game · Mar 6, 2020"),
 * so mixed league/season/position archives say what each entry is (C-20).
 *
 * @param string $post_type Post type.
 * @param string $date      Formatted date, used for games only.
 * @return string '' for a non-SportsPress type.
 */
function blueline_sp_post_card_label( string $post_type, string $date = '' ): string {
	$labels = array(
		'sp_team'     => __( 'Team', 'blueline' ),
		'sp_player'   => __( 'Player', 'blueline' ),
		'sp_staff'    => __( 'Staff', 'blueline' ),
		'sp_list'     => __( 'Player list', 'blueline' ),
		'sp_table'    => __( 'Standings', 'blueline' ),
		'sp_calendar' => __( 'Schedule', 'blueline' ),
		'sp_event'    => __( 'Game', 'blueline' ),
		'sp_sponsor'  => __( 'Sponsor', 'blueline' ),
	);

	if ( ! isset( $labels[ $post_type ] ) ) {
		return '';
	}

	return ( 'sp_event' === $post_type && '' !== $date )
		/* translators: 1: "Game", 2: game date. */
		? sprintf( __( '%1$s · %2$s', 'blueline' ), $labels[ $post_type ], $date )
		: $labels[ $post_type ];
}

/**
 * Whether an archive card shows the excerpt: SportsPress data posts (teams,
 * players, lists...) have no prose worth teasing, and a team's auto-excerpt
 * is its old calendar-link text (C-20). Games and hand-written excerpts keep it.
 *
 * @param string $post_type   Post type.
 * @param bool   $has_excerpt Whether a manual excerpt exists.
 * @return bool
 */
function blueline_post_card_shows_excerpt( string $post_type, bool $has_excerpt ): bool {
	return $has_excerpt || 'sp_event' === $post_type || 0 !== strpos( $post_type, 'sp_' );
}

add_filter( 'the_content', 'blueline_sp_content_caption_levels', 21 );
/**
 * Stock SportsPress templates the theme does not override (event details/
 * results/box score, player list, fixtures/past meetings) hard-code h4 captions
 * and h4/h5 event-block headings; give them the contextual levels, and drop a
 * caption that only repeats the
 * singular table/calendar/list's own h1 (C-12, C-21, B-23).
 *
 * @param string $content Post content, shortcodes expanded.
 * @return string
 */
function blueline_sp_content_caption_levels( $content ) {
	if ( false === strpos( (string) $content, 'sp-table-caption' ) ) {
		return $content;
	}

	$page_title = is_singular( array( 'sp_table', 'sp_calendar', 'sp_list' ) ) ? get_the_title( get_queried_object_id() ) : '';

	return blueline_sp_retag_captions( (string) $content, blueline_sp_caption_heading_level(), (string) $page_title );
}

/**
 * Retag stock SportsPress headings under captions of $level: captions (h4) to
 * $level, event-block titles (h4) one below, their scores (h5) two below.
 * A caption equal to $page_title is removed.
 *
 * @param string $content    HTML.
 * @param int    $level      Caption heading level (2 or 3).
 * @param string $page_title Singular title whose duplicate caption is removed ('' to keep all).
 * @return string
 */
function blueline_sp_retag_captions( string $content, int $level, string $page_title = '' ): string {
	$normalise = static function ( string $text ): string {
		return strtolower( trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) ) );
	};
	$title     = '' === $page_title ? '' : $normalise( $page_title );
	$stock     = array(
		'sp-table-caption' => array( '4', 0 ),
		'sp-event-title'   => array( '4', 1 ),
		'sp-event-results' => array( '5', 2 ),
	);

	return (string) preg_replace_callback(
		'#<h([2-5]) class="(sp-table-caption|sp-event-title|sp-event-results)"([^>]*)>(.*?)</h\1>#s',
		static function ( array $m ) use ( $level, $title, $normalise, $stock ): string {
			if ( 'sp-table-caption' === $m[2] && '' !== $title && $normalise( $m[4] ) === $title ) {
				return '';
			}

			if ( $stock[ $m[2] ][0] !== $m[1] ) {
				return $m[0];
			}

			return sprintf( '<h%1$d class="%2$s"%3$s>%4$s</h%1$d>', $level + $stock[ $m[2] ][1], $m[2], $m[3], $m[4] );
		},
		$content
	);
}

/**
 * Whether a block of post content contains its own h2 (A11Y-09).
 *
 * @param string $content Raw post content.
 * @return bool
 */
function blueline_content_has_h2( string $content ): bool {
	return 1 === preg_match( '/<h2[\s>]/i', $content );
}

add_action( 'dynamic_sidebar_before', 'blueline_sp_enter_sidebar' );
add_action( 'dynamic_sidebar_after', 'blueline_sp_leave_sidebar' );

/**
 * Whether a widget area is rendering right now (dynamic_sidebar_before/_after).
 *
 * @param bool|null $set Pass a bool to change the state.
 * @return bool
 */
function blueline_sp_in_sidebar( ?bool $set = null ): bool {
	static $in_sidebar = false;

	if ( null !== $set ) {
		$in_sidebar = $set;
	}

	return $in_sidebar;
}

/**
 * Mark a widget area as rendering (dynamic_sidebar_before).
 */
function blueline_sp_enter_sidebar(): void {
	blueline_sp_in_sidebar( true );
}

/**
 * Mark a widget area as finished (dynamic_sidebar_after).
 */
function blueline_sp_leave_sidebar(): void {
	blueline_sp_in_sidebar( false );
}

/**
 * The [sponsors] title level: h2 for the footer band after <main> (A11Y-09).
 *
 * @param bool $in_footer Whether this is the footer band (doing_action( 'get_footer' )).
 * @return int
 */
function blueline_sp_sponsors_title_level( bool $in_footer ): int {
	return $in_footer ? 2 : blueline_sp_caption_heading_level();
}

add_action( 'sportspress_before_single_sponsor', 'blueline_sp_sponsor_single_logo' );
/**
 * B-17: a sponsor's own page showed only its title and a bare link; print its
 * logo first (decorative: the sponsor name is the h1 right above it).
 */
function blueline_sp_sponsor_single_logo(): void {
	echo blueline_sp_sponsor_logo_figure( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built thumbnail markup.
}

/**
 * The single-sponsor logo figure, or '' when the sponsor has no logo.
 *
 * @param int $sponsor_id sp_sponsor post ID.
 * @return string
 */
function blueline_sp_sponsor_logo_figure( int $sponsor_id ): string {
	if ( ! $sponsor_id || ! has_post_thumbnail( $sponsor_id ) ) {
		return '';
	}

	return '<figure class="bl-sp-sponsor-logo">' . get_the_post_thumbnail( $sponsor_id, 'medium', array( 'alt' => '' ) ) . '</figure>';
}

add_filter( 'wp_get_attachment_image_attributes', 'blueline_sp_sponsor_logo_alt' );
/**
 * Name a sponsor logo link: an alt-less logo gets the sponsor's title (A11Y-04).
 *
 * @param array $attr Image attributes.
 * @return array
 */
function blueline_sp_sponsor_logo_alt( $attr ) {
	if (
		is_array( $attr )
		&& isset( $attr['class'] )
		&& false !== strpos( (string) $attr['class'], 'sp-sponsor-logo' )
		&& '' === trim( (string) ( $attr['alt'] ?? '' ) )
		&& ! empty( $attr['title'] )
	) {
		$attr['alt'] = $attr['title'];
	}

	return $attr;
}

/**
 * The roster table's stat columns, in display order -- the single source
 * both the <thead> column labels (sportspress/team-lists.php) and each
 * row's cells (blueline_render_roster_stats(), just below) read from, so
 * the two can never drift out of sync.
 *
 * @return array<string,string> Stat key => column label.
 */
function blueline_roster_stat_labels(): array {
	return array(
		'gp'  => __( 'GP', 'blueline' ),
		'g'   => __( 'G', 'blueline' ),
		'a'   => __( 'A', 'blueline' ),
		'pts' => __( 'PTS', 'blueline' ),
		'pim' => __( 'PIM', 'blueline' ),
	);
}

/**
 * Print one player's current-season stat cells (GP / G / A / PTS / PIM,
 * blueline_roster_stat_labels()' order) for a team roster row
 * (sportspress/team-lists.php, both the curated-list and the
 * blueline_get_team_roster() fallback loop) -- a real <table> so
 * SportsPress's own already-loaded sp-sortable-table/DataTables behaviour
 * (see league-table.php, same classes, same page) makes every column
 * click-to-sort with no new JS.
 *
 * Reads through blueline_get_player_season_stats() -- SP_Player::data(),
 * memoised, correctly season- and league-scoped (see that function's own
 * docblock, inc/account/player-data.php) -- rather than the columns already
 * available on $row from SP_Player_List::data(). That data only carries
 * whatever columns an admin configured on THIS PARTICULAR sp_list post
 * (SP_Player_List's own constructor default is just number/team/position,
 * no stats at all), curated inconsistently across a decade of team pages;
 * reading through the account dashboard's own stats reader instead means
 * every roster shows the same real numbers regardless of that per-list
 * configuration, the same way the account dashboard's own "My season"
 * module already does for a single signed-in player.
 *
 * PTS is goals + assists, computed here rather than stored: SportsPress
 * data carries no separate points figure for a skater, and this is the
 * standard hockey box-score derivation, not a guess.
 *
 * @param int        $player_id sp_player post ID.
 * @param array|null $stats     Precomputed stat line from blueline_roster_season_stats(); read live when null.
 * @return void
 */
function blueline_render_roster_stats( int $player_id, ?array $stats = null ) {
	if ( null === $stats ) {
		$stats = blueline_get_player_season_stats( $player_id );
	}

	$stats       += array(
		'gp'  => 0,
		'g'   => 0,
		'a'   => 0,
		'pim' => 0,
	);
	$stats['pts'] = $stats['g'] + $stats['a'];

	foreach ( array_keys( blueline_roster_stat_labels() ) as $bl_key ) {
		printf( '<td class="bl-sp-roster__stat">%s</td>', esc_html( (string) $stats[ $bl_key ] ) );
	}
}

/**
 * Option holding the generation number every theme-side SportsPress data
 * cache key embeds (rosters, league tables). Bumping it retires them all.
 */
const BLUELINE_SP_CACHE_GEN_OPTION = 'blueline_sp_cache_gen';

/**
 * Backstop TTL for those caches, in seconds; invalidation is event-driven.
 */
const BLUELINE_SP_CACHE_TTL = 900;

/**
 * Transient key for a theme-side SportsPress data cache.
 *
 * @param string                     $name  Cache family, e.g. 'roster_stats'.
 * @param array<int,int|string|null> $parts Everything the cached value varies by.
 * @return string
 */
function blueline_sp_cache_key( string $name, array $parts ): string {
	$generation = (int) get_option( BLUELINE_SP_CACHE_GEN_OPTION, 0 );

	return 'bl_' . $name . '_' . $generation . '_' . implode( '_', array_map( 'strval', $parts ) );
}

/**
 * Queue one generation bump for the end of this request -- after every
 * SportsPress save handler has written its meta, so nothing re-caches
 * half-saved data under the new generation. Re-adding the same callback
 * is a no-op, so many saves in one request still bump once.
 *
 * @return void
 */
function blueline_sp_cache_mark_dirty(): void {
	add_action( 'shutdown', 'blueline_sp_cache_bump' );
}

/**
 * Advance the cache generation.
 *
 * @return void
 */
function blueline_sp_cache_bump(): void {
	update_option( BLUELINE_SP_CACHE_GEN_OPTION, (int) get_option( BLUELINE_SP_CACHE_GEN_OPTION, 0 ) + 1, true );
}

// Same events the sportspress-cache-purge mu-plugin purges the page cache on, plus terms/settings.
add_action( 'save_post', 'blueline_sp_cache_on_post_change' );
add_action( 'delete_post', 'blueline_sp_cache_on_post_change' );
add_action( 'trashed_post', 'blueline_sp_cache_on_post_change' );
add_action( 'untrashed_post', 'blueline_sp_cache_on_post_change' );
add_action( 'sportspress_event_results_saved', 'blueline_sp_cache_mark_dirty' );
add_action( 'sportspress_event_leagues_saved', 'blueline_sp_cache_mark_dirty' );
add_action( 'sportspress_event_performances_saved', 'blueline_sp_cache_mark_dirty' );
add_action( 'created_term', 'blueline_sp_cache_on_term_change', 10, 3 );
add_action( 'edited_term', 'blueline_sp_cache_on_term_change', 10, 3 );
add_action( 'delete_term', 'blueline_sp_cache_on_term_change', 10, 3 );
add_action( 'set_object_terms', 'blueline_sp_cache_on_object_terms', 10, 4 );
add_action( 'added_option', 'blueline_sp_cache_on_option_change' );
add_action( 'updated_option', 'blueline_sp_cache_on_option_change' );

/**
 * Invalidate on any change to a SportsPress post type (sp_event, sp_team, sp_player, sp_table, ...).
 *
 * @param int $post_id Post ID.
 * @return void
 */
function blueline_sp_cache_on_post_change( $post_id ): void {
	$type = get_post_type( $post_id );

	if ( is_string( $type ) && 0 === strpos( $type, 'sp_' ) ) {
		blueline_sp_cache_mark_dirty();
	}
}

/**
 * Invalidate on a SportsPress taxonomy term change (sp_season, sp_league, ...).
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID.
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function blueline_sp_cache_on_term_change( $term_id, $tt_id, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
	if ( is_string( $taxonomy ) && 0 === strpos( $taxonomy, 'sp_' ) ) {
		blueline_sp_cache_mark_dirty();
	}
}

/**
 * Invalidate when a post's SportsPress terms change outside save_post (e.g. bulk edit).
 *
 * @param int    $object_id Object ID.
 * @param array  $terms     Terms.
 * @param array  $tt_ids    Term taxonomy IDs.
 * @param string $taxonomy  Taxonomy slug.
 * @return void
 */
function blueline_sp_cache_on_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
	blueline_sp_cache_on_term_change( 0, 0, $taxonomy );
}

/**
 * Invalidate when a SportsPress setting changes (table/stat configuration, league menu teams).
 *
 * @param string $option Option name.
 * @return void
 */
function blueline_sp_cache_on_option_change( $option ): void {
	if ( is_string( $option ) && 0 === strpos( $option, 'sportspress_' ) ) {
		blueline_sp_cache_mark_dirty();
	}
}

/**
 * Current-season stat lines for a roster, cached per team + season (PERF-01).
 *
 * Each line comes from blueline_get_player_season_stats() unchanged, so the
 * numbers are identical to an uncached read; this only stops a cold team page
 * paying one SP_Player::data() (~40 queries) per row on every view.
 *
 * @param int   $team_id    sp_team post ID the roster is rendered for.
 * @param int[] $player_ids sp_player post IDs.
 * @return array<int, array{gp:int, g:int, a:int, pim:int}> Keyed by player ID.
 */
function blueline_roster_season_stats( int $team_id, array $player_ids ): array {
	$player_ids = array_values( array_unique( array_filter( array_map( 'absint', $player_ids ) ) ) );

	if ( ! $player_ids ) {
		return array();
	}

	$season = (int) blueline_current_sp_season_term_id();
	$key    = blueline_sp_cache_key( 'roster_stats', array( $team_id, $season ) );
	$cached = get_transient( $key );
	$cached = is_array( $cached ) ? $cached : array();
	$dirty  = false;

	foreach ( $player_ids as $player_id ) {
		if ( ! isset( $cached[ $player_id ] ) || ! is_array( $cached[ $player_id ] ) ) {
			$cached[ $player_id ] = blueline_get_player_season_stats( $player_id );
			$dirty                = true;
		}
	}

	if ( $dirty ) {
		set_transient( $key, $cached, BLUELINE_SP_CACHE_TTL );
	}

	return array_intersect_key( $cached, array_flip( $player_ids ) );
}

/**
 * The wp_kses_post() allowlist plus the img attributes core's own
 * get_the_post_thumbnail() emits (srcset/sizes/decoding), which the stock
 * list drops -- used by sportspress/league-table.php so its team logos keep
 * their responsive sources (PERF-06).
 *
 * @return array
 */
function blueline_sp_kses_allowed_html(): array {
	static $allowed = null;

	if ( null === $allowed ) {
		$allowed = wp_kses_allowed_html( 'post' );

		$allowed['img'] = ( isset( $allowed['img'] ) && is_array( $allowed['img'] ) ? $allowed['img'] : array() ) + array(
			'srcset'   => true,
			'sizes'    => true,
			'decoding' => true,
		);
	}

	return $allowed;
}

/**
 * The `bl-sp-col-extra` class fragment for one league-table column, shared by
 * sportspress/league-table.php's header-building and row-building loops;
 * both used to repeat `in_array( $key, $bl_extra_keys, true ) ? '
 * bl-sp-col-extra' : ''` verbatim.
 *
 * @param string   $key         The column key being rendered.
 * @param string[] $extra_keys  Column keys marked "extra" (hidden until the
 *                               full-stats toggle is checked).
 * @return string ' bl-sp-col-extra' when $key is one of $extra_keys, '' otherwise.
 */
function blueline_sp_extra_class( string $key, array $extra_keys ): string {
	return in_array( $key, $extra_keys, true ) ? ' bl-sp-col-extra' : '';
}

/**
 * Pure decision behind blueline_team_events_current_season_id(): given
 * SportsPress' own filter value ($season -- its plugin default of 0, or
 * whatever an earlier-priority `sp_team_events_season` callback already
 * set) and this theme's own resolved "current season" answer, decide which
 * one actually wins.
 *
 * Split out purely so this decision is unit-testable without going through
 * blueline_current_sp_season_term_id()'s own get_posts()/transient-backed
 * resolution path (inc/account/player-data.php) -- that function's own
 * WordPress dependencies stay entirely outside this one's test surface.
 *
 * @param int      $season           SportsPress' own filter value.
 * @param int|null $resolved_current blueline_current_sp_season_term_id()'s answer.
 * @return int
 */
function blueline_resolve_team_events_season( int $season, ?int $resolved_current ): int {
	if ( $season > 0 ) {
		// Something upstream already chose a season (a future filter running
		// at a later priority, or a caller passing one explicitly) -- never
		// override a real value with our own guess.
		return $season;
	}

	return $resolved_current ? $resolved_current : $season;
}

add_filter( 'sp_team_events_season', 'blueline_team_events_current_season_id' );
/**
 * Scope a team's public "Upcoming Games"/"Results" cards
 * (sportspress/team-events.php, the theme's own override of SportsPress'
 * team-events.php) to the season currently driving the schedule.
 *
 * Live-site review: a team's Results section showed games from years
 * earlier (e.g. 2017) directly beneath Upcoming Games, with nothing
 * distinguishing them from the current season. Root cause, confirmed
 * against the SportsPress plugin's own team-events.php/
 * event-fixtures-results.php: the 'blocks' display format (this site's own
 * configured `sportspress_team_events_format`) calls
 * `sp_get_template( 'event-fixtures-results.php', array( 'team' => $id ) )`
 * with NO season argument at all, so SP_Calendar falls back to every
 * sp_event the team has EVER played. The 'list' format already exposes an
 * `sp_team_events_season` filter for exactly this purpose (unused on this
 * site, but honoured here too, for free, since both formats now read the
 * same filter); the theme's own team-events.php override adds the
 * equivalent call for the 'blocks' format, whose plugin default takes no
 * filter input at all.
 *
 * Reuses blueline_current_sp_season_term_id() (inc/account/player-data.php)
 * -- the same season-resolution the account dashboard's own My Season/My
 * Next Game readers already use -- rather than inventing a second rule for
 * "which season is current."
 *
 * @param int $season SportsPress' own filter value (its plugin default of 0
 *                     unless another filter already set one).
 * @return int
 */
function blueline_team_events_current_season_id( $season = 0 ) {
	$resolved = blueline_current_sp_season_term_id();

	return blueline_resolve_team_events_season( (int) $season, $resolved );
}
