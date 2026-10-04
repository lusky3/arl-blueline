<?php
/**
 * Uninstall: remove only this plugin's own options. User and post meta (sp_user, blueline_avatar_id, ...) are league data and stay.
 *
 * Deleting a person's own data is not an uninstall concern: a personal-data erasure request does it
 * per user, through the exporter/eraser pair in includes/privacy/privacy.php (avatar pointer + image,
 * sp_user link + own player photo). Uninstalling would otherwise destroy league data for everyone.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// One option per line, so modules can add theirs without merge conflicts.
$blueline_core_options = array(
	'blueline_core_flush_rewrite_rules',
	'blueline_core_version',
);

foreach ( $blueline_core_options as $blueline_core_option ) {
	delete_option( $blueline_core_option );
}
