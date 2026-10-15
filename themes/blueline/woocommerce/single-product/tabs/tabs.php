<?php
/**
 * Single Product tabs
 *
 * Copied from WooCommerce 11.0.1: woocommerce/templates/single-product/tabs/tabs.php
 *
 * The one change from stock: where a page embeds a product that is not the
 * page itself (/register's [product_page] shortcodes), every tab id carries
 * the product id (blueline_wc_product_tab_id_suffix(), inc/woocommerce.php).
 * Stock prints the same #tab-description / #tab-title-description for every
 * product, so with two products on one page each tab's aria-controls and each
 * panel's aria-labelledby resolved to the first product's elements.
 * WooCommerce's tab script finds a panel by its tab link's href, so the
 * suffixed ids keep working; on the product's own page the stock ids stay, for
 * the #tab-reviews links WooCommerce prints. Each panel is a <section> rather
 * than stock's <div>: its class carries the tab's key, which a plugin can set
 * to anything, and tests/NoticeDivGuardTest.php allows no <div> whose class
 * PHP builds from an open set. WooCommerce's script and styles find panels
 * by class, and role="tabpanel" sets the role either way.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 9.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter tabs and allow third parties to add their own.
 *
 * Each tab is an array containing title, callback and priority.
 *
 * @see woocommerce_default_product_tabs()
 */
$product_tabs = apply_filters( 'woocommerce_product_tabs', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- stock template variable.

$blueline_tab_suffix = blueline_wc_product_tab_id_suffix( $GLOBALS['product'] ?? null );

if ( ! empty( $product_tabs ) ) : ?>

	<div class="woocommerce-tabs wc-tabs-wrapper">
		<ul class="tabs wc-tabs" role="tablist">
			<?php foreach ( $product_tabs as $key => $product_tab ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- stock template variables. ?>
				<?php $blueline_tab_id = $key . $blueline_tab_suffix; ?>
				<li role="presentation" class="<?php echo esc_attr( $key ); ?>_tab" id="tab-title-<?php echo esc_attr( $blueline_tab_id ); ?>">
					<a href="#tab-<?php echo esc_attr( $blueline_tab_id ); ?>" role="tab" aria-controls="tab-<?php echo esc_attr( $blueline_tab_id ); ?>">
						<?php echo wp_kses_post( apply_filters( 'woocommerce_product_' . $key . '_tab_title', $product_tab['title'], $key ) ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- stock hook. ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php foreach ( $product_tabs as $key => $product_tab ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- stock template variables. ?>
			<?php $blueline_tab_id = $key . $blueline_tab_suffix; ?>
			<section class="woocommerce-Tabs-panel woocommerce-Tabs-panel--<?php echo esc_attr( $key ); ?> panel entry-content wc-tab" id="tab-<?php echo esc_attr( $blueline_tab_id ); ?>" role="tabpanel" aria-labelledby="tab-title-<?php echo esc_attr( $blueline_tab_id ); ?>">
				<?php
				if ( isset( $product_tab['callback'] ) ) {
					call_user_func( $product_tab['callback'], $key, $product_tab );
				}
				?>
			</section>
		<?php endforeach; ?>

		<?php do_action( 'woocommerce_product_after_tabs' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- stock hook. ?>
	</div>

<?php endif; ?>
