<?php
/**
 * Appearance -> Blueline: the Settings-API admin page.
 *
 * Deliberately plain WordPress Settings API, no React, no REST endpoint, no
 * webpack entry, because the theme has zero React anywhere else, and the
 * Settings API already supplies, for free, exactly what this page needs: a
 * nonce (settings_fields()), a capability check on the actual save request
 * (options.php itself refuses a non-manage_options user before this file's
 * code ever runs), and the sanitize/merge pipeline the rest of inc/settings/
 * provides.
 * (blueline_settings_sanitize_callback(), in inc/settings/sanitize-callback.php
 * which this file requires, wires onto
 * sanitize_option_{$option} UNCONDITIONALLY, at file scope; see "Every
 * write path is validated" below for why that is not the same thing as
 * register_setting()'s own sanitize_callback wiring. inc/settings/store.php's
 * blueline_settings_merge() already lives on pre_update_option_{$option}.)
 *
 * This file keeps the shared constants and loads the page's parts in order,
 * split by concern: inc/settings/page-registration.php (menu entry,
 * register_setting(), notices), page-snapshots.php ("Recent saves" and
 * restore), page-data-tools.php (export and import), page-render.php (tabs
 * and the page callback) and page-fields.php (the field renderers), then
 * admin-assets.php last. "This file" in the notes below means the settings
 * page as a whole, those parts included.
 *
 * ## Every write path is validated, not just wp-admin's
 *
 * `admin_init`, the hook register_setting() runs on, never fires for
 * WP-CLI or for a script calling update_option() directly. If
 * `sanitize_option_{$option}` were wired ONLY via register_setting()'s
 * `sanitize_callback` argument (i.e. only inside an admin_init-hooked
 * function), every write reachable outside wp-admin would bypass
 * blueline_sanitize_field() entirely, including the placeholder contract
 * inc/settings/sanitize.php exists to enforce, silently reintroducing the
 * exact sprintf()-format-string fatal that file was built to prevent the
 * moment a `wp option update`/import script carries a stray "%". This
 * theme's own blueline_settings_merge() (inc/settings/store.php) is
 * already registered unconditionally at file scope for exactly this
 * reason; the line below mirrors that pattern for validation. This is why
 * blueline_settings_register() (still admin_init-hooked, purely for the
 * Settings API's own UI/whitelist wiring) deliberately does NOT pass a
 * `sanitize_callback` in its register_setting() call: the filter below
 * is the only place that argument would ever be wired from, and wiring it
 * twice would be redundant at best.
 *
 * Tabs are plain links (?page=blueline&tab=content), NOT an ARIA tab
 * widget: a real page load per tab is simpler, linkable, back-button
 * correct, and does not need any JavaScript at all. Each tab's <form>
 * posts only its own fields to options.php, exactly the shape
 * blueline_settings_merge() was built to receive.
 *
 * ## Escaping is load-bearing, not a nicety
 *
 * blueline_sanitize_field() (inc/settings/sanitize.php) returns a WP_Error
 * whose message INTERPOLATES the admin's own input: the offending
 * conversion spec extracted from the submitted value, and a corrected
 * string built from it. WordPress' own add_settings_error() documents that
 * whatever renders a settings error echoes `$message` WITHOUT escaping,
 * because escaping is the caller's job. Every single WP_Error message this file
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
 * ## `_posted_fields` is required, not optional, and tab-scoped
 *
 * blueline_settings_merge() treats a field absent from a submission as
 * "belongs to another tab, carry it forward" UNLESS that field's key is
 * named in the submission's reserved `_posted_fields` array, in which case
 * absence means "this tab owns this field and the user cleared it" (a
 * deliberate delete). Every tab rendered here therefore emits one hidden
 * `_posted_fields[]` input per field it owns (blueline_settings_render_page()),
 * regardless of whether that specific field currently has a value to clear.
 * Omitting this array does not merely miss an edge case; it makes
 * clearing ANY of this tab's fields silently revert on the very next save,
 * from ANY tab, forever.
 *
 * `_posted_fields` alone is not enough, though: every tab shares one
 * settings_fields() nonce group, so the nonce does not bind a submission
 * to any particular tab. Without a further check, a request merely SHAPED
 * like the Content tab's form, but naming a Links-tab field (e.g.
 * `page_faqs`) in `_posted_fields` without posting that field's own value,
 * would make the merge read that absence as "owned but omitted: delete",
 * clearing a field the submission never rendered and does not
 * own. Every tab's form therefore also emits a hidden `_tab` input naming
 * the tab actually being submitted, and blueline_settings_sanitize_callback()
 * drops any `_posted_fields` entry whose OWN schema `tab` does not match
 * it, so naming a foreign field only ever fails silently and never deletes
 * it.
 *
 * `_tab` itself is FORWARDED in this callback's return value, not
 * dropped: a programmatic write (WP-CLI, a JSON import,
 * blueline_settings_migrate()'s own update_option() call) never carries
 * `_tab`, and blueline_settings_merge() needs to be able to tell that case
 * apart from a real tab-scoped form post, because `_posted_fields`-driven
 * deletion is a guarantee this file's own rendered form needs, not one a
 * programmatic write asked for or should be bound by. `_tab` is never
 * persisted on an ordinary, steady-state save:
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
 * ## `_schema` is reserved, not "unrecognised", and never from a form
 *
 * blueline_settings_sanitize_callback() must let inc/settings/store.php's
 * `_schema` migration bookkeeping key survive a write (it is deliberately
 * NOT part of the schema; see blueline_settings()'s own docblock), yet
 * blueline_settings_migrate() writes it directly via update_option(),
 * which runs through this same callback on every path. That is NOT
 * the same thing as forwarding every unrecognised key unchanged: doing
 * that would let ANY top-level key in a submission persist, silently
 * weakening the allow-list model, and specifically let
 * `_schema` itself be set from an ordinary POST. An admin (accidentally
 * or otherwise) sending `blueline_settings[_schema]` at or above
 * BLUELINE_SETTINGS_SCHEMA_VERSION would make blueline_settings_migrate()'s
 * forward-only guard treat the install as already current, permanently
 * and silently skipping a real future migration.
 *
 * The fix is an explicit reserved-key allow-list, today
 * `array( '_schema', 'aa_acknowledgements', 'occasions', '_validated_against' )`,
 * rather than "forward anything unrecognised":
 * any key that is neither a real schema field nor on that list is
 * dropped, exactly as it would be if it were never declared at all.
 * `_schema` itself is still sanitized like everything else (absint(),
 * matching every other integer-valued field this callback handles) AND
 * additionally clamped to `BLUELINE_SETTINGS_SCHEMA_VERSION`, matching
 * the limit inc/settings/import.php enforces on an import's own
 * `_schema` (there, by refusing the import outright; here, by clamping,
 * since a direct update_option() call has no "abort" to fall back to),
 * so a `_schema` at or above the running code's version can never survive
 * a write and permanently defeat blueline_settings_migrate()'s
 * forward-only guard. It is additionally dropped outright when the
 * submission carries a `_tab`, meaning it came from this file's own
 * rendered form. No tab's form has (or
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
 * This is deliberate (a unit test cannot see this class of bug at all,
 * because the server-side render is correct): this WordPress
 * install has a third-party plugin active (Capabilities Pro's own
 * admin-notices "declutter" module) whose JS removes, via
 * `$(element).remove()`, every `<div>` on any wp-admin screen whose
 * `class` attribute contains "notice", "error", "warning", "info" or
 * "updated" as a SUBSTRING anywhere, sweeping it into a "Notice Center"
 * panel instead. That selector is scoped to `div[...]` only; the per-field
 * inline error (a `<p>`) was never touched by it, which is exactly why
 * that part of this page always worked while the summary silently
 * vanished. Confirmed directly: the raw HTTP response body of a real
 * failed save DID contain the summary `<div>`, fully formed, every time.
 * This file's own PHP was never the problem, but it was gone from the
 * live DOM by the time anything queried it. Changing the tag to
 * `<section>` (this plugin's selector never matches it) is a complete fix
 * with no loss of styling or of the `role="alert"` semantics that already
 * override whatever implicit role the tag itself would otherwise carry.
 *
 * ## Never an autoload argument
 *
 * Nothing in this file calls update_option()/register_setting() with an
 * autoload argument. `'auto'` is not a valid update_option() input; it is
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
 * Arbitrary but must match between the two; see blueline_settings_register()
 * and the <form> settings_fields() call in blueline_settings_render_page().
 */
const BLUELINE_SETTINGS_OPTION_GROUP = 'blueline_settings_group';

/**
 * Top-level option keys that are neither a real schema field nor the two
 * request-scoped bookkeeping keys (`_posted_fields`, `_tab`) this file's
 * own form emits, but which a write still needs to be able to carry;
 * today four of them: inc/settings/store.php's `_schema` migration
 * version, inc/settings/acknowledgements.php's `aa_acknowledgements` map,
 * inc/occasions.php's `occasions` map (the one reserved key that a
 * tab-scoped submission may also carry, and only from its OWN tab; see
 * blueline_settings_sanitize_callback()'s docblock, rule 4), and
 * inc/settings/validation.php's `_validated_against` deploy-drift
 * bookkeeping hash (it follows
 * `_schema`/`aa_acknowledgements`'s shape, not `occasions`'s, because nothing
 * reads it back through blueline_settings()).
 * blueline_settings_sanitize_callback() checks every unrecognised key
 * against this explicit allow-list rather than forwarding it merely for
 * being unrecognised; see this file's own docblock's `_schema` section
 * for why that distinction is load-bearing.
 */
const BLUELINE_SETTINGS_RESERVED_KEYS = array( '_schema', 'aa_acknowledgements', 'occasions', '_validated_against' );

/**
 * Nonce action shared by the panel's two import steps (preview, then
 * apply). Separate from settings_fields()' save nonce because these are
 * separate, differently-shaped writes, which is the same reasoning
 * blueline_settings_maybe_restore() applies to its own action.
 */
const BLUELINE_SETTINGS_IMPORT_NONCE = 'blueline_settings_import';

require_once __DIR__ . '/sanitize-callback.php';

require_once __DIR__ . '/page-registration.php';
require_once __DIR__ . '/page-snapshots.php';
require_once __DIR__ . '/page-data-tools.php';
require_once __DIR__ . '/page-render.php';
require_once __DIR__ . '/page-fields.php';
require_once __DIR__ . '/admin-assets.php';
