# Security policy

## Supported versions

Security fixes go into the latest stable release of the Blueline theme and the blueline-core plugin. Older
releases and pre-releases (`-rc.N`) are not patched; update to the latest release.

## Reporting a vulnerability

**Please do not open a public issue or pull request for a security problem.**

Report it privately through GitHub: on this repository open **Security > Report a vulnerability**
(private vulnerability reporting). Include:

- what is affected (theme, plugin, or both) and the version
- steps to reproduce, and what an attacker gains
- any proof of concept, kept to the minimum needed to demonstrate the issue

This is a volunteer-run project. Expect an acknowledgement within about a week and a fix or a decision
as soon as is practical. Once a fix is released we are happy to credit you in the changelog and the
advisory, unless you prefer not to be named.

## Scope

In scope: code in this repository, including the theme, the blueline-core plugin, and the build, release and
deploy scripts.

Out of scope: WordPress core, WooCommerce, SportsPress and other third-party plugins (report those
upstream), and the league's live site or hosting (do not test against it).

## Areas that matter most here

The plugin handles member data, so reports about these are especially welcome: the player claim and
ownership checks, the player photo upload, the My Account endpoints, member-name exposure (REST users,
sitemaps, author archives, oEmbed) and the theme-update path (checksum verification of release downloads).

## Notes for contributors

Never commit credentials, tokens, host names or IP addresses of real infrastructure. Deploy scripts read
those from `scripts/lib/hosts.env`, which is gitignored; see `scripts/lib/hosts.env.example`.
