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

1. **Install and activate blueline-core first.** With the old theme still active it loads nothing and shows
   "Update the Blueline theme" to admins. No behaviour changes yet.
2. **Update the theme to 1.1.0 or newer** (Dashboard > Updates, or `scripts/deploy-theme.sh`). On the next request the
   plugin boots and its modules take over.
3. `wp rewrite flush` (the plugin flushes once itself after activation, but only when the
   `rh-royal-mcp-register-fix` mu-plugin is present; otherwise it defers and shows an admin notice: a flush without that
   mu-plugin once exposed a `/register` 405). Then purge Cloudflare.
4. Check: `/account/registrations`, `/account/my-team` (login page when logged out), `/wp-json/wp/v2/users` returns 404,
   a team page's `<head>` has Open Graph tags.

Staging: `scripts/deploy-plugin.sh staging` then `scripts/deploy-theme.sh staging` (the scripts refuse production; production
is a manual step by the site owner).

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

## Production cutover checklist (owner steps; nothing here is automated)

- [ ] Deploy order above; confirm the plugin's modules loaded: `wp eval 'foreach(["player-link","player-photo","avatars","account-endpoints","mail","checkout","admin-bar","seo-meta","search","privacy"] as $m){echo $m," ",blueline_core_module_loaded($m)?"LOADED":"no","\n";}'`
- [ ] **Team page section order** is a database setting, not code. SportsPress > Settings > Teams > Layout: drag "Add to
  Calendar" and "Upcoming Games" where you want them (or `wp option update sportspress_team_template_order '["calendar","schedule","logo","excerpt","content","link","details","staff","lists","tables","events"]' --format=json`). A database refresh can revert it.
- [ ] **Player ownership (SEC-01).** Only a Player-role account that is also the `post_author` of its linked player can
  change the photo or see registration details. See which linked players don't qualify:
  `wp blueline-core ownership report`. Fix individual players: `wp blueline-core ownership apply --ids=<player ids>`
  (dry run; add `--yes` to write). Never bulk-copy `sp_user` into `post_author`: that would promote name-claimed links to owners.
- [ ] **Avatars.** `wp blueline-core migrate-yith-avatars` lists what would move from YITH; `--apply` writes it.
- [ ] **Contact Us email.** On staging the page's email script lost its backslashes in a database import (fixed on staging). Open
  `/arl-league-info/contact-us/` on production and confirm it shows the address, not `u0070u006c...`.
- [ ] **Object cache.** Staging has the Redis plugin but no `object-cache.php` drop-in, so the new caches use
  `wp_options` there. Confirm production has a working drop-in (`wp redis status`).
- [ ] Put the GitHub update token's expiry in a calendar (`docs/RELEASING.md`, step R4).
