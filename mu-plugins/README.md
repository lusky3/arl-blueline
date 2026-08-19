# mu-plugins mirrored from production

Deployed path: `wp-content/mu-plugins/` on `production-host`.

These are **not** authored by this work — they are mirrored here because the documentation in
`../CHANGES-2026-08.md` depends on them and they are otherwise invisible outside the server.
Production has four; only the one that matters to §11 is copied so far:

| File | On prod | Mirrored here |
|---|---|---|
| `rh-royal-mcp-register-fix.php` | 2,606 bytes, added 2026-08-06 | yes |
| `rh-sportspress-perf.php` | 2,032 bytes | no |
| `sportspress-cache-purge.php` | 3,556 bytes | no |
| `000-debug.php` | 323 bytes | no |

## rh-royal-mcp-register-fix.php

This is the only reason `/register` resolves to page 11113. `royal-mcp` adds
`register/?$ => index.php?royal_mcp_oauth=register` at the top of the rewrite stack (position 26
of ~615), which otherwise makes the Register page unreachable by any URL and returns
`HTTP 405 {"error":"invalid_request","error_description":"POST method required."}` from the
OAuth Dynamic Client Registration handler.

It filters `option_rewrite_rules` and drops that rule **only for GET/HEAD**, so page reads fall
through to WordPress while POST still reaches DCR and OPTIONS still gets the CORS preflight.
It deliberately does not filter `rewrite_rules_array`, because that array feeds the
`update_option()` inside a flush — filtering it would let a flush during a GET persist the
removal and permanently break POST `/register`.

Consequence worth knowing: `get_option( 'rewrite_rules' )` reads *through* this filter, and
wp-cli has no `REQUEST_METHOD` so it defaults to GET. Any check of the rewrite rules via
`get_option()` will therefore report the rule as absent. Read the raw row to see the truth —
see §11 of `../CHANGES-2026-08.md`.

Revert path, per the file's own header: delete it. Upstream has been notified and a fix is
expected, after which it should be removed.

**mu-plugins are not part of the staging clone procedure by default** — see
`../staging/README.md`, which is why staging reproduced the 405 and production never did.
