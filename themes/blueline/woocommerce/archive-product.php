<?php
/**
 * The template for displaying all WooCommerce pages.
 *
 * Ported from rookie-child/woocommerce/archive-product.php (Task 9): the
 * loop, hooks and filters below are unchanged from production. Two
 * presentation-only fixes were made against the parent-theme original (see
 * inc/woocommerce.php for the full rationale):
 *
 * 1. The removed parent theme's sidebar-position helper is replaced with the
 *    blueline equivalent, `blueline_get_sidebar_setting()` (see
 *    inc/woocommerce.php for the old function name and full rationale),
 *    which resolves to the same value.
 * 2. The manual `<div id="primary">`/`<main id="main">` wrapper this file
 *    used to duplicate around the theme's own wrapper (opened by the
 *    `woocommerce_before_main_content` hook below) has been removed. That
 *    wrapper now comes exclusively from blueline_wc_wrapper_start()/_end(),
 *    matching stock WooCommerce's own archive-product.php, which never
 *    duplicates it either.
 *
 * @package blueline
 * @version 6.0.0
 */

get_header( 'shop' );

/**
 * Fires the woocommerce_before_main_content hook.
 *
 * @hooked blueline_wc_wrapper_start - 10 (opens the theme's #main.bl-main > .bl-container wrapper; see inc/woocommerce.php)
 * @hooked woocommerce_breadcrumb - 20
 */
do_action( 'woocommerce_before_main_content' );
?>

	<div class="woocommerce-shop-content content-area-<?php echo esc_attr( blueline_get_sidebar_setting() ); ?>-sidebar">

			<?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>

				<header class="page-header">

					<h1 class="page-title"><?php woocommerce_page_title(); ?></h1>

				</header><!-- .page-header -->

			<?php endif; ?>

			<?php do_action( 'woocommerce_archive_description' ); ?>

			<?php if ( have_posts() ) : ?>

				<?php
					/**
					 * Fires the woocommerce_before_shop_loop hook.
					 *
					 * @hooked woocommerce_result_count - 20
					 * @hooked woocommerce_catalog_ordering - 30
					 */
					do_action( 'woocommerce_before_shop_loop' );
				?>

				<?php woocommerce_product_loop_start(); ?>

					<?php woocommerce_product_subcategories(); ?>

					<?php
					while ( have_posts() ) :
						the_post();
						?>

						<?php wc_get_template_part( 'content', 'product' ); ?>

					<?php endwhile; // end of the loop. ?>

				<?php woocommerce_product_loop_end(); ?>

				<?php
					/**
					 * Fires the woocommerce_after_shop_loop hook.
					 *
					 * @hooked woocommerce_pagination - 10
					 */
					do_action( 'woocommerce_after_shop_loop' );
				?>

				<?php
			elseif ( ! woocommerce_product_subcategories(
				array(
					'before' => woocommerce_product_loop_start( false ),
					'after'  => woocommerce_product_loop_end( false ),
				)
			) ) :
				?>

				<?php wc_get_template( 'loop/no-products-found.php' ); ?>

			<?php endif; ?>

	</div><!-- .woocommerce-shop-content -->

<?php
	/**
	 * Fires the woocommerce_after_main_content hook.
	 *
	 * @hooked blueline_wc_wrapper_end - 10 (closes the wrapper opened above; see inc/woocommerce.php)
	 */
	do_action( 'woocommerce_after_main_content' );
?>

<?php do_action( 'woocommerce_sidebar' ); ?>
<?php get_footer( 'shop' ); ?>