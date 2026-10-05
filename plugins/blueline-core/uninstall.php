<?php
/**
 * Uninstall: remove only this plugin's own options. User and post meta (sp_user, blueline_avatar_id, ...)
 * are league data and stay; a personal-data erasure request removes one person's data
 * (includes/privacy/privacy.php), whereas uninstalling would destroy it for everyone.
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
