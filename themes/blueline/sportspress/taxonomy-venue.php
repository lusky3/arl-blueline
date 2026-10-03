<?php
/**
 * The sp_venue taxonomy archive: the venue's street address plus its own list
 * of events, and -- since several venues on this site share one physical
 * arena split into named pads at the same address (e.g. term 14 "Red" and
 * term 13 "Black" both sit at 1179 Northside Rd) -- a cross-link to any
 * sibling pad so a player who lands on the wrong one can find the right
 * game. The page's own H1 (get_the_archive_title(), filtered by
 * blueline_sp_venue_archive_title() in inc/sportspress.php) already names
 * the arena via blueline_venue_label(); this template does not invent a
 * second, differently-derived label of its own.
 *
 * P0 finding 3: the main WP_Query that used to drive this template applies
 * WordPress's default `post_date DESC` the moment
 * blueline_sp_venue_archive_include_future() (inc/sportspress.php) adds
 * 'future' to its post_status -- confirmed live, /venue/red opened on the
 * single farthest-future event, five more at that same date, then the next-
 * farthest, with 2,168 mostly-historical events sitting behind all of it.
 * This template now runs two of its OWN WP_Query objects instead of relying
 * on the main query at all: upcoming events (including 'future' status)
 * ordered ascending (soonest first, the way a player actually wants to see
 * what's next), and past events ordered descending (most recent first,
 * paginated) -- the same split single-team.php's own the_content() already
 * gets correct via SportsPress's own event-fixtures-results.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$has_sidebar = blueline_sp_has_sidebar();

$queried_term = get_queried_object();
$term_id      = ( $queried_term instanceof WP_Term ) ? $queried_term->term_id : 0;
$address      = '';
$venue_meta   = array();

if ( $term_id ) {
	$venue_meta = get_option( 'taxonomy_' . $term_id );
	if ( is_array( $venue_meta ) && ! empty( $venue_meta['sp_address'] ) ) {
		$address = (string) $venue_meta['sp_address'];
	}
}

// Find sibling pads: other sp_venue terms sharing this exact address.
$sibling_pads = array();
if ( $term_id && $address && taxonomy_exists( 'sp_venue' ) ) {
	$all_venues = get_terms(
		array(
			'taxonomy'   => 'sp_venue',
			'hide_empty' => false,
		)
	);

	if ( ! is_wp_error( $all_venues ) ) {
		foreach ( $all_venues as $venue_term ) {
			if ( $venue_term->term_id === $term_id ) {
				continue;
			}
			$other_meta    = get_option( 'taxonomy_' . $venue_term->term_id );
			$other_address = ( is_array( $other_meta ) && ! empty( $other_meta['sp_address'] ) ) ? $other_meta['sp_address'] : '';
			if ( $other_address && $other_address === $address ) {
				$sibling_pads[] = $venue_term;
			}
		}
	}
}
?>
<main id="main" class="bl-main bl-main--sp" tabindex="-1">
	<div class="bl-container">
		<div class="bl-content-layout<?php echo $has_sidebar ? ' bl-content-layout--has-sidebar' : ''; ?>">
			<div class="bl-content-layout__primary">

				<header class="bl-archive-header bl-sp-venue-header">
					<?php the_archive_title( '<h1 class="bl-archive-header__title">', '</h1>' ); ?>

					<?php if ( $address ) : ?>
						<p class="bl-sp-venue-header__address"><?php echo esc_html( $address ); ?></p>
					<?php endif; ?>

					<?php
					/*
					 * The map. SportsPress' own event-venue.php renders one on
					 * every EVENT page from exactly this option
					 * (`taxonomy_{$term_id}`, which is where SportsPress keeps a
					 * venue's address and coordinates -- not term meta), but
					 * nothing rendered it on the venue's own page once this
					 * template replaced the default archive, so the one page
					 * actually about the arena was the one page that did not
					 * show where it is. Not a deliberate omission: the header
					 * above documents what this template adds and never
					 * mentions dropping it.
					 *
					 * Rendered through sp_get_template() rather than hand-built
					 * markup so it stays the same map, with the same classes and
					 * the same Leaflet bootstrapping, as the event page's.
					 */
					if ( is_array( $venue_meta )
						&& function_exists( 'sp_get_template' )
						&& 'no' !== get_option( 'sportspress_event_show_maps', 'yes' ) ) :
						?>
						<div class="bl-sp-venue-header__map">
							<?php sp_get_template( 'venue-map.php', array( 'meta' => $venue_meta ) ); ?>
						</div>
						<?php
					endif;
					?>

					<?php if ( $sibling_pads ) : ?>
						<p class="bl-sp-venue-header__siblings">
							<?php esc_html_e( 'Also plays at this arena:', 'blueline' ); ?>
							<?php
							$sibling_links = array();
							foreach ( $sibling_pads as $sibling ) {
								$sibling_links[] = sprintf(
									'<a href="%1$s">%2$s</a>',
									esc_url( get_term_link( $sibling ) ),
									esc_html( $sibling->name )
								);
							}
							echo wp_kses_post( implode( ', ', $sibling_links ) );
							?>
						</p>
					<?php endif; ?>

					<?php the_archive_description( '<div class="bl-archive-header__description">', '</div>' ); ?>
				</header>

				<?php
				$now_mysql       = current_time( 'mysql' );
				$venue_tax_query = array(
					array(
						'taxonomy' => 'sp_venue',
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				);

				// Soonest first. Includes 'future' status -- the hard
				// constraint that upcoming games are post_status = 'future',
				// not 'publish' -- capped rather than paginated, since a
				// venue realistically has a short list of what's coming up,
				// not thousands of rows.
				$upcoming_query = new WP_Query(
					array(
						'post_type'      => 'sp_event',
						'post_status'    => array( 'publish', 'future' ),
						'tax_query'      => $venue_tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- taxonomy archive template; equivalent cost to the main query this replaces.
						'orderby'        => 'date',
						'order'          => 'ASC',
						'date_query'     => array(
							array(
								'column'    => 'post_date',
								'after'     => $now_mysql,
								'inclusive' => true,
							),
						),
						'posts_per_page' => 50,
						'no_found_rows'  => true,
					)
				);

				// Most recent first, paginated -- this is the "2,168 mostly-
				// historical events" bucket, so it gets real pagination
				// rather than dumping the entire history on one page.
				$bl_paged   = max( 1, (int) get_query_var( 'paged' ) );
				$past_query = new WP_Query(
					array(
						'post_type'      => 'sp_event',
						'post_status'    => 'publish',
						'tax_query'      => $venue_tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- taxonomy archive template; equivalent cost to the main query this replaces.
						'orderby'        => 'date',
						'order'          => 'DESC',
						'date_query'     => array(
							array(
								'column'    => 'post_date',
								'before'    => $now_mysql,
								'inclusive' => false,
							),
						),
						'paged'          => $bl_paged,
						'posts_per_page' => (int) get_option( 'posts_per_page' ),
					)
				);

				$has_any_events = $upcoming_query->have_posts() || $past_query->have_posts();
				?>

				<?php if ( ! $has_any_events ) : ?>
					<?php
					// This template already rendered its own H1 above
					// (the_archive_title(), in the header just above), so
					// content-none.php's own heading must not be a second
					// H1 on the page.
					get_template_part( 'content', 'none', array( 'heading_level' => 'h2' ) );
					?>
				<?php endif; ?>

				<?php if ( $upcoming_query->have_posts() ) : ?>
					<h2 class="bl-sp-venue-header__section"><?php esc_html_e( 'Upcoming games', 'blueline' ); ?></h2>
					<ul class="bl-sp-event-teaser-list">
						<?php
						while ( $upcoming_query->have_posts() ) :
							$upcoming_query->the_post();
							?>
							<li><?php blueline_sp_event_teaser( get_the_ID() ); ?></li>
							<?php
						endwhile;
						wp_reset_postdata();
						?>
					</ul>
				<?php endif; ?>

				<?php if ( $past_query->have_posts() ) : ?>
					<h2 class="bl-sp-venue-header__section"><?php esc_html_e( 'Past games', 'blueline' ); ?></h2>
					<ul class="bl-sp-event-teaser-list">
						<?php
						while ( $past_query->have_posts() ) :
							$past_query->the_post();
							?>
							<li><?php blueline_sp_event_teaser( get_the_ID() ); ?></li>
							<?php
						endwhile;
						wp_reset_postdata();
						?>
					</ul>
					<?php
					$pagination_links = paginate_links(
						array(
							'base'      => str_replace( 999999999, '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
							'format'    => '?paged=%#%',
							'current'   => $bl_paged,
							'total'     => (int) $past_query->max_num_pages,
							'prev_text' => '<span aria-hidden="true">&larr;</span> ' . __( 'Newer', 'blueline' ),
							'next_text' => __( 'Older', 'blueline' ) . ' <span aria-hidden="true">&rarr;</span>',
							'type'      => 'list',
						)
					);
					if ( $pagination_links ) :
						?>
						<nav class="bl-pagination" aria-label="<?php esc_attr_e( 'Past games navigation', 'blueline' ); ?>">
							<?php echo wp_kses_post( $pagination_links ); ?>
						</nav>
						<?php
					endif;
					?>
				<?php endif; ?>

			</div>
			<?php if ( $has_sidebar ) : ?>
				<?php get_sidebar(); ?>
			<?php endif; ?>
		</div>
	</div>
</main>
<?php
get_footer();
