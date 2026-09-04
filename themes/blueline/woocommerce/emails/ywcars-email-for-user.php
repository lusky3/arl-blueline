<?php
/**
 * Theme override of YITH Advanced Refund System's own customer-facing
 * refund-request email (yith-advanced-refund-system-for-woocommerce/
 * templates/emails/ywcars-email-for-user.php -- loaded via that plugin's
 * own wc_get_template_html() call, which checks this theme's woocommerce/
 * directory first, the same mechanism this theme already uses to override
 * WooCommerce's own core email templates).
 *
 * Backs 5 of this plugin's 8 registered email types (new request
 * submitted, approved, on hold, processing, rejected) -- confirmed by
 * reading each email class's own `$this->template_html` assignment. All
 * five already inherit this site's brand colors/font (the shared
 * woocommerce_email_header()/email_footer() hooks below, styled via
 * inc/woocommerce.php's blueline_wc_email_option_overrides()) with zero
 * changes -- this override exists for the CONTENT, which stock plugin
 * markup left as a single bare `<p>`:
 *
 *   - Wraps the body text in the same `.email-introduction` treatment
 *     WooCommerce's own core email templates use (an actual CSS class
 *     WooCommerce's own email-styles.php styles -- see e.g.
 *     customer-completed-order.php in this same directory).
 *   - Adds `{coupon_code}` as a real substitutable placeholder. The stock
 *     template already fetched `$email->coupon_code` but never referenced
 *     it anywhere -- only `{coupon_amount}` was ever actually substituted.
 *     An admin who customises the "Approved" email's body to mention a
 *     store-credit coupon by code, not just its dollar amount, had no way
 *     to do that; this doesn't change existing behaviour for anyone who
 *     never uses that placeholder.
 *   - Adds a real "View your request" link/button
 *     ($request->get_view_request_url(), already a public method this
 *     plugin's own admin-side templates use) -- stock left the customer
 *     with no way to click through to their own request status at all.
 *   - Adds a distinct coupon-code callout box, shown whenever a real
 *     coupon was actually issued (`$coupon_code` non-empty) REGARDLESS of
 *     whether the admin's own body text happens to reference
 *     `{coupon_code}` -- a real coupon should never be silently only
 *     describable in prose the admin might not have written to include it.
 *
 * @package blueline
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

$body       = ! empty( $email->email_body ) ? $email->email_body : '';
$request_id = ! empty( $email->request_id ) ? $email->request_id : '';
$request    = new YITH_Refund_Request( $request_id );
$order      = wc_get_order( $request->order_id );

$order_link   = '<a href="' . esc_url( $order->get_view_order_url() ) . '">#' . absint( $request->order_id ) . '</a>';
$request_link = '<a href="' . esc_url( $request->get_view_request_url() ) . '">#' . absint( $request_id ) . '</a>';
$customer     = new WP_User( $request->customer_id );

$coupon_amount      = ! empty( $email->coupon_amount ) ? $email->coupon_amount : '';
$coupon_code        = ! empty( $email->coupon_code ) ? $email->coupon_code : '';
$coupon_expiry_date = ! empty( $email->coupon_expiry_date ) ? $email->coupon_expiry_date : '';

do_action( 'woocommerce_email_header', $email_heading, $email );

$body = str_replace(
	array( '{customer_name}', '{order_number}', '{request_number}', '{coupon_amount}', '{coupon_code}' ),
	array(
		ucwords( $customer->display_name ),
		$order_link,
		$request_link,
		'' !== $coupon_amount ? wc_price( $coupon_amount ) : '',
		esc_html( $coupon_code ),
	),
	$body
);
?>
<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p><?php echo wp_kses_post( $body ); ?></p>

<p>
	<a href="<?php echo esc_url( $request->get_view_request_url() ); ?>">
		<?php esc_html_e( 'View your request', 'blueline' ); ?>
	</a>
</p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php if ( '' !== $coupon_code ) : ?>
	<table cellspacing="0" cellpadding="0" border="0" width="100%" role="presentation" style="margin: 16px 0 24px;">
		<tr>
			<td style="background-color: #F7FBFC; border: 1px solid #C7D7E3; border-radius: 4px; padding: 16px 20px;">
				<p style="margin: 0 0 4px; font-size: 13px; color: #2E4A74;">
					<?php esc_html_e( 'Your coupon code', 'blueline' ); ?>
				</p>
				<p style="margin: 0; font-size: 20px; font-weight: bold; letter-spacing: 0.04em; color: #132343;">
					<?php echo esc_html( $coupon_code ); ?>
				</p>
				<?php if ( '' !== $coupon_expiry_date ) : ?>
					<p style="margin: 8px 0 0; font-size: 13px; color: #2E4A74;">
						<?php
						printf(
							/* translators: %s: coupon expiry date. */
							esc_html__( 'Expires %s', 'blueline' ),
							esc_html( $coupon_expiry_date )
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_footer' );
