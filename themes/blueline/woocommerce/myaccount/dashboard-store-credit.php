<?php
/**
 * "My account > Store credit" dashboard section template.
 *
 * Blueline override: the stock template saying "registration" where it said
 * "purchase". The edited string uses the blueline text domain; the rest is
 * the original (Kestrel Store Credit, GPL-3.0). When Store Credit bumps the
 * version below, re-copy the original and re-apply that string
 * (scripts/check-template-versions.sh).
 *
 * Copied from Store Credit 5.1.3: woocommerce-store-credit/templates/myaccount/dashboard-store-credit.php
 *
 * @author    Kestrel
 * @copyright Copyright (c) 2012-2025 Kestrel Commerce LLC [hey@kestrelwp.com]
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @package WC_Store_Credit/Templates/My Account
 * @version 4.0.0
 */

defined( 'ABSPATH' ) || exit;

$coupons = wc_store_credit_get_customer_coupons( get_current_user_id() );

if ( ! empty( $coupons ) && 'yes' === get_option( 'wc_store_credit_show_my_account', 'yes' ) ) :

	?>
	<h3><?php esc_html_e( 'Store credit', 'woocommerce-store-credit' ); ?></h3>
	<p>
		<?php
		printf(
			/* translators: %s: URL of the Store credit page in My Account. */
			wp_kses_post( __( 'You have <a href="%s">store credit coupons</a> available to spend on your next registration.', 'blueline' ) ),
			esc_url( wc_get_endpoint_url( 'store-credit' ) )
		);
		?>
	</p>
	<?php

endif;
