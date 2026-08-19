# 2026-08-12 — Cloudflare 520 outage: nginx segfaulting on every HTTPS request

**Resolved.** Site restored ~13:26 local. Root cause: the WordOps Redis full-page cache config
(`common/redis-php83.conf`) crashes nginx **1.30.4**, which `wo update` installed that morning.

## Timeline

| Time (local) | Event |
|---|---|
| 12:37:37 | `wo update` upgraded nginx **1.28.0 → 1.30.4** (`nginx-common`, `nginx-custom`, `nginx-wo`) |
| 12:55:04 | nginx restarted onto the new binary — segfaults begin |
| 13:13 | A previous agent edited `conf/nginx/ssl.conf` (disabled HTTP/2, commented `ssl_stapling_verify`, added a debug header). No effect — it was not a TLS problem. |
| ~13:24 | `common/redis-php83.conf` → `common/php83.conf`. Site returns 200. |
| 13:25:09 | Last segfault (lingering pre-reload workers) |
| 13:26+ | Zero crashes in 75 s of live traffic; `/`, `/register`, `/faqs` all 200 |

1,349 worker segfaults were logged in total.

## Root cause

`redis-php83.conf` is the only include on this site that does real request processing, and it
depends on **four third-party nginx modules**: `srcache` (`srcache_fetch`/`srcache_store`),
`redis2` (`redis2_query`), `set-misc` (`set_escape_uri`/`set_unescape_uri`) and `headers-more`.
Those are compiled into WordOps' `nginx-wo` build and are the usual casualties of an nginx
major-version bump. Under 1.30.4 the srcache path dereferences a bad pointer — every crash hit
the same instruction offset inside the nginx binary with `error 4` (user-mode read of an
unmapped page).

Swapping that single include for `common/php83.conf` (same `location` blocks, no srcache) stopped
the crashes outright.

## Why the earlier diagnosis pointed the wrong way

The handoff concluded "NGINX workers segfault on every incoming HTTPS connection … a bug in the
WordOps NGINX/OpenSSL stack when handling ECDSA certificates". That was wrong, and the misleading
evidence is worth recording:

- **The TLS handshake actually succeeds.** `openssl s_client` completes a full TLSv1.3 handshake
  with the ECDSA cert and reads 3,861 bytes; `curl -v` gets the certificate and settles ALPN on
  http/1.1. The crash happens afterwards, when a request is processed. So it was never TLS, and
  disabling HTTP/2, HTTP/3 and OCSP stapling could not have helped.
- **"Local HTTP works, local HTTPS fails" was a false comparison.** This vhost has *no* port-80
  block — the only `listen` it has comes from `conf/nginx/ssl.conf`, which is 443-only. A plain
  HTTP request for the host is answered by the WordOps default server (it returns the WordOps
  dashboard, not the site). There was never a working HTTP path for this site to contrast with.
- **Other HTTPS vhosts on the box were fine**, which rules out a global nginx/OpenSSL fault.
  `adultrecreationalleague.ca` even includes the *same* `ssl.conf`, and serves HTTPS happily —
  because it only does `return 301` and never reaches the srcache config.

Useful discriminator for next time: compare a vhost that does full processing against one that
only redirects, and read the raw `dmesg` fault address — `in nginx[...]` means the main binary,
not a `.so`.

## Changes made (both reversible)

**1. Swapped the page-cache include** — the actual fix.

```bash
# revert (restores the Redis full-page cache — will crash again on nginx 1.30.4)
sudo sed -i 's|include common/php83.conf;.*|include common/redis-php83.conf;|' \
  /etc/nginx/sites-available/rookiehockey.ca
sudo nginx -t && sudo systemctl restart nginx
```

**2. Disabled a stale ModSecurity module** — investigated first, **not** the cause, left off
deliberately. The `.so` at `/usr/share/nginx/modules/ngx_http_modsecurity_module.so` dates from
September 2023 and its strings identify it as built against **nginx/1.24.0**, now loaded into
1.30.4. `modsecurity on;` appears nowhere in the config, so it was providing no protection while
being an ABI-mismatched crash hazard.

```bash
# restore if wanted
sudo ln -s ../modules-available/50-ModSecurity-Dynamic.conf \
  /etc/nginx/modules-enabled/50-ModSecurity-Dynamic.conf
sudo nginx -t && sudo systemctl restart nginx
```

**3. Removed a leaked debug header** the previous agent added to `ssl.conf` —
`more_set_headers "X-protocol : $server_protocol always"` was being sent to every visitor (and
the `always` was inside the quoted value, so it was not even valid flag usage).

## What this costs while it stands

The **nginx full-page cache is off**. WordPress' Redis *object* cache (redis-cache plugin) is
untouched and still active, so the hit is bounded — but every page view now reaches PHP-FPM
instead of being served from Redis by nginx.

This also means the cache-purge procedure documented elsewhere (deleting
`nginx-cache:httpsGETwww.rookiehockey.ca/<path>` keys from Redis) **does not apply** while this
config is in place — there are no srcache keys being written. Page edits appear immediately.

Load was 0.85 shortly after the change, so it is coping — but registration is open and
**~85% of current requests are `/wp-login.php` brute-force traffic** (1,711 of the last 2,000 log
lines; top sources 95.216.13.46, 188.40.26.206, 205.134.255.185, all hosting providers). Without
the page cache there is less headroom than usual, so that traffic matters more than it did.

## Follow-ups for Cody to decide

1. **Restore full-page caching.** Options, best first:
   - Move this site to WordOps' **FastCGI cache** (`wo site update rookiehockey.ca --wpfc`). It
     uses nginx's built-in `fastcgi_cache` with no third-party modules, so it is immune to this
     class of breakage. Caveat: the purge mechanism changes, so Nginx Helper config and the
     documented Redis-key purge procedure both need updating.
   - **Roll nginx back to 1.28.0** from the WordOps PPA to restore the exact prior state. Keeps
     srcache working; gives up 1.30.4's fixes.
   - Wait for a WordOps build that fixes srcache on 1.30.x, then flip the include back.
2. ~~**Brute-force mitigation**~~ — **DONE 2026-08-12**, see "Cloudflare WAF rule" below.
3. **`conf/nginx/ssl.conf` still has uncommitted edits** from the previous agent — HTTP/2 is now
   off and `ssl_stapling_verify` is commented out. Neither was the cause. Origin HTTP/2 barely
   matters because Cloudflare talks HTTP/1.1 to origins, but this should be either reverted or
   committed on purpose rather than left as accidental drift. Note that `listen 443 ssl http2` is
   deprecated in nginx ≥1.25 — use a separate `http2 on;` directive if restoring it.
4. **`wo update` restarts nginx.** Any future WordOps upgrade can re-break this in the same way;
   check `/var/log/nginx/error.log` for `exited on signal 11` immediately after one.

Files here are point-in-time captures: `*.orig` is pre-incident, `*.current` is as-deployed.

---

## Cloudflare WAF rule for the brute force (added 2026-08-12)

There was **no** wp-login rule on the zone — the earlier attempt never landed. Zone
`rookiehockey.ca` = `f60f70a9396ad21dd8715584b1c28d41` (Free plan).

Before: two custom rules (`Allow Zapier with Woocommerce Order Export` → skip,
`Abusive UserAgent` → managed_challenge) and one rate-limiting rule (`admin-ajax.php`, block at
20 req/10 s per IP+colo). Nothing covering wp-login.

**Rate limiting was the wrong tool here:** the attack came from **222 distinct source IPs**, so
per-IP thresholds barely bite, and the single Free-plan rate-limit slot was already spent on
admin-ajax.php.

Added as **position 3** of the zone custom rules
(ruleset `9e14fc98367247caaab0016b026daebb`, phase `http_request_firewall_custom`):

| Field | Value |
|---|---|
| Rule id | `ed4fbd14595b4c4aa04feee49f8d8eef` |
| Action | `managed_challenge` — deliberately **not** block, so a real browser passes and nobody is locked out of wp-admin |
| Expression | `(http.request.uri.path contains "wp-login.php" and not cf.client.bot)` |

`contains`, not `eq`: the first version used `eq "/wp-login.php"` and bots simply moved to
**`/blog/wp-login.php`** (95 hits). `contains` covers `/blog/`, `/wordpress/`, `/wp/` and friends.

### Effect

| | Before | After |
|---|---|---|
| Origin requests per 90 s | (78% of all traffic was wp-login) | **35**, of which **2** wp-login |
| Share of origin traffic | 391 of last 500 requests | ~6% |
| Load average | 1.17 | 0.82 |

Verified challenged: `/wp-login.php`, `/blog/wp-login.php`, `/wordpress/wp-login.php` all return
403 with `cf-mitigated: challenge`. Verified unaffected: `/`, `/register`, `/faqs`, `/account`
(200) and `/checkout` (302), and the Zapier skip rule still bypasses.

### Things to know

- **`/wp-admin` 301s to `/wp-login.php`**, so admin sign-in now shows a Managed Challenge first.
  That is browser-passable (often invisible) — not a lockout.
- Customer accounts are unaffected: the account page is **`/account`**, and WooCommerce login does
  not go through wp-login.php. (`/my-account` returns 404 on this site and always did.)
- **`miniorange-saml-20-single-sign-on`** and **`woocommerce-social-login`** are active and could
  in principle route through wp-login.php. Across 20,000 requests the only query string seen there
  was `redirect_to` (4 times) — no SAML or OAuth callbacks — so it is not a routine path. If SSO
  ever misbehaves, that rule is the first thing to check.

```bash
# disable          → PATCH the rule with {"enabled": false}
# narrow to POSTs  → expression: (http.request.uri.path contains "wp-login.php"
#                                 and http.request.method eq "POST" and not cf.client.bot)
# remove entirely  → DELETE /zones/f60f70a9396ad21dd8715584b1c28d41/rulesets/\
#                     9e14fc98367247caaab0016b026daebb/rules/ed4fbd14595b4c4aa04feee49f8d8eef
```
