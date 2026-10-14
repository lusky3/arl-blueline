# Changelog

All notable changes to the Blueline theme. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/). Each release heading is
`## X.Y.Z` (or `## X.Y.Z-rc.N`); the release workflow publishes that section
as the release notes.

## [Unreleased]

### Changed
- The "next game" reminder no longer appears on the My Account, cart or checkout pages, where it sat over the
  fields being filled in. On phones it is now a slim bar along the bottom of the screen ("Fri 6:45 pm · vs Ice
  Bears · Red") instead of a card, and the page leaves room for it so the footer and any focused field are
  never hidden behind it. It is now labelled "Your next game" for screen readers and the desktop card.
- The Teams tab now shows each team's name beside its crest (it used to be a crest alone, with the name only
  as a hover tooltip that never shows on a phone), and the crests load straight away instead of opening as
  blank circles.
- The Teams tab's attention pulse now stops after three pulses, and for good once you have opened it. The
  crests no longer bob. The Teams panel now sits above the next-game reminder.
- Seasonal effects (snow, poppies, fireworks) get a "Hide seasonal effects" button in the footer.

### Fixed
- Keyboard focus is no longer lost to the top of the page when you dismiss the announcement banner or the
  next-game reminder.
- The Teams panel closes when keyboard focus leaves it, instead of staying open over whatever you tabbed to.
- The push-notification bell no longer sits on top of the mobile menu, and it tucks away while the phone
  next-game bar is showing.
### Fixed
- Schedule tables now say when a game is postponed, cancelled or has its time still to be confirmed. A postponed
  game with a 0-0 on file used to look like a final score, and a cancelled one looked like a game still on. The
  status shows as a label in the Result column (beside any score that was recorded), and the kick-off time of a
  postponed or cancelled game is struck through.
- On phones, the schedule (the Schedule page, a team's Upcoming Games, any schedule table) shows one card per game
  instead of a table you had to scroll sideways to find the opponent and the rink: the time and result, then
  "Home vs Away" with both crests, then the arena and pad. Tablets and desktops keep the table.
- Screen readers now hear each game's time link as the game ("6:45 pm, Lumberjacks vs Ice Bears, October 16,
  2026"), not just "6:45 pm" repeated for every game in the same time slot.
- Team names in the schedule wrap onto a second line instead of being cut off when text is enlarged or spaced
  out, and the full-name bubble no longer disappears when moving to a team name with the keyboard scrolls the page.
- The Arena column keeps the pad with the end of the arena name ("Mr. Lube and Tires / Arena — Red"), so a line
  never starts with "— Red", and fits on two lines at tablet width instead of three.
- Team pages show their division again, and My Team shows the team's record again, for the 2026-27 season.
  The theme looked for a season's teams in a place SportsPress only fills when a standings table is edited
  by hand, so every table created for the new season looked empty.
- A player on two current teams now sees both. "My next game" is the earliest game across all of their
  teams and says which team is playing ("Kings @ Ducks"), and My Team shows one card per team, each with its
  own roster and its own "Add your season to" calendar buttons.
- Postponed and cancelled games are no longer shown as a player's next game, on the account dashboard, on
  My Schedule or in the "Next Puck Drop" countdown; the next game that is going ahead shows instead. A
  game whose status changes now counts as "Updated since you last checked".
- My Schedule lists the player's next few games (up to five after the next one, across all of their teams)
  instead of a single game, with postponed and cancelled games labelled, and a calendar button per team.
- Single-game "Add to calendar" links (Apple/Outlook and Google) give the arena, pad and street address
  ("Mr. Lube and Tires Arena — Black, 1179 Northside Rd, …") instead of just the pad name, so maps apps can
  find the rink.
- Games whose time is still to be decided say "Time TBD" on the game page and the account pages, and offer
  no "Add to calendar" link, instead of showing a placeholder time as if it were real.
- Turning off "My next game" or "My team" in the theme settings now sends `/account/my-schedule/` and
  `/account/my-team/` to the account dashboard instead of showing a blank page.
- The home page hero (and the header's Register button) no longer quotes prices for registrations the league
  has hidden from the shop. A product with "Hidden" catalogue visibility is never offered, even when it is
  tagged Featured and still in stock.
- A $0 waitlist no longer counts as "registration open", so the site stops reading as open all year while a
  waitlist is on sale. Between seasons, when only waitlists are on offer, the hero says "Waitlist open" with a
  "Join the waitlist" button and no prices. While games are being played the hero stays the in-season one.
- Postponed and cancelled games no longer appear as the next game in the hero, in the home page's Next games
  list, or in the in-season "games this week" count. The next game that is going ahead shows instead, and a
  cancelled game's date is no longer printed as the season start.
- The in-season hero shows the real week of the season ("W2026-27 · Week 2"), counted from the season's first
  game. It used to print the number of games in the next seven days as the week. In a week with no games the
  headline now names the next game's date instead of "0 games this week".
- The home page's season emphasis is no longer cached for 15 minutes when WooCommerce or SportsPress was
  briefly unavailable (for example during a plugin update), and a sale that changes stock now refreshes it
  straight away.

## [1.1.4]

### Fixed
- Theme updates now work without a GitHub token. The updater did nothing at all unless `BLUELINE_GITHUB_TOKEN`
  was set in `wp-config.php`, which was needed while the repository was private. It is public now, so a site
  with no token checked for updates never, and kept showing the old version when a release was published.
  The token is now optional (it only raises the rate limit, or reaches a private fork). Site Health no longer
  reports "switched off" for a site without one. A site running 1.1.3 or older still needs this version
  installed by hand once.

## [1.1.3]

### Fixed
- The home page hero no longer says "Season starts <date>" once games are being played. When registration
  was open but no product carried the Featured tag, the hero fell back to its preseason copy, which prints the
  next game's date as the season start. It now falls back to the in-season hero while games are being played.
- Schedule table: each team's crest and name are mirrored (home is name then crest, away is crest then name)
  instead of centred, so every crest sits the same distance from the Result column. Crests used to land
  wherever the team name's length put them, up to 40px apart row to row.
- Schedule table on phones: team names no longer break mid-word ("Lumberjac/ks"). The Home and Away columns
  are wider, and a name that still cannot fit is cut off with an ellipsis instead of being split. Hovering it
  shows the full name; on a touch screen the first tap shows the full name and a second tap opens the team
  page. Names that fit behave as before.

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
