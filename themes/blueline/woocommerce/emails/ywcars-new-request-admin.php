<?php
/**
 * Theme override of YITH Advanced Refund System's own "new refund request"
 * admin-notification email (yith-advanced-refund-system-for-woocommerce/
 * templates/emails/ywcars-new-request-admin.php -- backs
 * yith_ywcars_new_request_admin_email specifically, confirmed by reading
 * that class's own `$this->template_html` assignment).
 *
 * Admin-facing, not customer-facing, so the bar here is lower than
 * ywcars-email-for-user.php/ywcars-new-message.php in this same
 * directory -- but stock left the reader with no direct link to actually
 * open the request that needs action, only plain `#123`-style text
 * embedded in the body via the {order_number}/{request_number}
 * placeholders. Adds an explicit "Review this request" link using
 * $request_url, already computed by the unchanged variable setup below.
 *
 * Every variable/branch (the admin edit-link construction, the
 * items-table include) is preserved exactly from the stock template --
 * only the OUTPUT markup changed.
 *
 * @package blueline
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

$body          = ! empty( $email->email_body ) ? nl2br( htmlspecialchars( $email->email_body ) ) : '';
$request_id    = ! empty( $email->request_id ) ? $email->request_id : '';
$request       = new YITH_Refund_Request( $request_id );
$order_id      = $request->order_id;
$customer_name = version_compare( WC()->version, '3.0.0', '<' ) ? $request->get_customer_link_legacy() : $request->get_customer_link();

$order_post             = get_post( $order_id );
$order_post_type_object = get_post_type_object( $order_post->post_type );
$order_url              = ( $order_post_type_object && $order_post_type_object->_edit_link )
	? admin_url( sprintf( $order_post_type_object->_edit_link . '&action=edit', $order_id ) )
	: '';

$request_post             = get_post( $request_id );
$request_post_type_object = get_post_type_object( $request_post->post_type );
$request_url              = ( $request_post_type_object && $request_post_type_object->_edit_link )
	? admin_url( sprintf( $request_post_type_object->_edit_link . '&action=edit', $request_id ) )
	: '';

$order_link   = '<a href="' . esc_url( $order_url ) . '">#' . absint( $order_id ) . '</a>';
$request_link = '<a href="' . esc_url( $request_url ) . '">#' . absint( $request_id ) . '</a>';

ob_start();
wc_get_template(
	'ywcars-items-table-for-email.php',
	array( 'request_id' => $request_id ),
	'',
	YITH_WCARS_TEMPLATE_PATH . '/'
);
$items_table = ob_get_clean();

$body = str_replace(
	array( '{customer_name}', '{order_number}', '{request_number}', '{items_table}' ),
	array( $customer_name, $order_link, $request_link, $items_table ),
	$body
);

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
// Not wp_kses_post(): $body already carries the {items_table} substitution
// above, a full <table>+<style> block this plugin generates itself (see
// ywcars-items-table-for-email.php) -- wp_kses_post()'s allowed-tags list
// strips <style>, which would silently drop that table's own border
// styling. $body's surrounding prose is admin-configured settings text
// (WooCommerce > Settings > Emails, not end-user input), the same trust
// level the stock template already treated it at (a bare, unescaped
// echo) -- unchanged here, just no longer also blind to $items_table's
// own markup.
echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see comment above.
?>
</p>

<p>
	<a href="<?php echo esc_url( $request_url ); ?>">
		<?php esc_html_e( 'Review this request', 'blueline' ); ?>
	</a>
</p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
do_action( 'woocommerce_email_footer' );
