<?php
/**
 * Branding for non-WooCommerce mail: the wp-email-template plugin's
 * option overrides and the theme's own plain-text fallback wrapper.
 *
 * Kept out of inc/woocommerce.php, which returns early without
 * WooCommerce: Contact Form 7, Gravity Forms and core mail need none of it.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'option_wp_email_template_general', 'blueline_wp_email_template_general_overrides' );
/**
 * The wp-email-template plugin (a3rev) is this site's one general-purpose
 * HTML-mail wrapper for every wp_mail() sender that isn't WooCommerce/FUE
 * (those already have their own branded system, the option overrides in
 * inc/woocommerce.php) -- Contact Form 7, Gravity Forms' remaining notifications, and
 * WordPress core's own mail all go out through it. Two distinct things
 * this pins in the same option:
 *
 * - `apply_template_all_emails` forced to "yes": the plugin's own master
 *   switch, confirmed live to be "no" by default -- with it off, NOTHING
 *   wraps a plain wp_mail() call in HTML at all, which is why an
 *   unmodified CF7 test submission arrived as bare plain text.
 * - `apply_for_woo_emails` forced to "no", unchanged from before: the
 *   plugin ALSO wraps WooCommerce/Follow-Up Emails output with its own
 *   generic template on top of WooCommerce's own (confirmed live:
 *   wp_email_template_general's own apply_for_woo_emails was "yes") --
 *   off-brand styling (Verdana/Century-Gothic-italic, #1155CC links)
 *   competing with the WooCommerce option overrides. Turning the master switch
 *   on makes this exclusion load-bearing in a way it wasn't before
 *   (previously the master switch being off already prevented any
 *   wrapping, Woo included).
 *
 * `background_colour` (the outermost canvas, outside the 600px card) is
 * also repointed to --bl-paper here, matching the same token the
 * WooCommerce email overrides (inc/woocommerce.php) use for the identical role, so every
 * branded email on the site -- Woo, FUE, or this plugin's -- shares one
 * outer-canvas colour.
 *
 * A targeted merge throughout, never a wholesale replacement of the
 * stored option, so any other setting an admin configures there later
 * (email_container_width, outlook_apply_border, etc.) survives untouched.
 *
 * @param mixed $value The stored wp_email_template_general option value.
 * @return mixed
 */
function blueline_wp_email_template_general_overrides( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$value['apply_template_all_emails'] = 'yes';
	$value['apply_for_woo_emails']      = 'no';

	if ( isset( $value['background_colour'] ) && is_array( $value['background_colour'] ) ) {
		$value['background_colour']['color'] = '#F7FBFC'; // --bl-paper.
	}

	return $value;
}

/*
 * No option-filter for wp_email_template_style_body/style_header's own
 * font/colour keys (content_font, h1_font-h6_font, content_link_colour,
 * content_background_colour, base_colour, header_font) -- confirmed live,
 * by reading the plugin's own classes/class-email-functions.php, that its
 * rendering code hardcodes ALL of those as PHP literals in its own
 * shortcode-replacement array and never reads them from these options at
 * all, despite a real, working admin settings UI for them. A first attempt
 * at filtering those options (like every other override in this file) had
 * zero effect on an actual sent test email for exactly this reason. The
 * theme's own emails/email_header.html and emails/email_footer.html --
 * the plugin's own theme-override mechanism, the same one WooCommerce
 * templates in this theme already use -- carry the real brand values for
 * those specific properties instead; see that file's own docblock for the
 * full trace.
 */

add_filter( 'option_wp_email_template_style_header_image', 'blueline_wp_email_template_style_header_image_overrides' );
/**
 * The logo band sitting above the header band -- this site's logo image
 * itself is already correctly configured (an admin-uploaded attachment,
 * not this theme's concern), only its own background colour is
 * repointed here, to the same --bl-paper token as the header and outer
 * canvas, for the same reason.
 *
 * @param mixed $value The stored wp_email_template_style_header_image option value.
 * @return mixed
 */
function blueline_wp_email_template_style_header_image_overrides( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	if ( isset( $value['header_image_background_color'] ) && is_array( $value['header_image_background_color'] ) ) {
		$value['header_image_background_color']['color'] = '#F7FBFC'; // --bl-paper.
	}

	return $value;
}

/**
 * Whether the wp-email-template plugin is active on this install --
 * `WP_EMAIL_TEMPLATE_DIR` is a constant its own main plugin file defines
 * unconditionally at load time, the same detection shape this theme
 * already uses for WooCommerce (`class_exists( 'WooCommerce' )`).
 *
 * @return bool
 */
function blueline_email_template_plugin_active(): bool {
	return defined( 'WP_EMAIL_TEMPLATE_DIR' );
}

/**
 * Whether a wp_mail() call has already been given HTML content -- either
 * an explicit `Content-Type: text/html` header, or a message that already
 * looks like a real HTML document/fragment. WooCommerce/FUE's own emails
 * are always already-complete HTML by the time they reach wp_mail() (this
 * theme's own woocommerce/emails/*.php templates, or FUE's "WooCommerce"
 * template mode inheriting the same), so this is the generic signal that
 * lets blueline_maybe_wrap_plain_text_email() below skip them without
 * needing a per-plugin exclusion list the way wp-email-template's own
 * `apply_for_woo_emails` toggle does.
 *
 * @param array $args wp_mail()'s own filterable args: to, subject, message, headers, attachments.
 * @return bool
 */
function blueline_email_is_already_html( array $args ): bool {
	$headers = $args['headers'] ?? '';
	$headers = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;

	if ( false !== stripos( $headers, 'text/html' ) ) {
		return true;
	}

	return (bool) preg_match( '/<\s*(?:html|body|table|div|p)\b/i', (string) ( $args['message'] ?? '' ) );
}

/**
 * Renders themes/blueline/emails/plain-text-fallback.php with $subject/
 * $message in scope, the same "plain PHP template, variables via the
 * calling scope" convention this theme's own woocommerce/emails/*.php
 * templates already use -- not a shortcode-replacement string, since this
 * template has no wp-email-template-specific placeholder syntax to honour.
 *
 * @param string $subject The email's own subject line.
 * @param string $message The plain-text message body.
 * @return string
 */
function blueline_render_plain_text_email_wrapper( string $subject, string $message ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- both ARE used, read by plain-text-fallback.php via PHP's own include-inherits-calling-scope behaviour (see that file's own @var docblock), which this sniff cannot see statically.
	ob_start();
	include BLUELINE_DIR . '/emails/plain-text-fallback.php';
	return (string) ob_get_clean();
}

/**
 * Wraps a genuinely plain-text wp_mail() call (CF7, Gravity Forms, WP
 * core's own notification emails -- none of which have any HTML template
 * of their own) in this theme's own minimal branded shell, using the
 * theme's own site logo (blueline_social_logo_url(), inc/social-meta.php)
 * rather than any plugin-configured logo setting.
 *
 * Split from blueline_wrap_plain_text_email_in_brand_template() below so
 * this pure decision-and-transform logic is directly testable without
 * needing to fake WP_EMAIL_TEMPLATE_DIR (a real constant a test cannot
 * safely define and later undefine).
 *
 * @param array $args wp_mail()'s own filterable args.
 * @return array
 */
function blueline_maybe_wrap_plain_text_email( array $args ): array {
	if ( blueline_email_is_already_html( $args ) ) {
		return $args;
	}

	$args['message'] = blueline_render_plain_text_email_wrapper(
		(string) ( $args['subject'] ?? '' ),
		(string) ( $args['message'] ?? '' )
	);

	$headers   = $args['headers'] ?? array();
	$headers   = is_array( $headers ) ? $headers : ( '' === (string) $headers ? array() : array( (string) $headers ) );
	$headers[] = 'Content-Type: text/html; charset=UTF-8';

	$args['headers'] = $headers;

	return $args;
}

add_filter( 'wp_mail', 'blueline_wrap_plain_text_email_in_brand_template' );
/**
 * The theme-owned fallback for exactly the gap
 * blueline_wp_email_template_general_overrides() otherwise fills: if
 * wp-email-template is active, this defers to it entirely (that plugin's
 * own master switch is what actually wraps CF7/Gravity Forms/WP-core mail
 * in HTML in that case) and does nothing here, so the two mechanisms
 * never compete. Only when that plugin is NOT active does this theme wrap
 * plain-text mail itself, so branding never silently regresses to plain
 * text if that plugin is ever deactivated or removed -- discovered as a
 * real risk during this feature's own development.
 *
 * @param array $args wp_mail()'s own filterable args.
 * @return array
 */
function blueline_wrap_plain_text_email_in_brand_template( array $args ) {
	if ( blueline_email_template_plugin_active() ) {
		return $args;
	}

	return blueline_maybe_wrap_plain_text_email( $args );
}
