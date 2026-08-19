# Staging: staging-host

`https://staging.rookiehockey.ca` — Cloudflare-proxied to `staging-host.example:8443`.

A Docker Compose stack at **`/opt/staging`** on host `staging-host`:

| Container | Image | Notes |
|---|---|---|
| `staging-wp` | `wordpress:6.9-php8.3-fpm` | site files in volume `staging_wp_data` |
| `staging-db` | `mariadb:11.4` | data in volume `staging_db_data` |
| `staging-nginx` | `nginx:alpine` | publishes `8080:80`, `8443:443`. **No page cache** — unlike production there is no Nginx srcache/Redis layer to purge. |

Host paths: site files at `/var/lib/docker/volumes/staging_wp_data/_data`.
**`staging-host` has ~4 GB free on a 36 GB disk (88% used)** — free space before importing, don't
stack copies.

Two helper scripts are installed on `staging-host` (created by this work):

```bash
swp <wp-cli args>   # wp-cli against the stack (runs wordpress:cli-php8.3, uid 33)
sdb  <mysql args>   # mariadb client against the staging database
```

`swp` sets `WP_CLI_PHP_ARGS` to silence PHP deprecation notices from older plugins and to
raise `memory_limit` — the full production plugin set exceeds the container's default 128M.

## Containment — read this before cloning production

The staging database is a **copy of production**, so it carries FluentSMTP/Mailgun
credentials, the Follow-Up Emails queue (~3,700 unsent rows), OneSignal push credentials,
UpdraftPlus credentials for the production Backblaze B2 bucket, and PayPal credentials.

`00-staging-guard.php` belongs at
`wp-content/mu-plugins/00-staging-guard.php`. It is **file-based on purpose** so it survives a
database import. It:

- short-circuits `pre_wp_mail` returning **`true`** (not `false` — `false` reports failure and
  sends callers down retry/error paths), and logs every blocked message;
- strips recipients at `phpmailer_init` as a second layer;
- denies outbound HTTP to Mailgun, SendGrid, Postmark, OneSignal, PayPal, Backblaze,
  Cloudflare API, Bitly and `rookiehockey.ca` itself;
- shows a warning banner in wp-admin.

Blocked attempts land in `wp-content/staging-guard.log`.

`wp-config.php` also carries (added alongside the guard):

```php
define( 'DISABLE_WP_CRON', true );        // staging had cron firing on every page load
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'staging.rookiehockey.ca,localhost,127.0.0.1' );
define( 'WP_HOME', 'https://staging.rookiehockey.ca' );   // pin: never redirect to production
define( 'WP_SITEURL', 'https://staging.rookiehockey.ca' );
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
define( 'WP_MEMORY_LIMIT', '512M' );
define( 'WP_MAX_MEMORY_LIMIT', '768M' );
```

Verify containment **before** importing production data — send a canary `wp_mail()` and a
`wp_remote_post()` to Mailgun and confirm both are blocked and logged.

## Cloning production

`staging-host` **cannot resolve `production-host`**, so everything relays through a machine that can reach
both.

```bash
# 1. plugins + themes (staging must match production's WooCommerce version --
#    the cart code under test lives in WC core)
ssh production-host 'sudo tar -C /var/www/rookiehockey.ca/htdocs/wp-content -cf - plugins themes | gzip -1' \
  | ssh staging-host 'cat > /tmp/wpcontent.tgz'
ssh staging-host 'V=/var/lib/docker/volumes/staging_wp_data/_data
  rm -rf "$V/wp-content/plugins" "$V/wp-content/themes"       # frees space first
  tar -C "$V/wp-content" -xzf /tmp/wpcontent.tgz
  chown -R 33:33 "$V/wp-content/plugins" "$V/wp-content/themes"
  rm -f /tmp/wpcontent.tgz'

# 2. database. --single-transaction so production's live registrations are not locked.
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
    db export - --single-transaction --quick --skip-lock-tables \
    --default-character-set=utf8mb4 --skip-themes 2>/dev/null | gzip -1' \
  | ssh staging-host 'cat > /tmp/prod.sql.gz'
ssh staging-host 'docker exec -i staging-db mariadb -uroot -p"$ROOT_PW" -e "
    DROP DATABASE IF EXISTS wordpress;
    CREATE DATABASE wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL PRIVILEGES ON wordpress.* TO \"wordpress\"@\"%\"; FLUSH PRIVILEGES;"
  zcat /tmp/prod.sql.gz | docker exec -i staging-db mariadb -uroot -p"$ROOT_PW" \
    --max-allowed-packet=256M wordpress
  rm -f /tmp/prod.sql.gz'
```

**Credentials are not stored in this repo.** `$ROOT_PW` is `MARIADB_ROOT_PASSWORD` and
`STAGING_DB_PASS` (used by `bin/swp` and `bin/sdb`) is `MARIADB_PASSWORD`, both from
`/opt/staging/compose.yml` on staging-host. The copies of `swp`/`sdb` installed on the host have the
value inline; the copies here read it from the environment.

**Delete `/tmp/prod.sql.gz` and the plugin tarball as soon as the import succeeds.** A production
database dump on the staging host is the single most sensitive artifact this procedure creates,
and stray files in `/tmp` trip suspicious-file alerts.

336 MB of SQL / 43 MB gzipped; the transfer takes seconds and the import ~40s.

### After every import

```bash
# never let the clone touch production infrastructure
swp plugin deactivate updraftplus cloudflare cloudflare-cli-1.0.0 \
  onesignal-free-web-push-notifications fluent-smtp nginx-helper redis-cache \
  simple-cloudflare-turnstile miniorange-saml-20-single-sign-on \
  codehaveli-bitly-url-shortener flying-analytics
swp option update fue_staging yes            # third layer on top of the mail block

# rewrite content URLs, or links in content will send you to PRODUCTION by mistake
swp search-replace 'www.rookiehockey.ca' 'staging.rookiehockey.ca' \
  --all-tables-with-prefix --skip-columns=guid --report-changed-only
```

Only `www.rookiehockey.ca` is replaced — **never** the bare domain, which would corrupt
addresses like `play@rookiehockey.ca`.

### mu-plugins are NOT covered by the sync above — and that matters

The tar above copies `plugins` and `themes` only. Production also relies on
`wp-content/mu-plugins`, so staging behaves differently until you copy those too:

```bash
ssh production-host 'sudo tar -C /var/www/rookiehockey.ca/htdocs/wp-content -cf - mu-plugins | gzip -1' \
  | ssh staging-host 'cat > /tmp/mu.tgz'
# extract WITHOUT --delete: 00-staging-guard.php lives here and must survive
ssh staging-host 'V=/var/lib/docker/volumes/staging_wp_data/_data
  tar -C "$V/wp-content" -xzf /tmp/mu.tgz && chown -R 33:33 "$V/wp-content/mu-plugins"
  rm -f /tmp/mu.tgz; ls -1 "$V/wp-content/mu-plugins"'
```

Never replace the whole `mu-plugins` directory — the staging guard lives in it. Re-verify the
guard (canary `wp_mail()` + a blocked `wp_remote_post()`) immediately afterwards.

This gap is what made `/register` return
`HTTP 405 {"error":"invalid_request","error_description":"POST method required."}` on staging
after a `wp rewrite flush`: production carries
`mu-plugins/rh-royal-mcp-register-fix.php`, which strips royal-mcp's `register/?$` rule for
GET/HEAD requests, and staging simply did not have it. Production is **not** at risk from a
permalink flush. See §11 of `../CHANGES-2026-08.md`; the file is mirrored at
`../mu-plugins/rh-royal-mcp-register-fix.php`.

If you ever need the old parity hack (staging without the mu-plugin), it was:

```php
$r = get_option( 'rewrite_rules' ); unset( $r['register/?$'] ); update_option( 'rewrite_rules', $r );
```

## Tests

`tests/` holds the regression tests for the order-116704 defect. They refuse to run against
anything but `staging.rookiehockey.ca`.

| Script | What it does |
|---|---|
| `repro-duplicate-registration.sh` | The real defect: logged-in add → session dropped → guest add → login merge. Expects 1 item after the fix, 2 before. |
| `test-one-registration-guard.sh` | Injects a second registration straight into the WC session (the post-merge state) and checks `/checkout` trims it and shows the notice. Needs `inject-second-reg.php` in the webroot. |
| `checkout-end-to-end.py` | Harvests the checkout form (Checkout Field Editor Pro adds ~16 custom fields), submits via `?wc-ajax=checkout` with the offline `betpg` gateway, and prints the order. |

Note when reading test output: the **Store API also fires `woocommerce_check_cart_items`**
(`StoreApi/Utilities/CartController.php:525`), so merely inspecting the cart via
`/wp-json/wc/store/v1/cart` can itself trigger the guard. Go straight to `/checkout` if you
need to observe the untrimmed state.

Test user: `arlcarttest` (id 2434). Test order from this work: **116712**.


## Outbound exception for the order → Sheet sync

`WP_ACCESSIBLE_HOSTS` in staging's `wp-config.php` includes `script.google.com` and
`script.googleusercontent.com`, and `00-staging-guard.php` has a matching `HTTP ALLOW` branch so
that traffic is logged rather than silent. Both were added for the order → Google Sheet sync
(§13 of `../CHANGES-2026-08.md`).

**It has never actually been used.** The staging containers have **no internet egress at all** —
CSF on staging-host runs `Chain OUTPUT (policy DROP)` with an explicit allowlist and the docker bridge
matches no ACCEPT rule, so even `google.com` times out from inside `staging_default`. The staging-host host
itself has egress; the containers do not.

That is good isolation for a production clone, so it was left alone: the sync was instead proven on
production against a disposable spreadsheet, with the checkout-path hooks held off behind
`arl_sheet_enabled=0` until the transport was verified. If staging ever needs real egress, opening
CSF for the docker bridge would give a full production clone — Mailgun credentials, the Follow-Up
Emails queue, Backblaze keys — general outbound access. Weigh that before doing it.
