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
 * re-sanitize quirk that affects `_posted_fields`: add_option()'s own
 * re-sanitize pass has no merge stage to strip a second time, so a genuine
 * first write can persist a spurious `_tab => ''` alongside
 * `_posted_fields => []`, self-healing on the very next real save. The
 * merge-side half of the fix is blueline_settings_merge()'s defensive
 * second strip (inc/settings/store.php); the quirk itself is set out in
 * inc/settings/snapshots.php's file docblock, which has to reason about it
 * to decide what NOT to snapshot.
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
 * The fix is an explicit reserved-key allow-list -- today
 * `array( '_schema', 'aa_acknowledgements', 'occasions' )` -- rather than
 * "forward anything unrecognised":
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
 * today inc/settings/store.php's `_schema` migration version and
 * inc/settings/acknowledgements.php's `aa_acknowledgements` map.
 * blueline_settings_sanitize_callback() checks every unrecognised key
 * against this explicit allow-list rather than forwarding it merely for
 * being unrecognised -- see this file's own docblock's `_schema` section
 * for why that distinction is load-bearing.
 */
const BLUELINE_SETTINGS_RESERVED_KEYS = array( '_schema', 'aa_acknowledgements', 'occasions' );

/**
 * Nonce action shared by the panel's two import steps (preview, then
 * apply). Separate from settings_fields()' save nonce because these are
 * separate, differently-shaped writes -- the same reasoning
 * blueline_settings_maybe_restore() applies to its own action.
 */
const BLUELINE_SETTINGS_IMPORT_NONCE = 'blueline_settings_import';

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
 * Four responsibilities, each described in this file's own docblock in
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
 *    allow-list (BLUELINE_SETTINGS_RESERVED_KEYS, today `_schema`,
 *    `aa_acknowledgements`, and `occasions`), never forwarded merely for being
 *    unrecognised -- see this file's docblock's `_schema` section for why
 *    "forward anything unrecognised" was rejected. A reserved key is still
 *    sanitized, though not identically: `_schema` is sanitized like every
 *    other integer-valued field (absint()) and additionally clamped to
 *    BLUELINE_SETTINGS_SCHEMA_VERSION, while `aa_acknowledgements` is not
 *    an integer at all and instead goes through its own validator,
 *    blueline_sanitize_acknowledgements() (inc/settings/acknowledgements.php).
 *    Either way, the key is dropped outright when the submission carries a
 *    `_tab` (came from this file's own form, which never legitimately
 *    submits either one) -- EXCEPT `occasions`, which is the one reserved
 *    key that DOES survive a tab-scoped submission, and only when that
 *    submission's own `_tab` is literally `'occasions'` (design spec
 *    §5.1's second ruling): that tab's own rendered form posts
 *    `blueline_settings[occasions]` as one opaque map value, never through
 *    `_posted_fields` per-field carry-forward. `_schema` and
 *    `aa_acknowledgements` keep the absolute drop-on-any-tab rule
 *    unchanged.
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
			// quirk set out in inc/settings/snapshots.php's file docblock is
			// the one exception) --
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

			if ( '' !== $submitted_tab && ! ( 'occasions' === $key && 'occasions' === $submitted_tab ) ) {
				// Reserved, but this submission carries `_tab` -- it came
				// from this file's own rendered form, which never
				// legitimately submits a reserved key... EXCEPT
				// `occasions` submitted BY its own Occasions tab (design
				// spec §5.1's second ruling): that tab's own form posts
				// `blueline_settings[occasions]` as one opaque map value,
				// never through `_posted_fields` per-field carry-forward,
				// since `occasions` is not a scalar schema field at all.
				// `_schema` and `aa_acknowledgements` keep the absolute
				// rule unchanged -- this exception names `occasions` AND
				// `'occasions' === $submitted_tab` together, rather than
				// loosening the rule for every reserved key.
				continue;
			}

			if ( 'aa_acknowledgements' === $key ) {
				// Not an integer like every other reserved key today -- a map
				// of acknowledgement entries (inc/settings/acknowledgements.php).
				// Its own validator drops anything malformed rather than
				// corrupting the option or crashing a later reader.
				$output[ $key ] = blueline_sanitize_acknowledgements( $value );
				continue;
			}

			if ( 'occasions' === $key ) {
				if ( 'occasions' === $submitted_tab ) {
					// The Occasions tab's own save (design spec §5.1's
					// first ruling): derive and de-duplicate every row's
					// id server-side BEFORE the unchanged
					// blueline_sanitize_occasions() ever sees it -- the
					// admin never types an id directly.
					$stored_occasions = is_array( $current['occasions'] ?? null ) ? $current['occasions'] : array();
					$with_ids         = blueline_occasions_assign_unique_ids( $value, $stored_occasions );

					// Per-row override checkboxes ride along inside
					// $with_ids (blueline_occasions_assign_unique_ids()
					// copies every OTHER key of a row through untouched)
					// -- read them here, keyed by each row's own FINAL
					// id, before blueline_sanitize_occasions() strips the
					// extra `override_aa` key off (it only ever keeps
					// the eight documented Occasion keys).
					$raw_overrides = array();
					foreach ( $with_ids as $row_id => $row ) {
						$raw_overrides[ $row_id ] = is_array( $row ) && ! empty( $row['override_aa'] );
					}

					$sanitized_occasions = blueline_sanitize_occasions( $with_ids );

					$output[ $key ] = $sanitized_occasions;

					// design spec §5.1's fifth ruling: the Occasions
					// tab's own save is also what decides this save's
					// new `aa_acknowledgements` value -- per-occasion,
					// symmetric record/remove, plus orphan cleanup. This
					// key is never present in $input for this
					// submission (the form never renders a field named
					// it), so nothing else in this loop will ever
					// overwrite it.
					$output['aa_acknowledgements'] = blueline_occasions_apply_aa_overrides(
						$sanitized_occasions,
						$raw_overrides,
						blueline_stored_acknowledgements(),
						blueline_settings_inputs_hash(),
						get_current_user_id()
					);
				} else {
					// A programmatic write (WP-CLI, a direct update_option()
					// call, an import) -- no id derivation: the caller is
					// expected to already supply final, correctly-keyed
					// ids, exactly as this branch behaved before 2.1b.
					$output[ $key ] = blueline_sanitize_occasions( $value );
				}
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
				// the limit blueline_settings_import_prepare()
				// (inc/settings/import.php) enforces on an import's own
				// `_schema` -- for the CLI and the panel alike, since
				// fe37280 unified them (there, by refusing the whole
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

add_action( 'admin_notices', 'blueline_settings_newer_schema_notice' );
/**
 * Tell an admin when the stored settings were written by a NEWER version of
 * this theme than the one running -- the one state inc/settings/store.php's
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
 * - "the automatic settings upgrade refuses to run" --
 *   test_the_migration_refuses_to_write_and_leaves_the_stored_value_alone()
 * - "the site still reads every setting this version recognises" --
 *   test_settings_still_read_normally_under_a_newer_stored_schema()
 * - "saving from Appearance -> Blueline carries the newer values forward" --
 *   test_an_ordinary_panel_save_keeps_both_the_newer_schema_and_its_unknown_keys()
 *
 * A `<section>`, not a `<div>` -- see this file's own docblock's "Never a
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
 * Apply a restore submitted from the "Recent saves" list, if this request
 * carries one.
 *
 * Called from blueline_settings_render_page() BEFORE it renders anything,
 * so the page an admin sees after clicking Restore already shows the
 * restored values and the outcome notice. That is deliberately not a
 * POST-redirect-GET round trip: the control names a snapshot by its stable
 * id (see blueline_settings_snapshot_take()), so re-submitting the same
 * request -- the browser refresh a non-redirecting POST invites -- restores
 * the same snapshot again, which by then changes nothing and records no
 * new history (blueline_settings_snapshot_on_save() skips a write whose
 * merged result matches storage).
 *
 * Three guards, in this order:
 *
 * 1. No `blueline_restore_snapshot` in the POST: not a restore request at
 *    all, return before touching anything else.
 * 2. `manage_options`. This is checked here as well as in
 *    blueline_settings_render_page() -- the same defence in depth that
 *    function already applies over blueline_settings_add_page()'s own
 *    capability argument -- so this function is safe whatever ends up
 *    calling it.
 * 3. check_admin_referer() against the restore's own action, which is a
 *    separate nonce from settings_fields()' save nonce because this is a
 *    separate, differently-shaped write.
 *
 * @return void
 */
function blueline_settings_maybe_restore(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only, to decide whether this is a restore request at all; the nonce is verified below before anything is read or written.
	if ( ! isset( $_POST['blueline_restore_snapshot'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to restore Blueline settings.', 'blueline' ) );
	}

	check_admin_referer( 'blueline_settings_restore' );

	// is_scalar() first: PHP evaluates a non-empty array to 1 on the way
	// through absint(), so an array-shaped POST value (`...[]=x`) would
	// silently name snapshot 1 and restore it. Nonce and capability both
	// stand in front of this, so it is not a security hole -- it is a
	// restore nobody asked for, which is bad enough. 0 is never a real
	// snapshot id (they start at 1), so a non-scalar falls into the
	// "no longer available" branch below and says so.
	$id = is_scalar( $_POST['blueline_restore_snapshot'] ) ? absint( wp_unslash( $_POST['blueline_restore_snapshot'] ) ) : 0;

	if ( blueline_settings_snapshot_restore( $id ) ) {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_restored',
			// "Any values it replaced" rather than "the values it
			// replaced": restoring a copy the settings ALREADY match
			// replaces nothing and records nothing (see
			// blueline_settings_snapshot_on_save()), which is exactly what
			// happens if this page is refreshed after a restore.
			esc_html__( 'Those settings were restored. Any values it replaced were recorded below first, so a restore can itself be undone.', 'blueline' ),
			'success'
		);
		return;
	}

	add_settings_error(
		BLUELINE_SETTINGS_OPTION,
		'blueline_settings_restore_missing',
		esc_html(
			sprintf(
				/* translators: %d: how many saved copies are kept. */
				__( 'That saved copy is no longer available -- only the last %d are kept. Nothing was changed.', 'blueline' ),
				BLUELINE_SETTINGS_SNAPSHOT_LIMIT
			)
		),
		'error'
	);
}

/**
 * Render the "Recent saves" list: every stored snapshot, newest first,
 * each with its own nonce-protected restore control.
 *
 * The copy here makes three claims, and tests/SettingsSnapshotsTest.php
 * pins one test on each -- named here so a future edit to either side can
 * be checked against the other:
 *
 * - "through the same checks an ordinary save uses" --
 *   test_restoring_an_invalid_value_keeps_the_stored_one_and_reports_it()
 *   plants a value the sanitizer refuses into a snapshot (which nothing
 *   validates on the way in) and proves the restore refuses it too, keeping
 *   the stored value and reporting the refusal.
 * - "records the current values first, so a restore can itself be undone"
 *   -- test_a_restore_is_itself_undoable().
 * - "a setting a saved copy does not carry keeps its current value" --
 *   test_restore_leaves_a_key_the_snapshot_does_not_carry_alone(). This is
 *   the merge-not-replace behaviour every write to this option has
 *   (inc/settings/store.php), and an admin told "this puts the settings
 *   back exactly as they were" would be told something this code does not
 *   do.
 *
 * Each row is its own small <form> posting back to the current tab's URL,
 * rather than one form with several submit buttons, so the id being
 * restored is unambiguous in the submitted request.
 *
 * @return void
 */
function blueline_settings_render_snapshots(): void {
	$snapshots = blueline_settings_snapshot_list();
	?>
	<section class="bl-settings-history">
		<h2><?php echo esc_html( __( 'Recent saves', 'blueline' ) ); ?></h2>

		<?php if ( empty( $snapshots ) ) : ?>
			<p><?php echo esc_html( __( 'No saves recorded yet. The next time these settings are saved, the values it replaces are recorded here.', 'blueline' ) ); ?></p>
		<?php else : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: how many saves are kept. */
						__( 'Every save that changes something records the values it replaced; the last %d are kept. Restoring one writes those values back through the same checks an ordinary save uses, and records the current values first, so a restore can itself be undone. A setting a saved copy does not carry keeps its current value rather than being reset.', 'blueline' ),
						BLUELINE_SETTINGS_SNAPSHOT_LIMIT
					)
				);
				?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( __( 'Recorded', 'blueline' ) ); ?></th>
						<th scope="col"><?php echo esc_html( __( 'Settings in this copy', 'blueline' ) ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php echo esc_html( __( 'Restore', 'blueline' ) ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $snapshots as $snapshot ) : ?>
						<tr>
							<td><?php echo esc_html( blueline_settings_snapshot_time_label( $snapshot['time'] ) ); ?></td>
							<td><?php echo esc_html( (string) count( $snapshot['settings'] ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( blueline_settings_tab_url( blueline_settings_current_tab() ) ); ?>">
									<?php wp_nonce_field( 'blueline_settings_restore' ); ?>
									<button
										type="submit"
										class="button"
										name="blueline_restore_snapshot"
										value="<?php echo esc_attr( (string) $snapshot['id'] ); ?>"
									>
										<?php echo esc_html( __( 'Restore', 'blueline' ) ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Nonce action for the export download, separate from the import one:
 * they are separate requests to separate endpoints, and one token that
 * covered both would let a stale import form authorise a download or the
 * other way round.
 */
const BLUELINE_SETTINGS_EXPORT_NONCE = 'blueline_settings_export';

/**
 * The exact bytes the panel's Download button hands back -- the SAME
 * payload and the SAME JSON flags `wp blueline settings export` prints, so
 * the two surfaces cannot drift into two shapes `import` would then have to
 * accept both of. tests/SettingsPanelImportExportTest.php asserts that
 * equality directly rather than trusting this comment.
 *
 * @return string
 */
function blueline_settings_export_json(): string {
	return (string) wp_json_encode( blueline_settings_export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

/**
 * The download's filename: dated, in the SITE's timezone (wp_timezone(),
 * the same source inc/settings/snapshots.php uses for its own labels)
 * rather than UTC, because the admin reading their downloads folder thinks
 * in local time. Dated at all so two exports taken from two environments on
 * two days do not both land as one anonymous "settings.json".
 *
 * @return string
 */
function blueline_settings_export_filename(): string {
	return 'blueline-settings-' . ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' ) . '.json';
}

add_action( 'admin_post_blueline_settings_export', 'blueline_settings_handle_export' );
/**
 * Serve the export as a file download.
 *
 * On `admin_post_*` rather than inside the panel's own render, because a
 * download has to send its headers before any other output -- rendering
 * half a wp-admin page and then trying to become a file is not something
 * that can be recovered from.
 *
 * Capability first, then nonce: an export hands the caller every stored
 * setting, so it is gated exactly as the panel itself is.
 *
 * The final four lines (the headers, the echo and the exit) are the one
 * part of these controls no test here exercises -- a PHPUnit process cannot
 * usefully assert on headers it also has to keep running after. Everything
 * they depend on (the payload, the filename, the capability refusal) is
 * covered separately.
 *
 * @return void
 */
function blueline_settings_handle_export(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to export Blueline settings.', 'blueline' ) );
	}

	check_admin_referer( BLUELINE_SETTINGS_EXPORT_NONCE );

	$json = blueline_settings_export_json();

	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . blueline_settings_export_filename() . '"' );
	header( 'Content-Length: ' . strlen( $json ) );

	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this response IS a JSON file, not HTML: escaping it would corrupt the download it exists to produce. Its content comes from blueline_settings(), which every write path has already sanitized, and wp_json_encode() has already escaped it as JSON.
	exit;
}

/**
 * Work out what an import of $raw would do, without doing any of it.
 *
 * Runs the identical machinery `wp blueline settings import --dry-run`
 * runs, in the identical order (inc/settings/import.php): bound and decode,
 * refuse a newer `_schema`, collect the keys the schema does not declare,
 * then walk every recognised field through blueline_sanitize_field(). The
 * diff is built from the SANITIZED values, never the file's own text,
 * because that sanitizer normalises as well as validates -- a preview built
 * from the raw payload would report a change to a value the import is never
 * going to store.
 *
 * A payload with any rejected field returns `diff => null` deliberately:
 * a rejected field keeps its currently-stored value, so a diff drawn
 * alongside the failures would be a preview of something that is not going
 * to happen. The caller withholds the apply control in that case, which is
 * the panel's equivalent of the CLI dry run's "fix the file and re-run to
 * see the diff".
 *
 * @param string $raw Raw JSON, from an upload or a paste.
 * @return array{errors: string[], dropped: string[], diff: array<string, array{status:string, from:mixed, to:mixed}>|null, prepared: array<string, mixed>, raw: string}
 */
function blueline_settings_import_preview_state( string $raw ): array {
	$state = array(
		'errors'   => array(),
		'dropped'  => array(),
		'diff'     => null,
		'prepared' => array(),
		'raw'      => $raw,
	);

	$payload = blueline_settings_import_decode( $raw );

	if ( is_wp_error( $payload ) ) {
		$state['errors'][] = $payload->get_error_message();
		return $state;
	}

	$prepared = blueline_settings_import_prepare( $payload, BLUELINE_SETTINGS_SCHEMA_VERSION );

	if ( is_wp_error( $prepared ) ) {
		$state['errors'][] = $prepared->get_error_message();
		return $state;
	}

	$schema = blueline_settings_schema();

	$state['prepared'] = $prepared;
	$state['dropped']  = blueline_settings_import_dropped_keys( $prepared, $schema );

	$sanitized = blueline_settings_import_sanitize_payload( $prepared, $schema );

	if ( ! empty( $sanitized['errors'] ) ) {
		$state['errors'] = $sanitized['errors'];
		return $state;
	}

	$state['diff'] = blueline_settings_diff( blueline_settings(), $sanitized['values'] );

	return $state;
}

/**
 * Read the bytes an import submission is offering, from whichever of its
 * two inputs was used.
 *
 * The form offers both an upload and a paste box, in that order of
 * precedence. Two inputs rather than one because an admin who can reach
 * wp-admin cannot necessarily get a file onto the machine they are browsing
 * from (a shared laptop, a phone), and the whole point of this feature is
 * moving a small config between environments.
 *
 * @return string|WP_Error The raw payload, or an error naming what went
 *                          wrong with the submission itself (not with its
 *                          contents, which is blueline_settings_import_preview_state()'s
 *                          job).
 */
function blueline_settings_import_submitted_payload() {
	// Both sniffs are silenced on the line itself rather than from the line
	// above: phpcs lets a trailing annotation REPLACE a preceding-line one, so
	// splitting them across two comments silently drops the first.
	$file = isset( $_FILES['blueline_import_file'] ) && is_array( $_FILES['blueline_import_file'] ) ? $_FILES['blueline_import_file'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce for this submission is verified by blueline_settings_maybe_handle_import(), this function's only caller, before it is called; and this is an upload descriptor, not text: every field it reads is cast and validated in blueline_settings_import_read_upload(), and its bytes go through the JSON decoder's own bounds rather than a text sanitizer.

	if ( null !== $file && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
		return blueline_settings_import_read_upload( $file );
	}

	$pasted = isset( $_POST['blueline_import_json'] ) ? trim( (string) wp_unslash( $_POST['blueline_import_json'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by the only caller, as above; and deliberately NOT run through sanitize_text_field(): this value is a JSON document, and stripping tags/newlines out of it would corrupt a valid payload rather than protect anything. It is validated by blueline_settings_import_decode()'s size, depth and shape bounds, and every value inside it by blueline_sanitize_field(), before any of it is stored.

	if ( '' !== $pasted ) {
		return $pasted;
	}

	return new WP_Error(
		'blueline_import_nothing_submitted',
		__( 'Choose a settings file, or paste one in, and try again.', 'blueline' )
	);
}

/**
 * Turn one `$_FILES` entry into its contents, refusing anything that is not
 * a plausible settings file BEFORE reading it.
 *
 * The size check happens on the size the upload REPORTS, ahead of any read:
 * a browser upload is a much easier thing to point at an enormous file than
 * a CLI invocation is, and a bound applied only after the bytes are in
 * memory would not be much of a bound.
 *
 * is_uploaded_file() is the last gate before the read. It is what
 * distinguishes a path PHP itself created while parsing a multipart request
 * from any other path that might somehow appear in this array.
 *
 * @param array<string, mixed> $file One `$_FILES` entry.
 * @return string|WP_Error
 */
function blueline_settings_import_read_upload( array $file ) {
	$upload_error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

	if ( UPLOAD_ERR_OK !== $upload_error ) {
		return new WP_Error(
			'blueline_import_upload_failed',
			__( 'That file did not finish uploading. Try again, or paste its contents instead.', 'blueline' )
		);
	}

	$size = isset( $file['size'] ) ? (int) $file['size'] : 0;

	if ( $size > BLUELINE_SETTINGS_IMPORT_MAX_BYTES ) {
		return new WP_Error( 'blueline_import_too_large', blueline_settings_import_too_large_message( $size ) );
	}

	$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

	if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
		return new WP_Error(
			'blueline_import_upload_failed',
			__( 'That file did not finish uploading. Try again, or paste its contents instead.', 'blueline' )
		);
	}

	return (string) file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local temp file PHP itself just wrote from a multipart upload (proven by is_uploaded_file() immediately above), not an HTTP fetch; wp_remote_get() is for URLs.
}

/**
 * Handle an import submission, if this request carries one.
 *
 * Called from blueline_settings_render_page() BEFORE anything is rendered,
 * for the same reason blueline_settings_maybe_restore() is: an applied
 * import rewrites the very values the fields below are about to be filled
 * from.
 *
 * Guards, in this order and shared by both steps:
 *
 * 1. Neither `blueline_import_preview` nor `blueline_import_apply` in the
 *    POST: not an import request at all, return before touching anything.
 * 2. `manage_options`.
 * 3. check_admin_referer() against BLUELINE_SETTINGS_IMPORT_NONCE. The
 *    apply step carries its own copy of the token rather than inheriting
 *    the preview's: it is a separate request, and a separate write.
 *
 * @return array<string, mixed>|null The preview to render, or null when
 *                                    there is nothing to show (not an
 *                                    import request, or an apply that
 *                                    succeeded and has said so via
 *                                    add_settings_error()).
 */
function blueline_settings_maybe_handle_import(): ?array {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence checks only, to decide whether this is an import request at all; the nonce is verified below before anything is read or written.
	$is_apply = isset( $_POST['blueline_import_apply'] );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
	$is_preview = isset( $_POST['blueline_import_preview'] );

	if ( ! $is_apply && ! $is_preview ) {
		return null;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to import Blueline settings.', 'blueline' ) );
	}

	check_admin_referer( BLUELINE_SETTINGS_IMPORT_NONCE );

	if ( $is_apply ) {
		return blueline_settings_apply_import();
	}

	$raw = blueline_settings_import_submitted_payload();

	if ( is_wp_error( $raw ) ) {
		return array(
			'errors'   => array( $raw->get_error_message() ),
			'dropped'  => array(),
			'diff'     => null,
			'prepared' => array(),
			'raw'      => '',
		);
	}

	return blueline_settings_import_preview_state( $raw );
}

/**
 * Apply the payload the preview step handed back through a hidden field.
 *
 * Re-runs the whole preview -- decode, bounds, `_schema` refusal, sanitizer
 * walk -- rather than trusting that the payload has not changed between the
 * two requests. It has travelled through a browser in the meantime, and the
 * preview's verdict is not a token of any kind.
 *
 * ALL OR NOTHING: if any field is refused, nothing is written at all. The
 * ordinary panel save deliberately does the opposite (a rejected field
 * keeps its stored value while every other field in the same submission
 * saves), because there a human is looking at one form and can fix the one
 * field. An import is a file the admin has just been shown a preview of; a
 * partial application would leave the site in a state that preview never
 * described.
 *
 * The write goes through update_option(), so the same
 * `sanitize_option_{$option}` callback and the same merge every other write
 * runs apply here too -- which is also what makes an omitted key carry its
 * stored value forward rather than resetting, exactly as the preview said.
 *
 * @return array<string, mixed>|null The preview state again when nothing
 *                                    could be applied (so the page can
 *                                    re-show why), or null on success.
 */
function blueline_settings_apply_import(): ?array {
	$raw = isset( $_POST['blueline_import_payload'] ) ? (string) wp_unslash( $_POST['blueline_import_payload'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by blueline_settings_maybe_handle_import(), this function's only caller, immediately before it is called; and this is a JSON document, not text -- see blueline_settings_import_submitted_payload() for why a text sanitizer would corrupt rather than protect it, and what does validate it instead.

	$state = blueline_settings_import_preview_state( $raw );

	if ( ! empty( $state['errors'] ) || null === $state['diff'] ) {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_import_failed',
			esc_html__( 'Nothing was imported. The problems below have to be fixed in the file first.', 'blueline' ),
			'error'
		);

		return $state;
	}

	update_option( BLUELINE_SETTINGS_OPTION, $state['prepared'] );

	$changed = 0;
	$kept    = 0;

	foreach ( $state['diff'] as $entry ) {
		if ( 'changed' === $entry['status'] ) {
			++$changed;
		} elseif ( 'carried_forward' === $entry['status'] ) {
			++$kept;
		}
	}

	add_settings_error(
		BLUELINE_SETTINGS_OPTION,
		'blueline_settings_imported',
		esc_html(
			sprintf(
				/* translators: 1: how many settings the file changed, 2: how many settings the file did not mention. */
				__( 'Imported. %1$d setting(s) changed; %2$d the file did not mention were left exactly as they were.', 'blueline' ),
				$changed,
				$kept
			)
		),
		'success'
	);

	return null;
}

/**
 * The admin-facing label for one blueline_settings_diff() status.
 *
 * `carried_forward` gets the longest one on purpose: it is the state an
 * admin is most likely to misread, and "Not in the file" alone would leave
 * "so what happens to it?" unanswered.
 *
 * @param string $status A blueline_settings_diff() status.
 * @return string
 */
function blueline_settings_diff_status_label( string $status ): string {
	switch ( $status ) {
		case 'changed':
			return __( 'Changing', 'blueline' );
		case 'added':
			return __( 'Adding', 'blueline' );
		case 'carried_forward':
			return __( 'Not in the file — kept as it is', 'blueline' );
		default:
			return __( 'No change', 'blueline' );
	}
}

/**
 * Render "Export and import": the download control, the two-input import
 * form, and -- when this request produced one -- the preview of what that
 * import would do.
 *
 * Rendered below the tab's own form, alongside "Recent saves", rather than
 * on a tab of its own: these operate on the whole option rather than on one
 * tab's fields, and tabs here are derived from the schema
 * (blueline_settings_tab_slugs()), so a tab with no fields behind it would
 * have to be special-cased into that list.
 *
 * @param array<string, mixed>|null $preview A blueline_settings_maybe_handle_import() result.
 * @return void
 */
function blueline_settings_render_data_tools( ?array $preview ): void {
	$here = blueline_settings_tab_url( blueline_settings_current_tab() );
	?>
	<section class="bl-settings-data">
		<h2><?php echo esc_html( __( 'Export and import', 'blueline' ) ); ?></h2>

		<p>
			<?php
			echo esc_html(
				__( 'The download below contains only the settings on this page: the page choices, the copy, the toggles, and the contact address the site already publishes. No passwords, no API keys, and nothing about any member.', 'blueline' )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="blueline_settings_export">
			<?php wp_nonce_field( BLUELINE_SETTINGS_EXPORT_NONCE ); ?>
			<p>
				<button type="submit" class="button">
					<?php echo esc_html( __( 'Download these settings as JSON', 'blueline' ) ); ?>
				</button>
			</p>
		</form>

		<h3><?php echo esc_html( __( 'Import a settings file', 'blueline' ) ); ?></h3>

		<p>
			<?php
			echo esc_html(
				__( 'Nothing is written until you have seen exactly what would change. A setting the file does not mention is left as it is rather than reset, so a file carrying one setting changes one setting.', 'blueline' )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( $here ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( BLUELINE_SETTINGS_IMPORT_NONCE ); ?>

			<p>
				<label for="blueline-import-file"><?php echo esc_html( __( 'Settings file', 'blueline' ) ); ?></label><br>
				<input type="file" id="blueline-import-file" name="blueline_import_file" accept=".json,application/json">
			</p>

			<p>
				<label for="blueline-import-json"><?php echo esc_html( __( 'Or paste the file\'s contents', 'blueline' ) ); ?></label><br>
				<textarea id="blueline-import-json" name="blueline_import_json" rows="4" class="large-text code"></textarea>
			</p>

			<p>
				<button type="submit" class="button" name="blueline_import_preview" value="1">
					<?php echo esc_html( __( 'Preview changes', 'blueline' ) ); ?>
				</button>
			</p>
		</form>

		<?php
		if ( null !== $preview ) {
			blueline_settings_render_import_preview( $preview, $here );
		}
		?>
	</section>
	<?php
	blueline_settings_render_delete_all_data();
}

/**
 * Render the "Delete all Blueline data" control -- but only when the Advanced
 * toggle is on.
 *
 * This is what `advanced_enabled` gates, and the pairing is the point: the spec
 * calls Advanced a "here be dragons" disclosure affordance, and this is the
 * most dragon-like control the panel has. Hiding it by default costs an admin
 * who genuinely wants it one extra checkbox, and stops everyone else from
 * finding it next to the harmless-looking export button.
 *
 * It is a DISCLOSURE, not a lock, and the copy on the toggle itself says so.
 * Anyone who can see this page holds `manage_options` and could delete these
 * rows by other means; hiding the button protects against the slip, not
 * against the determined.
 *
 * @return void
 */
function blueline_settings_render_delete_all_data(): void {
	if ( ! blueline_settings( 'advanced_enabled' ) ) {
		return;
	}
	?>
	<section class="bl-settings-data bl-settings-data--danger">
		<h2><?php echo esc_html( __( 'Delete all Blueline data', 'blueline' ) ); ?></h2>

		<p>
			<?php
			echo esc_html(
				__( 'Removes every setting on this page, the saved copies under "Recent saves", and nothing else. Your pages, posts, players, events, photographs and member accounts are untouched -- this deletes the theme\'s own settings, not your content.', 'blueline' )
			);
			?>
		</p>

		<p>
			<?php
			echo esc_html(
				__( 'This is not the same as resetting. Resetting puts every field back to its default and leaves the saved copies in place, so you can undo it. This deletes the saved copies too: afterwards there is nothing to restore from except a database backup. The site keeps working and falls back to the same defaults either way.', 'blueline' )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'blueline_settings_delete_all_data' ); ?>
			<input type="hidden" name="action" value="blueline_settings_delete_all_data">
			<p>
				<label>
					<input type="checkbox" name="blueline_delete_confirm" value="1">
					<?php echo esc_html( __( 'Yes, delete all Blueline settings and their saved copies', 'blueline' ) ); ?>
				</label>
			</p>
			<button type="submit" class="button button-secondary">
				<?php echo esc_html( __( 'Delete all Blueline data', 'blueline' ) ); ?>
			</button>
		</form>
	</section>
	<?php
}

add_action( 'admin_post_blueline_settings_delete_all_data', 'blueline_settings_handle_delete_all_data' );

/**
 * Handle the "Delete all Blueline data" submission.
 *
 * Three gates, in the order that fails most cheaply first: capability, nonce,
 * then the explicit confirmation checkbox. The checkbox is not ceremony -- it
 * is the only one of the three a logged-in admin cannot satisfy by accident,
 * since both of the others are satisfied merely by being who they already are
 * and clicking something on a page they already have open.
 *
 * @return void
 */
function blueline_settings_handle_delete_all_data(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage this site\'s settings.', 'blueline' ) );
	}

	check_admin_referer( 'blueline_settings_delete_all_data' );

	$confirmed = isset( $_POST['blueline_delete_confirm'] ) && '1' === $_POST['blueline_delete_confirm']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against an exact literal rather than used as text, so there is nothing for a sanitizer to strip; the nonce is verified immediately above.

	if ( ! $confirmed ) {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_delete_unconfirmed',
			__( 'Nothing was deleted: the confirmation box was not ticked.', 'blueline' ),
			'warning'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( blueline_settings_tab_url( blueline_settings_current_tab() ) );
		exit;
	}

	$result = blueline_settings_delete_all_data();

	if ( ! $result['deleted'] ) {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_delete_nothing_stored',
			__( 'There was nothing to delete: no Blueline settings were stored.', 'blueline' ),
			'warning'
		);
	} else {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_deleted',
			$result['purge_ran']
				? __( 'All Blueline data deleted. The site is now using the built-in defaults.', 'blueline' )
				: __( 'All Blueline data deleted. The site is now using the built-in defaults. The page cache was not purged, so visitors may keep seeing the old settings until it is purged by hand.', 'blueline' ),
			'success'
		);
	}

	set_transient( 'settings_errors', get_settings_errors(), 30 );
	wp_safe_redirect( blueline_settings_tab_url( blueline_settings_current_tab() ) );
	exit;
}

/**
 * Render one import preview: what would be refused, what would be ignored,
 * what would happen to every setting, and -- only when there is nothing to
 * refuse -- the control that actually applies it.
 *
 * Both notices below are `<section>`s, never `<div>`s -- see this file's own
 * docblock's "Never a `<div>`" section and tests/NoticeDivGuardTest.php.
 *
 * EVERY key gets a row, including the ones that are not changing. That is
 * the point of the preview and the same choice
 * blueline_settings_cli_diff_lines() makes: an import that omits a key does
 * not reset it, so a preview showing only differences would read as "these
 * are the only differences" while every omitted key sat invisible in it.
 *
 * @param array<string, mixed> $preview A blueline_settings_import_preview_state() result.
 * @param string               $here    URL this preview's own apply form posts back to.
 * @return void
 */
function blueline_settings_render_import_preview( array $preview, string $here ): void {
	?>
	<section class="bl-settings-import-preview">
		<h3><?php echo esc_html( __( 'What this file would do', 'blueline' ) ); ?></h3>

		<?php if ( ! empty( $preview['errors'] ) ) : ?>
			<section class="notice notice-error">
				<p><?php echo esc_html( __( 'This file cannot be imported as it stands:', 'blueline' ) ); ?></p>
				<ul>
					<?php foreach ( $preview['errors'] as $message ) : ?>
						<li><?php echo esc_html( (string) $message ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $preview['dropped'] ) ) : ?>
			<section class="notice notice-warning">
				<p><?php echo esc_html( __( 'These entries are not settings this version of the theme has, and would be ignored:', 'blueline' ) ); ?></p>
				<ul>
					<?php foreach ( $preview['dropped'] as $dropped_key ) : ?>
						<li><code><?php echo esc_html( (string) $dropped_key ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( is_array( $preview['diff'] ) ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( __( 'Setting', 'blueline' ) ); ?></th>
						<th scope="col"><?php echo esc_html( __( 'What would happen', 'blueline' ) ); ?></th>
						<th scope="col"><?php echo esc_html( __( 'Value afterwards', 'blueline' ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $preview['diff'] as $key => $entry ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $key ); ?></code></td>
							<td><?php echo esc_html( blueline_settings_diff_status_label( (string) $entry['status'] ) ); ?></td>
							<td><code><?php echo esc_html( blueline_settings_import_render_value( $entry['to'] ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( $here ); ?>">
				<?php wp_nonce_field( BLUELINE_SETTINGS_IMPORT_NONCE ); ?>
				<input type="hidden" name="blueline_import_payload" value="<?php echo esc_attr( (string) $preview['raw'] ); ?>">
				<p>
					<button type="submit" class="button button-primary" name="blueline_import_apply" value="1">
						<?php echo esc_html( __( 'Import these settings', 'blueline' ) ); ?>
					</button>
				</p>
			</form>
		<?php endif; ?>
	</section>
	<?php
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
 * Whether one queued settings error belongs to a specific FIELD -- i.e.
 * whether blueline_settings_field_errors() should claim it and the error
 * summary should link to it.
 *
 * Two conditions, and the second is the one that was missing: the entry
 * must be an `error` (a success/info entry like "Settings saved." belongs
 * to no field), AND its code must actually name a schema field. The
 * summary renders a code as the field's label and links it to
 * `#blueline-field-{code}`, so an error whose code is NOT a field -- a
 * failed snapshot restore, for one -- rendered a raw internal key as its
 * label and linked to an element that does not exist on the page. Those
 * entries belong in the page-level notice list instead, which
 * blueline_settings_non_field_messages() now takes as its complement.
 *
 * @param array<string, mixed> $error One get_settings_errors() entry.
 * @return bool
 */
function blueline_settings_error_is_field_scoped( array $error ): bool {
	if ( 'error' !== ( $error['type'] ?? '' ) ) {
		return false;
	}

	return isset( blueline_settings_schema()[ $error['code'] ?? '' ] );
}

/**
 * Field-level error messages from the last save, keyed by field key -- see
 * blueline_settings_error_is_field_scoped() for exactly which entries
 * qualify. Every message returned here was already run through esc_html()
 * at the point blueline_settings_sanitize_callback() called
 * add_settings_error() (see this file's docblock); it is safe to echo
 * directly, never re-escape it.
 *
 * @return array<string, string> Field key => already-escaped message.
 */
function blueline_settings_field_errors(): array {
	$errors = array();
	foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
		if ( blueline_settings_error_is_field_scoped( $error ) ) {
			$errors[ $error['code'] ] = $error['message'];
		}
	}
	return $errors;
}

/**
 * Every queued message that is NOT field-scoped: the generic "Settings
 * saved." success blueline_settings_maybe_flag_saved() queues, and any
 * page-level ERROR whose code names no field (a failed restore). The exact
 * complement of blueline_settings_field_errors(), so every queued entry is
 * rendered exactly once, by exactly one of the two. Already-escaped, same
 * guarantee as blueline_settings_field_errors().
 *
 * @return array<int, array{type:string, message:string}>
 */
function blueline_settings_non_field_messages(): array {
	$messages = array();
	foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
		if ( ! blueline_settings_error_is_field_scoped( $error ) ) {
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

	// Before anything is read for rendering: a restore rewrites the very
	// values the fields below are about to be filled from.
	blueline_settings_maybe_restore();

	// Same reason, same ordering requirement: an APPLIED import rewrites
	// them too. Its return value is the preview to render further down --
	// null when this request was not an import, or was one that applied
	// cleanly and has already queued its own message.
	$import_preview = blueline_settings_maybe_handle_import();

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

		<?php blueline_settings_render_snapshots(); ?>

		<?php blueline_settings_render_data_tools( $import_preview ); ?>
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
 * pick from); a `<select>` for any field declaring `choices`, checked ahead
 * of every type branch except `band_photos` because it describes the
 * control rather than the storage shape;
 * an `<input type="date">` for a `date` field -- Task 6, and
 * without it a `date` would fall through to the plain text input, since an
 * unrecognised type does not error here, it simply takes the last branch;
 * text/email otherwise), and, when this field failed the last
 * save, `aria-invalid`, `aria-describedby` and a visible error paragraph
 * that does not rely on colour alone (an explicit "Error:" prefix plus
 * text, in addition to the `bl-settings-field--error` class a stylesheet
 * may use for a colour treatment).
 *
 * `page_id`/`term_id` fields never fail validation -- blueline_sanitize_field()
 * sanitizes both with absint(), which cannot return a WP_Error -- so
 * neither branch below needs to handle an error state. A `date` field CAN
 * (a malformed date is a WP_Error, not a coercion), and so can a `choices`
 * field (a value off the list is refused rather than dropped -- reachable
 * from a hand-built POST or WP-CLI even though the select cannot produce
 * one), so both branches carry the same aria wiring the text input does.
 *
 * A field's `help` string is printed once, after whichever branch ran, for
 * every type. It used to be printed inside the `bool`/`section` branch
 * alone, which silently dropped `help` on any other type.
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
			<?php elseif ( ! empty( $field['choices'] ) ) : ?>
				<?php
				/*
				 * Task 7 fix round: `choices` is checked ahead of every
				 * `type` branch below because it is orthogonal to type -- it
				 * says "this field's value comes off a fixed list", which is
				 * a statement about the control, not the storage shape.
				 *
				 * `band_photos` above it is the one exception, and stays
				 * first deliberately: it is a repeater with its own renderer
				 * and no single scalar value, so a `<select>` could not
				 * express it even if someone added `choices` to it.
				 * SettingsDefaultsTest forbids that combination outright.
				 *
				 * A dropdown rather than a validated text box, deliberately:
				 * rejecting a typo at save time tells an admin they were
				 * wrong, but a list of five options means they cannot be
				 * wrong in the first place. That matters most for
				 * `season_state_override`, which is used under pressure.
				 * blueline_sanitize_field()'s own `choices` branch is still
				 * the guard behind this, for a hand-built POST or a WP-CLI
				 * write that never renders this select at all.
				 *
				 * No hidden companion input is needed here (unlike the
				 * checkbox branch below): a `<select>` always posts exactly
				 * one value, including its empty option.
				 */
				?>
				<select
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					<?php
					if ( $has_error ) :
						?>
						aria-invalid="true" aria-describedby="<?php echo esc_attr( $error_id ); ?>"<?php endif; ?>
				>
					<?php foreach ( (array) $field['choices'] as $choice_value => $choice_label ) : ?>
						<option
							value="<?php echo esc_attr( (string) $choice_value ); ?>"
							<?php selected( (string) $choice_value, (string) $value ); ?>
						><?php echo esc_html( (string) $choice_label ); ?></option>
					<?php endforeach; ?>
				</select>
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
			<?php elseif ( 'date' === $type ) : ?>
				<?php
				/*
				 * Task 6: without this branch a `date` field falls through
				 * to the plain text input below -- no error, just a text box
				 * an admin has to know to type YYYY-MM-DD into, against a
				 * sanitizer that rejects anything else. `<input type="date">`
				 * is what makes the stored format and the entered format the
				 * same thing.
				 *
				 * Unlike `page_id`/`term_id`, a `date` CAN fail validation
				 * (blueline_sanitize_field() returns a WP_Error for a
				 * malformed value), so this branch carries the same
				 * aria-invalid/aria-describedby wiring the text input does.
				 */
				?>
				<input
					type="date"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					<?php
					if ( $has_error ) :
						?>
						aria-invalid="true" aria-describedby="<?php echo esc_attr( $error_id ); ?>"<?php endif; ?>
				>
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

			<?php
			/*
			 * A field's `help` string renders once, here, for EVERY type --
			 * not inside each branch. It used to live inside the
			 * `bool`/`section` branch alone, which meant a `help` string on
			 * any other type was accepted by the schema, listed in its
			 * docblock, and then silently dropped at render: the panel would
			 * have promised an explanation it never printed. Task 6's
			 * announcement fields are the first `text`/`page_id`/`date`
			 * fields to carry one.
			 */
			?>
			<?php if ( ! empty( $field['help'] ) ) : ?>
				<p class="description"><?php echo esc_html( (string) $field['help'] ); ?></p>
			<?php endif; ?>

			<?php
			/*
			 * Task 5 (P1b-panel-completion): a `section` field mapped to a
			 * populated widget area (today, the four `chrome_footer_widgets_N`
			 * keys -- see blueline_section_widget_warning()'s own docblock
			 * for the real per-area mapping) gets a second, distinct notice
			 * naming the live widget count, so switching it off doesn't read
			 * as "delete my widgets" to whoever's holding the mouse. '' for
			 * every non-`section` field, and for any `section` field with no
			 * widget area behind it or an empty one.
			 */
			$widget_warning = 'section' === $type ? blueline_section_widget_warning( $field_key ) : '';
			?>
			<?php if ( '' !== $widget_warning ) : ?>
				<p class="description"><?php echo esc_html( $widget_warning ); ?></p>
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

		<?php
		/*
		 * This field's `help` string is NOT printed here: since Task 6,
		 * blueline_settings_render_field() prints it once for every field
		 * type, immediately after whichever input branch ran. Printing it
		 * again here would render it twice for this one field.
		 */
		?>
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
