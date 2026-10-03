# Changelog

All notable changes to the Blueline theme. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/). Each release heading is
`## X.Y.Z` (or `## X.Y.Z-rc.N`); the release workflow publishes that section
as the release notes.

## [Unreleased]

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
