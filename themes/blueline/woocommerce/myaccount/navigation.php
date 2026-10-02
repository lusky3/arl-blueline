<?php
/**
 * The Blue Line My Account nav: a horizontal row of pill tabs, ending with
 * the billing group collapsed into a <details> dropdown. The dropdown is
 * its own <li> in the SAME <ul> as the pills, not a sibling of the list --
 * that keeps it inside the one flex-wrap flow (account.css's
 * `.bl-account-nav__pills`), so it wraps onto whichever line still has
 * room instead of always dropping to a line of its own. Overrides
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
 * The list wraps onto extra lines once it runs out of width, rather than
 * scrolling -- it does NOT carry .bl-table-scroll (assets/src/js/
 * table-scroll.js's mobile scroll-edge-fade mechanism, used for wide
 * SportsPress tables): a live check at ~970px found the earlier
 * scrolling version showing a bare OS scrollbar under primary navigation,
 * with no visible cue that the missing tabs were a scroll away rather
 * than just gone. See account.css's own note on `.bl-account-nav__pills`.
 *
 * Opens with the theme's own `.bl-band`/`.bl-band--ink` pair (base.css) --
 * the same paired ice+navy rule the homepage hero closes on
 * (inc/homepage-modules.php) -- to mark the handoff from the site's global
 * chrome into account content. Without it the account page carried none of
 * the site's four signature devices (docs/DESIGN.md) at all, which read as
 * a generic dashboard bolted onto the site rather than a page of it.
 *
 * Rewrite of WooCommerce's myaccount/navigation.php, checked against core 9.3.0 (WC 11.0.1).
 *
 * @package blueline
 * @version 9.3.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_navigation' );

$blueline_nav_items = function_exists( 'blueline_account_nav_items' )
	? blueline_account_nav_items( wc_get_account_menu_items() )
	: array();

$blueline_pill_items    = array_values( array_filter( $blueline_nav_items, static fn( $item ) => 'billing' !== $item['group'] ) );
$blueline_billing_items = array_values( array_filter( $blueline_nav_items, static fn( $item ) => 'billing' === $item['group'] ) );

/*
 * Whether the CURRENT page is one of the billing-group endpoints -- the
 * <details> starts collapsed, so nothing else marks it (or its <summary>)
 * as the active nav item when viewing e.g. /account/edit-address/ or the
 * refund-requests tab. Mirrors the same wc_get_account_menu_item_classes()
 * check the <li> loop below already runs per item.
 */
$blueline_billing_active = (bool) array_filter(
	$blueline_billing_items,
	static fn( $item ) => str_contains( wc_get_account_menu_item_classes( $item['endpoint'] ), 'is-active' )
);
?>
<div class="bl-account-nav__band">
	<div class="bl-band" aria-hidden="true"></div>
	<div class="bl-band--ink" aria-hidden="true"></div>
</div>
<nav class="woocommerce-MyAccount-navigation bl-account-nav" aria-label="<?php esc_attr_e( 'Account', 'blueline' ); ?>">
	<ul class="bl-account-nav__pills">
		<?php foreach ( $blueline_pill_items as $blueline_nav_item ) : ?>
			<?php $blueline_item_classes = wc_get_account_menu_item_classes( $blueline_nav_item['endpoint'] ); ?>
			<li class="<?php echo esc_attr( $blueline_item_classes ); ?>">
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( $blueline_nav_item['endpoint'] ) ); ?>"<?php echo str_contains( $blueline_item_classes, 'is-active' ) ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $blueline_nav_item['label'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>

		<?php if ( $blueline_billing_items ) : ?>
			<li class="bl-account-nav__billing-item">
				<details class="bl-account-nav__billing<?php echo $blueline_billing_active ? ' is-active' : ''; ?>"<?php echo $blueline_billing_active ? ' open' : ''; ?>>
					<summary<?php echo $blueline_billing_active ? ' aria-current="true"' : ''; ?>><?php esc_html_e( 'Account & Billing', 'blueline' ); ?></summary>
					<ul class="bl-account-nav__billing-panel">
						<?php foreach ( $blueline_billing_items as $blueline_nav_item ) : ?>
							<?php $blueline_item_classes = wc_get_account_menu_item_classes( $blueline_nav_item['endpoint'] ); ?>
							<li class="<?php echo esc_attr( $blueline_item_classes ); ?>">
								<a href="<?php echo esc_url( wc_get_account_endpoint_url( $blueline_nav_item['endpoint'] ) ); ?>"<?php echo str_contains( $blueline_item_classes, 'is-active' ) ? ' aria-current="page"' : ''; ?>>
									<?php echo esc_html( $blueline_nav_item['label'] ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			</li>
		<?php endif; ?>
	</ul>
</nav>
<?php

do_action( 'woocommerce_after_account_navigation' );

/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */