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

// D-17: no gallery at all for an image-less registration (not a white placeholder).
remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
add_action( 'woocommerce_before_single_product_summary', 'blueline_wc_show_product_images', 20 );

/**
 * Whether a product has a featured or gallery image to show.
 *
 * @param mixed $product WC_Product (or anything else, which counts as "unknown").
 * @return bool True unless the product is known to have no image at all.
 */
function blueline_wc_product_has_images( $product ): bool {
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_image_id' ) || ! method_exists( $product, 'get_gallery_image_ids' ) ) {
		return true;
	}

	return (bool) $product->get_image_id() || array() !== (array) $product->get_gallery_image_ids();
}

/**
 * WooCommerce's own gallery output, skipped when it would only be the placeholder.
 *
 * @return void
 */
function blueline_wc_show_product_images(): void {
	if ( function_exists( 'woocommerce_show_product_images' ) && blueline_wc_product_has_images( $GLOBALS['product'] ?? null ) ) {
		woocommerce_show_product_images();
	}
}

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
 * Whether this request can show a WooCommerce product, cart, checkout or
 * account surface -- the only places a PayPal/Google Pay/Apple Pay button or
 * WooCommerce's own styling has anything to render. Includes ordinary pages
 * embedding a product via shortcode or block (e.g. /register's [product_page]).
 *
 * @return bool
 */
function blueline_is_commerce_request(): bool {
	if ( ! function_exists( 'is_woocommerce' ) ) {
		return false;
	}

	if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
		return true;
	}

	return blueline_request_content_contains( array( '[product', '[add_to_cart', '[woocommerce_', '[shop_messages', '[recent_products', '[sale_products', '[best_selling_products', '[top_rated_products', '[featured_products', '[aepfw_bnpl_message', 'wp:woocommerce/' ) );
}

add_action( 'wp_enqueue_scripts', 'blueline_dequeue_commerce_assets', 1000 );
// PayPal's pay-later messaging enqueues from a the_content filter, which this theme runs before wp_head.
add_action( 'wp_print_scripts', 'blueline_dequeue_commerce_assets', PHP_INT_MAX );
add_action( 'wp_print_styles', 'blueline_dequeue_commerce_assets', PHP_INT_MAX );
add_action( 'wp_print_footer_scripts', 'blueline_dequeue_commerce_assets', 1 );
/**
 * PERF-02/PERF-05: drop the PayPal/Google Pay/Apple Pay SDK loaders (~780 KB
 * of third-party script) and WooCommerce's front-end CSS on requests that can
 * render no commerce UI (blueline_is_commerce_request()). Runs after every
 * plugin's own enqueue (PayPal's run up to priority 100) and again just before
 * footer scripts print, for anything enqueued mid-render. Product, cart,
 * checkout, account and product-embedding pages keep everything.
 *
 * @return void
 */
function blueline_dequeue_commerce_assets(): void {
	if ( is_admin() || blueline_is_commerce_request() ) {
		return;
	}

	foreach ( array( 'angelleye_ppcp-common-functions', 'angelleye-paypal-checkout-sdk', 'angelleye_ppcp-apple-pay', 'angelleye_ppcp-google-pay', 'angelleye_ppcp', 'angelleye-pay-later-messaging', 'wc-add-to-cart', 'wc-social-login-frontend' ) as $handle ) {
		wp_dequeue_script( $handle );
	}

	foreach ( array( 'angelleye_ppcp', 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general', 'wc-blocks-style', 'wc-social-login-frontend' ) as $handle ) {
		wp_dequeue_style( $handle );
	}
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

/*
 * D-11: an empty shop/category/tag archive showed only WooCommerce's
 * "No products were found" notice, a dead end. Same bl-empty-state as the
 * cart, pointing at the Register page (catalogue visibility is untouched).
 */
remove_action( 'woocommerce_no_products_found', 'wc_no_products_found' );
add_action( 'woocommerce_no_products_found', 'blueline_wc_no_products_found' );

/**
 * Themed "nothing listed here" state for an empty product archive (D-11).
 * h2: the archive header above it already prints the page's h1.
 */
function blueline_wc_no_products_found(): void {
	?>
	<section class="bl-empty-state bl-empty-state--shop">
		<?php blueline_leaf_mark( 'bl-empty-state__mark' ); ?>
		<h2 class="bl-empty-state__title"><?php esc_html_e( 'Nothing listed here', 'blueline' ); ?></h2>
		<p class="bl-empty-state__text">
			<?php esc_html_e( 'Every season and program is on the Register page.', 'blueline' ); ?>
		</p>
		<ul class="bl-empty-state__links">
			<li>
				<a class="bl-btn bl-btn--primary" href="<?php echo esc_url( blueline_resolve_link( 'page_register' ) ); ?>">
					<span class="bl-skew"><span><?php esc_html_e( 'Go to registration', 'blueline' ); ?></span></span>
				</a>
			</li>
		</ul>
	</section>
	<?php
}

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

/*
 * Live-site UX review finding 5 -- /register's product title and image link
 * nowhere.
 *
 * /register embeds each registration product with WooCommerce's own
 * `[product_page sku="..."]` shortcode (confirmed live: the page's content
 * literally contains `[product_page sku="116522-P"]` /
 * `[product_page sku="116522-G"]`), which renders the SAME
 * content-single-product.php template a real single-product page uses --
 * this theme does not override that template (checked: woocommerce/ has no
 * content-single-product.php or single-product/product-image.php), and
 * neither should it, since the real single-product page at e.g.
 * /registration/player-registration-w2026-27 renders correctly on its own
 * and must keep working exactly as-is.
 *
 * The two hooks below only change anything when is_product() is false --
 * i.e. only on the /register embed, never on that real single-product page
 * -- which is exactly the same "am I on my own singular page or embedded
 * elsewhere" check WooCommerce's own shortcode class already makes (see
 * WC_Shortcodes::product_page()'s `if ( ! is_singular( 'product' ) )`
 * branch, which is why the "Awaiting review"/excerpt already behave
 * differently there today). On /register that makes the title a link to
 * the product's own real permalink, and replaces the image's normal
 * zoom-into-full-size-artwork link with a link to that same permalink --
 * matching how a normal, non-overridden WooCommerce product LOOP links its
 * title and thumbnail, which is what this shortcode is actually being used
 * to imitate here, rather than a real single-product view.
 */
add_action( 'woocommerce_single_product_summary', 'blueline_wc_product_page_shortcode_title_link_open', 4 );
add_action( 'woocommerce_single_product_summary', 'blueline_wc_product_page_shortcode_title_link_close', 6 );
add_filter( 'woocommerce_single_product_image_thumbnail_html', 'blueline_wc_product_page_shortcode_thumbnail_link', 20, 2 );

/**
 * Open a link to the current product's real permalink immediately before
 * `woocommerce_template_single_title()` (hooked at priority 5) prints the
 * `<h1>`, but only when this product is being rendered somewhere other than
 * its own single-product page (the /register shortcode embed).
 */
function blueline_wc_product_page_shortcode_title_link_open() {
	if ( is_product() ) {
		return;
	}

	global $product;
	if ( ! ( $product instanceof WC_Product ) ) {
		return;
	}

	$permalink = get_permalink( $product->get_id() );
	if ( ! $permalink ) {
		return;
	}

	printf(
		'<a href="%s" class="blueline-wc-product-page-title-link">',
		esc_url( $permalink )
	);

	// A11Y-09: buffer the core <h1> title so _close() can demote it under the page's own h1.
	blueline_wc_title_buffer_active( ob_start() );
}

/**
 * Whether _title_link_open() has an output buffer waiting for _close().
 *
 * @param bool|null $set Pass a bool to change the state.
 * @return bool
 */
function blueline_wc_title_buffer_active( ?bool $set = null ): bool {
	static $active = false;

	if ( null !== $set ) {
		$active = $set;
	}

	return $active;
}

/**
 * Turn a product title's <h1> into an <h2> (A11Y-09: /register has its own h1).
 *
 * @param string $html Title markup from woocommerce_template_single_title().
 * @return string
 */
function blueline_wc_demote_product_title( string $html ): string {
	return (string) preg_replace( '#<(/?)h1\b#i', '<$1h2', $html );
}

add_filter( 'the_password_form', 'blueline_wc_password_form_heading', 10, 2 );
/**
 * D-09: a password-protected product page prints only core's password form,
 * with no title at all (axe page-has-heading-one). Give it the product's h1.
 *
 * @param string $output Password form markup.
 * @param mixed  $post   The protected post (WP 5.8+ passes it).
 * @return string
 */
function blueline_wc_password_form_heading( $output, $post = null ) {
	$id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;

	if ( ! $id || ! is_singular( 'product' ) || get_queried_object_id() !== $id ) {
		return $output;
	}

	return '<h1 class="product_title entry-title">' . esc_html( get_the_title( $id ) ) . '</h1>' . $output;
}

/*
 * D-16: the Description tab panel repeated its own tab label as an h2
 * ("Description" under a "Description" tab).
 */
add_filter( 'woocommerce_product_description_heading', '__return_empty_string' );

/**
 * Close the link opened by blueline_wc_product_page_shortcode_title_link_open().
 */
function blueline_wc_product_page_shortcode_title_link_close() {
	if ( is_product() ) {
		return;
	}

	global $product;
	if ( ! ( $product instanceof WC_Product ) ) {
		return;
	}

	if ( blueline_wc_title_buffer_active() ) {
		blueline_wc_title_buffer_active( false );
		echo blueline_wc_demote_product_title( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own already-escaped title markup, only its tag name changed.
	}

	echo '</a>';
}

/**
 * Replace the single-product gallery's own zoom-into-full-size-image link
 * with a link to the product's real permalink, but only off the real
 * single-product page (the /register shortcode embed) -- the real
 * single-product page keeps WooCommerce's own gallery and zoom lightbox
 * completely untouched.
 *
 * @param string $html          Gallery image markup, as built by
 *                               wc_get_gallery_image_html() (a
 *                               `.woocommerce-product-gallery__image` div
 *                               wrapping an `<a href="{full-size image}">`).
 * @param int    $attachment_id Attachment ID for the image being rendered.
 * @return string The unchanged $html on the real single-product page, or a
 *                simple `<a href="{permalink}">{image}</a>` off it.
 */
function blueline_wc_product_page_shortcode_thumbnail_link( $html, $attachment_id ) {
	if ( is_product() ) {
		return $html;
	}

	global $product;
	if ( ! ( $product instanceof WC_Product ) ) {
		return $html;
	}

	$permalink = get_permalink( $product->get_id() );
	if ( ! $permalink ) {
		return $html;
	}

	$image = wp_get_attachment_image( $attachment_id, 'woocommerce_single', false, array( 'class' => 'wp-post-image' ) );
	if ( '' === $image ) {
		return $html;
	}

	// A11Y-05: the title link right below goes to the same place, so this one stays out of the a11y tree.
	return sprintf(
		'<a href="%s" class="blueline-wc-product-page-image-link" tabindex="-1" aria-hidden="true">%s</a>',
		esc_url( $permalink ),
		$image
	);
}

/**
 * This site's own brand tokens (style.css) for every email color/type
 * option WooCommerce's email_improvements-flag styling reads (confirmed
 * enabled on this site: FeaturesUtil::feature_is_enabled(
 * 'email_improvements')). Color values resolve through the admin-tunable
 * brand-color settings (inc/team-colors.php's blueline_resolved_brand_color()),
 * falling back to the theme default when no override is set. The 3 non-color
 * keys (woocommerce_email_header_alignment, woocommerce_email_font_family,
 * woocommerce_email_header_image_width) are pinned literals.
 *
 * The base_color option drives BOTH the CTA button fill AND,
 * unconditionally under email_improvements, the link text color --
 * --bl-ice (#74C0E1) is
 * documented fill-only in style.css (1.94:1 contrast) and would make
 * every email link nearly unreadable if used here. --bl-accent-text
 * (#3F6E9D, 5.13:1, style.css's own "links/accent text on light" token)
 * is the correct value: real WCAG AA link contrast, and WooCommerce's
 * own wc_hex_is_light() check on that value picks white button text
 * automatically -- a solid navy button with white text, both accessible
 * and a normal professional treatment (this theme's own skewed
 * ice-fill/ink-text ribbon uses a CSS transform unsupported in email
 * clients, so a literal port was never viable here).
 *
 * @return array<string,string> option name => value.
 */
function blueline_wc_email_option_overrides(): array {
	return array(
		'woocommerce_email_background_color'      => blueline_resolved_brand_color( 'paper' ),
		'woocommerce_email_body_background_color' => blueline_resolved_brand_color( 'white' ),
		'woocommerce_email_base_color'            => blueline_resolved_brand_color( 'accent_text' ),
		'woocommerce_email_text_color'            => blueline_resolved_brand_color( 'ink' ),
		'woocommerce_email_footer_text_color'     => blueline_resolved_brand_color( 'ink_mid' ),
		'woocommerce_email_header_alignment'      => 'left',
		'woocommerce_email_font_family'           => 'Helvetica',
		'woocommerce_email_header_image_width'    => '96',
	);
}

add_action( 'init', 'blueline_register_wc_email_option_overrides' );
/**
 * Register one option_{name} filter per key in
 * blueline_wc_email_option_overrides() -- WordPress applies
 * `option_{$option}` on every get_option() call for that option, so this
 * pins each value regardless of what's actually stored in wp_options
 * (wp-admin's own Settings > Emails screen still shows and can edit the
 * underlying value; only the runtime value emails actually render with
 * is locked).
 *
 * Wrapped in its own function rather than a bare file-scope foreach --
 * tests/IncTopLevelCallGuardTest.php bans a bare top-level call into a
 * theme-defined function (see that test's own docblock for the live
 * incident it guards against); calling
 * blueline_wc_email_option_overrides() directly inside a top-level
 * foreach is exactly that shape, even though this particular call is
 * pure/side-effect-free.
 */
function blueline_register_wc_email_option_overrides(): void {
	foreach ( blueline_wc_email_option_overrides() as $blueline_email_option => $blueline_email_value ) {
		add_filter(
			"option_{$blueline_email_option}",
			static function () use ( $blueline_email_value ) {
				return $blueline_email_value;
			}
		);
	}
}

/**
 * Email template filenames the PayPal for WooCommerce (AngellEYE) plugin
 * forces to its OWN copy regardless of a theme override, and this theme
 * has its own override for. See
 * blueline_reclaim_paypal_email_template_override()'s own docblock for
 * why this list -- rather than a wildcard match on every
 * `emails/angelleye-*.php` name -- is deliberate.
 *
 * @return string[] Basenames, e.g. 'angelleye-customer-partial-paid-order.php'.
 */
function blueline_paypal_email_templates_to_reclaim(): array {
	return array(
		'angelleye-customer-partial-paid-order.php',
		'angelleye-admin-new-partial-paid-order.php',
	);
}

add_filter( 'woocommerce_locate_template', 'blueline_reclaim_paypal_email_template_override', PHP_INT_MAX, 2 );
/**
 * The PayPal for WooCommerce (AngellEYE) plugin registers its OWN
 * `woocommerce_locate_template` filter
 * (ppcp-gateway/class-angelleye-paypal-ppcp-smart-button.php,
 * angelleye_ppcp_woocommerce_locate_template(), priority 11) that
 * unconditionally forces ITS OWN plugin directory for any email template
 * filename that exists there -- bypassing the standard theme-override
 * lookup `wc_locate_template()` would otherwise have already resolved,
 * confirmed live: `wc_locate_template( 'emails/angelleye-customer-
 * partial-paid-order.php' )` returned the plugin's own file even with a
 * real, correctly-placed override already sitting at
 * woocommerce/emails/angelleye-customer-partial-paid-order.php in this
 * theme. A second filter the SAME plugin registers
 * (angelleye-includes/angelleye-functions.php,
 * ae_override_paypal_email_template(), priority 99999) already does
 * exactly this "check the theme first" reclaim, but only for ONE
 * filename (angelleye-paypal-seller-onboard-invitation.php) -- not the
 * two this theme also overrides.
 *
 * PHP_INT_MAX guarantees this runs after both of that plugin's own
 * filters, so it gets the last word. Scoped to a fixed, explicit list of
 * filenames (blueline_paypal_email_templates_to_reclaim()) this theme
 * KNOWS it has a real override for, not a wildcard on every
 * `angelleye-*.php` name -- a theme override existing is what makes
 * reclaiming correct; guessing at every current and future filename this
 * one plugin might ever add is not.
 *
 * @param string $template      The template path WooCommerce/other filters resolved.
 * @param string $template_name The template name being located (e.g. 'emails/angelleye-customer-partial-paid-order.php').
 * @return string
 */
function blueline_reclaim_paypal_email_template_override( string $template, string $template_name ): string {
	if ( ! in_array( basename( $template_name ), blueline_paypal_email_templates_to_reclaim(), true ) ) {
		return $template;
	}

	$theme_override = get_stylesheet_directory() . '/woocommerce/' . $template_name;

	return file_exists( $theme_override ) ? $theme_override : $template;
}
