<?php
/**
 * Boot, module loading, legacy-theme coexistence and the rewrite-flush helper.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

// Legacy flag option (set on activation by 0.1.0). Nothing sets it any more -- activation now deletes
// the version option below instead -- but it is still cleared after a flush, on deactivation and on
// uninstall so a leftover row never lingers.
const BLUELINE_CORE_FLUSH_OPTION = 'blueline_core_flush_rewrite_rules';

// Last plugin version that flushed rewrite rules; missing or different triggers one flush (activation,
// upgrade). Stored autoloaded so the per-request check below costs no query.
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
 * Hard module dependencies: dependent slug => slugs whose functions it calls at runtime.
 *
 * The loader loads a required module (when it is in the list at all) before its dependent, whatever
 * order the list or the `blueline_core_modules` filter gives. A dependency that was switched off is
 * not forced back on: the dependent must already degrade via function_exists().
 *
 * - mail needs seo-meta for blueline_social_logo_url() (the email header logo).
 *
 * @return array<string, string[]>
 */
function blueline_core_module_requirements(): array {
	return array(
		'mail' => array( 'seo-meta' ),
	);
}

/**
 * Reorder a module list so every module follows the modules it requires, otherwise keeping order.
 *
 * @param array<string, string> $modules Slug => file relative to includes/.
 * @return array<string, string> Same entries, dependencies first.
 */
function blueline_core_order_modules( array $modules ): array {
	$requirements = blueline_core_module_requirements();
	$ordered      = array();
	$visiting     = array();

	$visit = static function ( string $slug ) use ( &$visit, &$ordered, &$visiting, $modules, $requirements ): void {
		if ( isset( $ordered[ $slug ] ) || isset( $visiting[ $slug ] ) || ! isset( $modules[ $slug ] ) ) {
			return;
		}

		$visiting[ $slug ] = true;
		foreach ( $requirements[ $slug ] ?? array() as $required ) {
			$visit( $required );
		}
		unset( $visiting[ $slug ] );

		$ordered[ $slug ] = $modules[ $slug ];
	};

	foreach ( array_keys( $modules ) as $slug ) {
		$visit( (string) $slug );
	}

	return $ordered;
}

/**
 * Require each module file in order (dependencies first). A listed file that is missing or
 * unreadable is skipped so the site stays up, but loudly: it is recorded (see
 * blueline_core_failed_modules()), written to the PHP error log, shown to administrators in an admin
 * notice and reported by the Site Health test. Every listed module is expected to exist; a miss
 * means a broken deploy, and modules such as `privacy` are security-relevant.
 *
 * @param array<string, string> $modules Slug => file relative to includes/.
 * @return string[] Slugs loaded so far (this call and earlier ones).
 */
function blueline_core_load_modules( array $modules ): array {
	$loaded = &blueline_core_loaded_modules();
	$failed = &blueline_core_failed_modules();

	foreach ( blueline_core_order_modules( $modules ) as $slug => $relative ) {
		$file = BLUELINE_CORE_DIR . '/includes/' . ltrim( (string) $relative, '/' );

		if ( ! is_readable( $file ) ) {
			if ( ! isset( $failed[ (string) $slug ] ) ) {
				$failed[ (string) $slug ] = $file;
				error_log( sprintf( 'blueline-core: module "%1$s" was not loaded: %2$s is missing or unreadable.', $slug, $file ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a skipped module must reach the server log; there is no other channel this early in boot.
			}
			add_action( 'admin_notices', 'blueline_core_failed_modules_notice' );
			continue;
		}

		require_once $file;
		$loaded[ (string) $slug ] = $file;
	}

	return array_keys( $loaded );
}

/**
 * Request-scoped registry of modules that were listed but could not be loaded: slug => expected file.
 *
 * @return array<string, string>
 */
function &blueline_core_failed_modules(): array {
	static $failed = array();

	return $failed;
}

/**
 * Admin notice naming the modules that failed to load. Administrators only.
 *
 * @return void
 */
function blueline_core_failed_modules_notice(): void {
	$failed = blueline_core_failed_modules();

	if ( ! $failed || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: comma-separated module slugs. */
				__( 'Blueline Core could not load these modules: %s. Their features (and any protection they provide) are switched off. Reinstall the plugin from a complete package.', 'blueline-core' ),
				implode( ', ', array_keys( $failed ) )
			)
		)
	);
}

add_filter( 'site_status_tests', 'blueline_core_register_site_health_test' );
/**
 * Register the "Blueline Core modules" Site Health direct test.
 *
 * @param array<string, array<string, mixed>> $tests Site Health tests.
 * @return array<string, array<string, mixed>>
 */
function blueline_core_register_site_health_test( $tests ) {
	$tests['direct']['blueline_core_modules'] = array(
		'label' => __( 'Blueline Core modules', 'blueline-core' ),
		'test'  => 'blueline_core_run_site_health_test',
	);

	return $tests;
}

/**
 * Run the Site Health test against this request's loader registries.
 *
 * @return array<string, mixed>
 */
function blueline_core_run_site_health_test(): array {
	return blueline_core_site_health_result( blueline_core_failed_modules(), blueline_core_loaded_modules() );
}

/**
 * The Site Health result for the module loader. Pure.
 *
 * @param array<string, string> $failed Slug => file for modules that could not be loaded.
 * @param array<string, string> $loaded Slug => file for modules that were loaded.
 * @return array<string, mixed>
 */
function blueline_core_site_health_result( array $failed, array $loaded ): array {
	if ( $failed ) {
		$status      = 'critical';
		$color       = 'red';
		$label       = __( 'Blueline Core could not load some modules', 'blueline-core' );
		$description = sprintf(
			/* translators: %s: comma-separated module slugs. */
			__( 'These modules are listed but their files are missing or unreadable, so their features are off: %s. Reinstall the plugin from a complete package.', 'blueline-core' ),
			implode( ', ', array_keys( $failed ) )
		);
	} elseif ( ! $loaded ) {
		$status      = 'recommended';
		$color       = 'orange';
		$label       = __( 'Blueline Core has loaded no modules', 'blueline-core' );
		$description = __( 'Either the plugin is idle beside a pre-1.1.0 Blueline theme (update the theme) or every module was switched off by a filter.', 'blueline-core' );
	} else {
		$status      = 'good';
		$color       = 'blue';
		$label       = __( 'Blueline Core modules are loaded', 'blueline-core' );
		$description = sprintf(
			/* translators: %s: comma-separated module slugs. */
			__( 'Loaded: %s.', 'blueline-core' ),
			implode( ', ', array_keys( $loaded ) )
		);
	}

	return array(
		'label'       => $label,
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Blueline', 'blueline-core' ),
			'color' => $color,
		),
		'description' => '<p>' . esc_html( $description ) . '</p>',
		'actions'     => '',
		'test'        => 'blueline_core_modules',
	);
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
 * Activation: forget the last-flushed version, so the next `init` flushes rewrite rules after the
 * modules have registered their endpoints. (No separate flag option: see
 * blueline_core_maybe_flush_rewrite_rules() for why.)
 *
 * @return void
 */
function blueline_core_activate(): void {
	delete_option( BLUELINE_CORE_VERSION_OPTION );
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
 * This runs on every request, so the steady-state path must be free. It is ONE read of an
 * autoloaded option (served from the alloptions cache WordPress already loaded, no query). An
 * absent non-autoloaded option, by contrast, costs a query per request without a persistent object
 * cache, which is what the old separate "flush pending" flag did; hence activation now just deletes
 * the version option and the version mismatch drives the flush.
 *
 * @return bool Whether rules were flushed.
 */
function blueline_core_maybe_flush_rewrite_rules(): bool {
	if ( BLUELINE_CORE_VERSION === get_option( BLUELINE_CORE_VERSION_OPTION, '' ) ) {
		return false;
	}

	if ( ! blueline_core_rewrite_flush_safe() ) {
		add_action( 'admin_notices', 'blueline_core_flush_blocked_notice' );
		return false;
	}

	flush_rewrite_rules( false );
	delete_option( BLUELINE_CORE_FLUSH_OPTION );
	update_option( BLUELINE_CORE_VERSION_OPTION, BLUELINE_CORE_VERSION, true );

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
