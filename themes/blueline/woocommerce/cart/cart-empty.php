<?php
/**
 * Empty cart page.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/cart-empty.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 7.0.1
 *
 * ARL override: themed empty state -- see Cody Edits below.
 *
 * Cody Edits: full replacement of the stock "Your cart is currently empty"
 * notice (core's wc_empty_cart_message(), unhooked from
 * 'woocommerce_cart_is_empty' in inc/woocommerce.php so it doesn't render
 * a second, un-themed notice alongside this one) and the generic
 * "return to shop" link -- this site has no shop page to return to
 * (PRODUCT.md: "no catalogue, no cart-building, no upsell"), so a bare
 * cart was a dead end for a hesitant first-timer right when they're
 * primed to abandon. Reuses the same bl-empty-state pattern (leaf mark,
 * heading, text, button list) that content-none.php and 404.php already
 * use for every other "nothing here" page on this site, rather than
 * inventing a second empty-state design just for the cart. The
 * 'woocommerce_cart_is_empty' action itself is still fired, in its
 * original spot, for compatibility with anything else that hooks into it.
 *
 * Heading is an h2, not h1: the Cart page's own title ("Cart") already
 * renders as the page h1 via content-page.php before this template's
 * output ever appears, so a second h1 here would be a heading-level skip
 * of the kind this theme's a11y sweep has already had to fix once (see
 * abd6866/b61aac5).
 */

defined( 'ABSPATH' ) || exit;
?>

<section class="bl-empty-state bl-empty-state--cart">
	<?php blueline_leaf_mark( 'bl-empty-state__mark' ); ?>

	<h2 class="bl-empty-state__title"><?php esc_html_e( 'Empty net.', 'blueline' ); ?></h2>
	<p class="bl-empty-state__text">
		<?php esc_html_e( 'That just means you haven’t grabbed a spot for the season yet — no rush, but registration’s right this way.', 'blueline' ); ?>
	</p>

	<?php
	/*
	 * @hooked wc_empty_cart_message - 10 (removed, see inc/woocommerce.php)
	 */
	do_action( 'woocommerce_cart_is_empty' );
	?>

	<ul class="bl-empty-state__links">
		<li>
			<a class="bl-btn bl-btn--primary" href="<?php echo esc_url( blueline_resolve_link( 'page_register' ) ); ?>">
				<span class="bl-skew"><span><?php esc_html_e( 'Go to registration', 'blueline' ); ?></span></span>
			</a>
		</li>
	</ul>
</section>
