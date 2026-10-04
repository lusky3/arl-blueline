# Blueline Core

Companion plugin for the Blueline theme. It holds league functionality that must survive a theme switch:
player linking and photos, avatars, My Account routing, the mail wrapper, checkout fields, the admin bar, SEO
tags, search and privacy hardening. The theme keeps presentation and works (with fewer features) without it.

## Boot

`blueline-core.php` hooks `blueline_core_boot()` on `after_setup_theme` priority 1. If a legacy Blueline theme
(< 1.1.0, which still defines `blueline_get_linked_player_id()`) is active, no module loads and admins see a
notice. Otherwise every entry in `includes/modules.php` is required in order, then `blueline_core_loaded` fires.
A listed module whose file is missing or unreadable is skipped but never silently: it is written to the error log,
shown in an admin notice (`manage_options`) and reported by the Site Health test `blueline_core_modules`.

## How to add a module

1. Your slug is already listed in `includes/modules.php` (`'<slug>' => '<slug>/<slug>.php'`). Order is for readability:
   a module that calls another module's functions declares it in `blueline_core_module_requirements()`
   (`includes/boot.php`) and the loader loads the required module first (today: `mail` requires `seo-meta`).
2. Create `includes/<slug>/<slug>.php` starting with `defined( 'ABSPATH' ) || exit;`. Extra files go in the same
   folder and are `require_once`d from it with `BLUELINE_CORE_DIR . '/includes/<slug>/…'`.
3. Moved code keeps its names, hooks, option/meta keys and nonce actions. Register hooks at file scope as before.
   Strings switch to the `blueline-core` text domain; theme helpers are called behind `function_exists()`.
4. Rewrite endpoints: register them on `init` (priority below 99). Don't flush; the plugin flushes once after
   activation or a version bump (`blueline_core_maybe_flush_rewrite_rules()`, `init` priority 99).
5. Tests go in `tests/<Name>Test.php` and `require_once __DIR__ . '/../includes/<slug>/<slug>.php';`.
6. Stubs only your module needs go in `tests/stubs/<slug>.php`, each wrapped in `if ( ! function_exists() )`.
   Never edit the theme's `tests/bootstrap.php` for plugin-only stubs.
7. A new option the plugin owns must also be added to `uninstall.php`. Never delete user or post meta there.
8. Gate: `composer test && composer lint` here, and the theme gate in `themes/blueline`. Lint uses `phpcs.xml.dist`
   (text domain `blueline-core`, global prefix `blueline`, minimum WordPress 6.9, keep that equal to the plugin header).

`blueline_core_module_loaded( 'slug' )` tells other code whether a module is running in this request.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `blueline_core_loaded` | action | Modules are loaded (never fires beside a legacy theme). |
| `blueline_core_modules` | filter | `array<slug, file>`; remove a slug to switch that module off. |
| `blueline_core_legacy_theme_active` | filter | Override legacy-theme detection. |
| `blueline_core_rewrite_flush_safe` | filter | Allow flushing without `mu-plugins/rh-royal-mcp-register-fix.php` (local sites). |
| `blueline_core_rewrite_rules_flushed` | action | The plugin flushed rewrite rules. |
| `blueline_core_account_endpoint_enabled` | filter | `( bool $enabled, string $slug )`: the theme's section toggle for a league My Account endpoint (`my-team`, `my-schedule`, `player-profile`, `preferences`). |
| `blueline_core_email_brand` | filter | `( array $brand )`: colours and logo for the mail wrapper; the plugin has hard-coded defaults. |
| `blueline_core_seo_plugin_active` | filter | `( bool $active )`: report that another SEO plugin owns the meta tags, so the plugin prints none. The older name `blueline_seo_plugin_active` is still honoured (deprecated; it wins on conflict). |
| `blueline_core_checkout_field_guidance` | filter | `( array $map )`: the per-field placeholder/description copy the checkout module applies. |
| `blueline_core_player_photo_max_pixels` | filter | `( int $pixels )`: upload cap on width x height (default 40,000,000, about 6300 x 6300). |
| `blueline_pre_claim_pool_player_ids` | filter | `( ?int[] $ids, int $user_id )`: replace the query that picks which players a name claim is scored against. Candidates still pass the name gate and every link check. |

The plugin never reads the theme's `blueline_settings` option; the theme answers through these filters.

## Tests

`tests/bootstrap.php` reuses `themes/blueline/tests/bootstrap.php` (WordPress, WooCommerce and SportsPress stubs,
`blueline_test_*()` helpers), then `tests/stubs-extra.php`, then `tests/stubs/*.php`, then this plugin.

PHPUnit runs in random order and fails on warnings and risky tests (`phpunit.xml`); a failure that only shows up in
one order is reproduced with the "Random Seed" it prints.

## Deploy and release

`scripts/deploy-plugin.sh staging` from the repo root: preflight, rsync, activate, then it verifies the plugin booted
(`--verify` runs only that check). What ships is `.distignore` (anchored rsync patterns; there are no runtime Composer
dependencies, so `vendor/` stays out).

The plugin has its own version line: bump the `Version:` header and `BLUELINE_CORE_VERSION` together (the constant change
is what triggers the one-time rewrite flush after an upgrade). A tagged theme release attaches
`blueline-core-<version>.zip`, built by `scripts/release/package-plugin.sh`. Production rollout is manual: see
`docs/RELEASING.md` ("Plugin (blueline-core)") and `docs/OPERATIONS.md`.
