<?php
/**
 * Brand colours for the blueline-core plugin's mail module.
 *
 * The plain-text-to-HTML wrapper and the wp-email-template pins live in the
 * plugin (plugins/blueline-core/includes/mail/mail.php); this theme only
 * supplies its own brand tokens, which follow the admin-tunable brand-colour
 * settings (inc/team-colors.php). Without the plugin this file does nothing.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'blueline_core_email_brand', 'blueline_core_email_brand_tokens' );
/**
 * Fill the plugin's email brand array with this theme's resolved brand colours.
 *
 * @param mixed $brand The plugin's default brand array.
 * @return mixed
 */
function blueline_core_email_brand_tokens( $brand ) {
	if ( ! is_array( $brand ) ) {
		return $brand;
	}

	foreach ( array( 'paper', 'white', 'ink', 'ink_mid', 'accent_text' ) as $token ) {
		$color = blueline_resolved_brand_color( $token );

		if ( '' !== $color ) {
			$brand[ $token ] = $color;
		}
	}

	return $brand;
}
