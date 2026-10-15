<?php
/**
 * Stand-in for WooCommerce Subscriptions being active: the class the account
 * menu checks for. Load it only inside a #[RunInSeparateProcess] test, since a
 * class cannot be undeclared afterwards.
 *
 * @package blueline-core
 */

/**
 * WooCommerce Subscriptions' subscription object; an empty shell is enough.
 */
class WCS_Subscription {}
