<?php
/**
 * Team roster override -- a compact roster table, restyled from
 * SportsPress's own <table class="sp-player-list"> markup and (finding 8)
 * this theme's own earlier 16-up identical-card grid, which review flagged
 * as the exact "banned pattern" DESIGN.md warns against: a big jersey
 * number as the dominant element, the name shrunk to an afterthought, and
 * the word "SKATER" repeated on every single non-goalie card. A roster is
 * scanned for NAMES, so the name is still the largest, most prominent
 * thing in each row (~18px, the same accent-coloured link treatment team
 * names already get in the standings/schedule tables below) even though
 * this is a real <table> again -- see below for why it went back to one.
 * Position is shown only when it is not "Skater" -- i.e. only the goalie
 * actually needs the label, since "Skater" describes almost the entire
 * roster and adds nothing fifteen times over.
 *
 * A REAL <table> now, not a <ul>/<li> grid: live feedback asked for
 * click-to-sort columns, and sp-data-table/sp-sortable-table (the same two
 * classes league-table.php's own mini-standings table on this exact page
 * already uses) are SportsPress's own, already-loaded DataTables wiring
 * (sportspress.js) -- reusing it needs a real <table>/<thead>/<tbody>, and
 * costs no new JS. Per-row stat labels (GP/G/A/PTS/PIM) moved from each
 * cell up into the <thead> once, for the same reason: repeating a column's
 * own label on every row was the "SKATER on every card" problem all over
 * again. Position/"You" badges live INSIDE the name cell, right after the
 * name text, not as their own trailing columns -- live feedback found a
 * separate trailing badge column pushed the row wider than the others.
 *

 * This is a partial, not a page template: SportsPress's own team_content()
 * (SP_Template_Loader, hooked to the_content) calls
 * sportspress_output_team_lists(), which calls sp_get_template(
 * 'team-lists.php' ) with no arguments -- sp_locate_template() finds this
 * theme override first and simply `include`s it in that function's local
 * scope, so $id is not guaranteed to be set (mirrors the plugin's own
 * default team-lists.php, which has the identical guard).
 *
 * Player membership/order/columns are computed by SportsPress's own
 * SP_Player_List::data() -- the same class the plugin's default template
 * uses -- so grouping, filtering and sorting stay correct even though the
 * markup below is entirely custom.
 *
 * Live-site review fix: when SP_Team::lists() has nothing (see the inline
 * comment further down for why that is a separate, easily-forgotten admin
 * step and not the same thing as "this team has no roster"), this falls
 * back to blueline_get_team_roster() -- the account dashboard's own
 * sp_current_team-based roster reader -- rather than declaring the roster
 * unposted while the SAME data shows a real one elsewhere on this site.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $id ) ) {
	$id = get_the_ID(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $id is this template's documented extract()-provided argument name (sp_get_template()'s own convention, mirrored from the plugin's default team-lists.php), not a WordPress global.
}

if ( ! $id || ! class_exists( 'SP_Team' ) || ! class_exists( 'SP_Player_List' ) ) {
	return;
}

$team  = new SP_Team( $id );
$lists = $team->lists();

/*
 * The signed-in viewer's own linked player, if any -- read once, not per
 * row, since it is the same answer for both roster loops below (the
 * SP_Player_List-driven one and the blueline_get_team_roster() fallback).
 * Deliberately no team-colour tinting here (unlike league-table.php's
 * .bl-sp-row--mine): this template only ever renders on a team's OWN
 * single page, already uniformly team-coloured via single-team.php's own
 * blueline_team_color_style_attr() on <main> -- tinting a roster row here
 * too would colour the whole roster and distinguish nothing. A plain "You"
 * text badge is the whole treatment.
 */
$bl_current_user_player_id = function_exists( 'blueline_current_user_player_id' ) ? blueline_current_user_player_id() : null;

/*
 * Live-site review: a team can be fully, correctly rostered on the account
 * dashboard's own My Team module (blueline_get_team_roster(),
 * inc/account/dashboard.php, reading the sp_current_team meta every
 * player carries directly) while this public page still said "Roster not
 * posted yet" for the SAME team -- a real, current contradiction, not a
 * hypothetical.
 *
 * Root cause, confirmed against SportsPress' own class-sp-team.php:
 * SP_Team::lists() answers a genuinely different, more restrictive
 * question than sp_current_team does. It returns only sp_list posts that
 * (a) exist, (b) are scoped to this team (or to "all teams"), AND (c) have
 * been explicitly checked for this team in the team's own admin screen --
 * a separate, manual curation step with no relationship to whether players
 * are actually, correctly assigned to the team via sp_current_team.
 * Checked live on staging (2026-08-22): 4 of 143 published teams currently
 * have real sp_current_team-rostered players (9, 1, 20, and 15 of them)
 * but no checked sp_list at all, so every one of those teams' public pages
 * says "not posted" today.
 *
 * Falling back to the dashboard's own roster reader when SportsPress' own
 * curated-list mechanism has nothing is therefore the correct fix, not a
 * cosmetic one: it uses the SAME underlying data source the account
 * dashboard already treats as authoritative for "who is on this team,"
 * rather than requiring a second, easily-forgotten admin step before a
 * real roster becomes publicly visible. The curated $lists path (grouping,
 * per-list captions, position sections) still takes priority whenever an
 * admin HAS gone through the trouble of curating one -- this is a
 * fallback, not a replacement.
 */
if ( empty( $lists ) ) {
	$fallback_roster = function_exists( 'blueline_get_team_roster' ) ? blueline_get_team_roster( $id ) : array();

	if ( empty( $fallback_roster ) ) {
		?>
		<div class="bl-sp-empty">
			<?php
			if ( function_exists( 'blueline_leaf_mark' ) ) {
				blueline_leaf_mark( 'bl-sp-empty__mark' );
			}
			?>
			<p class="bl-sp-empty__text"><?php esc_html_e( 'Roster not posted yet.', 'blueline' ); ?></p>
		</div>
		<?php
		return;
	}
	?>
	<table class="bl-sp-roster sp-data-table sp-sortable-table">
		<thead>
			<tr>
				<th class="bl-sp-roster__col-number"><?php esc_html_e( '#', 'blueline' ); ?></th>
				<th class="bl-sp-roster__col-name"><?php esc_html_e( 'Player', 'blueline' ); ?></th>
				<?php foreach ( blueline_roster_stat_labels() as $bl_label ) : ?>
					<th class="bl-sp-roster__stat"><?php echo esc_html( $bl_label ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $fallback_roster as $mate ) : ?>
				<?php $number = get_post_meta( $mate['player_id'], 'sp_number', true ); ?>
				<tr>
					<td class="bl-sp-roster__number"><?php echo ( '' !== $number && null !== $number ) ? esc_html( $number ) : ''; ?></td>
					<td class="bl-sp-roster__name-cell">
						<a class="bl-sp-roster__name" href="<?php echo esc_url( get_permalink( $mate['player_id'] ) ); ?>">
							<?php if ( has_post_thumbnail( $mate['player_id'] ) ) : ?>
								<span class="bl-sp-roster__photo"><?php echo get_the_post_thumbnail( $mate['player_id'], 'thumbnail' ); ?></span>
							<?php endif; ?>
							<?php echo esc_html( $mate['name'] ); ?>
						</a>
						<?php if ( $bl_current_user_player_id && $mate['player_id'] === $bl_current_user_player_id ) : ?>
							<span class="bl-sp-roster__you"><?php esc_html_e( 'You', 'blueline' ); ?></span>
						<?php endif; ?>
					</td>
					<?php if ( function_exists( 'blueline_render_roster_stats' ) ) : ?>
						<?php blueline_render_roster_stats( $mate['player_id'] ); ?>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	return;
}

$multiple_lists = count( $lists ) > 1;

// Heading level depends on where this template is actually rendered -- see
// blueline_sp_caption_heading_level()'s own docblock (inc/sportspress.php)
// for the accessibility finding this fixes (team-lists.php's own caption
// used to be a hardcoded h4 that, on a team page, followed the hero's h1
// with nothing else in between). $bl_group_level always trails
// $bl_caption_level by exactly one, whether or not the caption itself is
// actually printed below (it is conditional on $multiple_lists, the group
// heading is not) -- so the group heading is never left one level too deep
// for a caption that never rendered.
$bl_caption_level = function_exists( 'blueline_sp_caption_heading_level' ) ? blueline_sp_caption_heading_level() : 3;
$bl_group_level   = $bl_caption_level + 1;

foreach ( $lists as $list_post ) :
	$list_id  = $list_post->ID;
	$grouping = get_post_meta( $list_id, 'sp_grouping', true );

	$player_list = new SP_Player_List( $list_id );
	$data        = $player_list->data();

	if ( empty( $data ) ) {
		continue;
	}

	unset( $data[0] ); // First row is column labels, not a player.

	if ( empty( $data ) ) {
		continue;
	}

	/*
	 * Modernization sweep finding: $data comes from SP_Player_List::data()
	 * (SportsPress's own stats-table reader), not a WP_Query, so nothing
	 * upstream has primed post/term/meta caches for these player IDs --
	 * the render loop below was hitting get_post_meta()/wp_get_post_terms()/
	 * get_the_post_thumbnail() one player at a time. A 20-25 player roster
	 * -- one of this site's most-visited page types -- cost roughly 2-3
	 * avoidable round trips per player. Primed once per list (covers every
	 * position group within it, since $groups below only filters the same
	 * $data), not per player.
	 */
	$player_ids = array_map( 'absint', array_keys( $data ) );
	if ( $player_ids ) {
		_prime_post_caches( $player_ids, true, true );

		$thumbnail_ids = array_filter( array_map( 'get_post_thumbnail_id', $player_ids ) );
		if ( $thumbnail_ids ) {
			_prime_post_caches( array_map( 'absint', $thumbnail_ids ), false, true );
		}
	}

	$groups = array( null );
	if ( 'position' === $grouping && taxonomy_exists( 'sp_position' ) ) {
		$position_terms = get_terms(
			array(
				'taxonomy'   => 'sp_position',
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $position_terms ) && ! empty( $position_terms ) ) {
			$groups = $position_terms;
		}
	}

	if ( $multiple_lists ) {
		printf( '<h%1$d class="sp-table-caption">%2$s</h%1$d>', $bl_caption_level, esc_html( $list_post->post_title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $bl_caption_level is always the int 2 or 3 blueline_sp_caption_heading_level() returns, never user input; the title is already escaped via esc_html().
	}

	foreach ( $groups as $group ) :
		$rows = array();

		foreach ( $data as $player_id => $row ) {
			if ( $group && ! has_term( $group->term_id, 'sp_position', $player_id ) ) {
				continue;
			}
			$rows[ $player_id ] = $row;
		}

		if ( empty( $rows ) ) {
			continue;
		}

		if ( $group ) {
			printf( '<h%1$d class="sp-table-caption bl-sp-team-list__group">%2$s</h%1$d>', $bl_group_level, esc_html( $group->name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $bl_group_level is always $bl_caption_level + 1 (an int), never user input; the group name is already escaped via esc_html().
		}
		?>
		<table class="bl-sp-roster sp-data-table sp-sortable-table">
			<thead>
				<tr>
					<th class="bl-sp-roster__col-number"><?php esc_html_e( '#', 'blueline' ); ?></th>
					<th class="bl-sp-roster__col-name"><?php esc_html_e( 'Player', 'blueline' ); ?></th>
					<?php foreach ( blueline_roster_stat_labels() as $bl_label ) : ?>
						<th class="bl-sp-roster__stat"><?php echo esc_html( $bl_label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $rows as $player_id => $row ) :
					$name = ! empty( $row['name'] ) ? wp_strip_all_tags( $row['name'] ) : (string) get_post_field( 'post_title', $player_id, 'raw' );

					if ( ! $name ) {
						continue;
					}

					$number = isset( $row['number'] ) && '' !== $row['number']
						? $row['number']
						: get_post_meta( $player_id, 'sp_number', true );

					// Only computed when the list isn't already grouped by
					// position (a group heading of "Goalie" would make a
					// per-row repeat of the same word redundant) -- unchanged
					// from before this finding.
					$position_label = '';
					if ( ! $group && taxonomy_exists( 'sp_position' ) ) {
						$position_terms = wp_get_post_terms( $player_id, 'sp_position' );
						if ( ! is_wp_error( $position_terms ) && ! empty( $position_terms ) ) {
							$position_label = $position_terms[0]->name;
						}
					}

					// "Skater" describes nearly the whole roster on a beginner
					// co-ed league -- showing it on every row is the repeated-
					// caption problem this finding exists to fix. Anything else
					// (Goalie, etc.) is genuinely informative and stays.
					$show_position = ( '' !== $position_label && 0 !== strcasecmp( $position_label, 'Skater' ) );
					?>
					<tr>
						<td class="bl-sp-roster__number"><?php echo ( '' !== $number && null !== $number ) ? esc_html( $number ) : ''; ?></td>
						<td class="bl-sp-roster__name-cell">
							<a class="bl-sp-roster__name" href="<?php echo esc_url( get_permalink( $player_id ) ); ?>">
								<?php if ( has_post_thumbnail( $player_id ) ) : ?>
									<span class="bl-sp-roster__photo"><?php echo get_the_post_thumbnail( $player_id, 'thumbnail' ); ?></span>
								<?php endif; ?>
								<?php echo esc_html( $name ); ?>
							</a>
							<?php if ( $show_position ) : ?>
								<span class="bl-sp-roster__position"><?php echo esc_html( $position_label ); ?></span>
							<?php endif; ?>
							<?php if ( $bl_current_user_player_id && (int) $player_id === $bl_current_user_player_id ) : ?>
								<span class="bl-sp-roster__you"><?php esc_html_e( 'You', 'blueline' ); ?></span>
							<?php endif; ?>
						</td>
						<?php if ( function_exists( 'blueline_render_roster_stats' ) ) : ?>
							<?php blueline_render_roster_stats( $player_id ); ?>
						<?php endif; ?>
					</tr>
					<?php
				endforeach;
				?>
			</tbody>
		</table>
		<?php
	endforeach;
endforeach;
