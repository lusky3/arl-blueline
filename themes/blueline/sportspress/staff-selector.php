<?php
/**
 * Theme override of SportsPress's staff-dropdown template (auto-injected on
 * single sp_staff pages).
 *
 * B-01: the stock template is a bare, unlabelled select that navigates on
 * `change` (arrow keys on a closed select, WCAG 3.2.2).
 * Same treatment as player-selector.php: visible label, themed select, and an
 * explicit "Go" button handled by assets/src/js/player-selector.js.
 *
 * Overrides SportsPress templates/staff-selector.php, core template version 2.7.11 as of
 * SportsPress Pro 2.7.29; re-check this override when that version changes.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( 'no' === get_option( 'sportspress_staff_show_selector', 'yes' ) ) {
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
	'post_type'      => 'sp_staff',
	'numberposts'    => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- one dropdown of every staff member, same as the stock template.
	'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- same.
	'orderby'        => 'title',
	'order'          => 'ASC',
	'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- same shape as the stock template this overrides.
		'relation' => 'AND',
	),
);

if ( $league_ids ) {
	$args['tax_query'][] = array(
		'taxonomy' => 'sp_league',
		'field'    => 'term_id',
		'terms'    => $league_ids,
	);
}

if ( $season_ids ) {
	$args['tax_query'][] = array(
		'taxonomy' => 'sp_season',
		'field'    => 'term_id',
		'terms'    => $season_ids,
	);
}

if ( $team ) {
	$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- same shape as the stock template this overrides.
		array(
			'key'   => 'sp_team',
			'value' => $team,
		),
	);
}

$staffs  = get_posts( $args );
$options = array();

foreach ( (array) $staffs as $staff ) {
	$options[] = '<option value="' . esc_url( get_post_permalink( $staff->ID ) ) . '" ' . selected( $staff->ID, $id, false ) . '>' . esc_html( $staff->post_title ) . '</option>';
}

if ( count( $options ) < 2 ) {
	return;
}

$select_id = 'bl-sp-staff-selector-' . (int) $id;
$roles     = taxonomy_exists( 'sp_role' ) ? wp_get_post_terms( $id, 'sp_role' ) : array();
$bl_role   = ( ! is_wp_error( $roles ) && ! empty( $roles ) ) ? strtolower( $roles[0]->name ) : '';
?>
<div class="sp-template sp-template-staff-selector sp-template-profile-selector bl-sp-player-selector">
	<label class="bl-sp-player-selector__label" for="<?php echo esc_attr( $select_id ); ?>">
		<?php
		echo esc_html(
			$bl_role
				/* translators: %s: staff role in lower case, e.g. "referee". */
				? sprintf( __( 'Jump to another %s', 'blueline' ), $bl_role )
				: __( 'Jump to another staff member', 'blueline' )
		);
		?>
	</label>
	<div class="bl-sp-player-selector__row">
		<select id="<?php echo esc_attr( $select_id ); ?>" class="sp-profile-selector sp-staff-selector bl-sp-player-selector__select">
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
		<button type="button" class="bl-btn bl-btn--secondary bl-sp-player-selector__go">
			<span class="bl-skew"><span><?php esc_html_e( 'Go', 'blueline' ); ?></span></span>
		</button>
	</div>
</div>
