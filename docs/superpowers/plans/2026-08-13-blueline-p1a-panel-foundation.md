# Blueline P1a — Panel Foundation, Content, Links & Commerce

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `Appearance → Blueline` with the controls a volunteer actually needs — copy, links, and the registration category — on a storage layer that every write path must pass through.

**Architecture:** One option (`blueline_settings`) holding a nested array, written through the Settings API. Validation lives in `sanitize_option_blueline_settings`; the cross-tab merge lives in `pre_update_option_blueline_settings`, which receives the old value as a parameter. No React, no REST, no third webpack entry — the theme has zero React and core supplies nonce, capability check and sanitizer for free.

**Tech Stack:** WordPress 6.9 Settings API, PHP 8.3, PHPUnit 12, Node 25 (`node --test`), phpcs/WPCS.

**Spec:** `docs/superpowers/specs/2026-08-13-blueline-control-panel-design.md` §6

## Global Constraints

- Text domain `blueline`. Theme dir `themes/blueline` — **run all npm/composer commands from there**. Shell cwd persists between commands; use absolute paths or re-cd.
- Capability is **`manage_options` throughout** (spec §3). Only administrators use this panel.
- **`phpcs:ignoreFile` is FORBIDDEN.** Line-level `phpcs:ignore <sniff> -- <reason>` is permitted.
- All PHP output escaped at point of echo; `$wpdb` only via `prepare()`.
- **Production (`production-host.example`) is read-only.** Staging deploys only.
- No git remote, no CI. `npm run check` is the only gate. Never push, force-push, or merge.
- Exclude `themes/blueline/node_modules` and `vendor` from every search.
- Baseline that must not regress: **180 PHPUnit tests / 399 assertions**, **42 JS tests**, contrast guard **35 `ok` + 1 informational**, exit 0.

---

## Verified facts this plan rests on

Established by two independent research passes, **not asserted**. P0's retrospective found plan-authored inventories were wrong three times out of three, so these were verified against the codebase and the live site before being written down.

### Links — 20 call sites, 9 destination paths

Not the 17 previously claimed. Verified `home_url()` call sites:

| Path | Sites | Live page |
|---|---|---|
| `/schedule` | 5 — `404.php:27`, `template-tags.php:304`, `homepage-modules.php:410,447,755` | id 55 ✓ |
| `/standings` | 3 — `404.php:32`, `homepage-modules.php:471,924` | id 111 ✓ |
| `/register` | 3 — `404.php:37`, `template-tags.php:296`, `homepage-modules.php:377` | id 11113 ✓ |
| `/faqs` | 2 — `template-tags.php:572`, `homepage-modules.php:1047` | id 13900 ✓ |
| `/` | 2 — `player-link.php:918`, `template-tags.php:489` | site root |
| `/news` | 1 — `homepage-modules.php:1078` | id 1725 ✓ |
| `/legal` | 1 — `template-tags.php:573` | id 3481 ✓ |
| `/arl-league-info/contact-us` | 1 — now via `blueline_contact_url()` | id 6379 ✓ |
| `/arl-league-info/equipment` | 1 — `homepage-modules.php:1026` | id 60 ✓ |

`/contact-us` was a **live 404** and is already fixed in `af381c3`. Every remaining path resolves.

### Commerce — the failure mode is silent

`BLUELINE_REGISTRATION_TERM_ID = 91` (`inc/season-state.php:93`), used at `season-state.php:136`,
`account/player-data.php:495,502`, `homepage-modules.php:195`.

If the term stops resolving: `get_terms()` returns empty → `has_purchasable_product` stays `false` →
`blueline_decide_registration_open()` returns `false` → `blueline_decide_season_state()` falls through
to the SportsPress signals. **The homepage renders a valid-looking wrong hero and stops selling
registration, with no error.** That is why this needs a setting.

**The number 91 could not be confirmed.** The available MCP tooling filters product categories by
slug only; `category: 91` returned nothing while `category: "registration"` returned the live
W2026-27 products correctly. Treat 91 as plausible-but-unconfirmed and make the setting resolve by
term object, falling back to 91.

### Section seams differ in cost by an order of magnitude

Deferred to **P1b**, but recorded here because the plan split depends on it:

- **Free** — utility nav (`has_nav_menu('utility')` already gates it); footer widget columns (`is_active_sidebar()` already gates each).
- **Cheap** — the 4 homepage modules (`blueline_render_module()` is already a name→function registry); standings extra-stats (a missing `checked` attribute).
- **Expensive** — the 6 account cards are **direct unconditional calls** in `woocommerce/myaccount/dashboard.php:29-45` with no identifiers; a toggle requires introducing a registry first. Roster layout is a **bare template override** with no conditional. Event-state display is direct calls at 2-3 sites.
- **Not ours** — the sponsor bar is not rendered by this theme at all. `inc/sportspress.php:127` only points SportsPress Pro's own loader at a mount div; the footer sponsors are the plugin's `get_footer` hook. There is no theme render call to toggle.

### Platform mechanics — six corrections to the spec

1. **`'auto'` is not a valid autoload input.** `register_setting()` has no `autoload` key at all; `update_option()` accepts `true`/`false`/`null`. `'auto'` is an internal DB state. Passing the string risks coercion to `true`. **Pass nothing** and let `wp_max_autoloaded_option_size` (150KB default) decide.
2. **Two hooks, two jobs.** `sanitize_option_{$option}` receives **only the posted subset** — one tab's keys. `pre_update_option_{$option}` receives `( $value, $old_value )` with the full stored value as a parameter. Validation belongs in the first; the cross-tab merge belongs in the second. Core's order is: `sanitize_option()` → `pre_update_option_{$option}` → `update_option_{$option}` (post-write).
3. **`get_permalink()` does not check `post_status`.** A trashed page returns a plausible, non-`false` URL that 404s for visitors. Must check `'publish' === get_post_status( $id )` as well.
4. **The srcache purge rests on an unverified infra assumption** — see Task 6.
5. **The migration lock needs a TTL.** Without `$expire`, a crash mid-migration holds the lock forever with no self-healing path.
6. Every write path claim holds: `add_option()` and `update_option()` both call `sanitize_option()` unconditionally. Only a raw `$wpdb` write bypasses it.

---

## File Structure

| File | Responsibility |
|---|---|
| `inc/settings/defaults.php` | **Create.** The schema: default values, field types, per-field placeholder contracts. Pure data + accessors. |
| `inc/settings/store.php` | **Create.** `blueline_settings()` read accessor, the sanitize filter, the merge filter, migration. No UI. |
| `inc/settings/sanitize.php` | **Create.** Per-type sanitizers incl. the placeholder-contract validator. Pure functions. |
| `inc/settings/page.php` | **Create.** `add_theme_page`, tab routing, field rendering. UI only. |
| `inc/settings/links.php` | **Create.** `blueline_resolve_link()` and the page-ID map. |
| `inc/settings/cache.php` | **Create.** Page-cache purge with its guards and the manual-notice fallback. |
| `inc/cli/settings-command.php` | **Create.** WP-CLI, loaded only under `defined('WP_CLI')`. |
| `inc/settings/health.php` | **Create.** Site Health `debug_information`. |
| `inc/season-state.php` | **Modify.** Read the configured registration term, falling back to 91. |
| `inc/template-tags.php`, `inc/homepage-modules.php`, `404.php` | **Modify.** Route hardcoded paths through `blueline_resolve_link()`. |
| `tests/Settings*Test.php` | **Create.** One per unit above. |

---

## Task 1: The schema and its defaults

**Files:**
- Create: `themes/blueline/inc/settings/defaults.php`
- Create: `themes/blueline/tests/SettingsDefaultsTest.php`
- Modify: `themes/blueline/functions.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `blueline_settings_schema(): array` — the field registry. `blueline_settings_defaults(): array` — the default option value. `BLUELINE_SETTINGS_OPTION` and `BLUELINE_SETTINGS_SCHEMA_VERSION` constants.

The schema is the contract every later task reads. Each field declares `type` (`text`|`email`|`page_id`|`term_id`|`bool`|`textarea`), `tab`, `label`, `default`, and — for text fields that feed `sprintf()` — a `placeholders` array naming the exact conversion specs the value must contain.

- [ ] **Step 1: Write the failing test**

```php
public function test_schema_declares_a_tab_and_type_for_every_field(): void {
    foreach ( blueline_settings_schema() as $key => $field ) {
        $this->assertArrayHasKey( 'type', $field, "$key has no type" );
        $this->assertArrayHasKey( 'tab', $field, "$key has no tab" );
        $this->assertContains(
            $field['type'],
            array( 'text', 'email', 'textarea', 'page_id', 'term_id', 'bool' ),
            "$key has an unknown type"
        );
    }
}

public function test_defaults_cover_every_schema_field(): void {
    $defaults = blueline_settings_defaults();
    foreach ( blueline_settings_schema() as $key => $field ) {
        $this->assertArrayHasKey( $key, $defaults, "$key has no default" );
    }
}

public function test_placeholder_contracts_match_the_declared_default(): void {
    foreach ( blueline_settings_schema() as $key => $field ) {
        if ( empty( $field['placeholders'] ) ) {
            continue;
        }
        $default = blueline_settings_defaults()[ $key ];
        foreach ( $field['placeholders'] as $spec ) {
            $this->assertStringContainsString(
                $spec,
                $default,
                "$key declares placeholder $spec but its own default lacks it"
            );
        }
    }
}
```

That third test is the important one: it proves the declared contract and the shipped default agree, so the contract cannot be wrong from birth.

- [ ] **Step 2: Run it, confirm it fails**

```bash
cd themes/blueline && ./vendor/bin/phpunit --filter SettingsDefaultsTest
```

Expected: FAIL — `blueline_settings_schema()` undefined.

- [ ] **Step 3: Implement the schema**

Create `inc/settings/defaults.php`. Start with these fields — the ranked candidates the inventory produced, restricted to ones with no placeholder or a simple one:

```php
const BLUELINE_SETTINGS_OPTION         = 'blueline_settings';
const BLUELINE_SETTINGS_SCHEMA_VERSION = 1;

function blueline_settings_schema(): array {
	return array(
		// Content tab.
		'contact_email'    => array( 'type' => 'email',    'tab' => 'content', 'label' => 'Contact email' ),
		'footer_heading'   => array( 'type' => 'text',     'tab' => 'content', 'label' => 'Footer column heading' ),
		'footer_location'  => array( 'type' => 'text',     'tab' => 'content', 'label' => 'Footer location line' ),
		'hero_offseason_cta' => array( 'type' => 'text',   'tab' => 'content', 'label' => 'Off-season CTA label' ),
		// Links tab — every value is a page ID; 0 means "use the built-in path".
		'page_schedule'    => array( 'type' => 'page_id',  'tab' => 'links', 'label' => 'Schedule page',    'fallback' => '/schedule' ),
		'page_standings'   => array( 'type' => 'page_id',  'tab' => 'links', 'label' => 'Standings page',   'fallback' => '/standings' ),
		'page_register'    => array( 'type' => 'page_id',  'tab' => 'links', 'label' => 'Register page',    'fallback' => '/register' ),
		'page_faqs'        => array( 'type' => 'page_id',  'tab' => 'links', 'label' => 'FAQs page',        'fallback' => '/faqs' ),
		'page_news'        => array( 'type' => 'page_id',  'tab' => 'links', 'label' => 'News page',        'fallback' => '/news' ),
		'page_legal'       => array( 'type' => 'page_id',  'tab' => 'links', 'label' => 'Legal page',       'fallback' => '/legal' ),
		'page_contact'     => array( 'type' => 'page_id',  'tab' => 'links', 'label' => 'Contact page',     'fallback' => '/arl-league-info/contact-us' ),
		'page_equipment'   => array( 'type' => 'page_id',  'tab' => 'links', 'label' => 'Equipment page',   'fallback' => '/arl-league-info/equipment' ),
		// Commerce tab.
		'registration_term' => array( 'type' => 'term_id', 'tab' => 'commerce', 'label' => 'Registration product category', 'fallback' => 91 ),
	);
}
```

Defaults: every `page_id`/`term_id` defaults to `0` (meaning "use the fallback"), text fields default to the literal currently in the theme.

**Do not add a field whose current literal contains a `sprintf` placeholder** in this task — those need the placeholder validator from Task 3 first, and are added in Task 8.

- [ ] **Step 4: Wire it into `functions.php` and run the tests**

```bash
cd themes/blueline && ./vendor/bin/phpunit --filter SettingsDefaultsTest && npm run check
```

Expected: PASS; all six gates green; **183 tests**.

- [ ] **Step 5: Commit**

```bash
git add themes/blueline/inc/settings/ themes/blueline/tests/SettingsDefaultsTest.php themes/blueline/functions.php
git commit -m "feat(blueline): settings schema and defaults

One declarative registry every later part of the panel reads: field type, tab,
label, default, and -- for text that feeds sprintf() -- the exact conversion
specs the value must contain.

A test asserts each declared placeholder contract appears in that field's own
default, so a contract cannot ship wrong from birth. Fields whose literals
carry placeholders are deliberately NOT added yet; they wait on the validator."
```

---

## Task 2: The store — read, sanitize, merge, migrate

**Files:**
- Create: `themes/blueline/inc/settings/store.php`
- Create: `themes/blueline/tests/SettingsStoreTest.php`

**Interfaces:**
- Consumes: Task 1's schema and constants.
- Produces: `blueline_settings( string $key = '' )` — the single read accessor, returning the whole array or one key. `blueline_settings_merge( $new, $old )` — the `pre_update_option` filter. `blueline_settings_migrate()`.

This is the load-bearing task. Two filters with **different jobs**:

- `sanitize_option_blueline_settings` receives **only what this submission posted** — one tab's keys. Validate there.
- `pre_update_option_blueline_settings` receives `( $value, $old_value )`. **Merge there**, copying forward any top-level key absent from the submission. Without this, saving the Content tab wipes Links.

- [ ] **Step 1: Write the failing tests**

```php
public function test_saving_one_tab_does_not_wipe_another(): void {
    update_option( BLUELINE_SETTINGS_OPTION, array(
        'content' => array( 'contact_email' => 'a@example.com' ),
        'links'   => array( 'page_faqs' => 42 ),
    ) );

    // A Content-tab submission posts only its own subkey.
    update_option( BLUELINE_SETTINGS_OPTION, array(
        'content' => array( 'contact_email' => 'b@example.com' ),
    ) );

    $stored = get_option( BLUELINE_SETTINGS_OPTION );
    $this->assertSame( 'b@example.com', $stored['content']['contact_email'] );
    $this->assertSame( 42, $stored['links']['page_faqs'], 'the Links tab was wiped' );
}

public function test_read_accessor_falls_back_to_defaults_for_unset_keys(): void {
    delete_option( BLUELINE_SETTINGS_OPTION );
    $this->assertSame( blueline_settings_defaults(), blueline_settings() );
}

public function test_migration_refuses_to_downgrade_a_newer_schema(): void {
    update_option( BLUELINE_SETTINGS_OPTION, array(
        '_schema' => BLUELINE_SETTINGS_SCHEMA_VERSION + 5,
    ) );
    blueline_settings_migrate();
    $stored = get_option( BLUELINE_SETTINGS_OPTION );
    $this->assertSame(
        BLUELINE_SETTINGS_SCHEMA_VERSION + 5,
        $stored['_schema'],
        'a rolled-back theme must not overwrite a newer schema'
    );
}
```

- [ ] **Step 2: Run, confirm failure**

```bash
cd themes/blueline && ./vendor/bin/phpunit --filter SettingsStoreTest
```

- [ ] **Step 3: Implement**

```php
add_filter( 'pre_update_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_settings_merge', 10, 2 );
/**
 * Carry forward any top-level key this submission did not post.
 *
 * The Settings API hands update_option() exactly what was in $_POST for this
 * option -- and a per-tab form only contains its own tab's fields. Without
 * this merge, saving Content would delete Links, Commerce and Sections.
 *
 * $old_value is a parameter here, which is why the merge lives on this filter
 * and not on sanitize_option_* (where it would need its own get_option() call
 * and a subtler race).
 */
function blueline_settings_merge( $new_value, $old_value ) {
	if ( ! is_array( $new_value ) ) {
		return $old_value;
	}
	if ( ! is_array( $old_value ) ) {
		return $new_value;
	}
	foreach ( $old_value as $key => $stored ) {
		if ( ! array_key_exists( $key, $new_value ) ) {
			$new_value[ $key ] = $stored;
		}
	}
	return $new_value;
}
```

Migration runs on `init` (not `admin_init` — that never fires for anonymous, cron, REST or CLI traffic), guarded by an integer compare **and** a `wp_cache_add()` lock **with a TTL**:

```php
$got_lock = wp_cache_add( 'blueline_migrating', 1, 'blueline', 60 );
```

The TTL matters: without it a crash mid-migration holds the lock forever with no self-healing path. And the integer compare — not the lock — is the correctness guarantee, because without a persistent object cache `wp_cache_add()` is per-request and locks nothing.

- [ ] **Step 4: Verify, including the write-path claim**

```bash
cd themes/blueline && ./vendor/bin/phpunit --filter SettingsStoreTest && npm run check
```

Then prove the merge survives a direct `update_option()` — not just a form post — since that is the path WP-CLI and import use.

- [ ] **Step 5: Commit**

```bash
git add themes/blueline/inc/settings/store.php themes/blueline/tests/SettingsStoreTest.php
git commit -m "feat(blueline): settings store with cross-tab merge and migration

Two filters doing two different jobs. sanitize_option_* sees only the keys this
submission posted -- one tab's worth -- so it validates. pre_update_option_*
receives the full stored value as a parameter, so it merges: any top-level key
absent from the submission is carried forward. Without that, saving the Content
tab silently deletes Links and Commerce.

Migration runs on init rather than admin_init, which never fires for anonymous,
cron, REST or CLI traffic -- on a volunteer site the gap between a deploy and
the first admin login is unbounded. The wp_cache_add lock carries a TTL so a
crash mid-migration self-heals; the schema integer compare, not the lock, is
the actual correctness guarantee, since without a persistent object cache the
lock degrades to per-request and locks nothing.

Forward-only: a stored schema newer than the code refuses to write."
```

---

## Task 3: Sanitizers, including the placeholder contract

**Files:**
- Create: `themes/blueline/inc/settings/sanitize.php`
- Create: `themes/blueline/tests/SettingsSanitizeTest.php`

**Interfaces:**
- Produces: `blueline_sanitize_field( $value, array $field )`; `blueline_extract_placeholders( string $text ): array`.

**This is the task that prevents a fatal.** Forty `sprintf`/`printf` call sites exist. On PHP 8.3, verified:

```
sprintf("%1$s · Week %2$d", "Fall")  → ArgumentCountError
sprintf("save 50% today", "x")       → ValueError: Unknown format specifier "t"
```

A volunteer typing "save 50% today" into a field that feeds `sprintf()` takes the front end down for anonymous visitors, from a screen that reported success.

- [ ] **Step 1: Write the failing tests**

```php
public function test_extract_placeholders_finds_positional_and_plain_specs(): void {
    $this->assertSame( array( '%s' ), blueline_extract_placeholders( 'Back on the ice %s.' ) );
    $this->assertSame( array( '%1$s', '%2$d' ), blueline_extract_placeholders( '%1$s · Week %2$d' ) );
    $this->assertSame( array(), blueline_extract_placeholders( 'no specs here' ) );
}

public function test_a_literal_percent_is_not_a_placeholder(): void {
    $this->assertSame( array(), blueline_extract_placeholders( 'save 50%% today' ) );
}

public function test_rejects_a_replacement_that_drops_a_required_placeholder(): void {
    $field  = array( 'type' => 'text', 'placeholders' => array( '%s' ) );
    $result = blueline_sanitize_field( 'Back on the ice.', $field );
    $this->assertWPError( $result );
}

public function test_rejects_a_bare_percent_that_would_fatal(): void {
    $field  = array( 'type' => 'text', 'placeholders' => array() );
    $result = blueline_sanitize_field( 'save 50% today', $field );
    $this->assertWPError( $result, 'a bare %% is an unknown format specifier and fatals' );
}

public function test_accepts_a_replacement_preserving_the_contract(): void {
    $field = array( 'type' => 'text', 'placeholders' => array( '%s' ) );
    $this->assertSame( 'Back on the ice %s!', blueline_sanitize_field( 'Back on the ice %s!', $field ) );
}
```

- [ ] **Step 2–4:** implement, verify each test flips, run `npm run check`.

Sanitizer is `sanitize_text_field()`, **not** `wp_kses_post()` — copy is echoed through `esc_html()`/`esc_attr()` at every call site, so permitted markup would render as literal visible tag text, and 29 sites are attribute contexts.

- [ ] **Step 5: Commit.**

---

## Task 4: Link resolution

**Files:**
- Create: `themes/blueline/inc/settings/links.php`
- Create: `themes/blueline/tests/SettingsLinksTest.php`

**Interfaces:**
- Produces: `blueline_resolve_link( string $key ): string`.

**`get_permalink()` does not check `post_status`.** A trashed page returns a plausible, non-`false` URL that 404s for visitors — which is the exact bug class this panel exists to prevent, reintroduced through the panel itself.

```php
function blueline_resolve_link( string $key ): string {
	$schema = blueline_settings_schema();
	$field  = $schema[ $key ] ?? array();
	$id     = (int) blueline_settings( $key );

	if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
		$url = get_permalink( $id );
		if ( false !== $url ) {
			return $url;
		}
	}
	return home_url( $field['fallback'] ?? '/' );
}
```

Tests must cover: unset (0) → fallback; a published page → its permalink; a **trashed** page → fallback, not the plausible-but-404 permalink; a deleted ID → fallback.

- [ ] Steps as above, ending in a commit.

---

## Task 5: Route the hardcoded paths through the resolver

**Files:** `inc/template-tags.php`, `inc/homepage-modules.php`, `404.php`, plus a test.

Replace each `home_url( '/x' )` from the verified table with `blueline_resolve_link( 'page_x' )`. **Leave the two `home_url( '/' )` site-root calls alone** — they are not page links.

Add a guard test in the shape of `ContactUrlTest::test_no_source_file_hardcodes_the_missing_contact_slug`: scan `inc/`, `sportspress/`, `woocommerce/` and the root templates for any `home_url( '/...' )` whose path appears in the schema's `fallback` values, and fail naming the file. That is what stops a future contributor reintroducing a hardcoded path.

- [ ] Steps as above. **Prove the guard bites** by reintroducing one hardcoded path, confirming failure, then restoring and confirming `git diff` is clean.

---

## Task 6: Page-cache purge — default to the honest fallback

**Files:** `inc/settings/cache.php` + test.

The spec requires purging the Redis-backed nginx srcache on save. Research established: the Redis Object Cache drop-in **does** expose `redis_instance()`, and `SCAN`+`UNLINK` over `nginx-cache:*<host>*` is the right shape.

**But whether the object cache's Redis and nginx's srcache share a server and logical database is an infra fact, not a WordPress fact** — and production hardening commonly separates them. If they differ, the purge reports success while purging nothing, which is **worse than the manual notice**, because it lies.

So: implement the guarded purge, but **ship it disabled behind a constant** (`BLUELINE_SRCACHE_PURGE`), defaulting to the manual-notice path. The panel shows a persistent post-save notice naming the exact purge command. Flip the constant only after someone has confirmed the shared-instance assumption against the server's `nginx.conf` and `wp-config.php`.

Record that verification as an explicit checklist item for cutover.

- [ ] Steps as above.

---

## Task 7: Panel shell, tabs, and field rendering

**Files:** `inc/settings/page.php` + test.

`add_theme_page` under Appearance, capability `manage_options`. Tabs as plain links (`?page=blueline&tab=content`), **not** an ARIA tab widget. Each tab renders only its own fields.

Accessibility is not optional here (spec §6.5): colour/text inputs with real `<label for>`; errors carrying `aria-invalid` and `aria-describedby`; save failures moving focus to an error summary; nothing conveyed by colour alone.

**Escaping — carried forward from Task 3, and load-bearing.** Task 3's validator returns `WP_Error`
messages that **interpolate the admin's own input** (the offending placeholder, the corrected string).
Nothing escapes them yet, because no renderer existed. This task is that renderer. Every one of those
messages must be escaped at the point of output — an unescaped settings error rendered in wp-admin is
**stored XSS**, triggerable by anyone who can reach the panel.

`add_settings_error()`'s `$message` is echoed by `settings_errors()` without escaping, so escaping is
the caller's job. Add a test that saves a field whose value contains `<script>` and asserts the
rendered notice contains no executable markup.

**Do not pass an autoload argument** anywhere the option is written. `'auto'` is not a valid input — it is an internal DB state, and the string would likely coerce to `true`. Passing nothing lets `wp_max_autoloaded_option_size` decide.

- [ ] Steps as above, ending with a staging deploy and a real save of each tab, confirming no tab wipes another.

---

## Task 8: The placeholder-bearing copy fields

Only now, with Task 3's validator in place, add the fields whose literals carry `sprintf` specs — `'Burlington's %s league.'`, `'Back on the ice %s.'`, `'Register — %s'`, `'%1$s %2$s this week.'`, and the rest from the inventory. Each declares its exact contract.

- [ ] Steps as above, including a test per field that its declared contract matches the literal it replaces.

---

## Task 9: Commerce — the registration category

Replace `BLUELINE_REGISTRATION_TERM_ID` reads with a resolver reading the configured term, falling back to 91. The setting is a dropdown of `product_cat` terms.

**Note 91 is unconfirmed** — the MCP tooling could not resolve a category by numeric ID. The resolver must therefore verify the configured term actually exists (`get_term()`), and fall back if not, rather than trusting either number.

- [ ] Steps as above, including a test that an unresolvable term falls back rather than silently producing an empty product set.

---

## Task 10: WP-CLI, Site Health, export/import

`wp blueline settings export|import|validate|reset`, loaded only under `defined( 'WP_CLI' ) && WP_CLI`. Import must re-run the **same** sanitizer and reject a payload whose `_schema` exceeds the code's.

Site Health `debug_information` with a `blueline` section: schema version, override counts, which links are configured vs falling back, and whether the srcache purge constant is on.

- [ ] Steps as above.

---

## Self-Review

**Spec coverage:** §6.1 storage → T1/T2. §6.2 validation in the option → T2/T3. §6.3 controls → T1/T5/T8/T9 (Sections and the banner are **P1b**). §6.4 Settings API build → T7. §6.5 panel accessibility → T7. §6.6 cache → T6. §6.7 lifecycle → T2. §6.8 export/import → T10. §6.9 CLI + Site Health → T10.

**Deliberately deferred to P1b**, with reasons: section toggles (the account-card registry refactor is a task in its own right and the sponsor bar turns out not to be ours to toggle), the announcement banner, and the season-state break-glass. None is a dependency of anything here.

**Type consistency:** `blueline_settings()`, `blueline_settings_schema()`, `blueline_settings_defaults()`, `blueline_sanitize_field()`, `blueline_extract_placeholders()`, `blueline_resolve_link()` — each defined once, referenced consistently.

## Risks

1. **Task 6's infra assumption.** Shipping the purge enabled would be worse than not shipping it. Default off.
2. **Task 5 touches every template.** The guard test is what makes it safe to review.
3. **Task 8 is the fatal-risk task.** It must not start before Task 3's validator is green.
4. **91 is unconfirmed.** Task 9 must resolve defensively.
