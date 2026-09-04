<?php
/**
 * Theme override of YITH Advanced Refund System's own "new message"
 * notification email (yith-advanced-refund-system-for-woocommerce/
 * templates/emails/ywcars-new-message.php -- backs both
 * yith_ywcars_new_message_user_email and yith_ywcars_new_message_admin_email,
 * confirmed by reading each class's own `$this->template_html` assignment).
 *
 * Stock's own message box (`.ywcars_refund_info_message_box` and its
 * children) has no styling of its own anywhere in this override -- those
 * class names exist only in the plugin's wp-admin CSS, which (correctly)
 * never gets loaded into, or inlined into, an actual sent email. Confirmed
 * live: a real message notification rendered as a bare, unstyled block of
 * text with no visual separation from the paragraph above it -- nothing
 * marked it as a quoted message rather than more body copy. Real inline
 * `style="..."` attributes, not a `<style>` block or CSS classes, are the
 * only reliably email-client-safe way to style this (the same reasoning
 * WooCommerce's own templates/emails/email-button.php already follows).
 *
 * Every variable/branch below (the customer-vs-admin recipient check, the
 * legacy WC<3.0 customer-link fallback, the admin edit-link construction)
 * is preserved exactly from the stock template -- only the OUTPUT markup
 * changed.
 *
 * @package blueline
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

$body        = $email->email_body;
$message_id  = $email->message_id;
$message     = new YITH_Request_Message( $message_id );
$request_id  = $email->request_id;
$request     = new YITH_Refund_Request( $request_id );
$customer_id = $request->customer_id;
$customer    = new WP_User( $customer_id );
$order_id    = $request->order_id;
$order       = wc_get_order( $order_id );

do_action( 'woocommerce_email_header', $email_heading, $email );

if ( $email->is_customer_email() ) {
	$customer_name = ucwords( $customer->display_name );
	$author        = esc_html_x( 'Shop Manager', 'Author of messages from shop', 'yith-advanced-refund-system-for-woocommerce' );
	$order_url     = $order->get_view_order_url();
	$request_url   = $request->get_view_request_url();
} else {
	$customer_name = version_compare( WC()->version, '3.0.0', '<' ) ? $request->get_customer_link_legacy() : $request->get_customer_link();
	$author        = ucwords( $customer->display_name );

	$order_post              = get_post( $order_id );
	$order_post_type_object  = get_post_type_object( $order_post->post_type );
	$order_url               = ( $order_post_type_object && $order_post_type_object->_edit_link )
		? admin_url( sprintf( $order_post_type_object->_edit_link . '&action=edit', $order_id ) )
		: '';

	$request_post             = get_post( $request_id );
	$request_post_type_object = get_post_type_object( $request_post->post_type );
	$request_url              = ( $request_post_type_object && $request_post_type_object->_edit_link )
		? admin_url( sprintf( $request_post_type_object->_edit_link . '&action=edit', $request_id ) )
		: '';
}

$order_link   = '<a href="' . esc_url( $order_url ) . '">#' . absint( $order_id ) . '</a>';
$request_link = '<a href="' . esc_url( $request_url ) . '">#' . absint( $request_id ) . '</a>';
$body         = nl2br(
	str_replace(
		array( '{customer_name}', '{request_number}', '{order_number}' ),
		array( $customer_name, $request_link, $order_link ),
		$body
	)
);
?>
<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p><?php echo wp_kses_post( $body ); ?></p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<table cellspacing="0" cellpadding="0" border="0" width="100%" role="presentation" style="margin: 0 0 24px;">
	<tr>
		<td style="background-color: #F7FBFC; border-left: 3px solid #3F6E9D; padding: 16px 20px;">
			<table cellspacing="0" cellpadding="0" border="0" width="100%" role="presentation" style="margin: 0 0 8px;">
				<tr>
					<td align="<?php echo is_rtl() ? 'right' : 'left'; ?>" style="font-weight: bold; color: #132343;"><?php echo esc_html( $author ); ?></td>
					<td align="<?php echo is_rtl() ? 'left' : 'right'; ?>" style="font-size: 12px; color: #2E4A74; white-space: nowrap;"><?php echo esc_html( $message->date ); ?></td>
				</tr>
			</table>
			<p style="margin: 0; color: #132343;"><?php echo wp_kses_post( nl2br( $message->message ) ); ?></p>
			<?php if ( $message->get_message_metas() ) : ?>
				<p style="margin: 16px 0 4px; font-size: 12px; color: #2E4A74; border-top: 1px solid #C7D7E3; padding-top: 12px;">
					<?php esc_html_e( 'Message attachments:', 'yith-advanced-refund-system-for-woocommerce' ); ?>
				</p>
				<?php foreach ( $message->get_message_metas() as $attachment_name => $attachment_url ) : ?>
					<p style="margin: 0 0 4px;">
						<a href="<?php echo esc_url( $attachment_url ); ?>" target="_blank" style="color: #3F6E9D;">
							<?php echo esc_html( $attachment_name ); ?>
						</a>
					</p>
				<?php endforeach; ?>
			<?php endif; ?>
		</td>
	</tr>
</table>

<p>
	<a href="<?php echo esc_url( $request_url ); ?>">
		<?php
		echo esc_html(
			$email->is_customer_email()
				? __( 'View your request', 'blueline' )
				: __( 'View this request', 'blueline' )
		);
		?>
	</a>
</p>

<?php
do_action( 'woocommerce_email_footer' );
