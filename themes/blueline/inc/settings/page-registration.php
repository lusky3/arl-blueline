<?php
/**
 * Appearance -> Blueline: registering the page. The admin menu entry, its
 * hook suffix, the error-summary focus script, register_setting(), the
 * newer-schema notice and the "Settings saved." flag.
 *
 * Loaded by inc/settings/page.php, whose docblock holds the constraints
 * this part follows.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'blueline_settings_add_page' );
/**
 * Register "Appearance -> Blueline". `manage_options` here is the
 * menu-level gate (WordPress hides the submenu item entirely for anyone
 * without it); blueline_settings_render_page() carries its own copy of the
 * same check, so a direct hit on the URL cannot bypass it even if this
 * registration were ever changed to a looser capability by mistake.
 *
 * Captures add_theme_page()'s own return value (the hook suffix WordPress
 * assigns this specific screen) via blueline_settings_page_hook(), so
 * blueline_settings_maybe_enqueue_focus_script(), which is hooked to
 * `admin_enqueue_scripts` and fires for every admin screen, not just
 * this one, can tell whether the screen currently loading is this one.
 *
 * @return void
 */
function blueline_settings_add_page(): void {
	$hook = add_theme_page(
		__( 'Blueline', 'blueline' ),
		__( 'Blueline', 'blueline' ),
		'manage_options',
		BLUELINE_SETTINGS_PAGE_SLUG,
		'blueline_settings_render_page'
	);

	blueline_settings_page_hook( $hook );
}

/**
 * Stores (and returns) the hook suffix add_theme_page() assigned this
 * screen. A $GLOBALS entry rather than a function-local static, so a test
 * harness can reset it between cases; the only two production callers
 * are blueline_settings_add_page() (which sets it, once, from admin_menu)
 * and blueline_settings_maybe_enqueue_focus_script() (which reads it, from
 * the later admin_enqueue_scripts), and both always run within the same
 * request.
 *
 * @param string|null $hook Set once, from blueline_settings_add_page(). Omit to read.
 * @return string|null
 */
function blueline_settings_page_hook( ?string $hook = null ): ?string {
	if ( null !== $hook ) {
		$GLOBALS['blueline_settings_page_hook'] = $hook;
	}
	return $GLOBALS['blueline_settings_page_hook'] ?? null;
}

add_action( 'admin_enqueue_scripts', 'blueline_settings_maybe_enqueue_focus_script' );
/**
 * Enqueues the error-summary focus script (see blueline_settings_focus_summary_script()'s
 * own docblock for why this exists at all), scoped to this page only via
 * the $hook_suffix WordPress passes every `admin_enqueue_scripts`
 * callback; every other admin screen returns immediately.
 *
 * Attached to the always-already-loaded `jquery` handle via
 * wp_add_inline_script() (a WordPress-blessed enqueue API, not a raw
 * echoed `<script>` tag) rather than a new webpack entry, because this file's
 * own docblock's "no webpack entry" constraint is about not adding a
 * build step for the theme's UI, not about never enqueuing any script at
 * all, and one inline snippet doing exactly one thing does not need one.
 *
 * @param string $hook_suffix The current admin screen's hook suffix.
 * @return void
 */
function blueline_settings_maybe_enqueue_focus_script( string $hook_suffix ): void {
	if ( blueline_settings_page_hook() !== $hook_suffix ) {
		return;
	}

	wp_add_inline_script( 'jquery', blueline_settings_focus_summary_script() );
}

/**
 * The inline script blueline_settings_maybe_enqueue_focus_script() enqueues:
 * moves focus to the error summary (if the current page load actually has
 * one; the element may not exist, in which case this is a no-op) once
 * the page has finished loading.
 *
 * This exists because the HTML5 living standard defines `autofocus` as a
 * global attribute valid on any focusable element, not only form
 * controls, which is what this file relied on before a live browser
 * check (not just a unit test, which cannot see this class of bug at
 * all; see blueline_settings_render_page()'s docblock) proved Chromium
 * does NOT actually move focus to a plain `<div autofocus>` on page load,
 * despite the spec allowing it: confirmed directly by loading this
 * exact page's own rendered markup, alongside WordPress core's real
 * wp-admin/js/common.js (which independently relocates every `.notice`
 * element to just after the page's own `<h1>` on every admin screen, on
 * `jQuery(document).ready()`), in an actual browser. `document.activeElement`
 * stayed `<body>` with `autofocus` alone, and only moved to the summary
 * once this explicit `.focus()` call ran.
 *
 * The `setTimeout( fn, 0 )` deference is deliberate, not decorative: it
 * defers this call to the NEXT event-loop tick, which runs after every
 * other script's own sole `jQuery(document).ready()` handler, including
 * WordPress core's own notice-relocation in common.js, has already
 * finished running, regardless of which handler happened to be
 * registered first. Without it, a focus call issued from INSIDE this
 * script's own ready() handler could run before common.js relocates the
 * summary element to its final DOM position, which is exactly the kind
 * of ordering bug that would pass in isolation and fail on a real page.
 *
 * `tabindex="-1"` (in the summary's own markup, blueline_settings_render_page())
 * is still required for `.focus()` to work on a `<div>` at all, because unlike
 * `autofocus`, a plain negative tabindex making an element programmatically
 * focusable (without adding it to the normal tab order) is reliably
 * supported everywhere.
 *
 * @return string
 */
function blueline_settings_focus_summary_script(): string {
	return 'jQuery( function ( $ ) {'
		. ' setTimeout( function () {'
		. " var el = document.getElementById( 'blueline-settings-error-summary' );"
		. ' if ( el ) { el.focus(); }'
		. ' }, 0 );'
		. ' } );';
}

add_action( 'admin_init', 'blueline_settings_register' );
/**
 * Wire BLUELINE_SETTINGS_OPTION into the Settings API's UI/whitelist
 * machinery: what makes options.php (WordPress core, not this theme)
 * accept a POST to this option at all from a settings_fields()-rendered
 * form. Deliberately does NOT pass a `sanitize_callback`: that would wire
 * blueline_settings_sanitize_callback() onto `sanitize_option_{$option}`
 * only while `admin_init` has fired, which WP-CLI and a direct
 * update_option() call from a script never do. The unconditional,
 * file-scope add_filter() a few lines below this function is what actually
 * wires validation, on every path; see this file's own docblock ("Every
 * write path is validated") for why that distinction matters.
 *
 * @return void
 */
function blueline_settings_register(): void {
	register_setting(
		BLUELINE_SETTINGS_OPTION_GROUP,
		BLUELINE_SETTINGS_OPTION,
		array(
			'type'    => 'array',
			'default' => blueline_settings_defaults(),
		)
	);
}

add_action( 'admin_notices', 'blueline_settings_newer_schema_notice' );
/**
 * Tell an admin when the stored settings were written by a NEWER version of
 * this theme than the one running: the one state inc/settings/store.php's
 * blueline_settings_migrate() deliberately refuses to act on, and the half
 * of the design spec's forward-only requirement ("refuse to write, still
 * render, and show a notice") that was never built. Site Health already
 * reports both version numbers (inc/settings/site-health.php), but nobody
 * opens Site Health unprompted; a rolled-back theme is otherwise silent.
 *
 * Registered at file scope on `admin_notices` rather than rendered inside
 * blueline_settings_render_page(), because the person who needs to see this
 * is exactly the person who does not yet know to open Appearance ->
 * Blueline. `admin_notices` fires for every logged-in user on every admin
 * screen, hence the capability check below: a user who cannot manage
 * options cannot act on this and should not be shown it.
 *
 * ## Every claim in the copy below is pinned by a test
 *
 * This is reassuring copy about a scary-looking state, which is exactly the
 * kind this project has shipped wrong before, so each factual claim has its
 * own test in tests/SettingsSchemaNoticeTest.php rather than being inferred
 * from the code's shape:
 *
 * - "the automatic settings upgrade refuses to run":
 *   test_the_migration_refuses_to_write_and_leaves_the_stored_value_alone()
 * - "the site still reads every setting this version recognises":
 *   test_settings_still_read_normally_under_a_newer_stored_schema()
 * - "saving from Appearance -> Blueline carries the newer values forward":
 *   test_an_ordinary_panel_save_keeps_both_the_newer_schema_and_its_unknown_keys()
 *
 * A `<section>`, not a `<div>`; see this file's own docblock's "Never a
 * `<div>`" section, and tests/NoticeDivGuardTest.php.
 *
 * @return void
 */
function blueline_settings_newer_schema_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$stored = get_option( BLUELINE_SETTINGS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();

	$stored_schema = isset( $stored['_schema'] ) ? (int) $stored['_schema'] : 0;

	if ( $stored_schema <= BLUELINE_SETTINGS_SCHEMA_VERSION ) {
		return;
	}
	?>
	<section class="notice notice-warning">
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: the settings format version found in the database, 2: the settings format version this theme understands. */
					__( 'Blueline\'s stored settings were written by a newer version of this theme: they are in settings format %1$d, and the version running now understands format %2$d.', 'blueline' ),
					$stored_schema,
					BLUELINE_SETTINGS_SCHEMA_VERSION
				)
			);
			?>
		</p>
		<p>
			<?php
			echo esc_html(
				__( 'Nothing has been changed or downgraded. The automatic settings upgrade refuses to run against a format it does not know, the site still reads every setting this version recognises, and saving from Appearance → Blueline carries the newer values forward rather than clearing them. Putting the newer theme version back is the fix; nothing here needs repairing first.', 'blueline' )
			);
			?>
		</p>
	</section>
	<?php
}

/**
 * If this is the redirect after a successful options.php save, queue a
 * generic "Settings saved." success message alongside any per-field errors
 * blueline_settings_sanitize_callback() may also have queued in the same
 * request, because a submission can partially succeed (some fields saved, one
 * rejected), and an admin needs to see both outcomes, not just one.
 *
 * @return void
 */
function blueline_settings_maybe_flag_saved(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag from options.php's own post-save redirect (that save was itself nonce-verified via settings_fields()), not a state-changing request of its own.
	if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_saved',
			esc_html__( 'Settings saved.', 'blueline' ),
			'success'
		);
	}
}
