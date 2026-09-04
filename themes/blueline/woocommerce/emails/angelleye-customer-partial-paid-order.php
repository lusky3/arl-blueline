<?php
/**
 * Theme override of the PayPal for WooCommerce (AngellEYE) plugin's own
 * "order partially paid" customer email
 * (paypal-for-woocommerce/template/emails/angelleye-customer-partial-paid-order.php
 * -- backs `customer_partially_paid_order`, fired via
 * `woocommerce_order_status_{cancelled,failed,on-hold,pending,processing}
 * _to_partial-payment_notification`, confirmed by reading
 * classes/wc-email-customer-partial-paid-order.php).
 *
 * Likely one of the higher-frequency emails this site sends: the site's
 * own FAQ content documents an active installment/split-payment
 * registration option, and this is the email a customer gets each time
 * one of those installments lands.
 *
 * Two real problems in the stock template, confirmed live:
 *
 *   1. The payment-summary box used hardcoded amber/warning colors
 *      (#fef3c7 background, #f59e0b/#d97706 borders) -- the same visual
 *      language as "something needs your attention," when a landed
 *      installment payment is actually the normal, expected, GOOD
 *      outcome of this flow. Restyled to this site's own neutral
 *      info-card treatment (the same one
 *      woocommerce/emails/ywcars-email-for-user.php's coupon-code box
 *      already uses), so the tone matches the event.
 *   2. The intro line ("...it is now being processed") assumes the order
 *      is CURRENTLY in "processing" status, but this template also
 *      fires when the order transitioned from cancelled, failed,
 *      on-hold, or pending -- none of which "now being processed"
 *      accurately describes. Reworded to a line that's true regardless
 *      of which of those five statuses preceded this one.
 *
 * The payment-summary computation itself (capture aggregation, refund
 * subtraction, balance/overage math -- all of it via
 * AngellEYE_PPCP_Partial_Payment_Data::get_capture_summary()) is
 * untouched: only the surrounding prose and the box's own inline styles
 * changed.
 *
 * @package blueline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
/* translators: %s: Customer first name. */
printf( esc_html__( 'Hi %s,', 'paypal-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) );
?>
</p>
<p>
<?php
/* translators: %s: order number. */
printf( esc_html__( 'A payment has been received toward your order #%s:', 'paypal-for-woocommerce' ), esc_html( $order->get_order_number() ) );
?>
</p>
<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
?>

<?php
// Capture aggregation + refund subtraction + date localization live in
// AngellEYE_PPCP_Partial_Payment_Data (ppcp-gateway/includes/).
// Required by classes/wc-email-customer-partial-paid-order.php, so the
// helper class is guaranteed to be loaded by the time this template
// renders.
$pfw_summary    = AngellEYE_PPCP_Partial_Payment_Data::get_capture_summary( $order );
$all_captures   = $pfw_summary['captures'];
$total_captured = $pfw_summary['total_captured'];
$order_total    = $pfw_summary['order_total'];
$balance        = $pfw_summary['balance'];
$pfw_currency   = $pfw_summary['currency'];

if ( $total_captured > 0 ) :
	?>
	<table cellspacing="0" cellpadding="0" border="0" width="100%" role="presentation" style="margin: 0 0 20px;">
		<tr>
			<td style="background-color: #F7FBFC; border: 1px solid #C7D7E3; border-radius: 4px; padding: 16px 20px;">
				<p style="margin: 0 0 10px; font-weight: bold; color: #132343;"><?php esc_html_e( 'Payment Summary', 'paypal-for-woocommerce' ); ?></p>
				<table cellspacing="0" cellpadding="0" border="0" width="100%" role="presentation">
					<tr>
						<td style="padding: 6px 0; color: #132343;"><?php esc_html_e( 'Order Total:', 'paypal-for-woocommerce' ); ?></td>
						<td style="padding: 6px 0; text-align: right; color: #132343;"><?php echo wp_kses_post( wc_price( $order_total, array( 'currency' => $pfw_currency ) ) ); ?></td>
					</tr>
					<tr>
						<td colspan="2" style="padding: 10px 0 6px; border-top: 1px solid #C7D7E3; font-weight: bold; color: #132343;">
							<?php esc_html_e( 'Payments Received:', 'paypal-for-woocommerce' ); ?>
						</td>
					</tr>
					<?php foreach ( $all_captures as $capture_data ) : ?>
						<tr>
							<td style="padding: 4px 0 4px 15px; font-size: 13px; color: #2E4A74;">
								<?php echo esc_html( $capture_data['date'] ); ?>
							</td>
							<td style="padding: 4px 0; text-align: right; font-size: 13px; color: #2E4A74;">
								<?php echo wp_kses_post( wc_price( $capture_data['amount'], array( 'currency' => $pfw_currency ) ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<td style="padding: 6px 0; border-top: 1px solid #C7D7E3; font-weight: bold; color: #132343;">
							<?php esc_html_e( 'Total Paid:', 'paypal-for-woocommerce' ); ?>
						</td>
						<td style="padding: 6px 0; text-align: right; border-top: 1px solid #C7D7E3; font-weight: bold; color: #132343;">
							<?php echo wp_kses_post( wc_price( $total_captured, array( 'currency' => $pfw_currency ) ) ); ?>
						</td>
					</tr>
					<?php if ( $balance > 0 ) : ?>
						<tr>
							<td style="padding: 6px 0; border-top: 2px solid #3F6E9D; font-weight: bold; color: #132343;">
								<?php esc_html_e( 'Balance Due:', 'paypal-for-woocommerce' ); ?>
							</td>
							<td style="padding: 6px 0; text-align: right; border-top: 2px solid #3F6E9D; font-weight: bold; color: #132343;">
								<?php echo wp_kses_post( wc_price( $balance, array( 'currency' => $pfw_currency ) ) ); ?>
							</td>
						</tr>
					<?php elseif ( $balance < 0 ) : ?>
						<tr>
							<td style="padding: 6px 0; border-top: 2px solid #3F6E9D; font-weight: bold; color: #132343;">
								<?php esc_html_e( 'Additional Amount Billed:', 'paypal-for-woocommerce' ); ?>
							</td>
							<td style="padding: 6px 0; text-align: right; border-top: 2px solid #3F6E9D; font-weight: bold; color: #132343;">
								<?php echo wp_kses_post( wc_price( abs( $balance ), array( 'currency' => $pfw_currency ) ) ); ?>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<?php if ( $balance > 0 ) : ?>
					<p style="margin: 10px 0 0; font-size: 13px; color: #2E4A74;">
						<?php esc_html_e( 'The remaining balance will be charged when additional items are ready to ship.', 'paypal-for-woocommerce' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
