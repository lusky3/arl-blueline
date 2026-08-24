<?php
/**
 * WooCommerce integration for the ported templates in woocommerce/ (Task 9).
 *
 * Task 9 is a port, not a redesign: every template in woocommerce/ was
 * copied byte-identical from production's rookie-child theme (verified by
 * md5sum before any edit -- see the Task 9 report) and is restyled only via
 * tokens/classes in assets/src/css/woocommerce.css. This file supplies the
 * small number of parent-theme dependencies those templates relied on that
 * blueline, a standalone theme, does not inherit for free:
 *
 * - blueline_get_sidebar_setting() replaces rookie-child's
 *   rookie_get_sidebar_setting() (used by woocommerce/archive-product.php).
 *   Production's `themeboy` option has `sidebar` set to '' (empty), so
 *   rookie_get_sidebar_setting() has always resolved to its own default of
 *   'right' (or 'left' under RTL -- not applicable to this English-only
 *   site). This reproduces that same effective value without carrying over
 *   rookie's unused theme-options admin screen.
 *
 * - blueline_wc_wrapper_start()/_end() replace WooCommerce core's default
 *   wrapper (woocommerce_output_content_wrapper()/_end(), registered in the
 *   plugin's includes/wc-template-hooks.php). Neither rookie nor
 *   rookie-child ever registered a slug with wc_get_theme_slug_for_templates()
 *   or overrode woocommerce/global/wrapper-start.php / wrapper-end.php, so
 *   on production that default wrapper always falls through to its generic
 *   `<div id="primary" class="content-area"><main id="main" class="site-
 *   main" role="main">` markup -- which, on the shop archive, is ALSO
 *   wrapped a second time by archive-product.php's own inline `#primary`/
 *   `#main` markup, producing duplicate ids on that page today. Removing
 *   the default wrapper and supplying a theme-specific one is WooCommerce's
 *   documented integration path (see "Theme wrapper hooks",
 *   https://woocommerce.com/document/template-structure/); doing so here
 *   also gives WooCommerce pages the same `#main.bl-main` > `.bl-container`
 *   structure page.php/archive.php already use, which is what the Step 4
 *   token restyle targets. archive-product.php's own inline wrapper was
 *   removed to match (see that file). This is a DOM-structure/presentation
 *   fix only -- no hook tied to cart, checkout, or order processing is
 *   touched, and no markup inside the ported templates themselves changes.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

if ( ! function_exists( 'blueline_get_sidebar_setting' ) ) {
	/**
	 * Sidebar-position class suffix for the shop archive template.
	 *
	 * Replaces rookie-child's rookie_get_sidebar_setting(); see the file
	 * docblock above for why 'right' (or 'left' under RTL) is always the
	 * effective value on this site.
	 *
	 * @return string 'left' or 'right'.
	 */
	function blueline_get_sidebar_setting() {
		return is_rtl() ? 'left' : 'right';
	}
}

remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
add_action( 'woocommerce_before_main_content', 'blueline_wc_wrapper_start' );
add_action( 'woocommerce_after_main_content', 'blueline_wc_wrapper_end' );

/*
 * P4 finding 4 -- both core's default single-product.php and the ported
 * archive-product.php (see that file's own docblock) call
 * do_action( 'woocommerce_sidebar' ) AFTER do_action( 'woocommerce_after_main_content' ),
 * i.e. after blueline_wc_wrapper_end() has already closed .bl-container/
 * #main. `woocommerce_get_sidebar()` (core's hooked callback, priority 10)
 * then calls get_sidebar(), and this theme's sidebar.php renders
 * `<aside class="bl-sidebar">` with no ancestor container at all -- measured
 * live on /registration/player-registration-w2026-27 at 1280px:
 * `.bl-sidebar` was x=0/width=1280 while the product above it was
 * x=64/width=1152. Wrapping the same 'woocommerce_sidebar' action (priority
 * 5/15, straddling core's own 10) gives it the identical container every
 * other page uses, without touching sidebar.php itself (not a Task 9 port
 * file, but shared with every non-WooCommerce template) or restructuring
 * either ported template.
 */
add_action( 'woocommerce_sidebar', 'blueline_wc_sidebar_wrapper_start', 5 );
add_action( 'woocommerce_sidebar', 'blueline_wc_sidebar_wrapper_end', 15 );

add_action( 'wp_enqueue_scripts', 'blueline_dequeue_cart_fragments', 20 );
/**
 * Drop WooCommerce core's `wc-cart-fragments` script everywhere except
 * cart/checkout. That script's entire job is refreshing a mini-cart
 * fragment via an AJAX round-trip to admin-ajax.php on every page load --
 * this theme has no mini-cart, no cart icon, and no `.widget_shopping_cart`
 * anywhere (header.php carries no cart markup at all), so on every other
 * page -- schedule, standings, a team or player page, which is most of
 * this site's real traffic -- it was pure dead weight: a script parse/exec
 * plus a same-origin POST that also sets the `woocommerce_cart_hash`
 * cookie on an otherwise-anonymous, cacheable visitor. Priority 20 runs
 * after WC core's own registration (`WC_Frontend_Scripts::load_scripts()`,
 * priority 10), which is required for `wp_dequeue_script()` to find
 * anything to remove.
 */
function blueline_dequeue_cart_fragments() {
	if ( is_cart() || is_checkout() ) {
		return;
	}

	wp_dequeue_script( 'wc-cart-fragments' );
}

/**
 * Open a container around whatever `do_action( 'woocommerce_sidebar' )`
 * renders (core's `woocommerce_get_sidebar()` -> `get_sidebar()` ->
 * sidebar.php's `.bl-sidebar`), matching the `.bl-container` gutter/max-width
 * every other page's sidebar sits inside. See the 'woocommerce_sidebar'
 * registration above for why this can't just live inside
 * blueline_wc_wrapper_start()/_end() -- the sidebar renders after that
 * wrapper has already closed.
 */
function blueline_wc_sidebar_wrapper_start() {
	echo '<div class="bl-container bl-container--wc-sidebar">';
}

/**
 * Close the container opened by blueline_wc_sidebar_wrapper_start().
 */
function blueline_wc_sidebar_wrapper_end() {
	echo '</div>';
}

/**
 * Open the WooCommerce content wrapper. Matches the `#main.bl-main` >
 * `.bl-container` structure used by page.php/archive.php so WooCommerce
 * pages sit in the same page width/gutter as the rest of the theme.
 */
function blueline_wc_wrapper_start() {
	?>
	<main id="main" class="bl-main bl-main--woocommerce" tabindex="-1">
		<div class="bl-container">
	<?php
}

/**
 * Close the WooCommerce content wrapper opened by blueline_wc_wrapper_start().
 */
function blueline_wc_wrapper_end() {
	?>
		</div>
	</main>
	<?php
}

/*
 * woocommerce/cart/cart-empty.php renders its own themed message and
 * registration CTA in place of the stock "Your cart is currently empty"
 * notice + "return to shop" link (this site has no shop page to return
 * to). wc_empty_cart_message() is core's callback on
 * 'woocommerce_cart_is_empty' (priority 10) that prints that stock
 * notice; removing it here, rather than editing the override template to
 * suppress it, keeps that action available for anything else that might
 * hook into it -- the override template still fires it, just with core's
 * own listener gone.
 */
remove_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message' );

add_action( 'woocommerce_before_checkout_form', 'blueline_checkout_reassurance' );
/**
 * A short reassurance note above the checkout form -- the same nervous
 * first-timer persona the homepage's 'new_here' module exists to calm (see
 * blueline_homepage_module_new_here() in inc/homepage-modules.php), except
 * here they are one field away from actually paying, which was previously
 * the one place in the whole registration flow with zero brand-voice copy
 * anywhere on the page (see this file's own docblock on woocommerce/ being
 * a byte-identical port). Hooked onto 'woocommerce_before_checkout_form'
 * rather than edited into the ported woocommerce/checkout/form-checkout.php
 * template, so that file's "byte-identical port" claim stays true and this
 * note can be found/changed in one place instead of inside vendor-shaped
 * markup.
 */
function blueline_checkout_reassurance() {
	?>
	<div class="bl-checkout-reassurance">
		<p>
			<?php esc_html_e( 'Whether this is your first season or your fifth, this one payment covers the whole season: jersey, socks, officials, and ice time. Nothing else to pay after this.', 'blueline' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'You’ll get an email confirmation right away, and your team, schedule, and roster show up in My Account once the season’s set. Still nervous? That’s normal — ask us anything, or just show up and skate.', 'blueline' ); ?>
		</p>
	</div>
	<?php
}
