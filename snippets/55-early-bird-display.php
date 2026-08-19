/**
 * ARL: show the Early Bird saving as its own discount line.
 *
 * The early bird is a SALE PRICE, so WooCommerce bakes it into the line price and the
 * customer never sees they saved anything -- a registrant reported he thought he had
 * missed the discount because the receipt showed only "$550.00".
 *
 * DISPLAY ONLY. Nothing here changes what anyone is charged.
 *
 *   Player Registration (W2026-27) x 1     $575.00
 *   Subtotal                               $575.00
 *   Early Bird discount                    -$25.00
 *   Returning player discount              -$25.00
 *   Processing Fee                          $18.68
 *   Total                                  $543.68
 *
 * The line item AND the subtotal both show the regular price, so they always agree --
 * an earlier version inflated only the subtotal and registrants complained that it
 * exceeded the price shown above it.
 *
 * PLACEMENT: core's review-order.php and cart-totals.php have no hook between the
 * subtotal row and the coupon rows, and woocommerce_review_order_before_shipping sits
 * INSIDE the shipping conditional so it never fires for these (virtual) products. Both
 * templates are therefore overridden in rookie-child/woocommerce/ purely to add
 * arl_review_order_after_subtotal / arl_cart_totals_after_subtotal. If WooCommerce
 * changes those templates, re-copy them and re-add the one do_action line.
 */
if ( class_exists( 'WooCommerce' ) ) {

	function arl_eb_meta_key() { return '_arl_earlybird_saving'; }

	function arl_eb_product_saving( $product, $qty = 1 ) {
		if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
			return 0.0;
		}
		$saving = ( (float) $product->get_regular_price() - (float) $product->get_price() ) * max( 1, (int) $qty );
		return $saving > 0 ? round( $saving, 2 ) : 0.0;
	}

	/** Early-bird saving across the whole cart. */
	function arl_eb_cart_saving() {
		if ( ! WC()->cart ) {
			return 0.0;
		}
		$saving = 0.0;
		foreach ( WC()->cart->get_cart() as $ci ) {
			$saving += arl_eb_product_saving( isset( $ci['data'] ) ? $ci['data'] : null, isset( $ci['quantity'] ) ? $ci['quantity'] : 1 );
		}
		return round( $saving, 2 );
	}

	function arl_eb_coupon_label( $code ) {
		$map  = array( 'returning-player' => 'Returning player discount' );
		$code = strtolower( $code );
		return isset( $map[ $code ] ) ? $map[ $code ] : ucwords( str_replace( '-', ' ', $code ) ) . ' discount';
	}

	function arl_eb_row( $saving ) {
		echo '<tr class="arl-early-bird-discount"><th>Early Bird discount</th><td data-title="Early Bird discount">-' . wp_kses_post( wc_price( $saving ) ) . '</td></tr>';
	}

	/* ---------- 1. Record the saving on the line item at checkout ---------- */

	add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $key, $values, $order ) {
		$saving = arl_eb_product_saving( $item->get_product(), $item->get_quantity() );
		if ( $saving > 0 ) {
			$item->add_meta_data( arl_eb_meta_key(), $saving, true );
		}
	}, 20, 4 );

	/* ---------- 2. CART / CHECKOUT: line and subtotal at the regular price ---------- */

	add_filter( 'woocommerce_cart_item_subtotal', function ( $html, $cart_item ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		$saving  = arl_eb_product_saving( $product, isset( $cart_item['quantity'] ) ? $cart_item['quantity'] : 1 );
		if ( $saving <= 0 ) {
			return $html;
		}
		return wc_price( (float) $product->get_regular_price() * max( 1, (int) $cart_item['quantity'] ) );
	}, 10, 2 );

	add_filter( 'woocommerce_cart_subtotal', function ( $html, $compound, $cart ) {
		$saving = arl_eb_cart_saving();
		if ( $saving <= 0 ) {
			return $html;
		}
		return wc_price( (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() + $saving );
	}, 10, 3 );

	// Rendered by the child-theme template overrides, immediately after the subtotal row.
	function arl_eb_render_row() {
		$saving = arl_eb_cart_saving();
		if ( $saving > 0 ) {
			arl_eb_row( $saving );
		}
	}
	add_action( 'arl_review_order_after_subtotal', 'arl_eb_render_row' );
	add_action( 'arl_cart_totals_after_subtotal', 'arl_eb_render_row' );

	/* ---------- 3. ORDER / RECEIPT: same shape ---------- */

	add_filter( 'woocommerce_order_formatted_line_subtotal', function ( $formatted, $item, $order ) {
		$saving = (float) $item->get_meta( arl_eb_meta_key() );
		if ( $saving <= 0 ) {
			return $formatted;
		}
		return wc_price( (float) $item->get_subtotal() + $saving, array( 'currency' => $order->get_currency() ) );
	}, 10, 3 );

	add_filter( 'woocommerce_get_order_item_totals', function ( $totals, $order ) {
		$saving = 0.0;
		foreach ( $order->get_items() as $item ) {
			$saving += (float) $item->get_meta( arl_eb_meta_key() );
		}
		$coupon_items = $order->get_items( 'coupon' );

		if ( $saving <= 0 && empty( $coupon_items ) ) {
			return $totals;
		}

		$currency = array( 'currency' => $order->get_currency() );
		$rebuilt  = array();

		foreach ( $totals as $key => $row ) {

			if ( 'cart_subtotal' === $key && $saving > 0 ) {
				$row['value']             = wc_price( (float) $order->get_subtotal() + $saving, $currency );
				$rebuilt['cart_subtotal'] = $row;
				$rebuilt['arl_earlybird'] = array(
					'label' => 'Early Bird discount:',
					'value' => '-' . wc_price( $saving, $currency ),
				);
				continue;
			}

			if ( 'discount' === $key && ! empty( $coupon_items ) ) {
				foreach ( $coupon_items as $cid => $coupon_item ) {
					$amount = (float) $coupon_item->get_discount();
					if ( $amount <= 0 ) {
						continue;
					}
					$rebuilt[ 'arl_coupon_' . $cid ] = array(
						'label' => arl_eb_coupon_label( $coupon_item->get_code() ) . ':',
						'value' => '-' . wc_price( $amount, $currency ),
					);
				}
				continue;
			}

			$rebuilt[ $key ] = $row;
		}

		return $rebuilt;
	}, 10, 2 );

	/* ---------- 4. Keep the raw bookkeeping meta out of the admin item table ---------- */

	add_filter( 'woocommerce_hidden_order_itemmeta', function ( $hidden ) {
		$hidden[] = arl_eb_meta_key();
		return $hidden;
	} );

}
