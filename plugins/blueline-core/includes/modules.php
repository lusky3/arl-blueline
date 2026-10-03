<?php
/**
 * Ordered module list: slug => file relative to includes/. A missing file is skipped at boot.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'player-link'       => 'player-link/player-link.php',
	'player-photo'      => 'player-photo/player-photo.php',
	'avatars'           => 'avatars/avatars.php',
	'account-endpoints' => 'account-endpoints/account-endpoints.php',
	'mail'              => 'mail/mail.php',
	'checkout'          => 'checkout/checkout.php',
	'admin-bar'         => 'admin-bar/admin-bar.php',
	'seo-meta'          => 'seo-meta/seo-meta.php',
	'search'            => 'search/search.php',
	'privacy'           => 'privacy/privacy.php',
);
