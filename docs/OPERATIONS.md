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

## Current season: the board flips it when the new season starts playing

Every season-scoped part of the site reads one setting: **SportsPress > Settings > General > Current Season**
(option `sportspress_season`). Change it on the day the new season's **first game is played**, not when its
registration opens. Registration is a separate thing: the hero, the Register page and the offers follow the
registration products (the next season) on their own, whatever this setting says.

| Surface | Before the flip (old season set) | After the flip (new season set) |
| --- | --- | --- |
| Homepage standings snippet | Old season's division tables (its Playoffs tables while its playoff games are on the schedule) | New season's division tables |
| Team pages: Upcoming Games / Results | Old season's games, playoffs included | New season's games |
| Team rosters: GP/G/A/PIM columns | Old season's figures | New season's figures (0 until games are scored) |
| My Account: My Team record and division, My Season stats | Old season's table and figures | New season's table and figures |
| Find-my-player (claim) pool | Old season's players (plus the newest season once it has players, as before) | New season's players |
| Team and player page descriptions (SEO) | Old season's name | New season's name |
| Hero, Next games, floating next-game widget, countdown | Unaffected: they follow the next game on the schedule | Unaffected |
| Hero registration eyebrow, Register page, offers | Unaffected: they follow the registration products | Unaffected |

How the setting is read (`blueline_core_current_season_id()`, blueline-core `includes/season/season.php`; the theme
carries a byte-identical copy in `inc/current-season-core.php` for when the plugin is missing):

- A **Playoffs** term counts as its season: setting "W2026-27 Playoffs" means W2026-27. Do not switch the setting to
  the Playoffs term for the playoffs; the homepage standings already change to the playoff tables while the season's
  playoff games are the ones on the schedule.
- **Unset, 0, or a deleted term**: the site falls back to the season of the next game going ahead (postponed and
  cancelled games skipped), or, with nothing scheduled, of the most recent game played. That is what every page did
  before the setting was read, so the site keeps working, but the setting is the source of truth.
- Check it: `wp eval 'echo blueline_core_current_season_id(), " ", get_option("sportspress_season"), "\n";'`. The
  theme's own answer is cached for the request only and its SportsPress data caches move on with the setting, so a
  change takes effect on the next page load. Pages already in the page cache can still show the old season: purge it
  (Nginx Helper > Purge Entire Cache) after the flip.

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
| `robots.txt`, HSTS | Set outside WordPress: a static `robots.txt` in production's web root, and the HSTS header at Cloudflare. | Re-create them on the new host (below). |
| `plugins/blueline-core/includes/headers/csp.php`, the Content-Security-Policy origin lists | The third parties this site's plugins load today (OneSignal, Cloudflare Web Analytics and Turnstile, PayPal, Google Pay, Apple Pay); see "Content Security Policy" below. | A host that injects its own scripts (a different CDN's analytics or bot check) shows up as new violations in `wp blueline-core csp report`; add its origin with the `blueline_core_csp_policy` filter. Harmless while the policy is report-only. |

### Security headers

blueline-core's `headers` module sends `Permissions-Policy: camera=(), microphone=(), geolocation=()` with every
front-end page (change or remove it with the `blueline_core_security_headers` filter), and a report-only
Content-Security-Policy (next section). It deliberately does not send the header that binds the whole domain:

- **Strict-Transport-Security.** Set at Cloudflare (SSL/TLS > Edge Certificates > HTTP Strict Transport Security).
  The 2026-10-13 audit found `arlhockey.ca` without it; by 2026-10-14 it sent
  `max-age=31536000; includeSubDomains; preload`, like the `rookiehockey.ca` zone. Every zone that serves pages needs it
  (on a new host or zone: Cloudflare, or `add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;`
  in nginx).

### Content Security Policy

blueline-core sends a `Content-Security-Policy-Report-Only` header with every front-end page (not wp-admin,
admin-ajax, REST, feeds, sitemaps, robots.txt, oEmbed embeds or wc-ajax; `wp-login.php` never runs the hook either).
Report-only means the browser blocks nothing: it only reports what the policy would block. The reports go to
`POST /wp-json/blueline/v1/csp-report` (named in both `report-uri` and `report-to`, with a `Reporting-Endpoints`
header), which keeps only counts. Code: `plugins/blueline-core/includes/headers/csp.php` (policy) and
`csp-report.php` (endpoint, store, Site Health).

**Filters** (in a mu-plugin or a code snippet):

- `blueline_core_csp_mode`: `'report-only'` (default), `'enforce'` or `'off'`. Any other value means report-only.
- `blueline_core_csp_policy`: the directive map (`directive => sources[]`, `report-uri`/`report-to` included) and a
  context `array( 'commerce' => bool )`. Example: `$d['frame-src'][] = 'https://www.youtube-nocookie.com';`.
- The theme's `blueline_keep_payment_scripts` (true loads the payment scripts on every page) also gives every page
  the payment origins.

**The starter policy.** Content pages:

```
default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self';
script-src 'self' 'unsafe-inline' https://cdn.onesignal.com https://api.onesignal.com https://onesignal.com https://static.cloudflareinsights.com https://challenges.cloudflare.com;
style-src 'self' 'unsafe-inline' https://onesignal.com; img-src 'self' data: https:; font-src 'self' data:;
connect-src 'self' https://*.onesignal.com https://onesignal.com https://cloudflareinsights.com https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com;
frame-src 'self' https://challenges.cloudflare.com https://maps.google.com https://www.google.com; worker-src 'self' blob:;
report-uri https://<site>/wp-json/blueline/v1/csp-report; report-to blueline-csp
```

Cart, checkout and every My Account page (the "commerce" pages) add: `form-action https://*.paypal.com`;
`script-src https://*.paypal.com https://*.paypalobjects.com https://pay.google.com https://applepay.cdn-apple.com https://maps.googleapis.com`;
`style-src https://ajax.googleapis.com`; `connect-src https://*.paypal.com https://pay.google.com https://google.com/pay https://www.google.com https://maps.googleapis.com https://places.googleapis.com`;
`frame-src https://*.paypal.com https://pay.google.com`.

Checked on 2026-10-15 by loading each page in headless Chromium with exactly this policy injected: 0 violations on
14 public live pages (home, schedule, standings, team, player, event, venue, sponsors, news post, FAQ, 404, search,
Contact Us, Arena Maps) and on 10 signed-in staging pages (home, My Account, registrations, account details, payment
methods, add payment method, a product, /register, cart, checkout with a product in the cart and the PayPal, Google Pay
and Apple Pay buttons rendered).

**Inventory** (2026-10-15; live `arlhockey.ca` for public pages, staging for signed-in, cart and checkout pages; server
HTML plus every request the rendered page made):

| Origin | Used for (directive) | Pages | Comes from |
|---|---|---|---|
| the site itself | scripts, styles, fonts, images, AJAX (admin-ajax, wc-ajax), REST (Contact Form 7), the OneSignal service worker, Cloudflare's `/cdn-cgi/` scripts (email protection, bot challenge) and its hidden challenge iframe | all | theme, plugins, Cloudflare |
| `https://cdn.onesignal.com`, `https://api.onesignal.com` | script (SDK; a JSONP sync script from `api.`), connect | all | OneSignal plugin |
| `https://onesignal.com` | style (prompt stylesheet), connect | all | OneSignal plugin |
| `https://static.cloudflareinsights.com` | script (`beacon.min.js`) | all | Cloudflare Web Analytics (added at the edge) |
| `https://cloudflareinsights.com` | connect (beacon, when not sent to `/cdn-cgi/rum`) | all | Cloudflare Web Analytics |
| `https://www.google-analytics.com` (`*.google-analytics.com`, `*.analytics.google.com`) | connect (GA4 hits, `G-WJRM9M1RQV`) | all | Flying Analytics plugin (self-hosted `gtag.js`) |
| `https://www.googletagmanager.com` | image (pixel), connect | some | Flying Analytics `gtag.js` |
| `https://challenges.cloudflare.com` | script, frame (Turnstile) | Contact Us, `wp-login.php` | Simple Cloudflare Turnstile plugin |
| `https://maps.google.com`, `https://www.google.com` | frame (Google Maps embeds) | Arena Maps | page content |
| `https://tile.openstreetmap.org` | image (map tiles; Leaflet itself is served by the site) | venue pages; event pages (after "Open map" once the click-to-load map is live) | SportsPress, theme |
| `data:` | image, font (data URIs in stylesheets) | all | WordPress core, theme, plugins |
| `blob:` | worker (emoji feature test) | all | WordPress core emoji loader |
| `https://www.paypal.com` (`*.paypal.com`), `https://www.paypalobjects.com` | script (SDK), connect, frame (buttons, card fields), form-action, image | cart, checkout, account payment methods (live today also /register and product pages, until the theme's payment-script change ships) | PayPal for WooCommerce (Angell EYE) |
| `https://pay.google.com`, `https://google.com/pay` | script, frame, connect | same as PayPal | PayPal for WooCommerce (Google Pay) |
| `https://www.google.com` | connect (Google Pay's payment-method manifest: Chrome fetches `https://google.com/pay`, which redirects here, and after a redirect only the target's origin is matched; the report still names `https://google.com`) | same as PayPal | PayPal for WooCommerce (Google Pay) |
| `https://applepay.cdn-apple.com` | script | same as PayPal | PayPal for WooCommerce (Apple Pay) |
| `https://maps.googleapis.com`, `https://places.googleapis.com`, `https://maps.gstatic.com` | script, connect, image (address autocomplete) | checkout, account details | Checkout Field Editor Pro |
| `https://ajax.googleapis.com` | style (jQuery UI theme for the date picker) | account details | Checkout Field Editor Pro |

Not seen anywhere: Google Fonts, YouTube or Vimeo embeds, Gravatar, sponsor images from other hosts (all are in the
media library), the SportsPress Facebook SDK (only loads with its Facebook widget, which is not used). `img-src https:`
covers any image host; a new embed or script host shows up in the report.

Inline code (no page printed an inline event handler such as `onclick=` or a `javascript:` URL; 2 to 4 `style=`
attributes per page):

| Inline `<script>` / `<style>` | Comes from |
|---|---|
| theme-preference bootstrap (head), `bl-band-photo` style and `bl-band-photo-rotate` script (home) | theme |
| JSON-LD (`application/ld+json`, not executed, so CSP does not apply) | blueline-core `seo-meta` |
| `wp-emoji-settings` + emoji loader module, `speculationrules`, `*-js-extra` (localized data), `*-js-translations`, `wp-i18n-js-after`, `wp-data-js-after`, `jquery-ui-*-js-before/after`, block and global styles (`wp-block-*-inline-css`, `global-styles-inline-css`, `classic-theme-styles-inline-css`, `core-block-supports-inline-css`, `wp-img-auto-sizes-contain-inline-css`, `dashicons-inline-css`) | WordPress core |
| `woocommerce-no-js` body-class script, `woocommerce-js-extra`, `wc-order-attribution-js-extra`, the cart/checkout/address/password `wc-*-js-extra` data, `woocommerce-inline-inline-css`, the gallery `<noscript>` style | WooCommerce |
| three `jQuery(document).ready(...)` snippets that move the league menu, scoreboard and header sponsors (into `.bl-header__sponsors`), `sportspress-js-extra`, "SportsPress Custom CSS", `.sp-footer-sponsors` style | SportsPress Pro |
| `spt-captain-inline-css` | SportsPress Player Tools |
| `OneSignalDeferred.push(...)` init | OneSignal |
| `window.GA_ID=...` and the `gtag('config', ...)` call | Flying Analytics |
| `window.__CF$cv$params=...` challenge loader, challenge iframe script | Cloudflare (added at the edge) |
| `fue-*-js-extra` | Follow-Up Emails |
| `contact-form-7-js-before`, `contact-form-7-js-translations`, `wpcf7cf-scripts-js-extra` | Contact Form 7, Conditional Fields |
| `cfturnstile-*` | Simple Cloudflare Turnstile |
| `angelleye-paypal-checkout-sdk-js-extra` | PayPal for WooCommerce |
| `thwcfe-public-*-js-extra` | Checkout Field Editor Pro |
| `ywcars-frontend-js-extra` | YITH Advanced Refund System |
| `wc-social-login-frontend-inline-css` | WooCommerce Social Login |
| registration rules modal script and style (`#arl-rules-modal`), "Fee Applies" label style | Code Snippets (site snippets) |
| `document.write('p...')` email address on Contact Us | page content |

**Reading the report.** `wp blueline-core csp report` lists each (directive, blocked origin, page path) with its count
and the last day (UTC) it was reported, most frequent first; `--format=csv` or `--format=json` for export, `--reset`
to clear it after printing. Tools > Site Health shows the number of distinct violations and the top five. In the
`blocked` column, an origin (`https://cdn.example.com`) is a third party the policy does not list; `inline` is an
inline script, style or `style=` attribute (only reported once `'unsafe-inline'` is removed); `eval` is `eval()` or
`new Function()`; `data`/`blob` are those URL schemes; `chrome-extension` and the like are the visitor's browser
extensions and can be ignored. Only counts are stored: no IP address, user agent, full URL or query string. The
store keeps 200 entries; when it is full, a new one replaces the least-seen entry (the one seen longest ago among
equally rare ones). Anyone can post to the endpoint, so a report only starts a new entry when its page is one the
site really serves: a path of at most six short slug-like segments that resolves to the front page, a post, page or
account tab, a page that lists other content (news, shop) or a taxonomy term archive (a venue, a news category);
page 2+ of a list counts when page 1 does. Reports from a 404 page or a made-up path are refused (400) and never
counted, so junk cannot push real rows out. A row already in the store is counted again without the lookup. To
accept another kind of page, use the `blueline_core_csp_report_path_resolves` filter (`bool $resolves, string $path`).
Each browser may send 30 report requests per fixed 10-minute window (the window opens with its first request and
closes 10 minutes later however many arrive; counted against a daily-rotating hash of address and user agent, kept
in a transient that lives only for the rest of the window); bodies over 16 KB and content types other than
`application/csp-report`, `application/reports+json` and `application/json` are refused.

A row is worth acting on when it repeats across days or pages. For each origin that the site really needs, add it
with the `blueline_core_csp_policy` filter (or in `csp.php`, with a line in the inventory above); for one it does not
need (a plugin feature nobody uses), turn that feature off. Then `--reset` and watch for a week.

**What must be fixed before enforcing without `'unsafe-inline'`.** Enforcing the starter policy as it is would block
nothing that was seen, but it keeps `'unsafe-inline'` for scripts and styles, which is most of the protection a CSP
gives. To drop it:

1. Scripts printed through WordPress's script API (`wp_add_inline_script`, `wp_localize_script`,
   `wp_print_inline_script_tag`: everything with an `id="*-js-extra|before|after|translations"`, the theme's own
   inline scripts, the emoji loader) can carry a nonce from the `wp_inline_script_attributes` filter.
2. Scripts plugins echo as raw `<script>` cannot: the SportsPress jQuery snippets, OneSignal's init, Flying
   Analytics' `GA_ID`/`gtag` lines, the `woocommerce-no-js` script, the registration rules modal snippet. Each needs a
   hash (they are static per site, except Flying Analytics' if its ID changes), a nonce-aware replacement, or moving
   into an enqueued file.
3. Cloudflare's injected `__CF$cv$params` script (its bot "JavaScript Detections") changes on every request and is
   added after WordPress has sent the page. Check Cloudflare's current guidance for CSP nonces with that feature, or
   turn the feature off, before dropping `'unsafe-inline'`; the first report after the change will show it.
4. Inline styles (WordPress block styles, plugin `*-inline-css`) and `style=` attributes: keep `'unsafe-inline'` in
   `style-src`; style injection is a far smaller risk, and nonces on core block styles are not practical yet.
5. The Contact Us email `document.write` in page content: replace it with a plain `mailto:` link or a block that does
   not need script.

**Moving to enforce** (only after at least two weeks of reports with nothing new):

1. Run `wp blueline-core csp report`. Every remaining row must be an origin you have added, a browser extension, or
   understood and accepted.
2. On staging, add `add_filter( 'blueline_core_csp_mode', fn() => 'enforce' );` in a mu-plugin, purge the page cache,
   and walk through: home, schedule, a team, a player, an event (open the map), a venue, Contact Us (send a test
   message), Arena Maps, sign in, My Account (each tab), add a product to the cart, checkout with PayPal, Google Pay
   and a card in sandbox. Check the browser console for "Refused to" messages.
3. Do the same on production, purge Cloudflare's and nginx's page cache (cached pages keep the old header until
   purged), then watch `wp blueline-core csp report` and the console for a day. Real blocks now show up in the
   report too.

**Rollback**: delete the mu-plugin line (or set the mode filter to `'report-only'`, or `'off'` to send nothing), then
purge the page caches. No other state changes.

Version strings: blueline-core removes the WordPress, WooCommerce and SportsPress generator tags (page head and
feeds) and replaces the WordPress version in core asset URLs (`?ver=7.1.3`) with a salted token that still changes on
every core update. Plugin asset versions (`?ver=11.2.1` on WooCommerce files) are left alone. The `x-powered-by: WordOps`
header is added by the server's nginx configuration, not WordPress, so it can only be removed there.

## Production cutover checklist (owner steps; nothing here is automated)

- [ ] Deploy order above; confirm the plugin's modules loaded: `wp eval 'foreach(["season","player-link","player-photo","avatars","account-endpoints","seo-meta","mail","checkout","admin-bar","access","search","privacy","headers","indexing","plugin-info"] as $m){echo $m," ",blueline_core_module_loaded($m)?"LOADED":"no","\n";}'`
- [ ] **Team page section order** is a database setting, not code. SportsPress > Settings > Teams > Layout: drag "Add to
  Calendar" and "Upcoming Games" where you want them (or `wp option update sportspress_team_template_order '["calendar","schedule","logo","excerpt","content","link","details","staff","lists","tables","events"]' --format=json`). A database refresh can revert it.
- [ ] **Player ownership (SEC-01).** Only a Player-role account that is also the `post_author` of its linked player can
  change the photo or see registration details. See which linked players don't qualify:
  `wp blueline-core ownership report`. Fix individual players: `wp blueline-core ownership apply --ids=<player ids>`
  (dry run; add `--yes` to write). Never bulk-copy `sp_user` into `post_author`: that would promote name-claimed links to owners.
- [ ] **My Account tab wording.** blueline-core 0.1.2+ names the tabs "My Registrations", "My Installments" (only with
  WooCommerce Subscriptions active), "My Address" and "Change My Details", and the theme heads each page with its tab's
  label. Deactivate the Code Snippet "My Account Tab Modifications" (Snippets > All Snippets), then confirm the menu reads
  the same and each tab's page heading matches its tab (e.g. My Registrations opens "My Registrations", not "Registrations").
- [ ] **Avatars.** `wp blueline-core migrate-yith-avatars` lists what would move from YITH; `--apply` writes it.
- [ ] **Contact Us email.** On staging the page's email script lost its backslashes in a database import (fixed on staging). Open
  `/arl-league-info/contact-us/` on production and confirm it shows the address, not `u0070u006c...`.
- [ ] **Email content type.** blueline-core forces wp-email-template's "Email content type" to HTML (multipart made Mailgun send raw HTML as text). With the plugin active, `wp eval 'echo get_option("wp_email_template_general")["email_content_type"];'` must print `html`; then send a Contact Us test and confirm it arrives formatted.
- [ ] **robots.txt.** Production's `robots.txt` has no `Sitemap:` line and none of WooCommerce's rules, so it looks like a static file in the web root, which bypasses WordPress. Delete it so WordPress's own one is served, or add `Sitemap: https://<site domain>/wp-sitemap.xml` to it; then purge the Cloudflare cache for `/robots.txt`.
- [ ] **Object cache.** Staging has the Redis plugin but no `object-cache.php` drop-in, so the new caches use
  `wp_options` there. Confirm production has a working drop-in (`wp redis status`).
- [ ] If you configured the optional GitHub update token, put its expiry in a calendar (`docs/RELEASING.md`, step R4). Without a token, updates are checked anonymously and nothing expires.
