# Changelog

All notable changes to the Blueline theme. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/). Each release heading is
`## X.Y.Z` (or `## X.Y.Z-rc.N`); the release workflow publishes that section
as the release notes.

## [Unreleased]

### Added
- Theme updates from GitHub releases: wp-admin offers "Update available" for
  tagged releases (stable and beta channels), with a checksum-verified download.
  Needs `BLUELINE_GITHUB_TOKEN` in `wp-config.php`; Site Health reports its state.
- `.distignore`, the single list of files excluded from both the rsync deploy
  and the release zip.

## [1.0.1]

### Changed
- Initial tracked release.
