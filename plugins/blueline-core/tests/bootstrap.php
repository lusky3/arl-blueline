<?php
/**
 * Plugin test bootstrap.
 *
 * Reuses the theme's WordPress/WooCommerce/SportsPress stubs and blueline_test_*() helpers
 * (themes/blueline/tests/bootstrap.php) instead of copying them, then loads plugin-only
 * stubs from stubs-extra.php, then the plugin itself (constants + boot functions; no module
 * is loaded until a test boots or requires one).
 *
 * @package blueline-core
 */

// Tells the theme bootstrap to skip its own vendor autoloader (PHPUnit comes from ours).
define( 'BLUELINE_CORE_TESTS', true );

require_once __DIR__ . '/../vendor/autoload.php';
require_once dirname( __DIR__, 3 ) . '/themes/blueline/tests/bootstrap.php';
require_once __DIR__ . '/stubs-extra.php';

// One stub file per module (tests/stubs/<slug>.php), so parallel module work never collides.
$blueline_core_stub_files = glob( __DIR__ . '/stubs/*.php' ) ?: array(); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- glob() returns false on error.
sort( $blueline_core_stub_files );
foreach ( $blueline_core_stub_files as $blueline_core_stub_file ) {
	require_once $blueline_core_stub_file;
}
unset( $blueline_core_stub_files, $blueline_core_stub_file );

require_once dirname( __DIR__ ) . '/blueline-core.php';
