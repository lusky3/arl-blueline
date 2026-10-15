# Domains: one WordPress, two faces

`arlhockey.ca` is the primary site and runs Blueline. `rookiehockey.ca` / `.com` keep serving the classic
Rookie theme from the same WordPress install. The old brand names redirect to the primary.

| Host | Face | Behaviour |
|---|---|---|
| `arlhockey.ca` | primary | Blueline; URLs on this host |
| `rookiehockey.ca`, `www.rookiehockey.ca` | legacy | Rookie / Rookie Child; URLs on the host served; `rel=canonical` and `og:url` point at `arlhockey.ca`; sitemaps redirect to `arlhockey.ca`'s and `robots.txt` names only `https://arlhockey.ca/wp-sitemap.xml`; admin, login, account, cart and checkout go to `arlhockey.ca` (`/wc-api` is still served on the old host so payment webhooks keep working until they are repointed; non-GET requests are redirected with 307/308 so a POST survives) |
| `rookiehockey.com`, `www.rookiehockey.com` | legacy alias | 301 to the same path and query on `www.rookiehockey.ca` (account, cart, checkout and sitemaps go straight to `arlhockey.ca`). See "rookiehockey.com" below: today Cloudflare answers these before WordPress does |
| `www.arlhockey.ca`, `arlhockey.com`, `adultrecreationalleague.ca`, `coedhockey.ca`, `beginnerhockey.ca` (+ `www.`) | alias | 301 to the same path on `arlhockey.ca` |
| anything else (staging, WP-CLI, cron) | other | untouched |

The routing is `mu-plugins/arl-domain-router.php`: one file, copied into `wp-content/mu-plugins/`. It does nothing
until a request arrives on a configured host. Settings live at the top of that file (`arl_dr_defaults()`); override
with `define( 'ARL_DOMAIN_ROUTER', array( ... ) )` or the `arl_domain_router_config` filter.

Cross-links that survive URL normalising: `https://arlhockey.ca/classic/<path>` opens `<path>` on the old site, and
`https://www.rookiehockey.ca/new/<path>` opens it on the new one. Use these for "Switch to Classic Site" links.

## `/new` and `/classic`

`/new/` was the new site's preview address on the old host, and links to it are still around. Because the router
points every old-host URL in a page at the host being served, a stored `www.rookiehockey.ca/new/...` link also shows
up as `arlhockey.ca/new/...` on the new site. So `/new` is handled the same way on both `arlhockey.ca` and the legacy
hosts:

| Request | Answer |
|---|---|
| `/new` or `/new/` (any query) | **301** to `https://arlhockey.ca/` (query kept). Always right, so permanent. |
| `/new/<path>` where WordPress has something at `<path>`: a post, page, team, event or player, a listing page (`/news`, `/shop`), or a term archive such as `/venue/red`, each with or without `/page/N` | **301** to `https://arlhockey.ca/<path>` (query kept). Both faces read one database, so a path that exists here exists there. |
| `/new/<path>` where it does not (a mistyped or long-gone address, or a date archive, which is not checked) | **404**, without WordPress's "closest matching post" guess. That guess used to send `arlhockey.ca/new` to a 2016 post. |

The check runs once WordPress has loaded its rewrite rules (`wp_loaded`), using `url_to_postid()`,
`get_page_by_path()` and the public taxonomies' term slugs (checked against staging's data on 2026-10-14: `/standings`,
`/news`, `/news/page/2`, `/shop`, `/team/whalers`, `/venue/red` and `/arl-league-info/rules/` resolve; `/player-evaluation`
and `/venue/nope` do not). A non-GET request is redirected with 308 so the method survives.

`/classic/<path>` on `arlhockey.ca` stays a **302** to `www.rookiehockey.ca/<path>`: it is a visitor's choice to see the
old site, not a page that moved.

## Search engines and the legacy hosts

The legacy hosts still serve full pages (the classic face), so they must not compete with `arlhockey.ca`:

- Every page's `rel=canonical` and `og:url` name the `arlhockey.ca` address. blueline-core's own archive canonical is
  switched off on the legacy face so a page never carries two.
- Sitemap addresses (`/wp-sitemap.xml`, `/wp-sitemap-*.xml`, the `.xsl` stylesheets, `/sitemap.xml`,
  `/sitemap_index.xml`) **301** to the same address on `arlhockey.ca`, which lists only `arlhockey.ca` URLs. Core
  sitemaps are switched off on the legacy face as well.
- WordPress's `robots.txt` on a legacy host keeps every rule but names only `Sitemap: https://arlhockey.ca/wp-sitemap.xml`.
  This only applies when WordPress serves `robots.txt`. Production currently serves a static file from the web
  root (see `docs/OPERATIONS.md`, "robots.txt"); that file is shared by every host on the docroot, so adding
  `Sitemap: https://arlhockey.ca/wp-sitemap.xml` to it is right for all of them.

## rookiehockey.com

`rookiehockey.com/<anything>` currently answers **301 to `https://www.rookiehockey.ca/`, dropping the path** (the query
string is kept). That response carries no `x-powered-by: WordOps` header, so it comes from a Cloudflare redirect rule
on the `rookiehockey.com` zone, not from nginx or WordPress. The router now has the right rule for these hosts (301 to
the same path and query on `www.rookiehockey.ca`), but it only runs if the request reaches WordPress. Either:

- **Fix the Cloudflare rule** (simplest): make it a dynamic redirect to
  `concat("https://www.rookiehockey.ca", http.request.uri.path)`, status 301, "Preserve query string" on; or
- **remove the Cloudflare rule** and let the origin answer, which needs `rookiehockey.com` and `www.rookiehockey.com`
  on the WordPress nginx server block with a certificate that covers them.

Check: `curl -sI https://rookiehockey.com/team/whalers?x=1` must show `location: https://www.rookiehockey.ca/team/whalers?x=1`.

## Deploy order

1. **Certificate and nginx.** Add `arlhockey.ca` to the WordPress server block (same docroot) with a certificate that
   covers it; the existing certificate covers `rookiehockey.ca` only, which is why `arlhockey.ca` returns a Cloudflare
   526 today. Point the alias block's redirect at `https://arlhockey.ca$request_uri`.
2. **Install the router** into `wp-content/mu-plugins/`. Nothing changes yet for `rookiehockey.*`; `arlhockey.ca`
   starts serving Blueline.
3. **Site address.** `wp option update home https://arlhockey.ca` and the same for `siteurl`, so cron, e-mail and
   webhooks (which have no Host header) build primary URLs. The router overrides both per request.
4. **Stored URLs, narrowly** (see below), after a full database backup.
5. **Cloudflare.** Single redirects on the alias zones to `https://arlhockey.ca/${uri}`; `www.arlhockey.ca` to the apex.
   Leave the `rookiehockey.ca` zone alone: it carries the mailboxes, outgoing-mail records, `r2.` / `cdn.` assets and `staging.`.
6. **Integrations** that name the domain: OneSignal (push subscriptions are per origin), social-login redirect URIs,
   PayPal and Stripe webhooks, SAML, the e-Transfer Cloudflare Worker URL, the FreeScout webhook, Turnstile hostnames,
   Google Analytics and Search Console.

E-mail addresses at `@rookiehockey.ca` stay as they are.

## Stored URLs: what to change and what to leave

The router rewrites URLs in every HTML and feed response, so pages are right on both hosts whatever the database
holds. Change the database only where something reads it without a page render (REST JSON, e-mail, exports):

- `wp_posts` `post_content` and `post_excerpt`, `wp_term_taxonomy` `description`
- widget and theme-mod options (`widget_*`, `theme_mods_*`), nav-menu link URLs (`_menu_item_url`), `_links_to`
- `home` and `siteurl`

Do **not** rewrite order meta (`_wc_order_attribution_*`, `_order_wcj_*`), comments, user meta, Gravity Forms
entries, GUIDs, or integration settings (SAML issuer, OneSignal, Jetpack): historical records stay accurate, and the
old host keeps serving the same files. Replace `https://`, `http://` and the JSON-escaped `https:\/\/` forms, using
`--skip-columns=guid` and `--dry-run` first.

## Rehearsal (staging, 2026-10-06)

Staging's web server accepts any Host header, so the real hostnames were exercised without DNS changes:
29 of 29 checks passed (theme per host, URL normalising, a single canonical tag naming `arlhockey.ca`, every
redirect above, REST root per host, admin-ajax left alone, e-mail addresses untouched). The rehearsal caught one
bug (the URL rewriter was undoing the canonical tag), now fixed and covered by a test.

## Production (live since 2026-10-06)

- `arlhockey.ca` (+ `arlhockey.com`) has its own Let's Encrypt certificate (acme.sh, Cloudflare DNS validation,
  auto-renewing) and its own nginx server block on the same WordPress docroot as `rookiehockey.ca`. The
  alias block (`adultrecreationalleague.ca/.com`, `coedhockey.ca`, `beginnerhockey.ca`, each with `www.`)
  returns a 301 to `https://arlhockey.ca$request_uri`.
- `wp-content/mu-plugins/arl-domain-router.php` is the file in this repo; `arl-domain-router-config.php` is the
  production-only override: `legacy_redirect` is `/account`, `/checkout`, `/cart`. `/wp-admin` and `/wp-login.php`
  deliberately stay on the old host because the JumpCloud SAML settings (`wp_saml_auth_settings`: base URL, entity
  ID and ACS URL) are pinned to `www.rookiehockey.ca`; add them back to that list once SSO is moved.
- Blueline-only options set in the database: `theme_mods_blueline` (header menu = "Menu 3.0", logo) and
  `blueline_settings` (non-default keys only). The team-page layout and the home/FAQs templates are applied by the
  router on the primary face only, because those database values are shared with the old theme.
- `blueline-core` is active; YITH "Customize My Account Page" is off. The Turnstile widget lists both domains.

### Gotchas

- `service nginx reload` is graceful: old workers keep serving open keep-alive connections (Cloudflare reuses them)
  for a moment. Wait until `pgrep -f "worker process is shutting down"` is empty before any step that depends on
  the new config, or two redirect rules can briefly point at each other.
- The legacy theme's settings (`theme_mods_rookie*`) and order meta contain the old host on purpose; do not rewrite them.
- Still pointing at the old host and working there: PayPal/Stripe webhooks (`/wc-api`), the e-Transfer Worker and
  FreeScout webhooks (REST), SAML, OneSignal, social-login redirect URIs. Move them one at a time.

## Updating the router

The router is a must-use plugin, so neither the theme updater, `scripts/deploy-theme.sh` nor `scripts/deploy-plugin.sh`
ships it. After a change:

1. Copy `mu-plugins/arl-domain-router.php` from the repo over `wp-content/mu-plugins/arl-domain-router.php` on the
   server (same file name, so the old copy is replaced; leave `arl-domain-router-config.php` alone). Check it parses:
   `wp eval 'echo function_exists( "arl_dr_boot" ) ? "ok" : "missing";'`.
2. Purge the Cloudflare cache (at least `/robots.txt`, `/wp-sitemap*` and the home page on every zone), because
   redirects and `robots.txt` are cached at the edge.
3. Run the checks in "Rehearsal" against the real hosts (or, on staging, with a `Host:` header), plus the
   `/new`, sitemap, `robots.txt` and `rookiehockey.com` checks above.

## Rollback

Delete `arl-domain-router.php` from `mu-plugins/`; restore `home` and `siteurl`; re-run the stored-URL replacement
in reverse if it was applied; revert the Cloudflare redirects.
