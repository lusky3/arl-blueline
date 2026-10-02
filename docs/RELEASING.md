# Releasing Blueline

Blueline ships as a GitHub release. Tag a version, CI builds a clean `blueline/`
theme zip, and wp-admin (Dashboard > Updates) offers it with a link to the
changelog. `scripts/deploy-theme.sh` (rsync) stays as the break-glass path.

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
