<?php
/**
 * Theme override of WooCommerce core's own customer email-verification
 * message (woocommerce/templates/emails/customer-verify-email.php --
 * backs `customer_verify_email`, WooCommerce's built-in
 * CustomerEmailVerification feature).
 *
 * Structurally this was already solid -- correct `.email-introduction`
 * wrapping, correct `wc_get_template('emails/email-button.php', ...)` call
 * for the CTA button (the one this theme's own new YITH Advanced Refund
 * System overrides, inc/woocommerce.php's account-email overrides, etc.
 * all follow too), so only the customer-facing copy changed: stock's
 * "we'll link any past orders to your account" is generic-shop phrasing
 * that doesn't fit a league site (no visitor here is thinking about
 * "past orders"), reworded to plain account-verification language.
 *
 * @package blueline
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<?php /* translators: %s: Customer first name, or username if name is not available. */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'blueline' ), esc_html( $user_display_name ) ); ?></p>
<?php /* translators: %s: the customer's email address. */ ?>
<p><?php printf( esc_html__( 'Please confirm that %s is the right email address for your account.', 'blueline' ), '<b>' . esc_html( $user_email ) . '</b>' ); ?></p>
<?php
wc_get_template(
	'emails/email-button.php',
	array(
		'url'   => $verify_url,
		'label' => __( 'Confirm email address', 'woocommerce' ),
	)
);
?>
<p><?php esc_html_e( "If you didn't request this, there's nothing to worry about -- you can safely ignore this email.", 'blueline' ); ?></p>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
