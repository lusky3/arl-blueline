<?php
/**
 * Customer renewal invoice email
 *
 * Blueline override: the stock template with installment-plan wording in
 * place of "subscription" and "registration" in place of "order" (the
 * league sells registrations paid in installments, not subscriptions).
 * Every edited string uses the blueline text domain; the rest is the
 * original. When WooCommerce Subscriptions bumps @version, re-copy the
 * original and re-apply those strings (scripts/check-template-versions.sh).
 *
 * Copied from WooCommerce Subscriptions 8.4.0: woocommerce-subscriptions/templates/emails/customer-renewal-invoice.php
 *
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 7.3.0 - Updated for WC core email improvements.
 */

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = wcs_is_wc_feature_enabled( 'email_improvements' );

do_action( 'woocommerce_email_header', $email_heading, $email );

echo $email_improvements_enabled ? '<div class="email-introduction">' : '';

/* translators: %s: Customer first name */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'woocommerce-subscriptions' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<?php if ( $order->has_status( 'pending' ) ) : ?>
	<p>
		<?php
		echo wp_kses(
			sprintf(
				// translators: %1$s: name of the blog, %2$s: link to checkout payment url, note: no full stop due to url at the end.
				_x(
					'A record has been created for you to pay your next installment with the %1$s. To pay for this invoice please use the following link: %2$s',
					'In customer renewal invoice email',
					'blueline'
				),
				esc_html( get_bloginfo( 'name' ) ),
				'<a href="' . esc_url( $order->get_checkout_payment_url() ) . '">'
				. esc_html__( 'Pay Now &raquo;', 'woocommerce-subscriptions' ) .
				'</a>'
			),
			array(
				'a' => array( 'href' => true ),
			)
		);
		?>
	</p>
<?php elseif ( $order->has_status( 'failed' ) ) : ?>
	<p>
		<?php
		echo wp_kses(
			sprintf(
				// translators: %1$s: name of the blog, %2$s: link to checkout payment url, note: no full stop due to url at the end.
				_x(
					'The automatic payment for your installment plan with %1$s has failed. In order to avoid having your registration cancelled, please log in and pay for this installment from your account page: %2$s',
					'In customer renewal invoice email',
					'blueline'
				),
				esc_html( get_bloginfo( 'name' ) ),
				'<a href="' . esc_url( $order->get_checkout_payment_url() ) . '">'
				. esc_html__( 'Pay Now &raquo;', 'woocommerce-subscriptions' ) .
				'</a>'
			),
			array(
				'a' => array( 'href' => true ),
			)
		);
		?>
	</p>
<?php endif; ?>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
do_action( 'woocommerce_subscriptions_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<div class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</div>' : '';
}

do_action( 'woocommerce_email_footer', $email );
