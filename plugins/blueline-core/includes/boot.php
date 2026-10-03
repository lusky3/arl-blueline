<?php
/**
 * Boot, module loading, legacy-theme coexistence and the rewrite-flush helper.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

// Option set on activation; the next `init` (priority 99) flushes and clears it.
const BLUELINE_CORE_FLUSH_OPTION = 'blueline_core_flush_rewrite_rules';

// Last plugin version that flushed rewrite rules; a mismatch triggers one flush after an upgrade.
const BLUELINE_CORE_VERSION_OPTION = 'blueline_core_version';

// A function only a pre-1.1.0 Blueline theme defines (it moved into this plugin's player-link module).
const BLUELINE_CORE_LEGACY_SENTINEL = 'blueline_get_linked_player_id';

// The mu-plugin that must exist before any flush (a flush once exposed a /register 405 without it).
const BLUELINE_CORE_REGISTER_FIX_MU_PLUGIN = 'rh-royal-mcp-register-fix.php';

/**
 * Boot the plugin on `after_setup_theme` priority 1: load modules, or stay idle beside a legacy theme.
 *
 * @return void
 */
function blueline_core_boot(): void {
	/**
	 * Filters whether a legacy (pre-1.1.0) Blueline theme still provides the moved features.
	 *
	 * @param bool $legacy Detected from BLUELINE_CORE_LEGACY_SENTINEL.
	 */
	if ( (bool) apply_filters( 'blueline_core_legacy_theme_active', blueline_core_legacy_theme_active() ) ) {
		add_action( 'admin_notices', 'blueline_core_legacy_theme_notice' );
		return;
	}

	blueline_core_load_modules( blueline_core_modules() );
	add_action( 'init', 'blueline_core_maybe_flush_rewrite_rules', 99 );

	/**
	 * Fires once the plugin's modules are loaded (never beside a legacy theme).
	 */
	do_action( 'blueline_core_loaded' );
}

/**
 * The ordered module list from includes/modules.php: slug => file relative to includes/.
 *
 * @return array<string, string>
 */
function blueline_core_modules(): array {
	$modules = require BLUELINE_CORE_DIR . '/includes/modules.php';

	/**
	 * Filters the ordered module list. Remove a slug to switch that module off.
	 *
	 * @param array<string, string> $modules Slug => file relative to includes/.
	 */
	$modules = apply_filters( 'blueline_core_modules', is_array( $modules ) ? $modules : array() );

	return is_array( $modules ) ? $modules : array();
}

/**
 * Require each module file in order, skipping files that do not exist yet.
 *
 * @param array<string, string> $modules Slug => file relative to includes/.
 * @return string[] Slugs loaded so far (this call and earlier ones).
 */
function blueline_core_load_modules( array $modules ): array {
	$loaded = &blueline_core_loaded_modules();

	foreach ( $modules as $slug => $relative ) {
		$file = BLUELINE_CORE_DIR . '/includes/' . ltrim( (string) $relative, '/' );

		if ( ! is_readable( $file ) ) {
			continue;
		}

		require_once $file;
		$loaded[ (string) $slug ] = $file;
	}

	return array_keys( $loaded );
}

/**
 * Request-scoped registry of loaded modules: slug => absolute file.
 *
 * @return array<string, string>
 */
function &blueline_core_loaded_modules(): array {
	static $loaded = array();

	return $loaded;
}

/**
 * Whether a module was loaded in this request.
 *
 * @param string $slug Module slug, as listed in includes/modules.php.
 * @return bool
 */
function blueline_core_module_loaded( string $slug ): bool {
	return isset( blueline_core_loaded_modules()[ $slug ] );
}

/**
 * Whether the sentinel function exists and was defined outside this plugin's includes/.
 *
 * @param string $sentinel Function name only a legacy theme defines.
 * @return bool
 */
function blueline_core_legacy_theme_active( string $sentinel = BLUELINE_CORE_LEGACY_SENTINEL ): bool {
	if ( ! function_exists( $sentinel ) ) {
		return false;
	}

	$file     = str_replace( '\\', '/', (string) ( new ReflectionFunction( $sentinel ) )->getFileName() );
	$includes = str_replace( '\\', '/', BLUELINE_CORE_DIR . '/includes/' );

	return ! str_starts_with( $file, $includes );
}

/**
 * Admin notice shown while a legacy theme keeps the plugin idle.
 *
 * @return void
 */
function blueline_core_legacy_theme_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'Blueline Core is idle: update the Blueline theme to 1.1.0 or newer. Until then the theme keeps providing these features itself.', 'blueline-core' )
	);
}

/**
 * Activation: flag a rewrite flush for the next `init`, after modules have registered their endpoints.
 *
 * @return void
 */
function blueline_core_activate(): void {
	update_option( BLUELINE_CORE_FLUSH_OPTION, 1, false );
}

/**
 * Deactivation: drop the pending flag and let WordPress rebuild rules without this plugin's endpoints.
 *
 * @return void
 */
function blueline_core_deactivate(): void {
	delete_option( BLUELINE_CORE_FLUSH_OPTION );

	if ( blueline_core_rewrite_flush_safe() ) {
		delete_option( 'rewrite_rules' );
	}
}

/**
 * Whether flushing rewrite rules is safe here: the /register 405 fix mu-plugin is present.
 *
 * @return bool
 */
function blueline_core_rewrite_flush_safe(): bool {
	$safe = defined( 'WPMU_PLUGIN_DIR' ) && is_readable( WPMU_PLUGIN_DIR . '/' . BLUELINE_CORE_REGISTER_FIX_MU_PLUGIN );

	/**
	 * Filters whether the plugin may flush rewrite rules (e.g. a local site without the mu-plugin).
	 *
	 * @param bool $safe True when the register-fix mu-plugin is present.
	 */
	return (bool) apply_filters( 'blueline_core_rewrite_flush_safe', $safe );
}

/**
 * On `init` priority 99: flush once after activation or a version change, when safe.
 *
 * @return bool Whether rules were flushed.
 */
function blueline_core_maybe_flush_rewrite_rules(): bool {
	$pending = (bool) get_option( BLUELINE_CORE_FLUSH_OPTION, false )
		|| BLUELINE_CORE_VERSION !== get_option( BLUELINE_CORE_VERSION_OPTION, '' );

	if ( ! $pending ) {
		return false;
	}

	if ( ! blueline_core_rewrite_flush_safe() ) {
		add_action( 'admin_notices', 'blueline_core_flush_blocked_notice' );
		return false;
	}

	flush_rewrite_rules( false );
	delete_option( BLUELINE_CORE_FLUSH_OPTION );
	update_option( BLUELINE_CORE_VERSION_OPTION, BLUELINE_CORE_VERSION, false );

	/**
	 * Fires after the plugin flushed rewrite rules.
	 */
	do_action( 'blueline_core_rewrite_rules_flushed' );

	return true;
}

/**
 * Admin notice shown while a pending flush is held back by the missing mu-plugin.
 *
 * @return void
 */
function blueline_core_flush_blocked_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: mu-plugin file name. */
				__( 'Blueline Core has not flushed rewrite rules because the %s must-use plugin is missing. Install it first; My Account links may 404 until then.', 'blueline-core' ),
				BLUELINE_CORE_REGISTER_FIX_MU_PLUGIN
			)
		)
	);
}
