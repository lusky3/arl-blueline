/**
 * /register: show "Coming soon" instead of "Out of stock".
 *
 * The W2026-27 registration products (SKUs 116522-P / 116522-G) are deliberately held
 * out of stock until registration opens, so "Out of stock" reads as "sold out" when we
 * mean "not open yet".
 *
 * NOTE: the check has to happen on `wp`, not inside the filter. The [product_page]
 * shortcode that renders the two products replaces the main query while it runs, so by
 * the time the availability filter fires, is_page()/get_queried_object() return
 * false/NULL. Attaching the filter conditionally on `wp` — while the main query is still
 * intact — avoids that entirely.
 */
add_action(
	'wp',
	function () {
		if ( ! is_page( 11113 ) ) {
			return;
		}

		add_filter(
			'woocommerce_get_availability_text',
			function ( $availability, $product ) {
				if ( $product instanceof WC_Product && ! $product->is_in_stock() ) {
					return 'Coming soon';
				}

				return $availability;
			},
			10,
			2
		);
	}
);