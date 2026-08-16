<?php
/**
 * Appearance -> Blueline: the Settings-API admin page every earlier task in
 * this plan has been building toward. This is the first task that produces
 * something a human can actually open and use.
 *
 * Deliberately plain WordPress Settings API, no React, no REST endpoint, no
 * webpack entry -- the theme has zero React anywhere else, and the Settings
 * API already supplies, for free, exactly what this page needs: a nonce
 * (settings_fields()), a capability check on the actual save request
 * (options.php itself refuses a non-manage_options user before this file's
 * code ever runs), and the sanitize/merge pipeline Tasks 2-6 already built
 * (blueline_settings_sanitize_callback() below wires onto
 * sanitize_option_{$option} UNCONDITIONALLY, at file scope -- see "Every
 * write path is validated" below for why that is not the same thing as
 * register_setting()'s own sanitize_callback wiring -- and
 * inc/settings/store.php's blueline_settings_merge() already lives on
 * pre_update_option_{$option}).
 *
 * ## Every write path is validated, not just wp-admin's
 *
 * `admin_init` -- the hook register_setting() runs on -- never fires for
 * WP-CLI or for a script calling update_option() directly. If
 * `sanitize_option_{$option}` were wired ONLY via register_setting()'s
 * `sanitize_callback` argument (i.e. only inside an admin_init-hooked
 * function), every write reachable outside wp-admin would bypass
 * blueline_sanitize_field() entirely -- including the placeholder contract
 * inc/settings/sanitize.php exists to enforce, silently reintroducing the
 * exact sprintf()-format-string fatal that file was built to prevent, the
 * moment a `wp option update`/import script carries a stray "%". This
 * theme's own blueline_settings_merge() (inc/settings/store.php) is
 * already registered unconditionally at file scope for exactly this
 * reason; the line below mirrors that pattern for validation. This is why
 * blueline_settings_register() (still admin_init-hooked, purely for the
 * Settings API's own UI/whitelist wiring) deliberately does NOT pass a
 * `sanitize_callback` in its register_setting() call -- the filter below
 * is the only place that argument would ever be wired from, and wiring it
 * twice would be redundant at best.
 *
 * Tabs are plain links (?page=blueline&tab=content), NOT an ARIA tab
 * widget: a real page load per tab is simpler, linkable, back-button
 * correct, and does not need any JavaScript at all. Each tab's <form>
 * posts only its own fields to options.php, exactly the shape
 * blueline_settings_merge() was built to receive.
 *
 * ## Escaping -- load-bearing, not a nicety
 *
 * blueline_sanitize_field() (inc/settings/sanitize.php) returns a WP_Error
 * whose message INTERPOLATES the admin's own input: the offending
 * conversion spec extracted from the submitted value, and a corrected
 * string built from it. WordPress' own add_settings_error() documents that
 * whatever renders a settings error echoes `$message` WITHOUT escaping --
 * escaping is the caller's job. Every single WP_Error message this file
 * hands to add_settings_error() is therefore run through esc_html() at the
 * exact point it is handed over (blueline_settings_sanitize_callback()
 * below), never later, and never left to whatever eventually reads it back.
 * Skipping that step is stored XSS: any admin who can reach this page could
 * submit a value whose rejection message reflects HTML-significant
 * characters straight into another admin's browser session.
 *
 * Every render function in this file that echoes a message already stored
 * via add_settings_error() (blueline_settings_render_page(),
 * blueline_settings_render_field()) echoes it RAW, with a line-level
 * phpcs:ignore explaining why: escaping again at that point would
 * double-encode entities the sanitize callback already escaped once.
 *
 * ## `_posted_fields` -- required, not optional, and tab-scoped
 *
 * blueline_settings_merge() treats a field absent from a submission as
 * "belongs to another tab, carry it forward" UNLESS that field's key is
 * named in the submission's reserved `_posted_fields` array, in which case
 * absence means "this tab owns this field and the user cleared it" (a
 * deliberate delete). Every tab rendered here therefore emits one hidden
 * `_posted_fields[]` input per field it owns (blueline_settings_render_page()),
 * regardless of whether that specific field currently has a value to clear
 * -- omitting this array does not merely miss an edge case, it makes
 * clearing ANY of this tab's fields silently revert on the very next save,
 * from ANY tab, forever.
 *
 * `_posted_fields` alone is not enough, though: every tab shares one
 * settings_fields() nonce group, so the nonce does not bind a submission
 * to any particular tab. Without a further check, a request merely SHAPED
 * like the Content tab's form -- but naming a Links-tab field (e.g.
 * `page_faqs`) in `_posted_fields` without posting that field's own value
 * -- would make the merge read that absence as "owned but omitted --
 * delete", clearing a field the submission never rendered and does not
 * own. Every tab's form therefore also emits a hidden `_tab` input naming
 * the tab actually being submitted, and blueline_settings_sanitize_callback()
 * drops any `_posted_fields` entry whose OWN schema `tab` does not match
 * it -- so naming a foreign field only ever fails silently, never deletes
 * it.
 *
 * `_tab` itself is now FORWARDED in this callback's return value, not
 * dropped -- a P1b fix-round finding (a Critical bug found on staging, not
 * by any unit test): a programmatic write (WP-CLI, a JSON import,
 * blueline_settings_migrate()'s own update_option() call) never carries
 * `_tab`, and blueline_settings_merge() needs to be able to tell that case
 * apart from a real tab-scoped form post, because `_posted_fields`-driven
 * deletion is a guarantee this file's own rendered form needs, not one a
 * programmatic write asked for or should be bound by. `_tab` is never
 * persisted on an ordinary, steady-state save, exactly as before --
 * inc/settings/store.php's blueline_settings_merge() (the very next filter
 * this same write triggers, on pre_update_option_{$option}, immediately
 * after this one) reads it for that one decision and strips it before
 * anything reaches storage. The one exception is the SAME first-ever-write
 * re-sanitize quirk that function's own docblock already documents for
 * `_posted_fields`: add_option()'s own re-sanitize pass has no merge stage
 * to strip a second time, so a genuine first write can persist a spurious
 * `_tab => ''` alongside `_posted_fields => []`, self-healing on the very
 * next real save. See that function's own docblock for the merge-side half
 * of this fix, and for that quirk in full.
 *
 * ## `_schema` is reserved, not "unrecognised" -- and never from a form
 *
 * blueline_settings_sanitize_callback() must let inc/settings/store.php's
 * `_schema` migration bookkeeping key survive a write (it is deliberately
 * NOT part of the schema -- see blueline_settings()'s own docblock -- yet
 * blueline_settings_migrate() writes it directly via update_option(),
 * which now runs through this same callback on every path). That is NOT
 * the same thing as forwarding every unrecognised key unchanged: doing
 * that once let ANY top-level key in a submission persist, silently
 * weakening the allow-list model Tasks 2-6 built, and specifically let
 * `_schema` itself be set from an ordinary POST -- an admin (accidentally
 * or otherwise) sending `blueline_settings[_schema]` at or above
 * BLUELINE_SETTINGS_SCHEMA_VERSION would make blueline_settings_migrate()'s
 * forward-only guard treat the install as already current, permanently
 * and silently skipping a real future migration.
 *
 * The fix is an explicit reserved-key allow-list -- today exactly
 * `array( '_schema' )` -- rather than "forward anything unrecognised":
 * any key that is neither a real schema field nor on that list is
 * dropped, exactly as it would be if it were never declared at all.
 * `_schema` itself is still sanitized like everything else (absint(),
 * matching every other integer-valued field this callback handles) AND
 * additionally clamped to `BLUELINE_SETTINGS_SCHEMA_VERSION` -- matching
 * the limit inc/cli/settings-command.php enforces on an import's own
 * `_schema` (there, by refusing the import outright; here, by clamping,
 * since a direct update_option() call has no "abort" to fall back to) --
 * so a `_schema` at or above the running code's version can never survive
 * a write and permanently defeat blueline_settings_migrate()'s
 * forward-only guard. It is additionally dropped outright when the
 * submission carries a `_tab`
 * -- i.e. came from this file's own rendered form. No tab's form has (or
 * should ever have) a `_schema` field, so its presence alongside a `_tab`
 * can only mean tampering, never a legitimate use of the panel; a
 * programmatic write (blueline_settings_migrate()'s own update_option()
 * call, WP-CLI, an import script) never carries `_tab` at all, which is
 * exactly the path `_schema` needs to keep surviving. This is not a hard
 * security boundary against a deliberately crafted request (both paths
 * already require `manage_options`, the same capability that can write
 * every schema field directly), only a deliberate narrowing of what the
 * UI's own form can ever legitimately submit.
 *
 * ## Never a `<div>` for the error summary
 *
 * blueline_settings_render_page()'s error summary is a `<section>`, not a
 * `<div>`, despite carrying the WP-admin `.notice`/`.notice-error` classes
 * (a plain class selector in wp-admin/css/common.css, with no tag
 * qualifier, so a `<section>` gets the exact same styling a `<div>` would).
 * This is deliberate, found by an actual browser click-through against a
 * real save on staging (a unit test cannot see this class of bug at all --
 * the server-side render is, and was always, correct): this WordPress
 * install has a third-party plugin active (Capabilities Pro's own
 * admin-notices "declutter" module) whose JS removes -- via
 * `$(element).remove()` -- every `<div>` on any wp-admin screen whose
 * `class` attribute contains "notice", "error", "warning", "info" or
 * "updated" as a SUBSTRING anywhere, sweeping it into a "Notice Center"
 * panel instead. That selector is scoped to `div[...]` only; the per-field
 * inline error (a `<p>`) was never touched by it, which is exactly why
 * that part of this page always worked while the summary silently
 * vanished. Confirmed directly: the raw HTTP response body of a real
 * failed save DID contain the summary `<div>`, fully formed, every time --
 * this file's own PHP was never the problem -- but it was gone from the
 * live DOM by the time anything queried it. Changing the tag to
 * `<section>` (this plugin's selector never matches it) is a complete fix
 * with no loss of styling or of the `role="alert"` semantics that already
 * override whatever implicit role the tag itself would otherwise carry.
 *
 * ## Never an autoload argument
 *
 * Nothing in this file calls update_option()/register_setting() with an
 * autoload argument. `'auto'` is not a valid update_option() input -- it is
 * an internal DB state, and passing the literal string would likely coerce
 * to `true`. The Settings API's own options.php handler already calls
 * update_option( $option, $value ) with no third argument, which is exactly
 * what register_setting() below relies on to get this for free.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu slug this page is registered under (Appearance -> Blueline).
 */
const BLUELINE_SETTINGS_PAGE_SLUG = 'blueline';

/**
 * Settings-API group name passed to register_setting()/settings_fields().
 * Arbitrary but must match between the two -- see blueline_settings_register()
 * and the <form> settings_fields() call in blueline_settings_render_page().
 */
const BLUELINE_SETTINGS_OPTION_GROUP = 'blueline_settings_group';

/**
 * Top-level option keys that are neither a real schema field nor the two
 * request-scoped bookkeeping keys (`_posted_fields`, `_tab`) this file's
 * own form emits, but which a write still needs to be able to carry --
 * today exactly inc/settings/store.php's `_schema` migration version.
 * blueline_settings_sanitize_callback() checks every unrecognised key
 * against this explicit allow-list rather than forwarding it merely for
 * being unrecognised -- see this file's own docblock's `_schema` section
 * for why that distinction is load-bearing.
 */
const BLUELINE_SETTINGS_RESERVED_KEYS = array( '_schema' );

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
 * blueline_settings_maybe_enqueue_focus_script() -- hooked to
 * `admin_enqueue_scripts`, which fires for every admin screen, not just
 * this one -- can tell whether the screen currently loading is this one.
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
 * screen. A plain module-level static rather than a global: the only two
 * callers are blueline_settings_add_page() (which sets it, once, from
 * admin_menu) and blueline_settings_maybe_enqueue_focus_script() (which
 * reads it, from the later admin_enqueue_scripts), and both always run
 * within the same request.
 *
 * @param string|null $hook Set once, from blueline_settings_add_page(). Omit to read.
 * @return string|null
 */
function blueline_settings_page_hook( ?string $hook = null ): ?string {
	static $stored = null;
	if ( null !== $hook ) {
		$stored = $hook;
	}
	return $stored;
}

add_action( 'admin_enqueue_scripts', 'blueline_settings_maybe_enqueue_focus_script' );
/**
 * Enqueues the error-summary focus script (see blueline_settings_focus_summary_script()'s
 * own docblock for why this exists at all), scoped to this page only via
 * the $hook_suffix WordPress passes every `admin_enqueue_scripts`
 * callback -- every other admin screen returns immediately.
 *
 * Attached to the always-already-loaded `jquery` handle via
 * wp_add_inline_script() (a WordPress-blessed enqueue API, not a raw
 * echoed `<script>` tag) rather than a new webpack entry -- this file's
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
 * one -- the element may not exist, in which case this is a no-op) once
 * the page has finished loading.
 *
 * This exists because the HTML5 living standard defines `autofocus` as a
 * global attribute valid on any focusable element, not only form
 * controls -- which is what this file relied on before a live browser
 * check (not just a unit test, which cannot see this class of bug at
 * all -- see blueline_settings_render_page()'s docblock) proved Chromium
 * does NOT actually move focus to a plain `<div autofocus>` on page load,
 * despite the spec allowing it: confirmed directly by loading this
 * exact page's own rendered markup, alongside WordPress core's real
 * wp-admin/js/common.js (which independently relocates every `.notice`
 * element to just after the page's own `<h1>` on every admin screen, on
 * `jQuery(document).ready()`), in an actual browser -- `document.activeElement`
 * stayed `<body>` with `autofocus` alone, and only moved to the summary
 * once this explicit `.focus()` call ran.
 *
 * The `setTimeout( fn, 0 )` deference is deliberate, not decorative: it
 * defers this call to the NEXT event-loop tick, which runs after every
 * other script's own sole `jQuery(document).ready()` handler -- including
 * WordPress core's own notice-relocation in common.js -- has already
 * finished running, regardless of which handler happened to be
 * registered first. Without it, a focus call issued from INSIDE this
 * script's own ready() handler could run before common.js relocates the
 * summary element to its final DOM position, which is exactly the kind
 * of ordering bug that would pass in isolation and fail on a real page.
 *
 * `tabindex="-1"` (in the summary's own markup, blueline_settings_render_page())
 * is still required for `.focus()` to work on a `<div>` at all -- unlike
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
 * machinery -- what makes options.php (WordPress core, not this theme)
 * accept a POST to this option at all from a settings_fields()-rendered
 * form. Deliberately does NOT pass a `sanitize_callback`: that would wire
 * blueline_settings_sanitize_callback() onto `sanitize_option_{$option}`
 * only while `admin_init` has fired, which WP-CLI and a direct
 * update_option() call from a script never do. The unconditional,
 * file-scope add_filter() a few lines below this function is what actually
 * wires validation, on every path -- see this file's own docblock ("Every
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

add_filter( 'sanitize_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_settings_sanitize_callback' );
/**
 * The sanitize_option_{$option} callback for BLUELINE_SETTINGS_OPTION --
 * registered UNCONDITIONALLY above, at file scope, exactly the way
 * inc/settings/store.php registers blueline_settings_merge() on
 * `pre_update_option_{$option}` -- so this runs on every write reachable
 * through update_option(), not only ones that pass through wp-admin's
 * `admin_init`. This is the single choke point every save passes through,
 * immediately before blueline_settings_merge() runs.
 *
 * Three responsibilities, each described in this file's own docblock in
 * more depth:
 *
 * 1. Validate every posted field with blueline_sanitize_field(). A field
 *    that fails keeps its EXISTING stored value rather than the rejected
 *    one -- one bad field cannot corrupt the option, and every other field
 *    in the same submission still saves normally.
 * 2. Escape every WP_Error message with esc_html() before it is handed to
 *    add_settings_error() -- see this file's docblock's Escaping section.
 * 3. Forward `_posted_fields` (filtered to known schema keys AND to keys
 *    whose OWN schema `tab` matches the submission's `_tab`) AND `_tab`
 *    itself so blueline_settings_merge() can tell "this tab cleared a
 *    field it owns" from "this field belongs to an untouched tab" from "no
 *    tab at all -- a programmatic write, where `_posted_fields` carries no
 *    ownership". A submission naming a foreign tab's field is not honoured
 *    for that field -- see this file's docblock's `_posted_fields`
 *    section.
 * 4. Every OTHER key is checked against an explicit reserved-key
 *    allow-list (BLUELINE_SETTINGS_RESERVED_KEYS, today just `_schema`),
 *    never forwarded merely for being unrecognised -- see this file's
 *    docblock's `_schema` section for why "forward anything unrecognised"
 *    was rejected. A reserved key is still sanitized (absint()), `_schema`
 *    specifically also clamped to BLUELINE_SETTINGS_SCHEMA_VERSION, and
 *    dropped outright when the submission carries a `_tab` (came from
 *    this file's own form, which never legitimately submits one).
 *
 * @param mixed $input Raw value from $_POST[BLUELINE_SETTINGS_OPTION], as
 *                      WordPress' sanitize_option_{$option} filter hands it
 *                      to us -- only the keys THIS submission posted.
 * @return array<string, mixed> The value to store, before
 *                               blueline_settings_merge() carries forward
 *                               whatever belongs to other tabs.
 */
function blueline_settings_sanitize_callback( $input ): array {
	$input   = is_array( $input ) ? $input : array();
	$schema  = blueline_settings_schema();
	$current = blueline_settings();

	$submitted_tab = isset( $input['_tab'] ) ? (string) $input['_tab'] : '';

	$posted_fields = array();
	if ( isset( $input['_posted_fields'] ) && is_array( $input['_posted_fields'] ) ) {
		foreach ( $input['_posted_fields'] as $posted_key ) {
			$posted_key = (string) $posted_key;
			if ( ! isset( $schema[ $posted_key ] ) ) {
				continue; // Not a real field at all.
			}
			if ( ( $schema[ $posted_key ]['tab'] ?? '' ) !== $submitted_tab ) {
				// Named by a submission that does not own it -- a request
				// merely SHAPED like another tab's form (or a tampered
				// one) naming a foreign field here must never be able to
				// delete it. Silently dropped, not honoured.
				continue;
			}
			$posted_fields[] = $posted_key;
		}
	}

	$output = array();

	foreach ( $input as $key => $value ) {
		if ( '_posted_fields' === $key || '_tab' === $key ) {
			// Reserved bookkeeping, both already consumed above --
			// `_posted_fields` is rebuilt, filtered, below; `_tab` is
			// forwarded, unchanged, below too. Neither is persisted on an
			// ordinary, steady-state save (the first-ever-write re-sanitize
			// quirk inc/settings/store.php's blueline_settings_merge()'s
			// own docblock already documents is the one exception) --
			// blueline_settings_merge() (the very next filter this same
			// write triggers) is what actually needs `_tab`, to tell a
			// tab-scoped submission (where `_posted_fields` decides
			// deletion) apart from a programmatic one (where it carries no
			// ownership at all) -- see that function's own
			// docblock -- and strips both before anything reaches storage.
			continue;
		}

		if ( ! isset( $schema[ $key ] ) ) {
			if ( ! in_array( $key, BLUELINE_SETTINGS_RESERVED_KEYS, true ) ) {
				// Not a real field and not on the reserved allow-list:
				// dropped, exactly as if it had never been declared at
				// all -- see this file's docblock's `_schema` section for
				// why this is an explicit allow-list rather than "forward
				// anything unrecognised".
				continue;
			}

			if ( '' !== $submitted_tab ) {
				// Reserved, but this submission carries `_tab` -- it came
				// from this file's own rendered form, which never
				// legitimately submits a reserved key. Dropped, not
				// honoured, rather than trusted just because it's on the
				// allow-list.
				continue;
			}

			// A programmatic write (blueline_settings_migrate()'s own
			// update_option() call, WP-CLI, an import script) -- exactly
			// the path a reserved key like `_schema` needs to keep
			// surviving. Still sanitized like everything else, never
			// trusted as opaque data.
			$sanitized_reserved = absint( $value );

			if ( '_schema' === $key ) {
				// Clamped to BLUELINE_SETTINGS_SCHEMA_VERSION, matching
				// the limit inc/cli/settings-command.php enforces on an
				// import's own `_schema` (there, by refusing the whole
				// import outright; here, by clamping, since this path
				// returns a value to store rather than an all-or-nothing
				// operation to abort). Without this, a `_schema` at or
				// above the running code's version -- written via any
				// direct update_option() call this reserved-key branch
				// lets through -- would make
				// blueline_settings_migrate()'s forward-only guard
				// (inc/settings/store.php) treat the install as already
				// current, permanently and silently skipping every
				// future migration, recoverable only via WP-CLI or the
				// database directly.
				$sanitized_reserved = min( $sanitized_reserved, BLUELINE_SETTINGS_SCHEMA_VERSION );
			}

			$output[ $key ] = $sanitized_reserved;
			continue;
		}

		$result = blueline_sanitize_field( $value, $schema[ $key ] );

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				BLUELINE_SETTINGS_OPTION,
				$key,
				esc_html( $result->get_error_message() ),
				'error'
			);
			// Keep what is already stored -- never write the rejected value.
			$output[ $key ] = $current[ $key ] ?? null;
			continue;
		}

		$output[ $key ] = $result;
	}

	$output['_posted_fields'] = $posted_fields;
	$output['_tab']           = $submitted_tab;

	return $output;
}

/**
 * If this is the redirect after a successful options.php save, queue a
 * generic "Settings saved." success message alongside any per-field errors
 * blueline_settings_sanitize_callback() may also have queued in the same
 * request -- a submission can partially succeed (some fields saved, one
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

/**
 * The distinct `tab` values the schema declares, in first-seen order --
 * deliberately derived from blueline_settings_schema() rather than a
 * hardcoded list, so a future task that adds a new tab (e.g. a Sections
 * tab) does not also have to remember to edit this file.
 *
 * @return string[]
 */
function blueline_settings_tab_slugs(): array {
	$slugs = array();
	foreach ( blueline_settings_schema() as $field ) {
		$tab = $field['tab'] ?? '';
		if ( '' !== $tab && ! in_array( $tab, $slugs, true ) ) {
			$slugs[] = $tab;
		}
	}
	return $slugs;
}

/**
 * Human-readable label for a tab slug. Known slugs get a proper label;
 * anything else (a future tab this file was not updated for) falls back to
 * a readable guess rather than rendering the raw slug or nothing at all.
 *
 * @param string $tab_slug A value from blueline_settings_tab_slugs().
 * @return string
 */
function blueline_settings_tab_label( string $tab_slug ): string {
	$labels = array(
		'content'    => __( 'Content', 'blueline' ),
		'links'      => __( 'Links', 'blueline' ),
		'appearance' => __( 'Appearance', 'blueline' ),
		'sections'   => __( 'Sections', 'blueline' ),
		'commerce'   => __( 'Commerce', 'blueline' ),
	);

	return $labels[ $tab_slug ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $tab_slug ) );
}

/**
 * Every schema field belonging to one tab, in schema order.
 *
 * @param string $tab_slug A value from blueline_settings_tab_slugs().
 * @return array<string, array<string, mixed>>
 */
function blueline_settings_fields_for_tab( string $tab_slug ): array {
	return array_filter(
		blueline_settings_schema(),
		static fn( $field ) => ( $field['tab'] ?? '' ) === $tab_slug
	);
}

/**
 * The tab the current request should display: the requested `tab` query
 * var if it names a real tab, otherwise the first tab the schema declares.
 * Never trusts an unrecognised value -- there is no field-rendering branch
 * for an unknown tab, so falling through to it would render an empty page
 * rather than something confusing.
 *
 * @return string
 */
function blueline_settings_current_tab(): string {
	$tabs = blueline_settings_tab_slugs();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector (which fields to display), not a state-changing request; validated against the known tab list below regardless.
	$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

	return in_array( $requested, $tabs, true ) ? $requested : ( $tabs[0] ?? '' );
}

/**
 * The URL for one tab's link in the tab nav.
 *
 * @param string $tab_slug A value from blueline_settings_tab_slugs().
 * @return string
 */
function blueline_settings_tab_url( string $tab_slug ): string {
	return admin_url( 'themes.php?page=' . rawurlencode( BLUELINE_SETTINGS_PAGE_SLUG ) . '&tab=' . rawurlencode( $tab_slug ) );
}

/**
 * The HTML id of a field's input/select -- shared between
 * blueline_settings_render_field() (which sets it) and the error summary
 * (which links to it), so the two can never drift apart into two different
 * ids for what is supposed to be the same element.
 *
 * @param string $field_key A blueline_settings_schema() key.
 * @return string
 */
function blueline_settings_field_input_id( string $field_key ): string {
	return 'blueline-field-' . $field_key;
}

/**
 * Field-level error messages from the last save, keyed by field key -- only
 * `error`-type entries, since success/info entries (e.g. "Settings saved.")
 * are not associated with any one field. Every message returned here was
 * already run through esc_html() at the point
 * blueline_settings_sanitize_callback() called add_settings_error() (see
 * this file's docblock); it is safe to echo directly, never re-escape it.
 *
 * @return array<string, string> Field key => already-escaped message.
 */
function blueline_settings_field_errors(): array {
	$errors = array();
	foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
		if ( 'error' === ( $error['type'] ?? '' ) ) {
			$errors[ $error['code'] ] = $error['message'];
		}
	}
	return $errors;
}

/**
 * Non-field-specific messages from the last save (currently only the
 * generic "Settings saved." success message blueline_settings_maybe_flag_saved()
 * queues). Already-escaped, same guarantee as blueline_settings_field_errors().
 *
 * @return array<int, array{type:string, message:string}>
 */
function blueline_settings_non_field_messages(): array {
	$messages = array();
	foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
		if ( 'error' !== ( $error['type'] ?? '' ) ) {
			$messages[] = array(
				'type'    => $error['type'] ?? 'updated',
				'message' => $error['message'],
			);
		}
	}
	return $messages;
}

/**
 * The page callback registered with add_theme_page(). Renders the tab nav,
 * an accessible error summary (moved into focus when present -- see
 * below), any success/error notices, and the current tab's own <form>.
 *
 * Capability is checked again here (see blueline_settings_add_page()'s
 * docblock for why this is not redundant): a request that somehow reaches
 * this callback without `manage_options` is refused outright rather than
 * shown anything.
 *
 * @return void
 */
function blueline_settings_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'blueline' ) );
	}

	blueline_settings_maybe_flag_saved();

	$tabs         = blueline_settings_tab_slugs();
	$current_tab  = blueline_settings_current_tab();
	$field_errors = blueline_settings_field_errors();
	$notices      = blueline_settings_non_field_messages();
	?>
	<div class="wrap bl-settings">
		<h1><?php echo esc_html( __( 'Blueline', 'blueline' ) ); ?></h1>

		<?php
		/*
		 * <section>, deliberately NOT a <div> -- see this file's own
		 * docblock's "Never a <div> for the error summary" section: the
		 * same third-party plugin (Capabilities Pro's admin-notices
		 * "declutter" module) that swept the error summary from the DOM
		 * also removes any <div> whose class contains "notice", and a
		 * genuinely successful save's own "Settings saved." notice carries
		 * exactly that class. Confirmed live: a real successful save on
		 * staging rendered no feedback at all until this was fixed.
		 */
		?>
		<?php foreach ( $notices as $notice ) : ?>
			<section class="notice notice-<?php echo esc_attr( 'success' === $notice['type'] ? 'success' : $notice['type'] ); ?>">
				<p><?php echo $notice['message']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_html()'d in blueline_settings_maybe_flag_saved() at the point add_settings_error() was called; re-escaping here would double-encode entities. ?></p>
			</section>
		<?php endforeach; ?>

		<?php
		/*
		 * tabindex="-1" makes this a valid focus target without adding it
		 * to the normal tab order; blueline_settings_maybe_enqueue_focus_script()
		 * actually moves focus here on page load, via an explicit .focus()
		 * call -- see this file's own docblock and
		 * blueline_settings_focus_summary_script()'s docblock for why a
		 * plain HTML attribute alone was tried first and does not work in
		 * the target browser. This explanation is a PHP comment, not an
		 * HTML one, so it is never sent to the browser at all.
		 */
		?>
		<?php if ( ! empty( $field_errors ) ) : ?>
			<?php
			/*
			 * A <section>, deliberately NOT a <div> -- see this file's own
			 * docblock's "Never a <div> for the error summary" section for
			 * why: a real browser click-through (not a unit test) found a
			 * THIRD-PARTY plugin active on this install (Capabilities Pro's
			 * admin-notices module) removes every <div> whose class
			 * attribute contains "notice", "error", "warning", "info" or
			 * "updated" as a SUBSTRING, anywhere on any wp-admin screen, as
			 * part of its own notice-decluttering feature. This element's
			 * classes (kept for their free WP-admin `.notice`/`.notice-error`
			 * styling, itself a plain class selector with no tag
			 * qualifier) match that pattern exactly, so as a <div> it was
			 * removed from the DOM by that plugin on every real page load,
			 * despite this file's own PHP emitting it correctly every
			 * time -- confirmed by inspecting the raw HTTP response body of
			 * an actual failed save, which DID contain it. A <section>
			 * (with the same classes, so identical styling) is never
			 * selected by that plugin's `div[...]` jQuery selector, and
			 * `role="alert"` below already overrides its implicit ARIA
			 * role, so nothing about the accessible semantics changes.
			 */
			?>
			<section
				id="blueline-settings-error-summary"
				class="notice notice-error bl-settings-error-summary"
				tabindex="-1"
				role="alert"
			>
				<h2><?php echo esc_html( __( 'There is a problem', 'blueline' ) ); ?></h2>
				<ul>
					<?php foreach ( $field_errors as $field_key => $message ) : ?>
						<?php $field_label = blueline_settings_schema()[ $field_key ]['label'] ?? $field_key; ?>
						<li>
							<a href="#<?php echo esc_attr( blueline_settings_field_input_id( $field_key ) ); ?>">
								<?php echo esc_html( $field_label ); ?>:
								<?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_html()'d in blueline_settings_sanitize_callback() at the point add_settings_error() was called (see this file's docblock); re-escaping here would double-encode entities such as turning "&lt;" into "&amp;lt;". ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug ) : ?>
				<a
					href="<?php echo esc_url( blueline_settings_tab_url( $tab_slug ) ); ?>"
					class="nav-tab<?php echo esc_attr( $tab_slug === $current_tab ? ' nav-tab-active' : '' ); ?>"
					<?php
					if ( $tab_slug === $current_tab ) :
						?>
						aria-current="page"<?php endif; ?>
				>
					<?php echo esc_html( blueline_settings_tab_label( $tab_slug ) ); ?>
				</a>
			<?php endforeach; ?>
		</h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( BLUELINE_SETTINGS_OPTION_GROUP ); ?>

			<!--
				`_tab` names which tab owns this submission -- every tab
				shares one settings_fields() nonce group, so the nonce
				alone cannot tell the sanitize callback that. Without it, a
				`_posted_fields` entry naming a field from a DIFFERENT tab
				could delete that field (see this file's own docblock).
			-->
			<input type="hidden" name="<?php echo esc_attr( BLUELINE_SETTINGS_OPTION . '[_tab]' ); ?>" value="<?php echo esc_attr( $current_tab ); ?>">

			<?php foreach ( blueline_settings_fields_for_tab( $current_tab ) as $field_key => $field ) : ?>
				<input type="hidden" name="<?php echo esc_attr( BLUELINE_SETTINGS_OPTION . '[_posted_fields][]' ); ?>" value="<?php echo esc_attr( $field_key ); ?>">
			<?php endforeach; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( blueline_settings_fields_for_tab( $current_tab ) as $field_key => $field ) : ?>
						<?php blueline_settings_render_field( $field_key, $field, $field_errors[ $field_key ] ?? null ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Render one field's table row: a real `<label for>`, the input itself
 * (a wp_dropdown_pages()-style select for `page_id` fields -- an admin
 * picks "FAQs", never types a raw post ID; a wp_dropdown_categories()-style
 * select for a `term_id` field that declares a `taxonomy` -- an admin picks
 * "Registration", never types a raw term ID; a plain number input for a
 * `term_id` field that does NOT declare a taxonomy (no such field exists
 * today, but the branch stays available for one that has no taxonomy to
 * pick from); text/email otherwise), and, when this field failed the last
 * save, `aria-invalid`, `aria-describedby` and a visible error paragraph
 * that does not rely on colour alone (an explicit "Error:" prefix plus
 * text, in addition to the `bl-settings-field--error` class a stylesheet
 * may use for a colour treatment).
 *
 * `page_id`/`term_id` fields never fail validation -- blueline_sanitize_field()
 * sanitizes both with absint(), which cannot return a WP_Error -- so
 * neither branch below needs to handle an error state.
 *
 * @param string      $field_key     A blueline_settings_schema() key.
 * @param array       $field         That key's schema entry.
 * @param string|null $error_message Already-escaped error message for this
 *                                    field from the last save, or null.
 * @return void
 */
function blueline_settings_render_field( string $field_key, array $field, ?string $error_message ): void {
	$type      = $field['type'] ?? 'text';
	$label     = $field['label'] ?? $field_key;
	$value     = blueline_settings( $field_key );
	$input_id  = blueline_settings_field_input_id( $field_key );
	$error_id  = $input_id . '-error';
	$name      = BLUELINE_SETTINGS_OPTION . '[' . $field_key . ']';
	$has_error = null !== $error_message;
	?>
	<tr class="bl-settings-field<?php echo esc_attr( $has_error ? ' bl-settings-field--error' : '' ); ?>">
		<th scope="row">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
		</th>
		<td>
			<?php if ( 'band_photos' === $type ) : ?>
				<?php blueline_settings_render_band_photos( $field_key, $field, $name, $input_id, (array) $value ); ?>
			<?php elseif ( 'bool' === $type || 'section' === $type ) : ?>
				<?php
				/*
				 * The hidden input before the checkbox is what makes UNCHECKING
				 * work: an unchecked box posts nothing at all, so without a
				 * companion the sanitizer would never see the field and the
				 * stored `true` would survive the save. The hidden 0 is always
				 * posted; a checked box overwrites it, because a later value
				 * wins for the same key.
				 *
				 * A `section` field (inc/settings/sections.php's presence
				 * toggles) renders with this exact same markup as `bool` --
				 * same companion, same bare checkbox -- since it sanitizes
				 * identically (inc/settings/sanitize.php); the distinct type
				 * name is conceptual, not a rendering difference.
				 */
				?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<?php
				/*
				 * No wrapping <label> here: the row header already renders one
				 * with `for` pointing at this input, so a second would print
				 * the same sentence twice on screen and announce it twice to a
				 * screen reader.
				 */
				?>
				<input
					type="checkbox"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="1"
					<?php checked( (bool) $value ); ?>
				>
				<?php if ( ! empty( $field['help'] ) ) : ?>
					<p class="description"><?php echo esc_html( (string) $field['help'] ); ?></p>
				<?php endif; ?>
				<?php
				/*
				 * Task 5 (P1b-panel-completion): a `section` field behind a
				 * populated widget area (today, only `chrome_footer_trust` ->
				 * footer-2 -- see blueline_section_widget_warning()'s own
				 * docblock) gets a second, distinct notice naming the live
				 * widget count, so switching it off doesn't read as "delete my
				 * widgets" to whoever's holding the mouse. '' for a `bool`
				 * field (no key in blueline_section_widget_warning()'s $areas
				 * map matches a non-`section` field's key) and for any
				 * `section` field with no widget area behind it or an empty one.
				 */
				$widget_warning = 'section' === $type ? blueline_section_widget_warning( $field_key ) : '';
				?>
				<?php if ( '' !== $widget_warning ) : ?>
					<p class="description"><?php echo esc_html( $widget_warning ); ?></p>
				<?php endif; ?>
			<?php elseif ( 'page_id' === $type ) : ?>
				<?php
				wp_dropdown_pages(
					array(
						'name'              => esc_attr( $name ),
						'id'                => esc_attr( $input_id ),
						'selected'          => (int) $value,
						'show_option_none'  => esc_html__( '— Use built-in page —', 'blueline' ),
						'option_none_value' => 0,
					)
				);
				?>
			<?php elseif ( 'term_id' === $type && ! empty( $field['taxonomy'] ) ) : ?>
				<?php
				wp_dropdown_categories(
					array(
						'taxonomy'          => $field['taxonomy'],
						'name'              => esc_attr( $name ),
						'id'                => esc_attr( $input_id ),
						'selected'          => (int) $value,
						'show_option_none'  => esc_html__( '— Use built-in category —', 'blueline' ),
						'option_none_value' => 0,
						'hide_empty'        => false,
					)
				);
				?>
			<?php elseif ( 'term_id' === $type ) : ?>
				<input
					type="number"
					min="0"
					step="1"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					class="small-text"
				>
			<?php else : ?>
				<input
					type="<?php echo esc_attr( 'email' === $type ? 'email' : 'text' ); ?>"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					class="regular-text"
					<?php
					if ( $has_error ) :
						?>
						aria-invalid="true" aria-describedby="<?php echo esc_attr( $error_id ); ?>"<?php endif; ?>
				>
			<?php endif; ?>

			<?php if ( $has_error ) : ?>
				<p id="<?php echo esc_attr( $error_id ); ?>" class="bl-settings-field__error">
					<strong><?php echo esc_html( __( 'Error:', 'blueline' ) ); ?></strong>
					<?php echo $error_message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_html()'d in blueline_settings_sanitize_callback() at the point add_settings_error() was called (see this file's docblock); re-escaping here would double-encode entities such as turning "&lt;" into "&amp;lt;". ?>
				</p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Render the repeatable hero-photograph field: a row per photograph carrying
 * its thumbnail, its own alignment, and a remove control, plus the button that
 * opens the media library.
 *
 * Every row is rendered server-side, including for photographs added in this
 * session -- assets/src/js/settings-photos.js clones the empty template below
 * rather than assembling markup of its own, so there is exactly one definition
 * of what a row looks like and the no-JS state and the JS state cannot drift.
 *
 * With JavaScript unavailable the picker button does nothing, but the existing
 * rows still render, still submit, and their alignment selects still work --
 * so an admin without JS can reorder priorities and fix alignment even if they
 * cannot add a new photograph.
 *
 * @param string $field_key Schema key.
 * @param array  $field     Schema entry.
 * @param string $name      The `name` attribute base for this field.
 * @param string $input_id  The field's input id.
 * @param array  $rows      Stored rows: each { id, align }.
 * @return void
 */
function blueline_settings_render_band_photos( string $field_key, array $field, string $name, string $input_id, array $rows ): void {
	$alignments = function_exists( 'blueline_band_photo_alignments' ) ? blueline_band_photo_alignments() : array();
	$max        = (int) ( $field['max'] ?? 12 );
	?>
	<div
		class="bl-photos"
		id="<?php echo esc_attr( $input_id ); ?>"
		data-bl-photos
		data-bl-photos-name="<?php echo esc_attr( $name ); ?>"
		data-bl-photos-max="<?php echo esc_attr( (string) $max ); ?>"
	>
		<ul class="bl-photos__list" data-bl-photos-list>
			<?php foreach ( $rows as $index => $row ) : ?>
				<?php
				$photo_id = absint( $row['id'] ?? 0 );

				if ( ! $photo_id ) {
					continue;
				}

				$align = (string) ( $row['align'] ?? 'center-center' );
				?>
				<li class="bl-photos__item" data-bl-photos-item>
					<img
						class="bl-photos__thumb"
						src="<?php echo esc_url( (string) wp_get_attachment_image_url( $photo_id, 'thumbnail' ) ); ?>"
						alt=""
						width="60"
						height="60"
					>
					<input
						type="hidden"
						name="<?php echo esc_attr( $name . '[' . $index . '][id]' ); ?>"
						value="<?php echo esc_attr( (string) $photo_id ); ?>"
						data-bl-photos-id
					>
					<label class="bl-photos__align">
						<span class="screen-reader-text">
							<?php
							printf(
								/* translators: %s: the photograph's file name. */
								esc_html__( 'Alignment for %s', 'blueline' ),
								esc_html( (string) get_the_title( $photo_id ) )
							);
							?>
						</span>
						<select name="<?php echo esc_attr( $name . '[' . $index . '][align]' ); ?>" data-bl-photos-align>
							<?php foreach ( $alignments as $key => $unused ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $align, $key ); ?>>
									<?php echo esc_html( blueline_settings_alignment_label( $key ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="button" class="button-link bl-photos__remove" data-bl-photos-remove>
						<?php esc_html_e( 'Remove', 'blueline' ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="bl-photos__empty" data-bl-photos-empty <?php echo $rows ? 'hidden' : ''; ?>>
			<?php esc_html_e( 'Using the photographs that ship with the theme.', 'blueline' ); ?>
		</p>

		<p>
			<button type="button" class="button" data-bl-photos-add>
				<?php esc_html_e( 'Add photographs', 'blueline' ); ?>
			</button>
		</p>

		<?php if ( ! empty( $field['help'] ) ) : ?>
			<p class="description"><?php echo esc_html( (string) $field['help'] ); ?></p>
		<?php endif; ?>

		<template data-bl-photos-template>
			<li class="bl-photos__item" data-bl-photos-item>
				<img class="bl-photos__thumb" src="" alt="" width="60" height="60">
				<input type="hidden" name="" value="" data-bl-photos-id>
				<label class="bl-photos__align">
					<span class="screen-reader-text"><?php esc_html_e( 'Alignment', 'blueline' ); ?></span>
					<select name="" data-bl-photos-align>
						<?php foreach ( $alignments as $key => $unused ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'center-center', $key ); ?>>
								<?php echo esc_html( blueline_settings_alignment_label( $key ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="button-link bl-photos__remove" data-bl-photos-remove>
					<?php esc_html_e( 'Remove', 'blueline' ); ?>
				</button>
			</li>
		</template>
	</div>
	<?php
}

/**
 * Human-readable label for an alignment key.
 *
 * Kept out of blueline_band_photo_alignments() so that map stays a pure
 * key => CSS-value lookup usable on the front end, where these admin-facing
 * strings have no business being loaded.
 *
 * @param string $key An alignment key.
 * @return string
 */
function blueline_settings_alignment_label( string $key ): string {
	$labels = array(
		'left-top'      => __( 'Left top', 'blueline' ),
		'center-top'    => __( 'Centre top', 'blueline' ),
		'right-top'     => __( 'Right top', 'blueline' ),
		'left-center'   => __( 'Left middle', 'blueline' ),
		'center-center' => __( 'Centre', 'blueline' ),
		'right-center'  => __( 'Right middle', 'blueline' ),
		'left-bottom'   => __( 'Left bottom', 'blueline' ),
		'center-bottom' => __( 'Centre bottom', 'blueline' ),
		'right-bottom'  => __( 'Right bottom', 'blueline' ),
	);

	return $labels[ $key ] ?? $key;
}

add_action( 'admin_enqueue_scripts', 'blueline_settings_maybe_enqueue_photo_picker' );
/**
 * Enqueue the media library and the hero-photograph picker, on this page only.
 *
 * Enqueued as a PLAIN SOURCE FILE, not a webpack entry, which is a deliberate
 * continuation of this file's own "no webpack entry" constraint rather than an
 * oversight: the script has no imports, no JSX and no dependencies beyond
 * wp.media, so a build step would buy nothing but a build step. It is still
 * linted (npm run lint:js covers assets/src/js) and still shipped by the same
 * rsync as everything else.
 *
 * wp_enqueue_media() is what actually makes wp.media exist; without it the
 * picker button renders and does nothing, which is the same graceful state as
 * having no JavaScript at all.
 *
 * Version is blueline_dist_version() on the source file so a changed picker
 * busts its own cache, matching how every other asset in this theme is
 * versioned.
 *
 * @param string $hook_suffix The current admin screen's hook suffix.
 * @return void
 */
function blueline_settings_maybe_enqueue_photo_picker( string $hook_suffix ): void {
	if ( blueline_settings_page_hook() !== $hook_suffix ) {
		return;
	}

	// Only the Appearance tab has a photograph field; loading the whole media
	// library on the Content tab would be a large download for nothing.
	if ( 'appearance' !== blueline_settings_current_tab() ) {
		return;
	}

	wp_enqueue_media();

	$relative = '/assets/src/js/settings-photos.js';
	$path     = BLUELINE_DIR . $relative;

	wp_enqueue_script(
		'blueline-settings-photos',
		BLUELINE_URI . $relative,
		array(),
		file_exists( $path ) ? (string) filemtime( $path ) : '1',
		true
	);

	wp_add_inline_style( 'wp-admin', blueline_settings_photo_picker_styles() );
}

/**
 * The picker's own layout. Small enough to inline, and scoped tightly enough
 * that it cannot reach anything else in wp-admin.
 *
 * @return string
 */
function blueline_settings_photo_picker_styles(): string {
	return '.bl-photos__list{margin:0;padding:0;list-style:none;}'
		. '.bl-photos__item{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid #dcdcde;}'
		. '.bl-photos__thumb{width:60px;height:60px;object-fit:cover;border-radius:3px;background:#f0f0f1;}'
		. '.bl-photos__align{margin-inline-start:auto;}'
		. '.bl-photos__remove{color:#b32d2e;}'
		. '.bl-photos__empty{color:#646970;font-style:italic;}';
}
