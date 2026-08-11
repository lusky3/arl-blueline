<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<?php do_action( 'woocommerce_email_header', $email_heading ); ?>

<?php
if ( $coupon->get_description() ) :
	echo wp_kses_post( wpautop( wptexturize( $coupon->get_description() ) ) );
endif;
?>


<p><?php echo sprintf( __( "To redeem your credit use the following code during your next registration:", 'woocommerce-store-credit' ), $blogname ); ?></p>

<p style="margin: 40px 0;">
<strong style="display: block; font-size: 2em; line-height: 1.2em; text-align: center;"><?php echo esc_html( $coupon->get_code() ); ?></strong>
</p>


<?php
if ( $additional_content ) :
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
endif;
?>

<div style="clear:both;"></div>

<?php do_action( 'woocommerce_email_footer' ); ?>