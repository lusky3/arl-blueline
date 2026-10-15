<?php
/**
 * Customer new account email
 *
 * Blueline override: the stock template with league wording (no "view orders"; "an account has been created for you").
 * Every edited string uses the blueline text domain; the rest is the
 * original. When WooCommerce bumps @version, re-copy the original and
 * re-apply those strings (scripts/check-template-versions.sh).
 *
 * Copied from WooCommerce 11.0.1: woocommerce/templates/emails/customer-new-account.php
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 10.9.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

/**
 * Fires to output the email header.
 *
 * @hooked WC_Emails::email_header()
 *
 * @since 3.7.0
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php /* translators: %s: Customer first name, or username if name is not available */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $user_display_name ) ); ?></p>
<?php if ( $email_improvements_enabled ) : ?>
	<?php /* translators: %s: Site title */ ?>
	<p><?php printf( esc_html__( 'An account has been created for you on %s. Here\'s a copy of your account details.', 'blueline' ), esc_html( $blogname ) ); ?></p>
	<div class="hr hr-top"></div>
	<?php /* translators: %s: Username */ ?>
	<p><?php echo wp_kses( sprintf( __( 'Username: <b>%s</b>', 'woocommerce' ), esc_html( $user_login ) ), array( 'b' => array() ) ); ?></p>
	<?php if ( $password_generated && $set_password_url ) : ?>
		<?php // If the password has not been set by the user during the sign up process, send them a link to set a new password. ?>
		<p><a href="<?php echo esc_attr( $set_password_url ); ?>"><?php esc_html_e( 'Set your password to get started.', 'blueline' ); ?></a></p>
	<?php endif; ?>
	<div class="hr hr-bottom"></div>
	<p><?php esc_html_e( 'You can visit your account any time to review your details, update your password, and more, via the link below:', 'blueline' ); ?></p>
	<p><a href="<?php echo esc_attr( wc_get_page_permalink( 'myaccount' ) ); ?>"><?php printf( esc_html__( 'My account', 'woocommerce' ) ); ?></a></p>
<?php else : ?>
	<?php /* translators: %1$s: Site title, %2$s: Username, %3$s: My account link */ ?>
	<p><?php printf( esc_html__( 'An account has been created for you on %1$s. Your username is %2$s. You can visit your account any time to review your details, update your password, and more at: %3$s', 'blueline' ), esc_html( $blogname ), '<strong>' . esc_html( $user_login ) . '</strong>', make_clickable( esc_url( wc_get_page_permalink( 'myaccount' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	<?php if ( $password_generated && $set_password_url ) : ?>
		<?php // If the password has not been set by the user during the sign up process, send them a link to set a new password. ?>
		<p><a href="<?php echo esc_attr( $set_password_url ); ?>"><?php esc_html_e( 'Click here to set your password.', 'blueline' ); ?></a></p>
	<?php endif; ?>
<?php endif; ?>
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

/**
 * Fires to output the email footer.
 *
 * @hooked WC_Emails::email_footer()
 *
 * @since 3.7.0
 */
do_action( 'woocommerce_email_footer', $email );
