<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see inc/template-tags.php's identical disable.
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/endpoints.php';
require_once __DIR__ . '/../inc/account/dashboard.php';

/**
 * Covers blueline_account_nav_items() -- previously untested despite backing
 * the live nav template (woocommerce/myaccount/navigation.php).
 */
final class AccountNavItemsTest extends TestCase {

	/**
	 * dashboard and customer-logout are WooCommerce-owned menu items with no
	 * entry in blueline_account_endpoints() -- they must tag as group null,
	 * not throw or silently vanish.
	 */
	public function test_woocommerce_owned_items_get_a_null_group(): void {
		$items = blueline_account_nav_items(
			array(
				'dashboard'       => 'Dashboard',
				'customer-logout' => 'Log out',
			)
		);

		$this->assertSame( 'dashboard', $items[0]['endpoint'] );
		$this->assertNull( $items[0]['group'] );
		$this->assertSame( 'customer-logout', $items[1]['endpoint'] );
		$this->assertNull( $items[1]['group'] );
	}

	/**
	 * edit-account must carry the 'account' group -- not 'billing' -- so the
	 * nav template can render it as a top-level pill, never inside the
	 * Billing disclosure.
	 */
	public function test_edit_account_carries_the_account_group(): void {
		$items = blueline_account_nav_items( array( 'edit-account' => 'Account Details' ) );

		$this->assertSame( 'account', $items[0]['group'] );
	}

	/**
	 * A real billing endpoint still carries the 'billing' group.
	 */
	public function test_a_billing_endpoint_carries_the_billing_group(): void {
		$items = blueline_account_nav_items( array( 'edit-address' => 'Addresses' ) );

		$this->assertSame( 'billing', $items[0]['group'] );
	}

	/**
	 * Order and label pass through unchanged from $menu_items -- this
	 * function only adds the group tag, it never reorders or relabels.
	 */
	public function test_order_and_labels_pass_through_unchanged(): void {
		$items = blueline_account_nav_items(
			array(
				'dashboard' => 'Dashboard',
				'my-team'   => 'My Team',
			)
		);

		$this->assertSame( array( 'dashboard', 'my-team' ), array_column( $items, 'endpoint' ) );
		$this->assertSame( array( 'Dashboard', 'My Team' ), array_column( $items, 'label' ) );
	}
}
