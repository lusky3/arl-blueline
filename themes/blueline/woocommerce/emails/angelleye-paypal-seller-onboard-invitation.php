<?php
/**
 * Theme override of the PayPal for WooCommerce (AngellEYE) plugin's own
 * PayPal seller-onboarding invitation email
 * (paypal-for-woocommerce/template/emails/angelleye-paypal-seller-onboard-invitation.php
 * -- backs `paypal_onboard_seller_invitation`).
 *
 * A one-time, merchant/admin-only setup email (fires once when connecting
 * a PayPal account, not a recurring customer touchpoint), so the copy is
 * left as-is -- it's precise technical instruction ("click the button
 * below... make sure to click Return to Store"), not a place for brand
 * voice. The one real gap: stock never wrapped its intro in the
 * `.email-introduction` treatment every other email in this directory
 * uses, so it rendered with a flatter, denser layout than the rest of the
 * site's mail. Added for consistency; every hook/variable is otherwise
 * unchanged from stock.
 *
 * @package blueline
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p><?php esc_html_e( 'Hello,', 'paypal-for-woocommerce' ); ?></p>
<p>
<?php
printf(
	/* translators: %s: site name. */
	esc_html__( 'Just one more step to connect your PayPal account to %s and begin receiving payments for your products and services.', 'paypal-for-woocommerce' ),
	esc_html( wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) )
);
?>
</p>
<p><?php esc_html_e( 'Click the button below to begin the process. Simply log in to your PayPal account and follow the steps to get connected.', 'paypal-for-woocommerce' ); ?></p>

<p>
	<?php do_action( 'angelleye_pppc_seller_onboard_html', $post_id ); ?>
</p>
<p><?php esc_html_e( 'Make sure to click the "Return to Store" button at the end of the procedure.', 'paypal-for-woocommerce' ); ?></p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
