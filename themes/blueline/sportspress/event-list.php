<?php
/**
 * Theme override of SportsPress's [event_list] shortcode template
 * ([event_list], used on /schedule and embedded on every sp_team page).
 *
 * Finding 9: the stock table repeats the full date on every single row
 * ("August 14, 2026" eleven consecutive times on /schedule), makes date,
 * home, time/results, away AND venue all identical-weight blue links (~4-5
 * links per row across 30 rows), and shows the venue as a bare pad name
 * ("Red") with no arena context. This override:
 *
 *  - Groups rows under a date subheading (in the display face -- Device #2
 *    territory) instead of repeating the date in every row; the per-row
 *    date cell shows only the time, muted to --bl-ink-mid, still a link to
 *    the event (the schedule page's own on-page copy says "Click a time or
 *    date to see the event page" -- that affordance is kept, just no
 *    longer styled with the same weight as an actual team-name link).
 *  - Drops the venue cell to plain --bl-ink-mid text (no link -- team names
 *    are the only thing this table still styles as a link) and names it via
 *    blueline_venue_label() (finding 10) instead of the bare pad name.
 *  - Leaves team-name links exactly as SportsPress renders them (still real
 *    <a> elements, still the accent-coloured link a beginner expects to be
 *    clickable).
 *
 * Reimplementing every column/format permutation SportsPress supports
 * (title_format: title/teams/homeaway/event; time_format: combined/
 * separate/time/results) would be a much larger, higher-risk rewrite than
 * this finding calls for. This site's OWN configuration -- confirmed live
 * (sportspress_event_list_title_format = 'homeaway',
 * sportspress_event_list_time_format = 'combined', and every [event_list]
 * shortcode found on the site, including /schedule's, leaves both at their
 * option default) -- only ever exercises ONE combination. This override
 * handles exactly that combination and defers to SportsPress's own stock
 * template, completely unmodified, for anything else, so a page this task
 * never analysed (a future editor explicitly overriding title_format or
 * time_format in a shortcode attribute) cannot regress.
 *
 * Overrides SportsPress templates/event-list.php, core template version 2.7.23 as of
 * SportsPress Pro 2.7.29; re-check this override when that version changes.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

$bl_title_format = isset( $title_format ) ? $title_format : get_option( 'sportspress_event_list_title_format', 'title' );
$bl_time_format  = isset( $time_format ) ? $time_format : get_option( 'sportspress_event_list_time_format', 'combined' );

if ( ! function_exists( 'SP' ) ) {
	return;
}

if ( 'homeaway' !== $bl_title_format || 'combined' !== $bl_time_format || ! class_exists( 'SP_Calendar' ) ) {
	$bl_stock_template = trailingslashit( SP()->plugin_path() ) . 'templates/event-list.php';
	if ( file_exists( $bl_stock_template ) ) {
		include $bl_stock_template;
	}
	return;
}

$defaults = array(
	'id'                   => null,
	'title'                => false,
	'status'               => 'default',
	'format'               => 'default',
	'date'                 => 'default',
	'date_from'            => 'default',
	'date_to'              => 'default',
	'date_past'            => 'default',
	'date_future'          => 'default',
	'date_relative'        => 'default',
	'day'                  => 'default',
	'league'               => null,
	'season'               => null,
	'venue'                => null,
	'team'                 => null,
	'teams_past'           => null,
	'date_before'          => null,
	'player'               => null,
	'number'               => -1,
	'show_team_logo'       => get_option( 'sportspress_event_list_show_logos', 'no' ) === 'yes',
	'link_events'          => get_option( 'sportspress_link_events', 'yes' ) === 'yes',
	'link_teams'           => get_option( 'sportspress_link_teams', 'no' ) === 'yes',
	'responsive'           => get_option( 'sportspress_enable_responsive_tables', 'no' ) === 'yes',
	'sortable'             => get_option( 'sportspress_enable_sortable_tables', 'yes' ) === 'yes',
	'scrollable'           => get_option( 'sportspress_enable_scrollable_tables', 'yes' ) === 'yes',
	'paginated'            => get_option( 'sportspress_event_list_paginated', 'yes' ) === 'yes',
	'rows'                 => get_option( 'sportspress_event_list_rows', 10 ),
	'order'                => 'default',
	'columns'              => null,
	'show_all_events_link' => false,
	'show_title'           => get_option( 'sportspress_event_list_show_title', 'yes' ) === 'yes',
	'title_format'         => $bl_title_format,
	'time_format'          => $bl_time_format,
);

extract( $defaults, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- mirrors sp_get_template()'s own extract() convention for the args this template receives; EXTR_SKIP never overwrites an already-set variable.

/*
 * Bug found in manual verification: SportsPress's own always-loaded
 * assets/js/sportspress.js initialises every ".sp-data-table.sp-sortable-
 * table" (or ".sp-paginated-table") as a jQuery DataTable, which requires
 * every <tbody> row to have the exact same cell count as the header --
 * confirmed live, this site has both sorting and pagination enabled
 * (sportspress_enable_sortable_tables/sportspress_event_list_paginated),
 * and DataTables' own uncaught "Incorrect column count" alert() on the
 * <tr class="bl-sp-date-heading"> rows below (a single colspan'd <th>, not
 * one <td> per column) hangs the entire page waiting on a blocking native
 * dialog. Sorting/pagination make no sense once rows are grouped under a
 * date heading anyway -- the grouping IS the ordering, and pagination
 * would split a date group across pages -- so both are simply off for this
 * override, regardless of the site-wide option.
 */
$sortable  = false;
$paginated = false;

$calendar = new SP_Calendar( $id );
if ( 'default' !== $status ) {
	$calendar->status = $status;
}
if ( 'default' !== $format ) {
	$calendar->event_format = $format;
}
if ( 'default' !== $date ) {
	$calendar->date = $date;
}
if ( 'default' !== $date_from ) {
	$calendar->from = $date_from;
}
if ( 'default' !== $date_to ) {
	$calendar->to = $date_to;
}
if ( 'default' !== $date_past ) {
	$calendar->past = $date_past;
}
if ( 'default' !== $date_future ) {
	$calendar->future = $date_future;
}
if ( 'default' !== $date_relative ) {
	$calendar->relative = $date_relative;
}
if ( $league ) {
	$calendar->league = $league;
}
if ( $season ) {
	$calendar->season = $season;
}
if ( $venue ) {
	$calendar->venue = $venue;
}
if ( $team ) {
	$calendar->team = $team;
}
if ( $teams_past ) {
	$calendar->teams_past = $teams_past;
}
if ( $date_before ) {
	$calendar->date_before = $date_before;
}
if ( $player ) {
	$calendar->player = $player;
}
if ( 'default' !== $order ) {
	$calendar->order = $order;
}
if ( 'default' !== $day ) {
	$calendar->day = $day;
}

$data       = $calendar->data();
$usecolumns = $calendar->columns;

if ( isset( $columns ) && null !== $columns ) {
	$usecolumns = is_array( $columns ) ? $columns : explode( ',', $columns );
}

if ( $show_title && false === $title && $id ) {
	$caption = $calendar->caption;
	$title   = $caption ? $caption : get_the_title( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $title is this template's documented extract()-provided argument name (mirrors the stock event-list.php this overrides), not a WordPress global.
}

$identifier = uniqid( 'eventlist_' );

// Date-heading row colspan: every printed <th>, Result included (QA C-16).
$bl_col_count = blueline_sp_event_list_column_count( $usecolumns );
?>
<div class="sp-template sp-template-event-list">
	<?php if ( $title ) : ?>
		<?php
		// Heading level depends on where this shortcode/template is
		// actually rendered -- see blueline_sp_caption_heading_level()'s
		// own docblock (inc/sportspress.php) for the accessibility finding
		// this fixes (this caption used to be a hardcoded, level-skipping
		// h4 everywhere).
		$bl_caption_level = blueline_sp_caption_heading_level();
		printf( '<h%1$d class="sp-table-caption">%2$s</h%1$d>', $bl_caption_level, wp_kses_post( $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $bl_caption_level is always the int 2 or 3 blueline_sp_caption_heading_level() returns, never user input; $title is already escaped via wp_kses_post().
		?>
	<?php endif; ?>
	<?php if ( empty( $data ) ) : ?>
		<?php // QA B-14: one line, never a bare header row. ?>
		<div class="bl-sp-empty">
			<?php blueline_leaf_mark( 'bl-sp-empty__mark' ); ?>
			<p class="bl-sp-empty__text">
				<?php
				if ( 'future' === $calendar->status ) {
					esc_html_e( 'No upcoming games scheduled.', 'blueline' );
				} else {
					esc_html_e( 'No games scheduled yet.', 'blueline' );
				}
				?>
			</p>
		</div>
	<?php else : ?>
	<div class="sp-table-wrapper">
		<table class="sp-event-list sp-event-list-format-homeaway sp-data-table bl-sp-schedule<?php echo $paginated ? ' sp-paginated-table' : ''; ?><?php echo $sortable ? ' sp-sortable-table' : ''; ?><?php echo $responsive ? ' sp-responsive-table ' . esc_attr( $identifier ) : ''; ?><?php echo $scrollable ? ' sp-scrollable-table' : ''; ?>" data-sp-rows="<?php echo esc_attr( $rows ); ?>">
			<thead>
				<tr>
					<th class="data-date">
						<?php
						// The date itself now lives in the bl-sp-date-heading
						// row grouping the rows below it; this column shows
						// only the time, so the header says so.
						esc_html_e( 'Time', 'blueline' );
						?>
					</th>
					<?php if ( sp_column_active( $usecolumns, 'event' ) ) : ?>
						<th class="data-home"><?php esc_html_e( 'Home', 'sportspress' ); ?></th>
					<?php endif; ?>
					<?php if ( sp_column_active( $usecolumns, 'time' ) ) : ?>
						<th class="data-time">
						<?php
						/*
						 * "Result", not SportsPress' own "Time/Results".
						 *
						 * SportsPress names this column Time/Results because in
						 * ITS layout the cell falls back to the kick-off time
						 * until a score exists. This template does not: the
						 * date column to the left always shows the time (the
						 * date itself having moved to the bl-sp-date-heading
						 * grouping row), and the cell below only ever prints a
						 * score or an em dash. So the table read as two time
						 * columns, one of which was permanently a dash --
						 * naming it for the one thing it actually contains is
						 * the whole fix.
						 */
						esc_html_e( 'Result', 'blueline' );
						?>
					</th>
					<?php endif; ?>
					<?php if ( sp_column_active( $usecolumns, 'event' ) ) : ?>
						<th class="data-away"><?php esc_html_e( 'Away', 'sportspress' ); ?></th>
					<?php endif; ?>
					<?php if ( sp_column_active( $usecolumns, 'league' ) ) : ?>
						<th class="data-league"><?php esc_html_e( 'League', 'sportspress' ); ?></th>
					<?php endif; ?>
					<?php if ( sp_column_active( $usecolumns, 'season' ) ) : ?>
						<th class="data-season"><?php esc_html_e( 'Season', 'sportspress' ); ?></th>
					<?php endif; ?>
					<?php if ( sp_column_active( $usecolumns, 'venue' ) ) : ?>
						<th class="data-venue"><?php esc_html_e( 'Arena', 'sportspress' ); ?></th>
					<?php else : ?>
						<th style="display:none;" class="data-venue"><?php esc_html_e( 'Arena', 'sportspress' ); ?></th>
					<?php endif; ?>
					<?php if ( sp_column_active( $usecolumns, 'article' ) ) : ?>
						<th class="data-article"><?php esc_html_e( 'Article', 'sportspress' ); ?></th>
					<?php endif; ?>
					<?php if ( sp_column_active( $usecolumns, 'day' ) ) : ?>
						<th class="data-day"><?php esc_html_e( 'Match Day', 'sportspress' ); ?></th>
					<?php endif; ?>
					<?php do_action( 'sportspress_event_list_head_row', $usecolumns ); ?>
				</tr>
			</thead>
			<tbody>
				<?php
				$i                = 0;
				$limit            = ( is_numeric( $number ) && $number > 0 ) ? $number : null;
				$bl_prev_date     = null;
				$bl_reverse_teams = get_option( 'sportspress_event_reverse_teams', 'no' ) === 'yes';

				foreach ( $data as $event ) :
					if ( null !== $limit && $i >= $limit ) {
						continue;
					}

					$bl_date_key = get_post_time( 'Y-m-d', false, $event );
					if ( $bl_date_key !== $bl_prev_date ) {
						$bl_prev_date = $bl_date_key;
						printf(
							'<tr class="bl-sp-date-heading"><th scope="colgroup" colspan="%1$d">%2$s</th></tr>',
							(int) $bl_col_count,
							esc_html( get_post_time( get_option( 'date_format' ), false, $event, true ) )
						);
					}

					$teams        = get_post_meta( $event->ID, 'sp_team', false );
					$video        = get_post_meta( $event->ID, 'sp_video', true );
					$status       = get_post_meta( $event->ID, 'sp_status', true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $status is this loop's own per-event value (mirrors the stock event-list.php this overrides), not a WordPress global.
					$main_results = apply_filters( 'sportspress_event_list_main_results', sp_get_main_results( $event ), $event->ID );

					/*
					 * Finding 5 (live review): a past game with no result
					 * entered yet rendered the exact same blank em dash as a
					 * genuinely future game -- "TBD" and "already played,
					 * waiting on data entry" read identically. Reuses
					 * blueline_sp_event_state() (inc/sportspress.php,
					 * unit-tested in tests/EventStateTest.php), the same
					 * clock-vs-results decision blueline_sp_event_hero()
					 * already uses for the single-event page, so this table
					 * and that page can never disagree about the same event.
					 */
					$bl_start_ts    = blueline_sp_event_start_timestamp( $event->ID );
					$bl_event_state = blueline_sp_event_state( ! empty( $main_results ), $bl_start_ts );

					if ( $bl_reverse_teams ) {
						$main_results = array_reverse( $main_results, true );
					}

					$teams_array = array();
					$bl_has_logo = '';

					if ( $teams ) :
						foreach ( $teams as $t => $team_id ) :
							$name = sp_team_short_name( $team_id );
							if ( ! $name ) {
								continue;
							}

							$name = '<meta itemprop="name" content="' . esc_attr( $name ) . '">' . $name;

							if ( $show_team_logo && has_post_thumbnail( $team_id ) ) :
								$logo        = '<span class="team-logo">' . sp_get_logo(
									$team_id,
									'mini',
									array(
										'itemprop' => 'url',
										'alt'      => '', // Decorative: the team name sits beside it (C-27).
									)
								) . '</span>';
								$name        = $t ? $logo . ' ' . $name : $name . ' ' . $logo;
								$bl_has_logo = ' has-logo';
							endif;

							$team_output = $link_teams
								? '<a href="' . esc_url( get_post_permalink( $team_id ) ) . '" itemprop="url">' . $name . '</a>'
								: $name;

							$team_result = sp_array_value( $main_results, $team_id, null );
							if ( null !== $team_result && $usecolumns && ! in_array( 'time', $usecolumns, true ) ) :
								$team_output .= ' (' . $team_result . ')';
							endif;

							$teams_array[] = $team_output;
						endforeach;
					endif;

					if ( $bl_reverse_teams ) {
						$teams_array = array_reverse( $teams_array, true );
					}
					?>
					<tr class="sp-row sp-post<?php echo 0 === $i % 2 ? ' alternate' : ''; ?> sp-row-no-<?php echo (int) $i; ?>" itemscope itemtype="http://schema.org/SportsEvent">
						<td class="data-date bl-sp-schedule__time" data-label="<?php esc_attr_e( 'Time', 'sportspress' ); ?>">
							<?php if ( $link_events ) : ?>
								<a href="<?php echo esc_url( get_post_permalink( $event->ID, false, true ) ); ?>" itemprop="url">
							<?php endif; ?>
							<time itemprop="startDate" datetime="<?php echo esc_attr( mysql2date( 'Y-m-d\TH:i:sP', $event->post_date ) ); ?>">
								<?php echo esc_html( get_post_time( get_option( 'time_format' ), false, $event ) ); ?>
							</time>
							<?php if ( $link_events ) : ?>
								</a>
							<?php endif; ?>
						</td>
						<?php if ( sp_column_active( $usecolumns, 'event' ) ) : ?>
							<?php $bl_home = array_shift( $teams_array ); ?>
							<td class="data-home<?php echo esc_attr( $bl_has_logo ); ?>" itemprop="competitor" itemscope itemtype="http://schema.org/SportsTeam" data-label="<?php esc_attr_e( 'Home', 'sportspress' ); ?>"><?php echo wp_kses_post( $bl_home ); ?></td>
						<?php endif; ?>
						<?php if ( sp_column_active( $usecolumns, 'time' ) ) : ?>
							<?php // Matches the column header above; this is what the stacked mobile view prints as the row's label. ?>
							<td class="data-time <?php echo esc_attr( $status ); ?>" data-label="<?php esc_attr_e( 'Result', 'blueline' ); ?>">
								<?php if ( ! empty( $main_results ) ) : ?>
									<?php echo wp_kses_post( implode( ' - ', $main_results ) ); ?>
								<?php elseif ( 'pending' === $bl_event_state && blueline_sp_result_still_expected( $bl_start_ts ) ) : ?>
									<?php // Played recently, no result on file yet; older unscored games (QA C-24) fall through to the dash. ?>
									<span class="bl-sp-schedule__pending"><?php esc_html_e( 'Final score coming soon', 'blueline' ); ?></span>
								<?php else : ?>
									&#8212;
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<?php if ( sp_column_active( $usecolumns, 'event' ) ) : ?>
							<?php $bl_away = array_shift( $teams_array ); ?>
							<td class="data-away<?php echo esc_attr( $bl_has_logo ); ?>" itemprop="competitor" itemscope itemtype="http://schema.org/SportsTeam" data-label="<?php esc_attr_e( 'Away', 'sportspress' ); ?>"><?php echo wp_kses_post( $bl_away ); ?></td>
						<?php endif; ?>
						<?php if ( sp_column_active( $usecolumns, 'league' ) ) : ?>
							<td class="data-league" data-label="<?php esc_attr_e( 'League', 'sportspress' ); ?>">
								<?php
								$leagues = get_the_terms( $event->ID, 'sp_league' );
								if ( $leagues ) {
									echo esc_html( implode( ', ', wp_list_pluck( $leagues, 'name' ) ) );
								}
								?>
							</td>
						<?php endif; ?>
						<?php if ( sp_column_active( $usecolumns, 'season' ) ) : ?>
							<td class="data-season" data-label="<?php esc_attr_e( 'Season', 'sportspress' ); ?>">
								<?php
								$seasons = get_the_terms( $event->ID, 'sp_season' );
								if ( $seasons ) {
									echo esc_html( implode( ', ', wp_list_pluck( $seasons, 'name' ) ) );
								}
								?>
							</td>
						<?php endif; ?>
						<?php
						$bl_venues      = taxonomy_exists( 'sp_venue' ) ? get_the_terms( $event->ID, 'sp_venue' ) : array();
						$bl_venue_names = array();
						if ( $bl_venues && ! is_wp_error( $bl_venues ) ) {
							foreach ( $bl_venues as $bl_venue_term ) {
								$bl_venue_names[] = blueline_venue_label( $bl_venue_term->term_id );
							}
						}
						?>
						<?php if ( sp_column_active( $usecolumns, 'venue' ) ) : ?>
							<td class="data-venue" data-label="<?php esc_attr_e( 'Arena', 'sportspress' ); ?>" itemprop="location" itemscope itemtype="http://schema.org/Place">
								<div itemprop="address" itemscope itemtype="http://schema.org/PostalAddress"><?php echo esc_html( implode( ', ', $bl_venue_names ) ); ?></div>
							</td>
						<?php else : ?>
							<td style="display:none;" class="data-venue" data-label="<?php esc_attr_e( 'Arena', 'sportspress' ); ?>" itemprop="location" itemscope itemtype="http://schema.org/Place">
								<div itemprop="address" itemscope itemtype="http://schema.org/PostalAddress"><?php esc_html_e( 'N/A', 'sportspress' ); ?></div>
							</td>
						<?php endif; ?>
						<?php if ( sp_column_active( $usecolumns, 'article' ) ) : ?>
							<td class="data-article" data-label="<?php esc_attr_e( 'Article', 'sportspress' ); ?>">
								<?php if ( $link_events ) : ?>
									<a href="<?php echo esc_url( get_post_permalink( $event->ID, false, true ) ); ?>" itemprop="url">
								<?php endif; ?>
								<?php
								if ( $video ) {
									echo '<div class="dashicons dashicons-video-alt"></div>';
								} elseif ( has_post_thumbnail( $event->ID ) ) {
									echo '<div class="dashicons dashicons-camera"></div>';
								}
								if ( null !== $event->post_content ) {
									echo esc_html( 'publish' === $event->post_status ? __( 'Recap', 'sportspress' ) : __( 'Preview', 'sportspress' ) );
								}
								?>
								<?php if ( $link_events ) : ?>
									</a>
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<?php if ( sp_column_active( $usecolumns, 'day' ) ) : ?>
							<td class="data-day" data-label="<?php esc_attr_e( 'Match Day', 'sportspress' ); ?>">
								<?php
								$bl_day = get_post_meta( $event->ID, 'sp_day', true );
								echo '' === $bl_day ? '-' : wp_kses_post( $bl_day );
								?>
							</td>
						<?php endif; ?>
						<?php do_action( 'sportspress_event_list_row', $event, $usecolumns ); ?>
					</tr>
					<?php
					++$i;
				endforeach;
				?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
	<?php if ( $id && $show_all_events_link ) : ?>
		<div class="sp-calendar-link sp-view-all-link"><a href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php esc_html_e( 'View all events', 'sportspress' ); ?></a></div>
	<?php endif; ?>
</div>
