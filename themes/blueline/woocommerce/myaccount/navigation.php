<?php
/**
 * The Blue Line My Account nav rail (Task 12): league group first, billing
 * group second, per blueline_account_endpoints() (Task 10). Overrides
 * WooCommerce's own generic, ungrouped `myaccount/navigation.php`.
 *
 * WooCommerce's wc_get_account_menu_items() already returns the list in
 * dashboard-then-league-then-billing-then-logout order (Task 10's
 * `woocommerce_account_menu_items` filter); this template only adds the
 * visual group headings between them via blueline_account_nav_items()
 * (inc/account/dashboard.php).
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_navigation' );

$blueline_nav_items = function_exists( 'blueline_account_nav_items' )
	? blueline_account_nav_items( wc_get_account_menu_items() )
	: array();

$blueline_nav_group_labels = array(
	'league'  => __( 'My League', 'blueline' ),
	'billing' => __( 'Account & Billing', 'blueline' ),
);

$blueline_nav_current_group = false;
?>
<nav class="woocommerce-MyAccount-navigation bl-account-nav" aria-label="<?php esc_attr_e( 'Account', 'blueline' ); ?>">
	<?php foreach ( $blueline_nav_items as $blueline_nav_item ) : ?>
		<?php if ( $blueline_nav_item['group'] !== $blueline_nav_current_group ) : ?>
			<?php if ( false !== $blueline_nav_current_group ) : ?>
				</ul>
			<?php endif; ?>
			<?php if ( $blueline_nav_item['group'] && isset( $blueline_nav_group_labels[ $blueline_nav_item['group'] ] ) ) : ?>
				<h2 class="bl-account-nav__group-title bl-account-nav__group-title--<?php echo esc_attr( $blueline_nav_item['group'] ); ?>">
					<?php echo esc_html( $blueline_nav_group_labels[ $blueline_nav_item['group'] ] ); ?>
				</h2>
			<?php endif; ?>
			<ul class="bl-account-nav__list<?php echo $blueline_nav_item['group'] ? '' : ' bl-account-nav__list--plain'; ?>">
			<?php $blueline_nav_current_group = $blueline_nav_item['group']; ?>
		<?php endif; ?>
		<li class="<?php echo esc_attr( wc_get_account_menu_item_classes( $blueline_nav_item['endpoint'] ) ); ?>">
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( $blueline_nav_item['endpoint'] ) ); ?>">
				<?php echo esc_html( $blueline_nav_item['label'] ); ?>
			</a>
		</li>
	<?php endforeach; ?>
	<?php if ( ! empty( $blueline_nav_items ) ) : ?>
		</ul>
	<?php endif; ?>
</nav>
<?php

do_action( 'woocommerce_after_account_navigation' );

/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */