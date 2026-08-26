<?php
/**
 * The Blue Line My Account nav: a horizontal row of pill tabs, with the
 * billing group collapsed into a <details> dropdown that sits alongside
 * (not inside) the scrolling pill row -- see this file's own inline note
 * on why those two must be siblings, not parent/child. Overrides
 * WooCommerce's own generic, ungrouped `myaccount/navigation.php`.
 *
 * WooCommerce's wc_get_account_menu_items() already aggregates every
 * account tab -- core, this theme's own, and plugin-added ones (e.g. YITH
 * Advanced Refund System's 'refund-requests') -- through the `woocommerce_account_menu_items`
 * filter chain. blueline_account_nav_items() (inc/account/dashboard.php)
 * tags each item with the 'group' blueline_account_endpoints()
 * (inc/account/endpoints.php) assigns its slug: 'league', 'billing',
 * 'account', or null for the two WooCommerce-owned items not in that map
 * (dashboard, customer-logout). A future plugin adding a new tab needs no
 * change here -- it appears automatically; only its group in
 * blueline_account_endpoints() decides whether it renders as a top-level
 * pill or inside the Billing dropdown.
 *
 * The pill row reuses .bl-table-scroll (assets/src/js/table-scroll.js,
 * sportspress.css's [data-fade-start]/[data-fade-end] mask rules) for its
 * mobile horizontal-scroll edge cue -- the same mechanism this theme
 * already uses for wide tables, not a new scroll affordance.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_navigation' );

$blueline_nav_items = function_exists( 'blueline_account_nav_items' )
	? blueline_account_nav_items( wc_get_account_menu_items() )
	: array();

$blueline_pill_items    = array_values( array_filter( $blueline_nav_items, static fn( $item ) => 'billing' !== $item['group'] ) );
$blueline_billing_items = array_values( array_filter( $blueline_nav_items, static fn( $item ) => 'billing' === $item['group'] ) );
?>
<nav class="woocommerce-MyAccount-navigation bl-account-nav" aria-label="<?php esc_attr_e( 'Account', 'blueline' ); ?>">
	<ul class="bl-account-nav__pills bl-table-scroll">
		<?php foreach ( $blueline_pill_items as $blueline_nav_item ) : ?>
			<li class="<?php echo esc_attr( wc_get_account_menu_item_classes( $blueline_nav_item['endpoint'] ) ); ?>">
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( $blueline_nav_item['endpoint'] ) ); ?>">
					<?php echo esc_html( $blueline_nav_item['label'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $blueline_billing_items ) : ?>
		<details class="bl-account-nav__billing">
			<summary><?php esc_html_e( 'Account & Billing', 'blueline' ); ?></summary>
			<ul class="bl-account-nav__billing-panel">
				<?php foreach ( $blueline_billing_items as $blueline_nav_item ) : ?>
					<li class="<?php echo esc_attr( wc_get_account_menu_item_classes( $blueline_nav_item['endpoint'] ) ); ?>">
						<a href="<?php echo esc_url( wc_get_account_endpoint_url( $blueline_nav_item['endpoint'] ) ); ?>">
							<?php echo esc_html( $blueline_nav_item['label'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
</nav>
<?php

do_action( 'woocommerce_after_account_navigation' );

/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */