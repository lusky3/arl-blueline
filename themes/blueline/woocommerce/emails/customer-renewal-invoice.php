<?php
/**
 * Customer renewal invoice email
 *
 * @author  Brent Shepherd
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 2.6.0
 *
 * My Edits: Changes of references to "subscription" to "installment" and "installment plan"
 * References of "order" changed to "registration"
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: Customer first name */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'woocommerce-subscriptions' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<?php if ( $order->has_status( 'pending' ) ) : ?>
	<p><?php echo wp_kses(
	sprintf(
		// translators: %1$s: name of the blog, %2$s: link to checkout payment url, note: no full stop due to url at the end
		_x( 'An record has been created for you to pay your next installment with the %1$s. To pay for this invoice please use the following link: %2$s', 'In customer renewal invoice email', 'woocommerce-subscriptions' ),
		esc_html( get_bloginfo( 'name' ) ),
		'<a href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html__( 'Pay Now »', 'woocommerce-subscriptions' ) . '</a>'
	), array( 'a' => array( 'href' => true ) ) ); ?>
	</p>
<?php elseif ( $order->has_status( 'failed' ) ) : ?>
	<p><?php echo wp_kses(
	sprintf(
		// translators: %1$s: name of the blog, %2$s: link to checkout payment url, note: no full stop due to url at the end
		_x( 'The automatic payment for your installment plan with %1$s has failed. In order to avoid having your registration cancelled, please log in and pay for this installment from your account page: %2$s', 'In customer renewal invoice email', 'woocommerce-subscriptions' ),
		esc_html( get_bloginfo( 'name' ) ),
		'<a href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html__( 'Pay Now »', 'woocommerce-subscriptions' ) . '</a>'
	), array( 'a' => array( 'href' => true ) ) ); ?>
	</p>
<?php endif; ?>

<?php
do_action( 'woocommerce_subscriptions_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
