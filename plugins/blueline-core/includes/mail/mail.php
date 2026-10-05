<?php
/**
 * Branding for non-WooCommerce mail (Contact Form 7, Gravity Forms, WordPress core): the
 * wp-email-template plugin's option overrides and a plain-text fallback wrapper. Brand colours
 * come from blueline_core_email_brand(); a theme can replace them through the
 * `blueline_core_email_brand` filter.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * The brand colours and logo the mail module uses: hard-coded Blueline token defaults, passed
 * through the `blueline_core_email_brand` filter (a theme supplies its own values there).
 * Invalid hex values fall back to the default for that key.
 *
 * @return array{paper:string,white:string,ink:string,ink_mid:string,accent_text:string,border:string,logo:string}
 */
function blueline_core_email_brand(): array {
	$defaults = array(
		'paper'       => '#F7FBFC',
		'white'       => '#FFFFFF',
		'ink'         => '#132343',
		'ink_mid'     => '#2E4A74',
		'accent_text' => '#3F6E9D',
		'border'      => '#DBE7F0',
		// Soft dependency on the seo-meta module: without it the logo is empty and the template
		// renders without one, so the function_exists() guard must stay.
		'logo'        => function_exists( 'blueline_social_logo_url' ) ? (string) blueline_social_logo_url() : '',
	);

	/**
	 * Filters the email brand: colour keys paper, white, ink, ink_mid, accent_text, border (hex)
	 * and logo (image URL, '' for none).
	 *
	 * @param array $defaults Default brand values.
	 */
	$brand = apply_filters( 'blueline_core_email_brand', $defaults );
	$brand = is_array( $brand ) ? $brand : array();

	foreach ( $defaults as $key => $default ) {
		$value = $brand[ $key ] ?? $default;

		if ( 'logo' === $key ) {
			$brand[ $key ] = is_string( $value ) ? $value : '';
			continue;
		}

		$brand[ $key ] = ( is_string( $value ) && 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) ? $value : $default;
	}

	return $brand;
}

add_filter( 'option_wp_email_template_general', 'blueline_wp_email_template_general_overrides' );
/**
 * Pin the wp-email-template plugin's (a3rev) general settings, used for every wp_mail() sender
 * that is not WooCommerce/FUE (Contact Form 7, Gravity Forms, WordPress core):
 *
 * - `apply_template_all_emails` forced to "yes": the plugin's master switch defaults to "no",
 *   and with it off nothing wraps a plain wp_mail() call in HTML at all.
 * - `apply_for_woo_emails` forced to "no": otherwise the plugin also wraps WooCommerce/FUE mail
 *   in its own off-brand template on top of WooCommerce's.
 * - `background_colour` (the canvas outside the 600px card) repointed to the brand paper colour,
 *   the same token the WooCommerce email styling uses, so every branded email shares one canvas.
 *
 * A targeted merge, never a replacement of the stored option, so any other setting an admin
 * configures there survives.
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
		$value['background_colour']['color'] = blueline_core_email_brand()['paper'];
	}

	return $value;
}

/*
 * There is deliberately no option filter for wp_email_template_style_body/style_header's font and
 * colour keys (content_font, h1_font-h6_font, content_link_colour, base_colour, header_font...):
 * the plugin's rendering code hardcodes them as PHP literals and never reads those options, so
 * filtering them has no effect on a sent mail. The brand values for those properties live in the
 * theme's own email header/footer template overrides instead.
 */

add_filter( 'option_wp_email_template_style_header_image', 'blueline_wp_email_template_style_header_image_overrides' );
/**
 * Repoint the background of the logo band above the header band to the brand paper colour, like
 * the header and the outer canvas.
 *
 * @param mixed $value The stored wp_email_template_style_header_image option value.
 * @return mixed
 */
function blueline_wp_email_template_style_header_image_overrides( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	if ( isset( $value['header_image_background_color'] ) && is_array( $value['header_image_background_color'] ) ) {
		$value['header_image_background_color']['color'] = blueline_core_email_brand()['paper'];
	}

	return $value;
}

/**
 * Whether the wp-email-template plugin is active (its main file defines `WP_EMAIL_TEMPLATE_DIR`
 * unconditionally at load time).
 *
 * @return bool
 */
function blueline_email_template_plugin_active(): bool {
	return defined( 'WP_EMAIL_TEMPLATE_DIR' );
}

/**
 * Whether a wp_mail() call already has HTML content: an explicit `Content-Type: text/html`
 * header, or a message that already looks like an HTML document or fragment. WooCommerce/FUE
 * mail is always complete HTML by the time it reaches wp_mail(), so this skips it without a
 * per-plugin exclusion list.
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
 * Whether a `wp_mail_content_type` filter would override the `text/html` Content-Type this
 * module adds, i.e. some other code forces the mail to another type (typically `text/plain`).
 *
 * Why this is needed: core's wp_mail() applies `wp_mail_content_type` AFTER it has parsed the
 * headers, so the filter's return value beats any Content-Type header, including ours. This
 * module runs earlier (on the `wp_mail` filter), so it cannot see the final type directly.
 * It probes the filter instead, offering the type it would set (`text/html`): a callback that
 * forces `text/plain` (for example WooCommerce's "Plain text" email type, via
 * WC_Email::get_content_type()) returns `text/plain` whatever it is given, while a
 * pass-through or no filter at all hands `text/html` straight back. Probing with `text/plain`
 * would not work, since a forced `text/plain` and "no filter" are then indistinguishable.
 * When the probe does not come back as `text/html` the HTML wrapper would be sent as plain
 * text (raw `<table>` markup in the recipient's inbox), so the caller leaves such mail alone.
 *
 * @return bool True when the wrapper's HTML content type would not survive.
 */
function blueline_email_content_type_is_overridden(): bool {
	return 'text/html' !== apply_filters( 'wp_mail_content_type', 'text/html' );
}

/**
 * Render templates/plain-text-fallback.php. The template reads $subject, $message and
 * $blueline_core_brand from this function's scope.
 *
 * @param string $subject The email's own subject line.
 * @param string $message The plain-text message body.
 * @return string
 */
function blueline_render_plain_text_email_wrapper( string $subject, string $message ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- both are read by plain-text-fallback.php through the include's inherited scope, which this sniff cannot see.
	$blueline_core_brand = blueline_core_email_brand();

	ob_start();
	include BLUELINE_CORE_DIR . '/templates/plain-text-fallback.php';
	return (string) ob_get_clean();
}

/**
 * Wrap a genuinely plain-text wp_mail() call (CF7, Gravity Forms, core notifications) in a
 * minimal branded shell with the site logo.
 *
 * Split from blueline_wrap_plain_text_email_in_brand_template() so the decision-and-transform
 * logic is testable without defining the WP_EMAIL_TEMPLATE_DIR constant.
 *
 * Mail is left untouched when it is already HTML, or when another plugin forces a content type
 * through `wp_mail_content_type` (see blueline_email_content_type_is_overridden()).
 *
 * @param array $args wp_mail()'s own filterable args.
 * @return array
 */
function blueline_maybe_wrap_plain_text_email( array $args ): array {
	if ( blueline_email_is_already_html( $args ) || blueline_email_content_type_is_overridden() ) {
		return $args;
	}

	$args['message'] = blueline_render_plain_text_email_wrapper(
		(string) ( $args['subject'] ?? '' ),
		(string) ( $args['message'] ?? '' )
	);

	$headers = $args['headers'] ?? array();

	if ( ! is_array( $headers ) ) {
		// wp_mail() accepts a newline-separated string but treats each ARRAY element as one header
		// line: split first, or "From: A\r\nReply-To: B" becomes a single malformed header.
		$headers = preg_split( '/\r\n|\r|\n/', trim( (string) $headers ), -1, PREG_SPLIT_NO_EMPTY );
		$headers = false === $headers ? array() : $headers;
	}

	$headers[] = 'Content-Type: text/html; charset=UTF-8';

	$args['headers'] = $headers;

	return $args;
}

add_filter( 'wp_mail', 'blueline_wrap_plain_text_email_in_brand_template' );
/**
 * Wrap plain-text mail in the brand template only when wp-email-template is NOT active. When it
 * is, that plugin's master switch already wraps this mail, and doing both would double-wrap; when
 * it is deactivated, branding must not silently regress to plain text.
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
