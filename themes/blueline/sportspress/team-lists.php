<?php
/**
 * Team roster override -- a CSS grid of player cards instead of
 * SportsPress's own <table class="sp-player-list"> markup.
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
		echo '<h4 class="sp-table-caption">' . esc_html( $list_post->post_title ) . '</h4>';
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
			echo '<h5 class="sp-table-caption bl-sp-team-list__group">' . esc_html( $group->name ) . '</h5>';
		}
		?>
		<ul class="sp-team-list bl-sp-team-list">
			<?php
			foreach ( $rows as $player_id => $row ) :
				$name = ! empty( $row['name'] ) ? wp_strip_all_tags( $row['name'] ) : (string) get_post_field( 'post_title', $player_id, 'raw' );

				if ( ! $name ) {
					continue;
				}

				$number = isset( $row['number'] ) && '' !== $row['number']
					? $row['number']
					: get_post_meta( $player_id, 'sp_number', true );

				$position_label = '';
				if ( ! $group && taxonomy_exists( 'sp_position' ) ) {
					$position_terms = wp_get_post_terms( $player_id, 'sp_position' );
					if ( ! is_wp_error( $position_terms ) && ! empty( $position_terms ) ) {
						$position_label = $position_terms[0]->name;
					}
				}
				?>
				<li class="bl-sp-team-list__card">
					<a class="bl-sp-team-list__link" href="<?php echo esc_url( get_permalink( $player_id ) ); ?>">
						<?php if ( has_post_thumbnail( $player_id ) ) : ?>
							<span class="bl-sp-team-list__photo"><?php echo get_the_post_thumbnail( $player_id, 'thumbnail' ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $number && null !== $number ) : ?>
							<span class="bl-sp-team-list__number"><?php echo esc_html( $number ); ?></span>
						<?php endif; ?>
						<span class="bl-sp-team-list__name"><?php echo esc_html( $name ); ?></span>
						<?php if ( $position_label ) : ?>
							<span class="bl-sp-team-list__position"><?php echo esc_html( $position_label ); ?></span>
						<?php endif; ?>
					</a>
				</li>
				<?php
			endforeach;
			?>
		</ul>
		<?php
	endforeach;
endforeach;
