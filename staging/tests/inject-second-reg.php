<?php
/**
 * Put a WC session cart into the exact state the post-login saved-cart merge produces:
 * two registration line items under two different cart item keys. This tests the
 * section-5 guard in isolation, without depending on merge mechanics.
 *
 * Customer id is read from /var/www/html/.cid (written by the calling script).
 */
global $wpdb;

$cid = trim( (string) @file_get_contents( ABSPATH . '.cid' ) );
if ( '' === $cid ) { echo "no .cid file\n"; return; }
echo "session key: ", $cid, "\n";

$table = $wpdb->prefix . 'woocommerce_sessions';
$row   = $wpdb->get_row( $wpdb->prepare( "SELECT session_key, session_value FROM {$table} WHERE session_key = %s", $cid ) );
if ( ! $row ) { echo "no session row for that key\n"; return; }

$data = maybe_unserialize( $row->session_value );
if ( ! is_array( $data ) || empty( $data['cart'] ) ) { echo "session has no cart\n"; return; }

$cart = maybe_unserialize( $data['cart'] );
echo "cart items before: ", count( $cart ), "\n";
foreach ( $cart as $k => $v ) {
	echo "  ", substr( $k, 0, 16 ), " product=", $v['product_id'], " qty=", $v['quantity'], "\n";
}

// Add a SECOND registration under its own key — a different product, so the
// deterministic-hash fix cannot collapse it. This is the Player+Goalie case.
$second_id  = 116523;
$second_key = md5( 'arl-guard-test-' . $second_id );
$product    = wc_get_product( $second_id );

$cart[ $second_key ] = array(
	'key'           => $second_key,
	'product_id'    => $second_id,
	'variation_id'  => 0,
	'variation'     => array(),
	'quantity'      => 1,
	'arl_rules_ack' => array( 'version' => 'W2026-27' ),
	'data_hash'     => function_exists( 'wc_get_cart_item_data_hash' ) ? wc_get_cart_item_data_hash( $product ) : '',
	'line_tax_data' => array( 'subtotal' => array(), 'total' => array() ),
	'line_subtotal' => (float) $product->get_price(),
	'line_total'    => (float) $product->get_price(),
	'line_tax'      => 0,
	'line_subtotal_tax' => 0,
);

$data['cart'] = maybe_serialize( $cart );
$wpdb->update( $table, array( 'session_value' => maybe_serialize( $data ) ), array( 'session_key' => $cid ) );

$verify = maybe_unserialize( maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$table} WHERE session_key = %s", $cid ) ) )['cart'] );
echo "cart items after injection: ", count( $verify ), "\n";
foreach ( $verify as $k => $v ) {
	echo "  ", substr( $k, 0, 16 ), " product=", $v['product_id'], " qty=", $v['quantity'], "\n";
}
