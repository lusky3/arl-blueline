# Changelog

All notable changes to the Blueline theme. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/). Each release heading is
`## X.Y.Z` (or `## X.Y.Z-rc.N`); the release workflow publishes that section
as the release notes.

## [Unreleased]

## [1.1.2]

### Fixed
- The footer's "Switch to Classic Site" link now opens the same page on the classic domain through the domain
  router's `/classic/<path>` route. It used the Theme Switcha cookie link, which forced the classic theme on
  the new domain itself once the site ran on two domains.

## [1.1.1]

### Changed
- The repository is now `lusky3/arl-blueline` (was `rookiehockey-blueline`). The theme
  updater, `Update URI` and the plugin homepage use the new name.

### Fixed
- Install 1.1.1 rather than 1.1.0: GitHub redirects a renamed repository's API to a
  numeric `repositories/<id>` path, which the updater's pinned-path check rejects, so a
  site running 1.1.0 cannot discover later updates and must be updated by hand.

## [1.1.0]

First stable release of the 1.1 line: everything in 1.1.0-rc.1 and 1.1.0-rc.2 below, plus the
following. The repository is now public under GPL-2.0-or-later.

### Added
- A wp-admin notice (Dashboard, Plugins, Themes) tells administrators when the Blueline
  Core plugin is not installed or not active, with an Activate or Upload button, instead of
  leaving the missing features to Site Health alone.
- Repository: README, SECURITY, CONTRIBUTING, CODE_OF_CONDUCT, issue and PR templates, and a
  GPL-2.0 `LICENSE`. End-to-end CI runs on a GitHub-hosted runner.

## [1.1.0-rc.2]

### Added
- **blueline-core companion plugin** (`plugins/blueline-core/`): player claim flow and
  the `sp_user` link, player photo upload, avatars, My Account endpoints and menu,
  plain-text mail wrapper, checkout field fixes, admin-bar rule, SEO/social meta,
  search ordering and member-privacy hardening now live in the plugin, so a theme
  switch no longer removes them. The theme works without it (Site Health reports its
  state) and the plugin refuses to load against a theme older than 1.1.0.
- WP-CLI: `wp blueline-core ownership report|apply`, `wp blueline-core migrate-yith-avatars`.
- `docs/OPERATIONS.md`: deploy order, rollback to the classic theme, cutover checklist.
- WooCommerce `checkout/terms.php` override: the terms checkbox is `required` and `aria-required`.
- Team-logo links (`.team-logo a`) get an accessible name, or are hidden when empty.
- Default team logo: a team with no logo (or whose logo image file is missing) shows a
  brand badge everywhere a team logo is drawn, instead of an empty box. The SEO tags
  never use it (social networks can't display an SVG).
- Event pages: both teams (name and crest) link to their team page, and a postponed or
  cancelled game that has a recorded result shows the score beside its single status.
- Player lists (division lists, stats): the signed-in player's own row gets the "You"
  badge and tint that standings already had.
- Appearance > Themes shows a branded screenshot; the plugin has a "View details" pop-up,
  icon and banner (no WordPress.org listing needed). The release also attaches
  `blueline-core-<version>.zip` and its checksum.
- blueline-core: personal-data export and erase for avatars and the player link;
  `wp blueline-core ownership unlink`; the YITH avatar migration reports conflicts.

### Changed
- Homepage, WooCommerce, account, forms and occasions CSS load only on pages that use
  them (about 24 KB to 17 KB gzip on most pages).
- The nav walker applies core's `nav_menu_css_class`, `nav_menu_link_attributes` and
  `walker_nav_menu_start_el` filters.
- The table-scroll pass uses WordPress's HTML5 parser (the old libxml pass lowercased SVG
  `viewBox` attributes).
- A replaced player photo that was uploaded through the site is deleted; earlier uploads are never touched.
- `inc/sportspress.php` is split into `inc/sportspress/*`; redundant `function_exists`
  guards on the theme's own helpers are removed.
- On pages with a sidebar, text fills the column flush left on the same edge as tables
  (it was a centred ~908px column); the brand leaf is the maple-leaf silhouette; the
  Next Puck Drop widget is centred.
- blueline-core: `player-link.php` is split into focused files, the photo upload handler
  into a validator and a store step, and the player-photo metadata strip prefers GD
  (Imagick stays as the fallback).

### Fixed
- Search form spacing and the logged-out /account heading alignment; Quotes Llama author
  images get `alt=""`.
- QA pass across every page type: clipped, misaligned and overlapping elements (clipped
  1,094 to 132, misaligned 212 to 10 in a 1,110-load sweep); every page has an `<h1>`;
  overflowing tables are keyboard-scrollable; the rules modal traps focus; focus rings keep
  a halo in light and dark; contrast fixes on cards, tabs, calendars and forms.
- Logged-in screens: the Account & Billing menu no longer opens over the Registrations
  table, the Preferences appearance toggle is readable in light mode, native buttons no
  longer show a grey box behind the skewed pill, the admin bar no longer covers the header
  on phones, and the duplicate "Profile Picture" tab is gone.
- Accented names ("José Müller") match their plain spelling when claiming a player; the
  matcher no longer depends on the server locale.
- blueline-core hardening: an atomic player claim, a name gate that padded names cannot
  bypass, a pixel cap on photo uploads, real `eventStatus` in structured data, clean
  plain-text titles and JSON-LD, correct multi-line mail headers, and a loud report when a
  module fails to load.

## [1.1.0-rc.1]

### Added
- Theme updates from GitHub releases: wp-admin offers "Update available" for
  tagged releases (stable and beta channels), with a checksum-verified download.
  Needs `BLUELINE_GITHUB_TOKEN` in `wp-config.php`; Site Health reports its state.
- `.distignore`, the single list of files excluded from both the rsync deploy
  and the release zip.
- Team pages: "Add to Calendar" and "Upcoming Games" are SportsPress team
  sections, reorderable in SportsPress > Settings > Teams > Layout.
- `.bl-callout` note component for /register and /equipment.

### Changed
- Name-based player claiming is limited to accounts with no Player role and no
  owned player record; only a Player-role account that owns its player record
  can change the photo or see registration details.
- Team flyout (desktop): click pins it open, and hover-open waits 450 ms before
  closing.
- Top-level lists line up with the centred prose column on wide screens.
- Five WooCommerce template overrides rebased on WooCommerce 11.0.1.

### Fixed
- Member names and usernames no longer exposed by the REST users endpoint, the
  user sitemap, author archives or oEmbed.
- Dark mode: registration price, highlighted standings row links, secondary
  buttons, the account login button and password icon.
- Schedule table overlaps and mid-word breaks at phone width; sidebar rail no
  longer causes horizontal scroll; the active nav chip no longer touches the
  Register button; single-product layout.
- Paragraph spacing in post and page content.
- Escape closes the team flyout and keyboard-opened dropdowns; sponsor and
  product link names; heading order.
- EXIF and GPS data removed from uploaded player photos.

### Performance
- Roster stats, standings and the team flyout are cached and cleared when a
  score or team is saved (team flyout: 131 queries to 9).
- PayPal, Google Pay and Apple Pay scripts, WooCommerce CSS, Contact Form 7 and
  the Facebook SDK load only where they can render.
- Header spacer sized in CSS (no layout shift); logo `sizes` and nav font
  preload fixed.

### Security
- Deploys and release zips no longer include tests, tooling or fixtures.
- Development dependency vulnerabilities patched (npm audit: 0).

## [1.0.1]

### Changed
- Initial tracked release.
