<?php
/**
 * Customer Reset Password email
 *
 * Blueline override: the stock template with a plainer "if this wasn't you" line.
 * Every edited string uses the blueline text domain; the rest is the
 * original. When WooCommerce bumps @version, re-copy the original and
 * re-apply those strings (scripts/check-template-versions.sh).
 *
 * Copied from WooCommerce 11.0.1: woocommerce/templates/emails/customer-reset-password.php
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 10.9.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php /* translators: %s: Customer first name, or username if name is not available */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $user_display_name ) ); ?></p>
<?php /* translators: %s: Store name */ ?>
<p><?php printf( esc_html__( 'Someone requested a password reset for the following account on %s:', 'blueline' ), esc_html( $blogname ) ); ?></p>
<?php if ( $email_improvements_enabled ) : ?>
	<div class="hr hr-top"></div>
	<?php /* translators: %s: Username */ ?>
	<p><?php echo wp_kses( sprintf( __( 'Username: <b>%s</b>', 'woocommerce' ), esc_html( $user_login ) ), array( 'b' => array() ) ); ?></p>
	<div class="hr hr-bottom"></div>
	<p><?php esc_html_e( 'If this wasn\'t you, no action is needed and your password will stay the same. Otherwise, reset your password using the link below:', 'blueline' ); ?></p>
<?php else : ?>
	<?php /* translators: %s: Customer username */ ?>
	<p><?php printf( esc_html__( 'Username: %s', 'woocommerce' ), esc_html( $user_login ) ); ?></p>
	<p><?php esc_html_e( 'If this wasn\'t you, no action is needed and your password will stay the same. Otherwise, reset your password:', 'blueline' ); ?></p>
<?php endif; ?>
<p>
	<a class="link" href="<?php echo esc_url( add_query_arg( array( 'key' => $reset_key, 'id' => $user_id, 'login' => rawurlencode( $user_login ) ), wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) ) ) ); ?>"><?php // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound ?>
		<?php
		if ( $email_improvements_enabled ) {
			esc_html_e( 'Reset your password', 'woocommerce' );
		} else {
			esc_html_e( 'Click here to reset your password', 'woocommerce' );
		}
		?>
	</a>
</p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
