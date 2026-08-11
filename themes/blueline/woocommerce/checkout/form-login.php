<?php
/**
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version 3.8.0
 *
 * Cody Edits: Replaced references of Customer with Player.
 */

defined( 'ABSPATH' ) || exit;

if ( is_user_logged_in() || 'no' === get_option( 'woocommerce_enable_checkout_login_reminder' ) ) {
	return;
}

?>
<div class="woocommerce-form-login-toggle">
	<?php wc_print_notice( apply_filters( 'woocommerce_checkout_login_message', esc_html__( 'Returning player?', 'woocommerce' ) ) . ' <a href="#" class="showlogin">' . esc_html__( 'Click here to login', 'woocommerce' ) . '</a>', 'notice' ); ?>
</div>
<?php

woocommerce_login_form(
	array(
		'message'  => esc_html__( 'If you have registered with the ARL before, please enter your details below. If you are a new player, please proceed to the Billing section.', 'woocommerce' ),
		'redirect' => wc_get_checkout_url(),
		'hidden'   => true,
	)
);
