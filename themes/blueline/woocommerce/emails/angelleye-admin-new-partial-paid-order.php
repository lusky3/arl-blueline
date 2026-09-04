<?php
/**
 * Theme override of the PayPal for WooCommerce (AngellEYE) plugin's own
 * "order partially paid" ADMIN email
 * (paypal-for-woocommerce/template/emails/angelleye-admin-new-partial-paid-order.php
 * -- backs `admin_partially_paid_order`, the staff-facing counterpart to
 * `woocommerce/emails/angelleye-customer-partial-paid-order.php` in this
 * same directory).
 *
 * Admin-only, so the bar here is lower than the customer-facing version --
 * but stock's own intro line ("You've received the following order from
 * X") was copied verbatim from WooCommerce core's own admin-new-order.php
 * template, which assumes a BRAND NEW order. This template fires on an
 * existing order transitioning TO partial-payment status (from
 * cancelled/failed/on-hold/pending/processing), which "received the
 * following order" doesn't accurately describe. Reworded; every hook and
 * variable is otherwise unchanged from stock.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
<?php
/* translators: %s: Customer billing full name. */
printf( esc_html__( 'A payment has been received on the following order from %s:', 'paypal-for-woocommerce' ), esc_html( $order->get_formatted_billing_full_name() ) );
?>
</p>
<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
