<?php
/**
 * 404 (not found) template.
 *
 * A friendly empty state using the blue-leaf mark, pointing visitors at
 * Schedule, Standings and Register rather than leaving them stranded.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="bl-main" tabindex="-1">
	<div class="bl-container">
		<section class="bl-empty-state bl-empty-state--404">
			<?php blueline_leaf_mark( 'bl-empty-state__mark' ); ?>

			<h1 class="bl-empty-state__title"><?php esc_html_e( 'That page took a bad bounce', 'blueline' ); ?></h1>
			<p class="bl-empty-state__text">
				<?php esc_html_e( 'We couldn’t find the page you were looking for. Here are a few places to start instead.', 'blueline' ); ?>
			</p>

			<ul class="bl-empty-state__links">
				<li>
					<a class="bl-btn bl-btn--secondary" href="<?php echo esc_url( home_url( '/schedule' ) ); ?>">
						<span class="bl-skew"><span><?php esc_html_e( 'Schedule', 'blueline' ); ?></span></span>
					</a>
				</li>
				<li>
					<a class="bl-btn bl-btn--secondary" href="<?php echo esc_url( home_url( '/standings' ) ); ?>">
						<span class="bl-skew"><span><?php esc_html_e( 'Standings', 'blueline' ); ?></span></span>
					</a>
				</li>
				<li>
					<a class="bl-btn bl-btn--primary" href="<?php echo esc_url( home_url( '/register' ) ); ?>">
						<span class="bl-skew"><span><?php esc_html_e( 'Register to Play', 'blueline' ); ?></span></span>
					</a>
				</li>
			</ul>

			<?php get_search_form(); ?>
		</section>
	</div>
</main>
<?php
get_footer();
