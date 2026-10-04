# Releasing Blueline

Blueline ships as a GitHub release. Tag a version, CI builds a clean `blueline/`
theme zip, and wp-admin (Dashboard > Updates) offers it with a link to the
changelog. `scripts/deploy-theme.sh` (rsync) stays as the break-glass path.

The same release also carries the **blueline-core plugin** zip
(`blueline-core-<plugin version>.zip`), which has no updater and is installed by
hand: see [Plugin (blueline-core)](#plugin-blueline-core) below.

## How it fits together

- `.github/workflows/release.yml` runs on a `v*` tag: **guard** (tag = `style.css`
  `Version` = `BLUELINE_VERSION`, and `CHANGELOG.md` has a section for it) ->
  **build** (`npm run build`, `npm run check`, zip, `manifest.json`) -> **publish**
  (`gh release create`; `--prerelease` when the tag has a `-` suffix).
- `themes/blueline/.distignore` is the one list of files that never ship. The rsync
  deploy and the release zip both read it.
- `themes/blueline/inc/updater.php` (+ `updater-rules.php`, `updater-client.php`)
  checks GitHub, verifies the zip's sha256 against the manifest, and hands WordPress
  the package. Updates never touch `blueline_settings`, user/post meta or uploads.
- Channels: `stable` offers final releases only; `beta` also offers `-rc` pre-releases.
  A site running `1.1.0` is never offered `1.1.0-rc.1` (final sorts above rc), so a
  site must run an older version to be offered an rc.

## Release procedure

1. In a PR, set `Version:` in `themes/blueline/style.css` **and** `BLUELINE_VERSION`
   in `themes/blueline/functions.php` to the same value, and add a `## X.Y.Z[-rc.N]`
   section to `themes/blueline/CHANGELOG.md` (rename `## [Unreleased]`). Merge.
2. Tag the merge commit: `git tag vX.Y.Z-rc.1 && git push origin vX.Y.Z-rc.1`.
   Wait for the workflow; the release appears as a pre-release.
3. On staging (`beta`): Dashboard > Updates > Check again, install, check the site.
4. Final: another PR sets the version to `X.Y.Z` and renames the changelog section;
   merge, tag `vX.Y.Z`. Production (`stable`) now offers it; click Update.

The guard fails the workflow, publishing nothing, if the tag, `style.css`,
`BLUELINE_VERSION` and `CHANGELOG.md` disagree.

Dry run without publishing (from the repo root, after `npm run build` in `themes/blueline`):

    php scripts/release/guard.php v1.0.1
    scripts/release/package.sh v1.0.1 /tmp/rel
    php scripts/release/build-manifest.php v1.0.1 /tmp/rel/blueline-1.0.1.zip /tmp/rel

## Plugin (blueline-core)

`plugins/blueline-core/` is the companion plugin (player claim flow, photos, My Account
routing, mail wrapper, SEO tags, privacy hardening). It is built and released by the
same workflow as the theme, but it has **its own version line** and **no updater**
(`Update URI: false`): nothing on a site ever offers a plugin update, so every plugin
change reaches production as a zip someone uploads.

### Versioning

- The plugin's version is the `Version:` header in `plugins/blueline-core/blueline-core.php`
  and the `BLUELINE_CORE_VERSION` constant right below it. They must be equal. The
  constant is not cosmetic: a changed value makes the plugin flush rewrite rules once
  on the next request (`blueline_core_maybe_flush_rewrite_rules()`), which is how a new
  My Account endpoint starts resolving after an upgrade. **Bump it whenever a change
  adds or renames a rewrite endpoint**, and for every release that ships plugin code.
- Plain semver, independent of the theme: `0.y.z` until the first production rollout is
  confirmed, then `1.0.0`. Patch = fix, minor = new behaviour or module, major = something
  a site owner has to react to (a removed hook, option or URL).
- `plugins/blueline-core/readme.txt` is mandatory: it feeds the plugin's "View details"
  pop-up (description, installation, changelog; see the `plugin-info` module). Its
  `Stable tag` must equal the header `Version`, and each release adds a `= x.y.z =`
  changelog entry. The icon and banners live in `plugins/blueline-core/assets/` and must
  ship (the packager fails without them). They are rendered by
  `docs/branding/render-assets.mjs`, which also renders the theme's `screenshot.png`
  (required in the theme zip; it is the Appearance > Themes card image).
- `composer.json` carries no `version` (Composer reads it from the tag). If one is ever
  added, the guard requires it to equal the header, and likewise a `Stable tag` in the
  README.md. `composer.json`'s `require.php` floor must equal the header's `Requires PHP`.
- Compatibility: the plugin loads only against theme **1.1.0 or newer** (an older theme
  still defines the code the plugin took over, so the plugin stays idle and shows an
  admin notice). The theme works without the plugin but lacks those features.

### How a plugin release is cut

The plugin zip rides on every theme release; the tag is the **theme's** (`vX.Y.Z`).

1. In the PR, bump `Version:` and `BLUELINE_CORE_VERSION` together when the plugin
   changed. Leave them alone when it did not; the release then simply re-attaches
   the same plugin version.
2. Merge, tag the theme release as in "Release procedure". `release.yml` then:
   - **guard**: `php scripts/release/plugin-guard.php` (header = constant = composer =
     readme; `Requires PHP` = composer floor). A disagreement stops the release before anything is built.
   - **build**: plugin PHPUnit, then `scripts/release/package-plugin.sh dist`. It
     applies `plugins/blueline-core/.distignore` with rsync (the file `deploy-plugin.sh`
     uses too), runs `composer install --no-dev` only if the plugin ever declares runtime
     dependencies (it has none today, so no `vendor/` ships), syntax-checks every file,
     and zips with sorted entries and fixed mtimes (reproducible: same tree, same bytes).
     It then asserts the zip has `blueline-core.php`, `uninstall.php`, `includes/boot.php`
     and every module `includes/modules.php` lists, has no `tests/`, `vendor/`,
     `composer.*`, `phpunit.xml`, `phpcs.xml.dist` or dotfiles, contains only allow-listed
     file types, and that the header inside the zip is the version in the file name.
   - **publish**: attaches `blueline-core-<version>.zip` and `.zip.sha256` to the same
     release (read-only build, write-scoped publish, exactly as for the theme).
3. A plugin-only fix still needs a theme tag to publish (the pipeline is one tag). Cut a
   theme patch release, or build the zip yourself with the dry run below and install that.

`check.yml` exercises the same machinery on every PR (job `plugin-release`): the guard,
shellcheck, and `scripts/release/test-plugin-release.sh`, which builds the zip, compares
its file list with the runtime files in the tree, builds twice and compares the bytes,
and proves the guard and packager refuse a drifted version, a syntax error, a missing
module and an unexpected file. The job also runs `composer validate --strict` and
`composer audit --locked` for the plugin.

Dry run without publishing (repo root; needs `php`, `rsync`, `zip`):

    php scripts/release/plugin-guard.php
    scripts/release/package-plugin.sh /tmp/rel-plugin
    unzip -Z1 /tmp/rel-plugin/blueline-core-*.zip
    scripts/release/test-plugin-release.sh

### First production rollout (owner steps)

Order matters. Theme 1.1.0 moved features into the plugin, so a site running theme
1.1.0 **without** the plugin has lost them; a site running the plugin beside an older
theme is untouched (the plugin stays idle). Therefore the plugin goes in first and the
theme second. The plugin only starts working once a theme >= 1.1.0 is active.

1. Download `blueline-core-<version>.zip` and its `.sha256` from the GitHub release.
   Verify: `sha256sum -c blueline-core-<version>.zip.sha256`.
2. Confirm the `rh-royal-mcp-register-fix.php` must-use plugin is present on production
   (the plugin refuses to flush rewrite rules without it, to avoid the `/register` 405).
3. wp-admin > Plugins > Add New > Upload Plugin, choose the zip, install, **activate**.
   With the old theme still active it loads nothing and shows "Update the Blueline theme"
   to admins; no behaviour changes yet. (Or unzip into `wp-content/plugins/`.)
4. Update the theme to 1.1.0 or newer (Dashboard > Updates). On the next request the plugin
   boots and its modules take over; it flushes rewrite rules once by itself.
5. Check the list in `docs/OPERATIONS.md` ("Production cutover checklist"), in particular
   that all ten modules report `LOADED`.

Later plugin releases: upload the new zip over the old one (Plugins > Add New > Upload,
"Replace current with uploaded"), or replace the folder. Do not delete the plugin first:
uninstalling removes its options. Staging takes a plugin build with
`scripts/deploy-plugin.sh staging` (local PHPUnit preflight, rsync, activate, then it
verifies the plugin is active, at the header's version, with every module loaded;
`--verify` re-runs only that check, `--skip-tests` skips the local test run).

## Owner steps (never automated)

Claude and CI never create, request or handle the token.

- **R1. Create the token.** GitHub > Settings > Developer settings > Fine-grained
  tokens: this repository only (`lusky3/rookiehockey-blueline`), permission
  **Contents: Read-only**, the longest expiry offered (max 1 year).
- **R2. Configure each site** in `wp-config.php` (above the "stop editing" line):

      define( 'BLUELINE_GITHUB_TOKEN', '<the token>' );
      define( 'BLUELINE_UPDATE_CHANNEL', 'beta' );   // staging; production: 'stable'

  The token lives only here: not in the database, not in a settings screen.
- **R3. Confirm the private-repo redirect** once an rc release exists (get an asset id
  with `gh api repos/lusky3/rookiehockey-blueline/releases --jq '.[0].assets[].id'`):

      BLUELINE_GITHUB_TOKEN=<token> scripts/verify-github-asset-redirect.sh lusky3/rookiehockey-blueline <asset_id>

  Expect `302`, a redirect host listed in `BLUELINE_UPDATER_STORAGE_HOSTS`
  (`inc/updater-rules.php`), then `200`/`206` from storage without credentials. The
  script never prints the token. If the host differs, add it to that constant.
- **R4. Token renewal.** Put the token's expiry in a calendar. When it lapses, update
  checks stop and Site Health (Tools > Site Health > Status) goes **critical**
  ("GitHub refused the update token"). Create a new token and replace the constant.

## Staging end-to-end (owner-run, in order)

1. Merge the updater PR. Deploy main to staging with `scripts/deploy-theme.sh staging`
   (staging runs 1.0.1 plus the updater, no token yet: Site Health shows a
   recommendation to add the token).
2. Do R1-R2 on staging. Site Health now reports the channel and "up to date".
3. Release-prep PR: version `1.1.0-rc.1` in `style.css` and `functions.php`,
   `## 1.1.0-rc.1` in `CHANGELOG.md`. Merge, then
   `git tag v1.1.0-rc.1 && git push origin v1.1.0-rc.1`. Confirm the workflow passes
   and the release is a pre-release with `blueline-1.1.0-rc.1.zip` and `manifest.json`.
   Do R3 now.
4. Staging: Dashboard > Updates > Check again. Blueline 1.1.0-rc.1 is offered, linking
   to the release notes. Update. The version matches and theme settings
   (Appearance > Blueline), pages and uploads are unchanged.
5. Negative checks:
   - Set staging's channel to `stable`, Check again: no offer (stands in for
     production). Set it back to `beta`.
   - Tamper: publish a throwaway `v1.1.0-rc.2`, then edit its `manifest.json` sha256
     (`gh release upload v1.1.0-rc.2 manifest.json --clobber`). Update on staging is
     refused with the integrity error and the installed theme is untouched. Delete the
     throwaway release and tag afterwards.
   - Remove the token: no offer, Site Health "recommended", nothing in the PHP error
     log. Use a wrong token: Site Health "critical".
