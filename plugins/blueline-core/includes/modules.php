<?php
/**
 * Ordered module list: slug => file relative to includes/.
 *
 * A listed file that is missing or unreadable is skipped at boot but reported loudly (error log,
 * admin notice, Site Health). Every entry is expected to exist.
 *
 * Dependencies: a module that calls another module's functions is declared in
 * blueline_core_module_requirements() (includes/boot.php); the loader then loads the required
 * module first regardless of this order. Today: mail requires seo-meta (blueline_social_logo_url()).
 * Keep requirements listed before their dependents here too, for readability.
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
