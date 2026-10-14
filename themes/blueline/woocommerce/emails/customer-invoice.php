<?php
/**
 * Customer invoice email
 *
 * Blueline override: the stock template with registration wording and a "Pay Now" button under the pay link.
 * Every edited string uses the blueline text domain; the rest is the
 * original. When WooCommerce bumps @version, re-copy the original and
 * re-apply those strings (scripts/check-template-versions.sh).
 *
 * Copied from WooCommerce 11.0.1: woocommerce/templates/emails/customer-invoice.php
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 10.4.0
 */

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

/**
 * Executes the e-mail header.
 *
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) );
} else {
	printf( esc_html__( 'Hi,', 'woocommerce' ) );
}
?>
</p>
<?php if ( $order->needs_payment() ) { ?>
	<p>
	<?php
	if ( $order->has_status( OrderStatus::FAILED ) ) {
		printf(
			wp_kses(
			/* translators: %1$s Site title, %2$s Order pay link */
				__( 'Sorry, your payment for your registration on %1$s was unsuccessful. Your registration details are below, with a link to try your payment again: %2$s', 'blueline' ),
				array(
					'a' => array(
						'href' => array(),
					),
				)
			),
			esc_html( get_bloginfo( 'name', 'display' ) ),
			'<a href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html__( 'Pay for this registration', 'blueline' ) . '</a>'
		);
	} else {
		printf(
			wp_kses(
			/* translators: %1$s Site title, %2$s Order pay link */
				__( 'A registration has been created for you on %1$s. Your registration details are below, with a link to make your payment: %2$s', 'blueline' ),
				array(
					'a' => array(
						'href' => array(),
					),
				)
			),
			esc_html( get_bloginfo( 'name', 'display' ) ),
			'<a href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html__( 'Pay for this registration', 'blueline' ) . '</a>'
		);
	}
	?>
	</p>

	<table cellspacing="0" cellpadding="0" border="0" style="margin: 20px 0;">
		<tr>
			<td align="center" bgcolor="#0071a1" style="border-radius: 5px;">
				<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" target="_blank" style="display: inline-block; padding: 12px 25px; font-size: 16px; color: #ffffff; background-color: #0071a1; text-decoration: none; border-radius: 5px;">
					<?php esc_html_e( 'Pay Now', 'blueline' ); ?>
				</a>
			</td>
		</tr>
	</table>
<?php } else { ?>
	<p>
	<?php
	/* translators: %s Order date */
	printf( esc_html__( 'Here are the details of your registration made on %s:', 'blueline' ), esc_html( wc_format_datetime( $order->get_date_created() ) ) );
	?>
	</p>
	<?php
}
?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
/**
 * Hook for the woocommerce_email_order_details.
 *
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Hook for the woocommerce_email_order_meta.
 *
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/**
 * Hook for woocommerce_email_customer_details.
 *
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/**
 * Executes the email footer.
 *
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
