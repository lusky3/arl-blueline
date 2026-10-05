<?php
/**
 * Ordered module list: slug => file relative to includes/.
 *
 * A listed file that is missing or unreadable is skipped at boot but reported loudly (error log,
 * admin notice, Site Health). Every entry is expected to exist.
 *
 * Order: modules load top to bottom. mail reads seo-meta's blueline_social_logo_url() behind
 * function_exists(), so seo-meta stays above mail (BootTest pins it); without it the email
 * header logo is simply empty.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'player-link'       => 'player-link/player-link.php',
	'player-photo'      => 'player-photo/player-photo.php',
	'avatars'           => 'avatars/avatars.php',
	'account-endpoints' => 'account-endpoints/account-endpoints.php',
	'seo-meta'          => 'seo-meta/seo-meta.php',
	'mail'              => 'mail/mail.php',
	'checkout'          => 'checkout/checkout.php',
	'admin-bar'         => 'admin-bar/admin-bar.php',
	'search'            => 'search/search.php',
	'privacy'           => 'privacy/privacy.php',
	'plugin-info'       => 'plugin-info/plugin-info.php',
);
