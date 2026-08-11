<?php
/**
 * The sp_venue taxonomy archive: the venue's street address plus its own list
 * of events, and -- since several venues on this site share one physical
 * arena split into named pads at the same address (e.g. term 14 "Red" and
 * term 13 "Black" both sit at 1179 Northside Rd) -- a cross-link to any
 * sibling pad so a player who lands on the wrong one can find the right
 * game. The venue term's own name already carries the pad distinction; this
 * template does not invent a second "Twin Rinks" label that could drift out
 * of sync with the taxonomy.
 *
 * The main query here is patched via blueline_sp_venue_archive_include_future()
 * (inc/sportspress.php) to include 'future'-status events -- WordPress's
 * default main-query post_status is 'publish' only, which would otherwise
 * silently drop every upcoming game at this venue.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();

$has_sidebar = function_exists( 'blueline_sp_has_sidebar' ) && blueline_sp_has_sidebar();

$queried_term = get_queried_object();
$term_id      = ( $queried_term instanceof WP_Term ) ? $queried_term->term_id : 0;
$address      = '';

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

				<?php if ( have_posts() ) : ?>
					<ul class="bl-sp-event-teaser-list">
						<?php
						while ( have_posts() ) :
							the_post();
							if ( function_exists( 'blueline_sp_event_teaser' ) ) {
								?>
								<li><?php blueline_sp_event_teaser( get_the_ID() ); ?></li>
								<?php
							}
						endwhile;
						?>
					</ul>
					<?php blueline_pagination(); ?>
				<?php else : ?>
					<?php get_template_part( 'content', 'none' ); ?>
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
