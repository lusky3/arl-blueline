# Blueline operations: theme + blueline-core

Blueline is two pieces that ship together:

| Piece | Lives in | Owns |
|---|---|---|
| **Blueline theme** (`themes/blueline/`) | presentation | templates, CSS/JS, settings (Appearance > Blueline), account page *renderers*, team flyout, homepage modules, WooCommerce/SportsPress template overrides |
| **blueline-core plugin** (`plugins/blueline-core/`) | data, URLs, behaviour | player claim flow + the `sp_user` link, player photo upload, avatars, My Account endpoints/menu, mail wrapper, checkout field fixes, admin-bar rule, SEO/social meta, search ordering, member-privacy hardening |

The theme works without the plugin (it simply lacks those features); the plugin refuses to load against an old
theme (<1.1.0) and shows an admin notice instead of breaking anything. Theme Site Health
(Tools > Site Health) reports whether the plugin is active.

## Deploy order (staging or production)

1. **Install and activate blueline-core first**, from the `blueline-core-<version>.zip` attached to the GitHub release
   (steps and checksum: `docs/RELEASING.md`, "Plugin (blueline-core)"). With the old theme still active it loads nothing
   and shows "Update the Blueline theme" to admins. No behaviour changes yet. (Do it in this order: theme 1.1.0 without the
   plugin has lost the features that moved into it.)
2. **Update the theme to 1.1.0 or newer** (Dashboard > Updates, or `scripts/deploy-theme.sh`). On the next request the
   plugin boots and its modules take over.
3. `wp rewrite flush` (the plugin flushes once itself after activation, but only when the
   `rh-royal-mcp-register-fix` mu-plugin is present; otherwise it defers and shows an admin notice: a flush without that
   mu-plugin once exposed a `/register` 405). Then purge Cloudflare.
4. Check: `/account/registrations`, `/account/my-team` (login page when logged out), `/wp-json/wp/v2/users` returns 404,
   a team page's `<head>` has Open Graph tags.

Staging: `scripts/deploy-plugin.sh staging` then `scripts/deploy-theme.sh staging` (the scripts refuse production; production
is a manual step by the site owner). The plugin script runs a local preflight (`php -l`, version guard, PHPUnit), then after
the rsync checks over `swp` that the plugin is active, reports the header's version and has every module loaded, and fails
loudly otherwise. `scripts/deploy-plugin.sh staging --verify` re-runs only that check (read-only); `--skip-tests` skips the
local PHPUnit run. A plugin held idle by a pre-1.1.0 theme is reported as idle, not as a failure.

The same check by hand (the `swp` pattern: wp-cli in a sidecar container on the staging host):
`ssh <staging host> "swp eval 'foreach ( array_keys( blueline_core_modules() ) as \$m ) { echo \$m, \" \", blueline_core_module_loaded( \$m ) ? \"LOADED\" : \"no\", \"\\n\"; }'"`.

## Template overrides after a plugin update

The theme overrides templates from WooCommerce, WooCommerce Subscriptions, Store Credit, PayPal for WooCommerce,
YITH Advanced Refund System and SportsPress Pro (`themes/blueline/woocommerce/`, `themes/blueline/sportspress/`).
Each override's header names the original it was copied from and that original's `@version`. After updating any
of those plugins on staging, run:

    scripts/check-template-versions.sh

It reads the originals from staging's plugin directory (read-only, over the SSH host in `scripts/lib/hosts.env`;
`BLUELINE_PLUGINS_DIR=<path to wp-content/plugins>` checks a local copy instead) and prints one row per override.
Exit 1 means at least one needs work:

- **DRIFT**: the original's `@version` moved. Copy the new original over the override, re-apply the theme's edits
  (the strings in the `blueline` text domain, plus anything the header describes), and set `@version` and the
  "Copied from" line to the new original.
- **REVIEW**: the original has no `@version` (PayPal, YITH) and the plugin's version changed. Diff the original
  against the version named in the header; if nothing changed, just update the plugin version in the header.
- **MISSING**: the plugin no longer ships that template. Delete the override or find where it moved.
- **NO-SOURCE / NO-VERSION**: an override records no original. Add the "Copied from" line and `@version`.

It is a manual check, not CI: CI cannot see staging's plugins. WooCommerce's own WooCommerce > Status > System
status ("Templates") flags outdated WooCommerce and Subscriptions overrides too, but not SportsPress, PayPal or YITH.

## XML sitemap: which seasons count as current

blueline-core's `indexing` module leaves SportsPress pages from past seasons (events, players, teams, staff, tables,
lists, calendars) out of the XML sitemap. They stay indexable and searchable; Tools > Site Health shows how many are
left out and how many listed.

- **Current** means the **2** seasons with the latest games (`blueline_core_archival_recent_seasons`; `0` turns the
  exclusion off). A season's playoff terms (slug containing `playoff`, parent = the season) count as part of it.
- A season needs at least **10** published or scheduled events (playoffs included) before it can count as current
  (`blueline_core_archival_min_season_events`). Without that floor, a test season with a game or two dated after the
  real ones would count as current and push a real season, with all its pages, out of the sitemap. Every season since
  2020 has at least 10 games in its first week, and the schedule usually goes in weeks ahead (W2026-27 started with
  58); a new season with fewer than 10 on the schedule counts as current once it reaches 10 (until then its pages are
  simply not in the sitemap yet). Draft events never count.
- The result is cached in the `blueline_archival_ids` transient (cleared on every event, player, team or season change;
  six-hour backstop). After a bulk import by WP-CLI or SQL, `wp transient delete blueline_archival_ids`.
- Pages flagged "Hide from search engines" and pages that redirect with Page Links To are also left out; that check is
  one database lookup per sitemap and is not cached, so a change shows at once.

## Rolling back to the classic theme (rookie-child)

The classic theme relied on the YITH "Customize My Account Page" plugin for the account URLs. To roll back:

1. **Deactivate blueline-core** (otherwise its account routing fights YITH's).
2. **Reactivate `yith-woocommerce-customize-myaccount-page`** (restores `/account/registrations`, `/account/store-credit`, ...).
3. Activate the classic theme. Members lose the claim flow, the photo upload and custom avatars until Blueline returns.
4. To return: activate Blueline, **deactivate YITH again** (left active it replaces Blueline's account navigation and
   adds a second avatar uploader), activate blueline-core.

### Theme Switcha (visitor-level "Switch to Classic Site")

Visitors who switch to the classic theme run **without Blueline's `functions.php`** but still run every active plugin,
so **blueline-core keeps working for them**: account URLs, mail wrapper, privacy hardening, SEO tags. League tabs
(`my-team`, `my-schedule`, `player-profile`, `preferences`) only appear where a theme supplies a renderer, so they are
omitted rather than leading to empty pages.

## Hosting-specific code (what breaks if the host changes)

The theme and plugin are meant to run on any WordPress host, but a few places know about this site's current
hosting (WordOps nginx with a Redis-backed page cache, Cloudflare in front, and some site-specific mu-plugins). None
of them can take a site down on a different host; each one degrades as described. Check this list when moving host.

| Where | What it assumes | On a different host |
|---|---|---|
| `themes/blueline/inc/settings/cache.php`, `blueline_srcache_purge_attempt()` (also its Site Health row in `inc/settings/site-health.php`, `wp blueline settings flush-cache` in `inc/cli/settings-command.php` and the purge in `inc/settings/delete-data.php`) | After a Blueline settings save, clear the nginx page cache by SCAN/UNLINK of `nginx-cache:*<host>*` keys through the Redis Object Cache drop-in's `redis_instance()`. Off unless `BLUELINE_SRCACHE_PURGE` is defined true (it is not, today). | With the constant off (the default), nothing runs: after a settings save an admin notice asks for a manual purge, and its suggested `redis-cli` command only fits this host. With it on but no such Redis or key layout, every guard fails safely, nothing is deleted, and the "purge needed" notice stays up. Either way, purge the new host's page cache by hand after changing Blueline settings. |
| `plugins/blueline-core/includes/boot.php`, `blueline_core_rewrite_flush_safe()` | Rewrite rules are flushed automatically (activation, upgrade) only when the `rh-royal-mcp-register-fix.php` mu-plugin exists, because a flush without it once let the Royal MCP plugin take over `/register` (405). | Without that mu-plugin the plugin never flushes and shows an admin notice instead. If Royal MCP is not installed there, allow it with `add_filter( 'blueline_core_rewrite_flush_safe', '__return_true' );` in a mu-plugin, or run `wp rewrite flush` by hand and check `/register` loads. |
| `themes/blueline/inc/sportspress/core.php`, the cache-generation hooks (`blueline_sp_cache_on_post_change()` and friends) | The theme's own SportsPress data caches are invalidated on the same events the `sportspress-cache-purge` mu-plugin uses to purge the nginx-helper page cache. | The theme's caches keep working anywhere (they are plain options and transients). What is lost without that mu-plugin is the page-cache purge itself: standings, schedules and event pages can stay stale until the new host's page cache expires. Install an equivalent purge, or give SportsPress pages a short cache lifetime. |
| `mu-plugins/arl-domain-router.php` (and `plugins/blueline-core/includes/mail/mail.php`, which asks it for the primary host) | The host names in `arl_dr_defaults()`; production's `arl-domain-router-config.php` override. See `docs/DOMAINS.md`. | Unknown hosts are left untouched, so a new host simply serves one face. Update the host lists before pointing domains at it. |
| `robots.txt`, HSTS, Content-Security-Policy | Set outside WordPress: a static `robots.txt` in production's web root, and response headers at Cloudflare/nginx. | Re-create them on the new host (below). |

### Security headers

blueline-core's `headers` module sends `Permissions-Policy: camera=(), microphone=(), geolocation=()` with every
front-end page (change or remove it with the `blueline_core_security_headers` filter). It deliberately does not
send the two headers that bind the whole domain:

- **Strict-Transport-Security.** Set at Cloudflare (SSL/TLS > Edge Certificates > HTTP Strict Transport Security).
  The 2026-10-13 audit found `arlhockey.ca` without it; by 2026-10-14 it sent
  `max-age=31536000; includeSubDomains; preload`, like the `rookiehockey.ca` zone. Every zone that serves pages needs it
  (on a new host or zone: Cloudflare, or `add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;`
  in nginx).
- **Content-Security-Policy.** Start with `Content-Security-Policy-Report-Only: frame-ancestors 'self'; object-src 'none'; base-uri 'self'`
  at Cloudflare or nginx. A `script-src` needs an inventory of the inline scripts the theme and plugins print first.

Version strings: blueline-core removes the WordPress, WooCommerce and SportsPress generator tags (page head and
feeds) and replaces the WordPress version in core asset URLs (`?ver=7.1.3`) with a salted token that still changes on
every core update. Plugin asset versions (`?ver=11.2.1` on WooCommerce files) are left alone. The `x-powered-by: WordOps`
header is added by the server's nginx configuration, not WordPress, so it can only be removed there.

## Production cutover checklist (owner steps; nothing here is automated)

- [ ] Deploy order above; confirm the plugin's modules loaded: `wp eval 'foreach(["player-link","player-photo","avatars","account-endpoints","seo-meta","mail","checkout","admin-bar","access","search","privacy","headers","indexing","plugin-info"] as $m){echo $m," ",blueline_core_module_loaded($m)?"LOADED":"no","\n";}'`
- [ ] **Team page section order** is a database setting, not code. SportsPress > Settings > Teams > Layout: drag "Add to
  Calendar" and "Upcoming Games" where you want them (or `wp option update sportspress_team_template_order '["calendar","schedule","logo","excerpt","content","link","details","staff","lists","tables","events"]' --format=json`). A database refresh can revert it.
- [ ] **Player ownership (SEC-01).** Only a Player-role account that is also the `post_author` of its linked player can
  change the photo or see registration details. See which linked players don't qualify:
  `wp blueline-core ownership report`. Fix individual players: `wp blueline-core ownership apply --ids=<player ids>`
  (dry run; add `--yes` to write). Never bulk-copy `sp_user` into `post_author`: that would promote name-claimed links to owners.
- [ ] **Avatars.** `wp blueline-core migrate-yith-avatars` lists what would move from YITH; `--apply` writes it.
- [ ] **Contact Us email.** On staging the page's email script lost its backslashes in a database import (fixed on staging). Open
  `/arl-league-info/contact-us/` on production and confirm it shows the address, not `u0070u006c...`.
- [ ] **Email content type.** blueline-core forces wp-email-template's "Email content type" to HTML (multipart made Mailgun send raw HTML as text). With the plugin active, `wp eval 'echo get_option("wp_email_template_general")["email_content_type"];'` must print `html`; then send a Contact Us test and confirm it arrives formatted.
- [ ] **robots.txt.** Production's `robots.txt` has no `Sitemap:` line and none of WooCommerce's rules, so it looks like a static file in the web root, which bypasses WordPress. Delete it so WordPress's own one is served, or add `Sitemap: https://<site domain>/wp-sitemap.xml` to it; then purge the Cloudflare cache for `/robots.txt`.
- [ ] **Object cache.** Staging has the Redis plugin but no `object-cache.php` drop-in, so the new caches use
  `wp_options` there. Confirm production has a working drop-in (`wp redis status`).
- [ ] If you configured the optional GitHub update token, put its expiry in a calendar (`docs/RELEASING.md`, step R4). Without a token, updates are checked anonymously and nothing expires.
