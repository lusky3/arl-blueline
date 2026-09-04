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

/*
 * Live-site UX review findings 1 & 2 -- checkout field guidance.
 *
 * "Preferred Division" (arl_division) and "Requested Team"/"Requested
 * Partner" (arl_team/arl_request/arl_request2/arl_request3) are four of the
 * ~16 custom fields the "WooCommerce Checkout Field Editor Pro" plugin (not
 * in this repo -- see this file's own top-level docblock on that boundary)
 * renders inside #customer_details on a live registration checkout. Neither
 * field's problem is a markup bug this theme can edit directly.
 *
 * Field keys and current content were read live off staging
 * (wp_options.thwcfe_sections, decoded via a one-off wp eval-file script
 * against the actual site, not guessed):
 *
 * - arl_division ("Preferred Division", section "player_profile"): a
 *   REQUIRED multiselect of skill levels ("5 - Beginner" .. "1 - Advanced"),
 *   not free text as first suspected from the live review alone -- but it
 *   had no placeholder and no description, so nothing on the page explains
 *   that this choice is what decides which numbered division (see
 *   /standings -- Division 1 through Division 5 on this site) a player is
 *   placed into.
 * - arl_team ("Requested Team"): placeholder was "Team or Captain's name.
 *   Remember: this is only a *request* and not a *guarantee*" -- the actual
 *   caveat that matters, sitting in a placeholder that truncates visually
 *   and disappears entirely once the field has a value.
 * - arl_request / arl_request2 / arl_request3 ("Requested Partner" /
 *   "Additional Partner" x2): all three share the identical placeholder
 *   "Please enter only 1 name... Do not use this field for captains or team
 *   names." -- same mechanism, same fix, applied to all three rather than
 *   just the first for consistency.
 *
 * Each fix is guarded by matching the EXISTING placeholder text (not just
 * the field key) before rewriting it, because the very same field keys are
 * reused, with different and already-fine short placeholders ("Team Name",
 * "Person's Name"), by a separate "waitlist" section on a different
 * product's checkout -- confirmed live in the same thwcfe_sections dump.
 * Matching on content rather than trusting the key alone means this can
 * never touch that other section's fields, even if this site's field
 * config changes which section name is used for which product in the
 * future.
 *
 * TWO filters, not one, are needed to actually change what renders --
 * confirmed live (deployed the `woocommerce_checkout_fields` filter alone
 * first; the checkout page's HTML did not change at all). WooCommerce
 * Checkout Field Editor Pro's custom sections (like "player_profile") are
 * NOT rendered from the array that filter produces: they render via
 * THWCFE_Public_Checkout::output_custom_section_single(), which reads the
 * field's args straight from its own stored section config and calls
 * WooCommerce core's woocommerce_form_field( $name, $field, $value )
 * directly -- and core's woocommerce_form_field() is what applies the
 * `woocommerce_form_field_args` filter, on every field it renders,
 * regardless of where its args array came from. That is the hook that
 * actually reaches the rendered HTML.
 *
 * `woocommerce_checkout_fields` is kept as well, at priority 1100 (WCFE
 * Pro hooks its own callback there at priority 1000 by default -- see
 * THWCFE_Public_Checkout::define_public_hooks() -- so this must run after
 * it, not before it finds anything to fix), because
 * `WC()->checkout->checkout_fields` -- populated via that same filter --
 * is what WCFE Pro's own required-field validation
 * (woo_checkout_fields_validation()) and admin/e-mail field display read
 * back later, and those should see the same corrected copy.
 *
 * blueline_wc_checkout_field_guidance_for_key() is deliberately a pure,
 * one-field-at-a-time function (no WordPress calls beyond __(), which is a
 * no-op pass-through even in the plain-PHPUnit test environment) so it can
 * be unit tested exactly like blueline_homepage_registration_cta_pricing()
 * (tests/RegistrationPricingTest.php) is: real WooCommerce/WCFE array
 * shapes in, asserted array shapes out, no WordPress install required. Both
 * filters below are thin wrappers around it.
 */
add_filter( 'woocommerce_form_field_args', 'blueline_wc_checkout_form_field_guidance', 20, 2 );
add_filter( 'woocommerce_checkout_fields', 'blueline_wc_checkout_field_guidance', 1100 );

/**
 * `woocommerce_form_field_args` wrapper -- the filter that actually reaches
 * the rendered checkout HTML. See the registration comment above for why
 * this is required in addition to (not instead of) the
 * `woocommerce_checkout_fields` filter below.
 *
 * @param array  $args WooCommerce form-field args, as accepted by
 *                      woocommerce_form_field() -- notably 'placeholder'
 *                      and 'description'.
 * @param string $key  The field's id/name (e.g. 'arl_division').
 * @return array $args, with only a matched field's 'placeholder' and/or
 *               'description' entries changed.
 */
function blueline_wc_checkout_form_field_guidance( $args, $key ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}

	return blueline_wc_checkout_field_guidance_for_key( (string) $key, $args );
}

/**
 * `woocommerce_checkout_fields` wrapper -- keeps
 * `WC()->checkout->checkout_fields` (validation, admin/e-mail field
 * display) in sync with the same correction. See the registration comment
 * above for why this alone does not fix the rendered checkout HTML.
 *
 * @param array $fields WooCommerce checkout fields, keyed by section then
 *                       field id (each field id => array of args accepted
 *                       by woocommerce_form_field()).
 * @return array The same shape, with only matched fields' args changed.
 */
function blueline_wc_checkout_field_guidance( array $fields ): array {
	foreach ( $fields as $section => $section_fields ) {
		if ( ! is_array( $section_fields ) ) {
			continue;
		}

		foreach ( $section_fields as $key => $field_args ) {
			if ( is_array( $field_args ) ) {
				$fields[ $section ][ $key ] = blueline_wc_checkout_field_guidance_for_key( (string) $key, $field_args );
			}
		}
	}

	return $fields;
}

/**
 * Move truncating/disappearing checkout-field placeholder caveats into a
 * persistent description, and give the "Preferred Division" field the
 * example text it never had, for exactly one field. See the registration
 * comment above blueline_wc_checkout_form_field_guidance() for the
 * live-audited field keys/content this acts on, and why matching is
 * guarded by the field's EXISTING placeholder content rather than its key
 * alone.
 *
 * @param string $key  The field's id/name (e.g. 'arl_division').
 * @param array  $args WooCommerce form-field args for this one field.
 * @return array $args, unchanged unless this exact field matched.
 */
function blueline_wc_checkout_field_guidance_for_key( string $key, array $args ): array {
	if ( 'arl_division' === $key ) {
		$args['placeholder'] = __( 'Select skill level(s)', 'blueline' );
		$args['description'] = __(
			"This decides which numbered division you're placed in -- select every skill level you'd be comfortable playing at (see /standings for this season's actual divisions).",
			'blueline'
		);

		return $args;
	}

	if ( 'arl_team' === $key && false !== strpos( (string) ( $args['placeholder'] ?? '' ), 'Remember' ) ) {
		$args['placeholder'] = __( "Team or captain's name", 'blueline' );
		$args['description'] = trim(
			( (string) ( $args['description'] ?? '' ) )
			. ' ' . __( 'Remember: this is only a request, not a guarantee.', 'blueline' )
		);

		return $args;
	}

	$is_partner_key = in_array( $key, array( 'arl_request', 'arl_request2', 'arl_request3' ), true );
	if ( $is_partner_key && false !== strpos( (string) ( $args['placeholder'] ?? '' ), 'Please enter only 1 name' ) ) {
		$args['placeholder'] = __( "Person's full name", 'blueline' );
		$args['description'] = trim(
			( (string) ( $args['description'] ?? '' ) )
			. ' ' . __( 'Enter only 1 name -- do not use this field for captains or team names.', 'blueline' )
		);
	}

	return $args;
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
}

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

	return sprintf(
		'<a href="%s" class="blueline-wc-product-page-image-link">%s</a>',
		esc_url( $permalink ),
		$image
	);
}

/**
 * This site's own brand tokens (style.css) for every email color/type
 * option WooCommerce's email_improvements-flag styling reads (confirmed
 * enabled on this site: FeaturesUtil::feature_is_enabled(
 * 'email_improvements')). Pinned from code rather than left as
 * hand-edited wp-admin state -- the same reasoning as every other
 * option_{name} filter in this codebase (e.g. inc/sportspress.php's
 * option_sportspress_league_menu_teams).
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
		'woocommerce_email_background_color'      => '#F7FBFC', // --bl-paper (outer canvas).
		'woocommerce_email_body_background_color' => '#FFFFFF', // --bl-white (card surface).
		'woocommerce_email_base_color'            => '#3F6E9D', // --bl-accent-text (links + buttons).
		'woocommerce_email_text_color'            => '#132343', // --bl-ink (body copy, headings).
		'woocommerce_email_footer_text_color'     => '#2E4A74', // --bl-ink-mid (footer credit line).
		'woocommerce_email_header_alignment'      => 'left', // Matches this site's own left-aligned heading convention.
		'woocommerce_email_font_family'           => 'Helvetica', // Closest of WooCommerce's fixed EmailFont::$font list to Inter/system-ui.
		'woocommerce_email_header_image_width'    => '96', // Sized for the real uploaded logo's own aspect ratio.
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

add_filter( 'option_wp_email_template_general', 'blueline_wp_email_template_disable_woo_wrapping' );
/**
 * The wp-email-template plugin (a3rev) ALSO wraps WooCommerce/Follow-Up
 * Emails output with its own generic template on top of WooCommerce's own
 * (confirmed live: wp_email_template_general's own apply_for_woo_emails
 * was "yes") -- off-brand styling (Verdana/Century-Gothic-italic,
 * #1155CC links) competing with the option overrides above. Turns off
 * ONLY that one integration, not the whole plugin (it may still be the
 * right tool for some other wp_mail() sender this site uses) and not
 * the whole stored option (a targeted merge, so any other setting an
 * admin configures there later survives).
 *
 * @param mixed $value The stored wp_email_template_general option value.
 * @return mixed
 */
function blueline_wp_email_template_disable_woo_wrapping( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$value['apply_for_woo_emails'] = 'no';

	return $value;
}
