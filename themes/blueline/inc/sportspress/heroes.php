<?php
/**
 * SportsPress integration: per-team results, event/player/team/staff
 * hero renderers and the event teaser, consumed by sportspress/*.php.
 *
 * Loaded by inc/sportspress.php; every SportsPress touchpoint is guarded
 * so the theme never fatals with SportsPress deactivated.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * One team's own scoreline for a played sp_event, looked up by team ID,
 * never by position in a shared results array.
 *
 * Review finding: SP_Event::main_results() (SportsPress core) returns a
 * plain, re-indexed array built by looping $teams and doing
 * `$output[] = $team_result` only `if ( null != $team_result )`, so a
 * team with no result recorded for this event (a bye, an incomplete score
 * entry, etc.) is skipped entirely, shifting every following team's score
 * left by one slot. Zipping that array against this theme's own
 * separately-fetched $teams array by index (the original implementation)
 * would silently attribute one team's score to a different team the moment
 * either team is missing a value: home/away meta-row order is meaningful
 * on this site but not guaranteed to line up with a differently-derived,
 * gap-compacted results array. This function re-derives one specific
 * team's own result directly from the sp_results meta, keyed by that
 * team's own ID, mirroring SP_Event::main_results()'s own per-team logic
 * (primary result column if configured and non-empty, else the last
 * non-empty, non-outcome column) without going through its shared,
 * position-based output array at all.
 *
 * @param int $event_id sp_event post ID.
 * @param int $team_id  sp_team post ID, expected to be one of this event's own sp_team meta values.
 * @return string|int|null The team's own scoreline, or null if none is recorded.
 */
function blueline_sp_team_result( $event_id, $team_id ) {
	$results = get_post_meta( $event_id, 'sp_results', true );

	if ( ! is_array( $results ) || empty( $results[ $team_id ] ) || ! is_array( $results[ $team_id ] ) ) {
		return null;
	}

	$team_results = $results[ $team_id ];
	$primary_key  = get_option( 'sportspress_primary_result', '' );

	if ( $primary_key && isset( $team_results[ $primary_key ] ) && '' !== $team_results[ $primary_key ] ) {
		return $team_results[ $primary_key ];
	}

	unset( $team_results['outcome'] );

	$team_results = array_filter(
		$team_results,
		static function ( $value ) {
			return '' !== $value && null !== $value;
		}
	);

	if ( empty( $team_results ) ) {
		return null;
	}

	return end( $team_results );
}

/**
 * The team ids attached to an sp_event's `sp_team` meta, absint()'d and
 * de-duplicated of anything that doesn't resolve to a real (positive) id:
 * the exact get_post_meta()/array_map()/array_filter() shape
 * blueline_sp_event_hero(), blueline_sp_event_teaser(),
 * blueline_social_meta_data_for_event() (inc/social-meta.php), and
 * blueline_sports_event_schema() (inc/social-meta.php) each used to repeat
 * verbatim. Callers that split this into a "team A"/"team B" pair (most of
 * them) still do that split themselves; this only resolves the raw id
 * list.
 *
 * @param int $event_id sp_event post ID.
 * @return int[] Team ids, re-indexed from 0, in the order SportsPress stored them.
 */
function blueline_sp_event_team_ids( int $event_id ): array {
	return array_values( array_filter( array_map( 'absint', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) );
}

/**
 * The player-facing venue label for a single sp_event's own `sp_venue`
 * term: "{Arena name} — {Pad name}" via blueline_venue_label() (P0 finding
 * 10) when that function exists, else the term's own name; '' when the
 * taxonomy doesn't exist, the event has no venue term at all, or the term
 * lookup errors.
 *
 * The exact wp_get_post_terms()/is_wp_error()/blueline_venue_label()-or-
 * fallback shape blueline_sp_event_hero(), blueline_sp_event_teaser(),
 * blueline_social_meta_data_for_event() (inc/social-meta.php), and
 * blueline_sports_event_schema() (inc/social-meta.php) each used to repeat
 * verbatim.
 * blueline_homepage_next_event_line() (inc/homepage-modules.php) used a
 * wp_get_object_terms() variant of the same lookup (functionally
 * identical for a single post's own terms), and now calls this one
 * canonical version instead.
 *
 * @param int $event_id sp_event post ID.
 * @return string
 */
function blueline_sp_event_venue_label( int $event_id ): string {
	if ( ! taxonomy_exists( 'sp_venue' ) ) {
		return '';
	}

	$venue_terms = wp_get_post_terms( $event_id, 'sp_venue' );

	if ( is_wp_error( $venue_terms ) || empty( $venue_terms ) ) {
		return '';
	}

	return blueline_venue_label( $venue_terms[0]->term_id );
}

/**
 * The event masthead: teams, "vs", venue (via blueline_venue_label(),
 * P0 finding 10, so this reads "Mr. Lube and Tires Arena — Red" rather
 * than just the pad name), and either an add-to-calendar link (game has not
 * started), "Result pending" (game has started but no score is in yet), or
 * the final score (a result has been recorded). Purely additive to what
 * the_content() renders below it: SportsPress's own event-logos/
 * event-details/event-venue sections still appear and are styled via
 * sportspress.css.
 *
 * @param int $event_id sp_event post ID.
 */
function blueline_sp_event_hero( $event_id ) {
	if ( ! function_exists( 'sp_get_status' ) ) {
		return;
	}

	$teams = blueline_sp_event_team_ids( $event_id );

	$has_results = ( 'results' === sp_get_status( $event_id ) );
	$start_ts    = blueline_sp_event_start_timestamp( $event_id );
	$state       = blueline_sp_event_state( $has_results, $start_ts );
	$is_played   = ( 'final' === $state );

	$status_labels = array(
		'final'   => __( 'Final', 'blueline' ),
		'pending' => __( 'Result pending', 'blueline' ),
		'preview' => __( 'Preview', 'blueline' ),
	);

	$venue_name = blueline_sp_event_venue_label( $event_id );

	$calendar_url = ( 'preview' === $state ) ? blueline_sp_event_calendar_url( $event_id ) : '';
	$ics_url      = ( 'preview' === $state ) ? blueline_sp_event_ics_url( $event_id ) : '';
	?>
	<header class="sp-scoreboard">
		<?php blueline_render_faceoff_rings(); ?>
		<div class="bl-container sp-scoreboard__inner">
			<p class="sp-scoreboard__status">
				<span class="bl-skew"><span><?php echo esc_html( $status_labels[ $state ] ); ?></span></span>
			</p>

			<div class="sp-scoreboard__matchup">
				<?php foreach ( array( 0, 1 ) as $slot ) : ?>
					<?php
					$team_id = $teams[ $slot ] ?? 0;
					$score   = ( $is_played && $team_id ) ? blueline_sp_team_result( $event_id, $team_id ) : null;
					?>
					<div class="sp-scoreboard__team">
						<?php if ( $team_id && has_post_thumbnail( $team_id ) ) : ?>
							<span class="sp-scoreboard__logo"><?php echo get_the_post_thumbnail( $team_id, 'thumbnail' ); ?></span>
						<?php endif; ?>
						<span class="sp-scoreboard__team-name">
							<?php echo esc_html( $team_id ? blueline_sp_title( $team_id ) : __( 'TBD', 'blueline' ) ); ?>
						</span>
						<?php if ( $is_played && null !== $score ) : ?>
							<span class="sp-scoreboard__score"><?php echo esc_html( $score ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( 0 === $slot ) : ?>
						<span class="sp-scoreboard__vs" aria-hidden="true"><?php esc_html_e( 'vs', 'blueline' ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<p class="sp-scoreboard__meta">
				<time class="sp-scoreboard__time" datetime="<?php echo esc_attr( get_the_date( DATE_W3C, $event_id ) ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: event date, 2: event time. */
							__( '%1$s · %2$s', 'blueline' ),
							get_the_date( 'D, M j', $event_id ),
							get_the_time( get_option( 'time_format' ), $event_id )
						)
					);
					?>
				</time>
				<?php if ( $venue_name ) : ?>
					<span class="sp-scoreboard__venue"><?php echo esc_html( $venue_name ); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( $calendar_url || $ics_url ) : ?>
				<div class="sp-scoreboard__calendar" data-calendar-links>
					<?php if ( $ics_url ) : ?>
						<?php
						/*
						 * esc_attr(), not esc_url(): confirmed live, esc_url()
						 * strips every %0d/%0a it finds (wp-includes/
						 * formatting.php's own anti-header-injection pass) --
						 * exactly the CRLF sequences RFC 5545 requires between
						 * every line of the encoded .ics payload below, so the
						 * calendar app received one unbroken, unparseable blob
						 * instead of a valid VEVENT. This data: URI is built
						 * entirely from this function's own escaped/encoded
						 * output (blueline_sp_event_ics_url(), never raw user
						 * input), so esc_attr()'s attribute-context escaping --
						 * which does not touch %0d/%0a -- is what this needed,
						 * the same way a plain <a href> to a same-origin,
						 * already-safe URL only ever needs esc_attr() rather
						 * than the fuller esc_url() sanitizer.
						 */
						?>
						<a class="bl-btn bl-btn--secondary" data-calendar="apple" href="<?php echo esc_attr( $ics_url ); ?>" download="<?php echo esc_attr( sanitize_file_name( blueline_sp_title( $event_id ) ) . '.ics' ); ?>">
							<span class="bl-skew"><span><?php esc_html_e( 'Apple / Outlook', 'blueline' ); ?></span></span>
						</a>
					<?php endif; ?>
					<?php if ( $calendar_url ) : ?>
						<a class="bl-btn bl-btn--secondary" data-calendar="google" href="<?php echo esc_url( $calendar_url ); ?>">
							<span class="bl-skew"><span><?php esc_html_e( 'Google', 'blueline' ); ?></span></span>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="bl-band" aria-hidden="true"></div>
		<div class="bl-band--ink" aria-hidden="true"></div>
	</header>
	<?php
}

/**
 * The player masthead: number, position (read from the sp_position
 * taxonomy, never a meta field), current team, and nationality (escaped
 * for the attribute context it is used in, per the Task 8 brief).
 *
 * @param int $player_id sp_player post ID.
 */
function blueline_sp_player_hero( $player_id ) {
	$number = get_post_meta( $player_id, 'sp_number', true );

	$position_names = array();
	if ( taxonomy_exists( 'sp_position' ) ) {
		$position_terms = wp_get_post_terms( $player_id, 'sp_position' );
		if ( ! is_wp_error( $position_terms ) ) {
			foreach ( $position_terms as $term ) {
				$position_names[] = $term->name;
			}
		}
	}

	$current_team_id = 0;
	if ( class_exists( 'SP_Player' ) ) {
		$sp_player     = new SP_Player( $player_id );
		$current_teams = array_filter( array_map( 'absint', (array) $sp_player->current_teams() ) );
		if ( ! empty( $current_teams ) ) {
			$current_team_id = (int) reset( $current_teams );
		}
	}

	$nationalities = array_filter( (array) get_post_meta( $player_id, 'sp_nationality', false ) );
	?>
	<header class="bl-sp-hero bl-sp-hero--player">
		<div class="bl-container bl-sp-hero__inner">
			<?php if ( has_post_thumbnail( $player_id ) ) : ?>
				<div class="bl-sp-hero__crest bl-sp-hero__crest--player"><?php echo get_the_post_thumbnail( $player_id, 'thumbnail' ); ?></div>
			<?php else : ?>
				<div class="bl-sp-hero__crest bl-sp-hero__crest--fallback">
					<?php blueline_leaf_mark( 'bl-sp-hero__crest-mark' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $number && null !== $number ) : ?>
				<span class="bl-sp-hero__number" aria-hidden="true"><?php echo esc_html( $number ); ?></span>
			<?php endif; ?>

			<div class="bl-sp-hero__identity">
				<h1 class="bl-sp-hero__title"><?php echo esc_html( blueline_sp_title( $player_id ) ); ?></h1>
				<p class="bl-sp-hero__meta">
					<?php if ( $position_names ) : ?>
						<span class="bl-sp-hero__position"><?php echo esc_html( implode( ', ', $position_names ) ); ?></span>
					<?php endif; ?>
					<?php foreach ( $nationalities as $code ) : ?>
						<?php
						$code = (string) $code;
						if ( '' === $code ) {
							continue;
						}
						?>
						<span class="bl-sp-hero__flag" title="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( strtoupper( $code ) ); ?></span>
					<?php endforeach; ?>
				</p>
			</div>

			<?php if ( $current_team_id && 'publish' === get_post_status( $current_team_id ) ) : ?>
				<a class="bl-sp-hero__team" href="<?php echo esc_url( get_permalink( $current_team_id ) ); ?>">
					<?php if ( has_post_thumbnail( $current_team_id ) ) : ?>
						<?php echo get_the_post_thumbnail( $current_team_id, 'thumbnail' ); ?>
					<?php endif; ?>
					<span><?php echo esc_html( blueline_sp_title( $current_team_id ) ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	</header>
	<?php
}

/**
 * Pure decision: which division name(s) blueline_sp_team_hero() should
 * render, given the CURRENT season's division name
 * blueline_player_division_name() resolved (inc/account/player-data.php;
 * '' if none) for this team.
 *
 * Always prefers that season-scoped single answer over the team's own raw,
 * unscoped sp_league terms -- an empty season-scoped answer is honest ("no
 * current-season assignment yet"), never a reason to fall back to whatever
 * historical tags the team happens to carry. Kept as its own pure function,
 * separate from the WordPress-touching wrapper below, so this policy
 * decision is directly unit testable.
 *
 * @param string $current_season_division blueline_player_division_name()'s result, or ''.
 * @return string[]
 */
function blueline_sp_team_hero_decide_division_names( string $current_season_division ): array {
	return '' !== $current_season_division ? array( $current_season_division ) : array();
}

/**
 * Division name(s) blueline_sp_team_hero() should show for $team_id -- the
 * CURRENT season's division only, resolved through
 * blueline_player_division_name() (inc/account/player-data.php), never the
 * team's own raw sp_league terms directly.
 *
 * Live-review finding: team pages showed 5-7 stale/historical division
 * tags, most not matching any of the site's five actually-current
 * divisions -- this used to render EVERY sp_league term ever attached to
 * the team, comma-joined, with no "current" filter at all.
 * blueline_player_division_name()'s own docblock already documents exactly
 * this: "an earlier version of this function picked the team's own HIGHEST
 * term_id sp_league term... [that] claim was wrong: blueline_sp_team_hero()
 * renders EVERY sp_league term... so the two pages could disagree about the
 * same team's division," and "confirmed live: team 14955 alone carries 7
 * different Division terms spanning several seasons." This reuses that
 * function's season-scoped resolution (via the team's current-season
 * sp_table -- see blueline_team_current_table_id()) instead of inventing a
 * second way to determine "current," per that same docblock's own
 * direction, keeping this page and the My Account dashboard's division
 * line in agreement. "No current season" is a real, honest answer this
 * function must not paper over with the raw terms.
 *
 * @param int $team_id sp_team post ID.
 * @return string[]
 */
function blueline_sp_team_hero_division_names( int $team_id ): array {
	return blueline_sp_team_hero_decide_division_names( blueline_player_division_name( $team_id ) );
}

/**
 * The team masthead: crest, division(s). Record is deliberately not
 * recomputed here: SportsPress's own standings table (auto-rendered by
 * the_content() below, via team-tables.php) already highlights this team's
 * row with .sp-highlight, which is the correct, already-computed source for
 * W/L/T/PTS rather than a second, hand-rolled aggregation.
 *
 * Finding 13: the hero itself is now a coloured band (fill = team primary,
 * text = the derived on-primary foreground) with a paired blue-line band
 * (Device #2) underneath in team primary + ink, in place of the old
 * border-top/box-shadow approximation. See single-team.php for the second
 * half of finding 13: it prints this same style attribute again on
 * <main>, promoting the custom properties to a scope the_content()'s own
 * league table (rendered as a SIBLING of this <header>, not a descendant)
 * can also see, which is what lets .sp-highlight (sportspress.css) pick up
 * the team's own colour there instead of the theme's generic ice fill.
 *
 * @param int $team_id sp_team post ID.
 */
function blueline_sp_team_hero( $team_id ) {
	$division_names = blueline_sp_team_hero_division_names( $team_id );

	/*
	 * The team's own colour, as scoped custom properties. Returns '' for a
	 * team with no usable `sp_colors`, in which case every rule below falls
	 * back to its theme token and the hero renders exactly as it always has.
	 * See inc/team-colors.php for why the stored palette is not used as-is:
	 * that derivation guard (the fills-vs-boundaries split and the
	 * achromatic withholding) is unchanged by this finding.
	 */
	$team_color_attr = blueline_team_color_style_attr( $team_id );
	?>
	<header class="bl-sp-hero bl-sp-hero--team"<?php echo $team_color_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- blueline_team_color_style_attr() returns a complete, esc_attr()'d style attribute built only from hex values it validated itself. ?>>
		<div class="bl-container bl-sp-hero__inner">
			<?php if ( has_post_thumbnail( $team_id ) ) : ?>
				<div class="bl-sp-hero__crest"><?php echo get_the_post_thumbnail( $team_id, 'medium' ); ?></div>
			<?php else : ?>
				<div class="bl-sp-hero__crest bl-sp-hero__crest--fallback">
					<?php blueline_leaf_mark( 'bl-sp-hero__crest-mark' ); ?>
				</div>
			<?php endif; ?>

			<div class="bl-sp-hero__identity">
				<h1 class="bl-sp-hero__title"><?php echo esc_html( blueline_sp_title( $team_id ) ); ?></h1>
				<?php if ( $division_names ) : ?>
					<p class="bl-sp-hero__meta"><?php echo esc_html( implode( ', ', $division_names ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="bl-band" aria-hidden="true"></div>
		<div class="bl-band--ink" aria-hidden="true"></div>
	</header>
	<?php
}

/**
 * The staff masthead: role(s) and the team(s) they're attached to.
 *
 * @param int $staff_id sp_staff post ID.
 */
function blueline_sp_staff_hero( $staff_id ) {
	$role_names = array();
	if ( taxonomy_exists( 'sp_role' ) ) {
		$role_terms = wp_get_post_terms( $staff_id, 'sp_role' );
		if ( ! is_wp_error( $role_terms ) ) {
			foreach ( $role_terms as $term ) {
				$role_names[] = $term->name;
			}
		}
	}

	$team_ids = array_filter( array_map( 'absint', (array) get_post_meta( $staff_id, 'sp_team', false ) ) );
	?>
	<header class="bl-sp-hero bl-sp-hero--staff">
		<div class="bl-container bl-sp-hero__inner">
			<?php if ( has_post_thumbnail( $staff_id ) ) : ?>
				<div class="bl-sp-hero__crest"><?php echo get_the_post_thumbnail( $staff_id, 'thumbnail' ); ?></div>
			<?php endif; ?>

			<div class="bl-sp-hero__identity">
				<h1 class="bl-sp-hero__title"><?php echo esc_html( blueline_sp_title( $staff_id ) ); ?></h1>
				<?php if ( $role_names ) : ?>
					<p class="bl-sp-hero__meta"><?php echo esc_html( implode( ', ', $role_names ) ); ?></p>
				<?php endif; ?>
				<?php if ( $team_ids ) : ?>
					<ul class="bl-sp-hero__teams">
						<?php
						foreach ( $team_ids as $team_id ) :
							if ( 'publish' !== get_post_status( $team_id ) ) {
								continue;
							}
							?>
							<li>
								<a href="<?php echo esc_url( get_permalink( $team_id ) ); ?>"><?php echo esc_html( blueline_sp_title( $team_id ) ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
	</header>
	<?php
}

/**
 * A compact single-line teaser for one sp_event, used in archive/taxonomy
 * listings (taxonomy-venue.php) where showing every event's full,
 * the_content()-rendered detail would be far too heavy per row.
 *
 * Same P0 finding 2/10 fixes as blueline_sp_event_hero(): "Preview" is
 * gated on the clock via blueline_sp_event_state(), not on whether a score
 * has been entered, and the venue (when the venue archive itself does not
 * already say it via the page title) reads through blueline_venue_label()
 * so a cross-pad "Also plays at this arena" link elsewhere on the page has
 * something concrete to distinguish from.
 *
 * @param int $event_id sp_event post ID.
 */
function blueline_sp_event_teaser( $event_id ) {
	$has_results = function_exists( 'sp_get_status' ) && ( 'results' === sp_get_status( $event_id ) );
	$start_ts    = blueline_sp_event_start_timestamp( $event_id );
	$state       = blueline_sp_event_state( $has_results, $start_ts );
	$is_played   = ( 'final' === $state );

	$status_labels = array(
		'pending' => __( 'Result pending', 'blueline' ),
		'preview' => __( 'Preview', 'blueline' ),
	);

	// Keyed by team ID (blueline_sp_team_result()), not positionally zipped
	// against a shared results array. See that function's own docblock
	// for why a positional pairing can silently attribute one team's score
	// to the other.
	$scores = array();
	if ( $is_played ) {
		$teams = blueline_sp_event_team_ids( $event_id );
		foreach ( $teams as $team_id ) {
			$score = blueline_sp_team_result( $event_id, $team_id );
			if ( null !== $score && '' !== $score ) {
				$scores[] = $score;
			}
		}
	}

	$venue_name = blueline_sp_event_venue_label( $event_id );
	?>
	<a class="bl-sp-event-teaser" href="<?php echo esc_url( get_permalink( $event_id ) ); ?>">
		<span class="bl-sp-event-teaser__date">
			<?php echo esc_html( get_the_date( 'D, M j \a\t g:ia', $event_id ) ); ?>
			<?php if ( $venue_name ) : ?>
				<span class="bl-sp-event-teaser__venue"> · <?php echo esc_html( $venue_name ); ?></span>
			<?php endif; ?>
		</span>
		<span class="bl-sp-event-teaser__title"><?php echo esc_html( blueline_sp_title( $event_id ) ); ?></span>
		<?php if ( $is_played && $scores ) : ?>
			<span class="bl-sp-event-teaser__score"><?php echo esc_html( implode( ' - ', $scores ) ); ?></span>
		<?php else : ?>
			<span class="bl-sp-event-teaser__status"><?php echo esc_html( $status_labels[ $state ] ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}
