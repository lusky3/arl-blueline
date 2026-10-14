<?php
/**
 * Customer send store credit email (HTML) template.
 *
 * Blueline override: the stock template saying "during your next
 * registration" where it said "during checkout". The edited string uses the
 * blueline text domain; the rest is the original (Kestrel Store Credit,
 * GPL-3.0). When Store Credit bumps @version, re-copy the original and
 * re-apply that string (scripts/check-template-versions.sh).
 *
 * Copied from Store Credit 5.1.3: woocommerce-store-credit/templates/emails/customer-store-credit.php
 *
 * @author    Kestrel
 * @copyright Copyright (c) 2012-2025 Kestrel Commerce LLC [hey@kestrelwp.com]
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @package WC_Store_Credit/Templates/Emails
 * @since 3.0.0
 * @version 5.0.0
 *
 * @var WC_Store_Credit_Email_Send_Credit $email email object
 * @var string $email_heading email heading
 * @var string $additional_content additional content
 * @var WC_Coupon $coupon coupon object
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore

if ( $coupon->get_description() ) :
	echo wp_kses_post( wpautop( wptexturize( $coupon->get_description() ) ) );
endif;

?>
<p><?php esc_html_e( 'To redeem your store credit use the following code during your next registration:', 'blueline' ); ?></p>

<div class="store-credit-wrapper text-center">
	<span class="store-credit-code"><?php echo esc_html( $coupon->get_code() ); ?></span>
</div>

<div class="store-credit-wrapper text-center">
	<?php printf( '<a class="store-credit-cta-button" href="%1$s" target="_blank">%2$s</a>', esc_url( wc_store_credit_get_redeem_url( $coupon ) ), esc_html( $email->get_button_text() ) ); ?>
</div>

<?php

$date_expires = $coupon->get_date_expires();

if ( $date_expires ) :

	echo '<p class="text-center">';
	/* translators: Placeholder: %s - expiration date */
	echo wp_kses_post( sprintf( __( 'This credit can be redeemed until %s.', 'woocommerce-store-credit' ), '<strong>' . esc_html( wc_format_datetime( $date_expires ) ) . '</strong>' ) );
	echo '</p>';

endif;

?>

<div class="store-credit-wrapper store-credit-restrictions">
	<?php

	$product_ids = $coupon->get_product_ids();

	if ( ! empty( $product_ids ) ) :

		$product_names = array_filter(
			array_map(
				function ( $id ) {
					$product = wc_get_product( $id );
					return $product ? '"' . $product->get_name() . '"' : '';
				},
				$product_ids
			)
		);

		if ( ! empty( $product_names ) ) :
			/* translators: Placeholder: %s - Comma-separated list of product names */
			echo '<p>' . wp_kses_post( sprintf( _n( 'This credit is valid for the following product: %s.', 'This credit is valid for the following products: %s.', count( $product_names ), 'woocommerce-store-credit' ), implode( ', ', $product_names ) ) ) . '</p>';
		endif;

	endif;

	$category_ids = $coupon->get_product_categories();

	if ( ! empty( $category_ids ) ) :

		$category_names = array_filter(
			array_map(
				function ( $id ) {
					$term = get_term( $id, 'product_cat' );
					return $term ? '"' . $term->name . '"' : '';
				},
				$category_ids
			)
		);

		if ( ! empty( $category_names ) ) :
			/* translators: Placeholder: %s - Comma-separated list of product category names */
			echo '<p>' . wp_kses_post( sprintf( _n( 'This credit is valid for the following product category: %s.', 'This credit is valid for the following product categories: %s.', count( $category_names ), 'woocommerce-store-credit' ), implode( ', ', $category_names ) ) ) . '</p>';
		endif;

	endif;

	?>
</div>
<?php

if ( $additional_content ) :
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
endif;

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore
