<?php
/**
 * Theme override of SportsPress's player-dropdown template ([player_selector],
 * auto-injected on single-player pages via player_content()).
 *
 * Finding 7: the stock template emits a bare `<select>` with no `<label>`,
 * `aria-label`, or associated text, AND builds its option list with
 * `'meta_key' => 'sp_number', 'orderby' => 'meta_value_num'` set at the TOP
 * LEVEL of the WP_Query args. WordPress treats a top-level 'meta_key' as an
 * implicit meta_query, which silently EXCLUDES every post with no row at all
 * for that key -- confirmed live: /player/alec-lehto has no sp_number meta
 * row, so he never appeared in his own teammates' dropdown, nothing ever got
 * `selected`, and the browser fell back to showing the alphabetically/
 * numerically first OTHER player's name as the select's own visible value
 * ("labelled with a different player's name").
 *
 * Two independent fixes:
 *  1. A visible, properly `for`-associated `<label>`.
 *  2. Fetch by title, then sort in PHP (numbered players first, ascending by
 *     number, exactly the original ordering) -- so a player with no jersey
 *     number on file still appears in their own team's list, and the current
 *     page's own player can therefore always end up `selected`.
 *
 * The redirect behaviour (`.sp-selector-redirect`, SportsPress's own always-
 * loaded assets/js/sportspress.js) is untouched -- same class name, so no JS
 * of this theme's own is needed.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( 'no' === get_option( 'sportspress_player_show_selector', 'yes' ) ) {
	return;
}

if ( ! isset( $id ) ) {
	$id = get_the_ID(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $id is this template's documented extract()-provided argument name (sp_get_template()'s own convention), not a WordPress global.
}

if ( ! function_exists( 'sp_get_the_term_ids' ) ) {
	return;
}

$league_ids = sp_get_the_term_ids( $id, 'sp_league' );
$season_ids = sp_get_the_term_ids( $id, 'sp_season' );
$team       = get_post_meta( $id, 'sp_current_team', true );

$args = array(
	'post_type'      => 'sp_player',
	'numberposts'    => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- player selector deliberately loads all players for a single dropdown; there is no pagination UI to page through.
	'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- same: the dropdown needs every player in one query, not a paginated subset.
	'orderby'        => 'title',
	'order'          => 'ASC',
	'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- same shape as the stock template this overrides.
		'relation' => 'AND',
	),
);

if ( $league_ids ) :
	$args['tax_query'][] = array(
		'taxonomy' => 'sp_league',
		'field'    => 'term_id',
		'terms'    => $league_ids,
	);
endif;

if ( $season_ids ) :
	$args['tax_query'][] = array(
		'taxonomy' => 'sp_season',
		'field'    => 'term_id',
		'terms'    => $season_ids,
	);
endif;

if ( $team ) :
	$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- same shape as the stock template this overrides.
		array(
			'key'   => 'sp_current_team',
			'value' => $team,
		),
	);
endif;

$args = apply_filters( 'sportspress_players_selector_args', $args );

$players = get_posts( $args );

if ( is_array( $players ) ) {
	usort(
		$players,
		static function ( $a, $b ) {
			$a_number = get_post_meta( $a->ID, 'sp_number', true );
			$b_number = get_post_meta( $b->ID, 'sp_number', true );
			$a_has    = ( '' !== $a_number && null !== $a_number );
			$b_has    = ( '' !== $b_number && null !== $b_number );

			if ( $a_has && $b_has ) {
				return (int) $a_number - (int) $b_number;
			}
			if ( $a_has !== $b_has ) {
				return $a_has ? -1 : 1; // Numbered players first, same order the stock query produced.
			}
			return strcasecmp( $a->post_title, $b->post_title );
		}
	);
}

$options = array();

if ( $players && is_array( $players ) ) :
	foreach ( $players as $player ) :
		$name   = $player->post_title;
		$number = get_post_meta( $player->ID, 'sp_number', true );
		if ( isset( $number ) && '' !== $number ) :
			$name = $number . '. ' . $name;
		endif;
		$options[] = '<option value="' . esc_url( get_post_permalink( $player->ID ) ) . '" ' . selected( $player->ID, $id, false ) . '>' . esc_html( $name ) . '</option>';
	endforeach;
endif;

if ( count( $options ) > 1 ) :
	$select_id = 'bl-sp-player-selector-' . (int) $id;
	?>
	<div class="sp-template sp-template-player-selector sp-template-profile-selector bl-sp-player-selector">
		<label class="bl-sp-player-selector__label" for="<?php echo esc_attr( $select_id ); ?>">
			<?php esc_html_e( 'Jump to a teammate', 'blueline' ); ?>
		</label>
		<select id="<?php echo esc_attr( $select_id ); ?>" class="sp-profile-selector sp-player-selector sp-selector-redirect">
			<?php
			echo wp_kses(
				implode( '', $options ),
				array(
					'option' => array(
						'value'    => array(),
						'selected' => array(),
					),
				)
			);
			?>
		</select>
	</div>
	<?php
endif;
