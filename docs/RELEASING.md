# Releasing Blueline

Blueline ships as a GitHub release. Tag a version, CI builds a clean `blueline/`
theme zip, and wp-admin (Dashboard > Updates) offers it with a link to the
changelog. `scripts/deploy-theme.sh` (rsync) stays as the break-glass path.

The same release also carries the **blueline-core plugin** zip
(`blueline-core-<plugin version>.zip`), which has no updater and is installed by
hand: see [Plugin (blueline-core)](#plugin-blueline-core) below.

## How it fits together

- `.github/workflows/release.yml` runs on a `v*` tag: **guard** (tag = `style.css`
  `Version` = `BLUELINE_VERSION` = `package.json` `version`, and `CHANGELOG.md` has a section for it) ->
  **build** (`npm run build`, `npm run check`, zip, `manifest.json`) -> **sign**
  (`manifest.json.sig` when the `RELEASE_SIGNING_KEY` secret is set, then a check with
  the theme's own keys; see [Release signing](#release-signing)) -> **publish**
  (`gh release create`; `--prerelease` when the tag has a `-` suffix).
- `themes/blueline/.distignore` is the one list of files that never ship. The rsync
  deploy and the release zip both read it. Besides tests and build config it drops
  the build inputs already compiled into `assets/dist/` (all of `assets/src/css/`,
  every `assets/src/js/` file except the three `settings-*.js` scripts the settings
  screen loads from source, the `assets/fonts/*.woff2` font sources, the empty
  `assets/dist/*.js` stubs of CSS-only entries) and the node scripts in `tools/`.
  `tools/*.json` and the OFL licence texts ship.
- `scripts/release/package.sh` zips `git archive` of the tag, not the working tree,
  so an untracked file in the checkout cannot reach a release and `assets/dist/` is the
  committed build. Entries are sorted and mtimes/permissions normalised
  (`SOURCE_DATE_EPOCH`, default: the tagged commit's time): the same tag gives the
  same bytes.
- `themes/blueline/inc/updater.php` (+ `updater-rules.php`, `updater-client.php`)
  checks GitHub, verifies the manifest's signature (once a release key is configured),
  verifies the zip's sha256 against the manifest, and hands WordPress the package.
  Updates never touch `blueline_settings`, user/post meta or uploads.
- Channels: `stable` offers final releases only; `beta` also offers `-rc` pre-releases.
  A site running `1.1.0` is never offered `1.1.0-rc.1` (final sorts above rc), so a
  site must run an older version to be offered an rc.
- Tags are `vX.Y.Z`, `vX.Y.Z-rc.N` or `vX.Y.Z-beta.N`, nothing else: PHP ranks a `-p1` or
  `-patch.1` suffix above the final release, so such a tag would reach staging but never
  production. A hotfix is a new patch number. The updater and `scripts/release` both refuse
  other suffixes.
- A release newer than the installed theme whose zip or `manifest.json` is missing or invalid
  is not offered, and Tools > Site Health says so (instead of "up to date"). Fix the release
  files; the check retries hourly.

## Release procedure

1. In a PR, set `Version:` in `themes/blueline/style.css`, `BLUELINE_VERSION`
   in `themes/blueline/functions.php` and `version` in `themes/blueline/package.json`
   (plus the two root `version` fields of `package-lock.json`) to the same value, and add a `## [X.Y.Z]` (or
   `## [X.Y.Z-rc.N]`) section to `themes/blueline/CHANGELOG.md` (rename
   `## [Unreleased]`). The guard accepts the heading with or without the brackets;
   the file uses brackets. Merge.
2. Tag the merge commit: `git tag vX.Y.Z-rc.1 && git push origin vX.Y.Z-rc.1`.
   Wait for the workflow; the release appears as a pre-release.
3. On staging (`beta`): Dashboard > Updates > Check again, install, check the site.
4. Final: another PR sets the version to `X.Y.Z` and renames the changelog section;
   merge, tag `vX.Y.Z`. Production (`stable`) now offers it; click Update.

The guard fails the workflow, publishing nothing, if the tag, `style.css`,
`BLUELINE_VERSION`, `package.json` and `CHANGELOG.md` disagree.

Dry run without publishing (from the repo root, after `npm run build` in `themes/blueline`).
Without a local tag, package the working tree instead:

    php scripts/release/guard.php v1.0.1
    BLUELINE_PACKAGE_FROM_WORKTREE=1 scripts/release/package.sh v1.0.1 /tmp/rel
    unzip -Z1 /tmp/rel/blueline-1.0.1.zip
    php scripts/release/build-manifest.php v1.0.1 /tmp/rel/blueline-1.0.1.zip /tmp/rel

## Release signing

Without signing, the updater checks integrity only: `manifest.json` (with the zip's sha256)
and the zip are assets of the same GitHub release, so anyone who can publish a release in
the repository (a stolen token, a compromised account) can publish a matching pair, and
every site would offer it. With signing, a release is offered only when `manifest.json`
carries a signature by a key whose private half lives in the owner's password manager.

### How it works

- **Algorithm:** ed25519 detached signatures (libsodium). Sites verify with
  `sodium_crypto_sign_verify_detached()`, from PHP's sodium extension or, when that is
  missing, the sodium_compat copy WordPress bundles.
- **Public keys:** `BLUELINE_RELEASE_PUBLIC_KEYS` in `themes/blueline/inc/updater-rules.php`,
  a list (so keys can be rotated) of 32-byte public keys in **standard base64**
  (44 characters ending in `=`). Safe to publish. A malformed entry fails the theme's tests.
- **Private key:** libsodium's 64-byte secret key in standard base64 (88 characters ending
  in `==`). It exists only in Bitwarden or Doppler and as the `RELEASE_SIGNING_KEY` Actions
  secret. Never in the repository, a chat, an email or a ticket.
- **Signature:** the release asset `manifest.json.sig`, the base64 detached signature over the
  exact bytes of `manifest.json` (not its parsed contents: re-serialised JSON does not verify).
- **On a site, while the key list is empty** (the state until the owner adds a key): updates
  work as before (sha256 integrity only), and Tools > Site Health shows the recommendation
  "Release signing is not enabled".
- **On a site, once a key is listed:** every release must carry a `manifest.json.sig` that
  verifies against ANY listed key. The signature is checked before any field of the manifest
  (version, sha256) is read, and the zip's sha256 is still checked on download. A release with
  a missing or invalid signature is **not offered** (an older, correctly signed release still
  can be) and Site Health turns **critical**, naming the version. There is no fallback to
  unsigned. The cached update check is keyed to the key list, so a key change takes effect on
  the next check. A second Site Health test ("Blueline release signing") reports the key
  configuration itself.
- **In the workflow** (`sign` job, which installs nothing and is the only job that sees the
  secret): `scripts/release/sign-manifest.php` signs (key from the environment only, never
  printed), then `scripts/release/verify-manifest.php` checks the pair with the theme's own
  `updater-rules.php` before `publish` runs:

  | `RELEASE_SIGNING_KEY` secret | Keys in the theme | Result |
  |---|---|---|
  | not set | none | published **unsigned**, with a `::warning::` |
  | set | none | signed, with a `::warning::` (sites ignore the signature until a key is listed) |
  | set | listed, and the secret matches one | signed, verified, published |
  | set | listed, but the secret matches none | run fails, nothing published |
  | not set | listed | run fails, nothing published (every updated site would refuse it) |

- **Sites on older theme versions** (before signing support, or with an empty key list) keep the
  integrity-only check until they install a release that lists a key. That first update is
  accepted on its sha256 alone; every update after it must be signed.

### Key ceremony (owner, once, on your own machine)

Status: done 2026-10-15. The first public key is listed in `BLUELINE_RELEASE_PUBLIC_KEYS`, so every
release from the one that carries it must be signed (the `RELEASE_SIGNING_KEY` secret is set).
The steps below stay for rotation and for a new site or repository.

Claude and CI never generate, see or handle the private key.

1. From a checkout of the repository (PHP 8.3 with sodium):

       php scripts/release/generate-signing-key.php ~/blueline-release-key.txt

   The private key goes to that file (created with mode 0600; the script never overwrites an
   existing file). Stdout prints only the public key, in a block marked "put this in
   updater-rules.php". Without a path, the private key is printed once to stderr instead.
2. Save the private key in **Bitwarden** (a secure note "Blueline release signing key") or
   **Doppler** (a secret named `RELEASE_SIGNING_KEY`), with the public key and today's date.
3. Give it to GitHub Actions:

       gh secret set RELEASE_SIGNING_KEY --repo lusky3/arl-blueline < ~/blueline-release-key.txt

   or paste it at Settings > Secrets and variables > Actions > New repository secret.
4. Delete the local copy (`shred -u ~/blueline-release-key.txt`, or `rm`) and clear the
   terminal scrollback.
5. Open a PR adding the public key to `BLUELINE_RELEASE_PUBLIC_KEYS`:

       define( 'BLUELINE_RELEASE_PUBLIC_KEYS', array( '<the 44-character public key>' ) );

   Merge it with the next release's version bump (or before it).
6. Cut a release as usual. The `sign` job shows `manifest.json.sig verifies against a key in
   BLUELINE_RELEASE_PUBLIC_KEYS`, and the release lists `manifest.json.sig`.
7. On staging, then production: install that release (Dashboard > Updates), then check
   Tools > Site Health: "Blueline theme updates are signed and verified", and Check again
   still reports updates normally.

Optional hardening (repository settings, no code change): protect `v*` tags with a tag ruleset
limited to the owner; move the secret into a GitHub Environment (for example `release`) with a
required reviewer and a `v*`-tags-only deployment rule, then add `environment: release` to the
`sign` job in `release.yml`.

### Rotating the key (planned)

Sites only trust keys they already have, so the new key must reach them inside a release
signed by the old one.

1. Generate a new pair (ceremony steps 1-2, 4). Do **not** replace the secret yet.
2. PR: **add** the new public key to the list, keeping the old one. Release it; it is signed by
   the old key (the secret is unchanged), so every site accepts it and now trusts both keys.
3. Replace the secret with the new key (`gh secret set RELEASE_SIGNING_KEY ...`). The next
   release is signed by the new key. A site that skipped step 2's release is offered that
   release first (it falls back to the newest release it can verify), then this one.
4. PR: **remove** the old public key; ship it in a later release. Mark the old key retired in
   the password manager (or delete it).

### If the private key leaks (incident)

1. Delete the secret at once (`gh secret delete RELEASE_SIGNING_KEY --repo lusky3/arl-blueline`),
   review who has write access, revoke tokens, and delete any release you did not publish.
2. Generate a new pair (ceremony steps 1-4).
3. PR: **remove** the leaked public key and add the new one. Release it, signed by the new key.
4. Sites still running a theme that trusts the leaked key **cannot** take that release
   automatically (it is signed by a key they do not know, so they refuse it and Site Health is
   critical), and they would accept a release signed with the leaked key. **Update every site
   manually** to the new release: download its zip, `manifest.json` and `manifest.json.sig`,
   check them with `php scripts/release/verify-manifest.php manifest.json` from a checkout
   that has the new key and with `sha256sum blueline-X.Y.Z.zip` against the manifest's
   `sha256`, then upload it at Appearance > Themes > Add New > Upload Theme (replace current),
   or use `scripts/deploy-theme.sh`.
5. Note the incident and the affected versions in the CHANGELOG.

Never empty the key list to get a release out: that switches every updated site back to
integrity-only checks.

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

## Translations

Both packages are translatable (text domains `blueline` and `blueline-core`) but no
translation ships yet, so neither has a `languages/` folder or a `.pot` template checked
in. The theme loads `languages/` through `load_theme_textdomain()` and the plugin through
`load_plugin_textdomain()` (its `Domain Path` header), and a site can also put files in
`wp-content/languages/themes/` or `wp-content/languages/plugins/`. When a translation is
wanted, generate the template with WP-CLI's i18n package (`wp package install
wp-cli/i18n-command`):

    wp i18n make-pot themes/blueline themes/blueline/languages/blueline.pot --domain=blueline --exclude=node_modules,vendor,tests,tests-e2e,tests-browser,assets/dist
    wp i18n make-pot plugins/blueline-core plugins/blueline-core/languages/blueline-core.pot --domain=blueline-core --exclude=vendor,tests

Translators work from those files; compile with `wp i18n make-mo`. The theme's
WooCommerce and SportsPress template overrides deliberately keep those plugins' own text
domains for the strings they did not change, so the plugins' translations still apply.

## Owner steps (never automated)

Claude and CI never create, request or handle the token. (The release signing key has its
own owner-only ceremony: [Release signing](#release-signing).)

**The token is optional.** The repository is public, so a site checks for updates
anonymously with no configuration (GitHub allows 60 requests an hour per address; a
successful check is cached for 12 hours and a failed one for 1, and Dashboard > Updates >
"Check again" bypasses the cache). R1-R4 apply only when you choose to define one: for
a higher rate limit, or because the repository is a private fork. Before 1.1.4 a site
with no token never checked at all (the updater did nothing without one), so a site
still on 1.1.3 or older needs one manual theme install of 1.1.4 or newer before it
starts seeing updates.

- **R1. Create the token (optional).** GitHub > Settings > Developer settings > Fine-grained
  tokens: this repository only (`lusky3/arl-blueline`), permission
  **Contents: Read-only**, the longest expiry offered (max 1 year).
- **R2. Configure each site** in `wp-config.php` (above the "stop editing" line):

      define( 'BLUELINE_GITHUB_TOKEN', '<the token>' );
      define( 'BLUELINE_UPDATE_CHANNEL', 'beta' );   // staging; production: 'stable'

  The token lives only here: not in the database, not in a settings screen.
- **R3. Confirm the private-repo redirect** once an rc release exists (get an asset id
  with `gh api repos/lusky3/arl-blueline/releases --jq '.[0].assets[].id'`):

      BLUELINE_GITHUB_TOKEN=<token> scripts/verify-github-asset-redirect.sh lusky3/arl-blueline <asset_id>

  Expect `302`, a redirect host listed in `BLUELINE_UPDATER_STORAGE_HOSTS`
  (`inc/updater-rules.php`), then `200`/`206` from storage without credentials. The
  script never prints the token. If the host differs, add it to that constant.
- **R4. Token renewal (only if you set one).** Put the token's expiry in a calendar. When
  it lapses, update checks stop and Site Health (Tools > Site Health > Status) goes
  **critical** ("GitHub refused the update request"). Create a new token and replace the
  constant, or remove it to fall back to anonymous checks.

## Staging end-to-end (owner-run, in order)

1. Merge the updater PR. Deploy main to staging with `scripts/deploy-theme.sh staging`
   (staging runs 1.0.1 plus the updater; with no token it checks anonymously).
2. Set the channel on staging (R2's `BLUELINE_UPDATE_CHANNEL` line; a token is optional).
   Site Health reports the channel and "up to date".
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
   - Signature (once a key is listed): on a throwaway release, replace `manifest.json.sig`
     with one made by another key (`gh release upload vX.Y.Z-rc.N manifest.json.sig --clobber`),
     or delete it. Check again: the release is not offered and Site Health is critical
     ("does not match any release key" / "without a release signature"). Delete the throwaway
     release and tag afterwards.
