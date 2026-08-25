<?php
/**
 * Team roster override -- a compact roster LIST instead of SportsPress's own
 * <table class="sp-player-list"> markup, and (finding 8) instead of this
 * theme's own earlier 16-up identical-card grid, which review flagged as
 * the exact "banned pattern" DESIGN.md warns against: a big jersey number as
 * the dominant element, the name shrunk to an afterthought, and the word
 * "SKATER" repeated on every single non-goalie card. A roster is scanned for
 * NAMES, so the name is now the largest, most prominent thing in each row
 * (~18px, the same accent-coloured link treatment team names already get in
 * the standings/schedule tables below), the number is a small fixed-width
 * column (still present, still useful, just no longer shouting), and
 * position is shown only when it is not "Skater" -- i.e. only the goalie
 * actually needs the label, since "Skater" describes almost the entire
 * roster and adds nothing fifteen times over.
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

if ( empty( $lists ) ) {
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
		<ul class="bl-sp-roster">
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
				<li class="bl-sp-roster__row">
					<span class="bl-sp-roster__number"><?php echo ( '' !== $number && null !== $number ) ? esc_html( $number ) : ''; ?></span>
					<a class="bl-sp-roster__name" href="<?php echo esc_url( get_permalink( $player_id ) ); ?>">
						<?php if ( has_post_thumbnail( $player_id ) ) : ?>
							<span class="bl-sp-roster__photo"><?php echo get_the_post_thumbnail( $player_id, 'thumbnail' ); ?></span>
						<?php endif; ?>
						<?php echo esc_html( $name ); ?>
					</a>
					<?php if ( $show_position ) : ?>
						<span class="bl-sp-roster__position"><?php echo esc_html( $position_label ); ?></span>
					<?php endif; ?>
				</li>
				<?php
			endforeach;
			?>
		</ul>
		<?php
	endforeach;
endforeach;
