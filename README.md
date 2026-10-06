# Blueline

A WordPress theme and companion plugin built for the [Adult Rookie League](https://www.rookiehockey.ca),
a recreational hockey league. It runs on [SportsPress](https://wordpress.org/plugins/sportspress/) and
[WooCommerce](https://woocommerce.com/) and carries the league's 2026 brand.

This is a bespoke project built for one league's site, published so others can read, reuse or learn from it.
It is not a general-purpose theme, and you should expect to adapt it.

| Piece | Path | What it does |
|---|---|---|
| **Blueline theme** | [`themes/blueline/`](themes/blueline) | Presentation: templates, CSS and JS, the Appearance > Blueline settings panel, homepage modules, the team flyout, My Account page renderers, and WooCommerce and SportsPress template overrides. |
| **blueline-core plugin** | [`plugins/blueline-core/`](plugins/blueline-core) | Data, URLs and behaviour that must survive a theme switch: the player claim flow and photo upload, avatars, My Account routing, the mail wrapper, checkout fixes, SEO and social tags, search ordering and member-privacy hardening. |

The theme works without the plugin (it simply lacks those features) and the plugin refuses to load against a
theme older than 1.1.0. Details are in [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

## Requirements

- WordPress 6.9 or newer, PHP 8.3 or newer
- WooCommerce and SportsPress (the theme overrides their templates; SportsPress Pro is what the league runs)

## Install

1. Download `blueline-<version>.zip` and `blueline-core-<version>.zip` from the
   [latest release](../../releases/latest). The plugin zip ships with a `.sha256` checksum.
2. Install and activate **blueline-core first**, then the theme. The order matters; see
   [`docs/OPERATIONS.md`](docs/OPERATIONS.md#deploy-order-staging-or-production).
3. Visit **Appearance > Blueline** to configure colours, content and the rest of the panel.

Releases tagged with a suffix such as `-rc.1` are pre-releases.

## Develop

```sh
# Theme
cd themes/blueline
composer install && npm ci
npm run build          # compile assets/src into the committed assets/dist
npm run check          # lint:css, lint:js, test:js, tokens:check, composer test, composer lint

# Plugin
cd plugins/blueline-core
composer install
composer test && composer lint
```

`assets/dist` is committed, so run `npm run build` and commit its output with any CSS or JS change. The
repository's Git hook runs the theme gate before any commit that stages theme files (enable it with
`git config core.hooksPath .githooks`); run the plugin gate yourself.

Browser tests live in `themes/blueline/tests-e2e/` (Playwright against a disposable WordPress sandbox,
run by CI) and `themes/blueline/tests-browser/` (run by hand against a full local clone).

## Docs

- [`docs/OPERATIONS.md`](docs/OPERATIONS.md): how the theme and plugin deploy together, rollback, cutover checklist
- [`docs/RELEASING.md`](docs/RELEASING.md): versioning, the release workflow, theme updates from GitHub releases
- [`docs/DESIGN.md`](docs/DESIGN.md) and [`docs/PRODUCT.md`](docs/PRODUCT.md): the design system and product intent
- [`themes/blueline/CHANGELOG.md`](themes/blueline/CHANGELOG.md): what changed in each release
- [`plugins/blueline-core/README.md`](plugins/blueline-core/README.md): the plugin's module system

## Contributing and security

Issues and pull requests are welcome; read [`CONTRIBUTING.md`](CONTRIBUTING.md) first. To report a
vulnerability, follow [`SECURITY.md`](SECURITY.md) and do not open a public issue.

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
