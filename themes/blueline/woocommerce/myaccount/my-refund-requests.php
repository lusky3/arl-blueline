<?php
/*
 * Cody Edits:
 * Changed references of Orders to Registrations, "Order" to "Reg #".
 */

defined( 'ABSPATH' ) || exit;

$columns = apply_filters( 'ywcars_my_refund_requests_columns', array(
	__( 'ID', 'yith-advanced-refund-system-for-woocommerce' ),
	__( 'Reg #', 'yith-advanced-refund-system-for-woocommerce' ),
	__( 'Item', 'yith-advanced-refund-system-for-woocommerce' ),
	__( 'Requested amount', 'yith-advanced-refund-system-for-woocommerce' ),
	__( 'Status', 'yith-advanced-refund-system-for-woocommerce' ),
	''
) );

?>

<?php if ( ! $request_ids ) : ?>
    <p><?php echo apply_filters( 'ywcars_no_requests_found', esc_html__( 'There are currently no refund requests, if you would like to request a refund, then please navigate to the Registrations page and select which registration you would like to request a refund for.', 'yith-advanced-refund-system-for-woocommerce' ) ); ?></p>
<?php else : ?>
    <table class="shop_table shop_table_responsive my_account_orders">
        <tr>
			<?php foreach ( $columns as $column ) : ?>
                <th><?php echo $column; ?></th>
			<?php endforeach; ?>
        </tr>
		<?php
		foreach ( $request_ids as $request_id ) {
			$request = new YITH_Refund_Request( $request_id );
			if ( ! ( $request instanceof YITH_Refund_Request && $request->exists() ) ) {
				continue;
			}
			if ( 'trash' === $request->status ) {
				continue;
			}
			$request_link = '<a href="' . esc_url( $request->get_view_request_url() ) . '">'
			                . '#' . $request_id . '</a>';
			$order = wc_get_order( $request->order_id );
			// A deleted order leaves its refund request behind; show the bare number instead of fataling.
			$order_link = $order
				? '<a href="' . esc_url( $order->get_view_order_url() ) . '">#' . absint( $request->order_id ) . '</a>'
				: '#' . absint( $request->order_id );
			if ( $request->whole_order ) {
				$product_link = '<b>' . esc_html__( 'Whole Amount', 'yith-advanced-refund-system-for-woocommerce' ) . '</b>';
			} else {
				$product = wc_get_product( $request->product_id );
				if ( $product ) {
					$product_link = '<a href="' . esc_url( $product->get_permalink() ) . '">' . esc_html( $product->get_title() ) . '</a>';
				}
			}
			$button = '<a class="button" href="' . esc_url( $request->get_view_request_url() ) . '">'
			          . esc_html__( 'View', 'yith-advanced-refund-system-for-woocommerce' ) . '</a>';
			?>
            <tr>
                <td data-title="<?php echo esc_attr( $columns[0] ); ?>"><?php echo $request_link ?></td>
                <td data-title="<?php echo esc_attr( $columns[1] ); ?>"><?php echo $order_link ?></td>
                <td data-title="<?php echo esc_attr( $columns[2] ); ?>"><?php echo $product_link ?></td>
                <td data-title="<?php echo esc_attr( $columns[3] ); ?>"><?php echo wc_price( $request->refund_total ) ?></td>
                <td data-title="<?php echo esc_attr( $columns[4] ); ?>"><?php echo 'ywcars-new' === $request->status ? esc_html__( 'Submitted', 'yith-advanced-refund-system-for-woocommerce' ) : ywcars_get_request_status_by_key( $request->status ) ?></td>
                <td><?php echo $button ?></td>
            </tr>
			<?php
		}
		?>
    </table>
<?php endif; ?>
<?php
