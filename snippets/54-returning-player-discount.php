/**
 * ARL: automatic returning-player discount.
 *
 * Replaces the old per-player coupon codes (629 of them) that players kept forgetting
 * to paste, which led to refund requests. Nothing to email, nothing to type.
 *
 * Eligible = a completed/processing order containing a Registration-category product
 * (product_cat 91) in the last 2 years, matched EITHER by user account OR by billing
 * email. The email path matters: ~25% of registration orders are guest checkouts, and
 * about half of those emails already have an account the player just did not log into.
 *
 * The coupon 'returning-player' ($25 off product 116522) is applied programmatically and
 * locked by woocommerce_coupon_is_valid, so typing the code by hand does nothing unless
 * the person actually qualifies.
 */
if ( class_exists( 'WooCommerce' ) ) {

	function arl_rp_code()     { return 'returning-player'; }
	function arl_rp_products() { return array( 116522 ); }   // update for next season's player product
	function arl_rp_years()    { return 2; }

	/** Does this account / email have a qualifying registration in the window? */
	function arl_rp_has_history( $user_id = 0, $email = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$email   = strtolower( trim( (string) $email ) );
		if ( ! $user_id && ! $email ) {
			return false;
		}

		$key = 'arl_rp_' . md5( $user_id . '|' . $email );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return '1' === $hit;
		}

		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-' . arl_rp_years() . ' years' ) );
		$tt    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy=%s",
				91,
				'product_cat'
			)
		);

		$base = "SELECT p.ID FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = p.ID
			JOIN {$wpdb->prefix}woocommerce_order_itemmeta im
			     ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id'
			JOIN {$wpdb->term_relationships} tr
			     ON tr.object_id = im.meta_value AND tr.term_taxonomy_id = {$tt}
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ( 'wc-completed', 'wc-processing' )
			  AND p.post_date >= %s ";

		if ( $user_id ) {
			$found = (bool) $wpdb->get_var(
				$wpdb->prepare( $base . "AND pm.meta_key='_customer_user' AND pm.meta_value=%d LIMIT 1", $since, $user_id )
			);
		} else {
			$found = (bool) $wpdb->get_var(
				$wpdb->prepare( $base . "AND pm.meta_key='_billing_email' AND LOWER(pm.meta_value)=%s LIMIT 1", $since, $email )
			);
		}

		set_transient( $key, $found ? '1' : '0', 6 * HOUR_IN_SECONDS );
		return $found;
	}

	/** Best email we know for the person currently checking out. */
	function arl_rp_current_email() {
		if ( WC()->session ) {
			$e = WC()->session->get( 'arl_rp_email' );
			if ( $e ) {
				return $e;
			}
		}
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			return $u ? $u->user_email : '';
		}
		return '';
	}

	function arl_rp_eligible() {
		if ( is_user_logged_in() && arl_rp_has_history( get_current_user_id(), '' ) ) {
			return true;
		}
		$email = arl_rp_current_email();
		return $email ? arl_rp_has_history( 0, $email ) : false;
	}

	/** Capture the billing email as the player types it at checkout. */
	add_action( 'woocommerce_checkout_update_order_review', function ( $post_data ) {
		parse_str( (string) $post_data, $d );
		if ( ! empty( $d['billing_email'] ) && is_email( $d['billing_email'] ) && WC()->session ) {
			WC()->session->set( 'arl_rp_email', sanitize_email( $d['billing_email'] ) );
		}
	} );

	/**
	 * Second chance at submit. The billing email only reaches the server via
	 * update_order_review if an ADDRESS field changes AFTER it is typed --
	 * WooCommerce only triggers a checkout update from .address-field inputs and
	 * #billing_email is not one. Guests who typed their email last therefore never
	 * got the discount. At woocommerce_checkout_process $_POST is complete and the
	 * order has not been created yet, so recalculating here still counts.
	 */
	add_action( 'woocommerce_checkout_process', function () {
		if ( ! empty( $_POST['billing_email'] ) && WC()->session ) {
			$em = sanitize_email( wp_unslash( $_POST['billing_email'] ) );
			if ( is_email( $em ) ) {
				WC()->session->set( 'arl_rp_email', $em );
			}
		}
		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}
	}, 5 );

	/** Apply (or withdraw) the discount as the cart is calculated. */
	add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		static $busy = false;
		if ( $busy ) {
			return;
		}
		$busy = true;

		$code    = arl_rp_code();
		$wanted  = arl_rp_products();
		$present = false;
		foreach ( $cart->get_cart() as $item ) {
			if ( in_array( (int) $item['product_id'], $wanted, true ) ) {
				$present = true;
				break;
			}
		}

		$applied = $cart->has_discount( $code );
		if ( $present && arl_rp_eligible() ) {
			if ( ! $applied ) {
				$cart->apply_coupon( $code );
			}
		} elseif ( $applied ) {
			$cart->remove_coupon( $code );
		}

		$busy = false;
	}, 20 );

	/** The code is useless to anyone who does not qualify. */
	add_filter( 'woocommerce_coupon_is_valid', function ( $valid, $coupon ) {
		if ( strtolower( $coupon->get_code() ) !== arl_rp_code() ) {
			return $valid;
		}
		return arl_rp_eligible() ? $valid : false;
	}, 10, 2 );

	/** Show it as a sentence, not a raw code. */
	add_filter( 'woocommerce_cart_totals_coupon_label', function ( $label, $coupon ) {
		if ( strtolower( $coupon->get_code() ) === arl_rp_code() ) {
			return 'Returning player discount';
		}
		return $label;
	}, 10, 2 );

}
