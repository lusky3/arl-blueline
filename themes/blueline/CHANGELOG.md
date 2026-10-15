# Changelog

All notable changes to the Blueline theme. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/). Each release heading is
`## X.Y.Z` (or `## X.Y.Z-rc.N`); the release workflow publishes that section
as the release notes.

## [Unreleased]

## [1.1.5]

Ships with blueline-core plugin 0.1.2. Install the plugin first (see Upgrade notes).

### Added
- `mu-plugins/arl-domain-router.php`: serves one WordPress under two faces by hostname (`arlhockey.ca` runs
  Blueline; `rookiehockey.ca/.com` keep the classic theme with a canonical tag pointing at the primary), redirects
  alias domains to the primary and sends admin, login, account and checkout from the old domains to it. See
  `docs/DOMAINS.md`. It is a must-use plugin, so the theme updater does not install it.
- Registration: the Register page opens with a "Choose your registration" button that jumps to the first
  registration card, and a price line ("Player $575.00 · Goalie $170.00") built from the same offers as the home
  page hero. Product pages get a "What's included" block (the product's short description, or the one-payment
  line used at checkout).
- Registration and waitlist: a $0 waitlist product reads "Join the waitlist" and "Free" instead of "$0.00".
  Paid product cards, product pages and the Register page now say up front that card, PayPal, Apple Pay and Google
  Pay add a 3.5% + $0.30 processing fee and Interac e-Transfer has none (the e-Transfer sentence shows only while
  that payment method is on).
- The home page's waitlist-only state: between seasons, when only waitlists are on offer, the hero says
  "Waitlist open" with a "Join the waitlist" button and no prices.
- Schedule pages that cover a whole season open on the page holding the next game, with "Earlier games" and
  "Later games" links under the table (the site's "paginated" and "rows" settings are honoured again, in whole game
  days).
- Search is easier to find: a magnifier button in the desktop header opens a search bar under it, and the phone
  menu ends with a search field. The no-results search page ends on the same three next steps as the 404 page
  (Schedule, Standings, Register to Play).
- Settings: export and import now round-trip the Occasions settings (validated like a normal save, all-or-nothing).
- Standings tables carry a one-line legend ("Record is W-L-T-OTL: wins, losses, ties, overtime losses.").
- Signed update manifests: the theme can now require an ed25519 signature on every release, and Site Health has a
  "Blueline release signing" test. See Upgrade notes: this stays dormant until the owner adds a public key.
- A drift check for the template overrides (a header on every override names the plugin version it was copied
  from) and a Site Health recommendation when the Blueline Core plugin is older than the theme needs.

### Changed
- The "next game" reminder no longer appears on the My Account, cart or checkout pages, where it sat over the
  fields being filled in. On phones it is now a slim bar along the bottom of the screen ("Fri 6:45 pm · vs Ice
  Bears · Red") instead of a card, and the page leaves room for it so the footer and any focused field are
  never hidden behind it. It is labelled "Your next game" for screen readers and on the desktop card, and it
  names the player's own team ("Kings vs Ducks") and says "Time TBD" for a game whose time is not settled.
- The Teams tab now shows each team's name beside its crest (it used to be a crest alone, with the name only
  as a hover tooltip that never shows on a phone), and the crests load straight away instead of opening as
  blank circles. Its attention pulse stops after three pulses, and for good once you have opened it. The crests
  no longer bob. The Teams panel sits above the next-game reminder.
- Seasonal effects (snow, poppies, fireworks) get a "Hide seasonal effects" button in the footer.
- Schedule on phones: one card per game instead of a table you had to scroll sideways (the time and result, then
  "Home vs Away" with both crests, then the arena and pad). Tablets and desktops keep the table, and the switch
  happens exactly when the table would no longer fit, so there is no half-scrolling state in between. The
  current-season calendar shows the table from 1270px wide.
- Schedule tables say when a game is postponed, cancelled or has its time to be confirmed ("Time TBD"). The status
  shows as a label in the Result column (beside any recorded score), and the kick-off time of a postponed or
  cancelled game is struck through. The Arena column keeps the pad with the end of the arena name and never
  starts a line with "— Red".
- Team names in the schedule wrap onto a second line instead of being cut off when text is enlarged or spaced out.
- Standings: nobody else's team is highlighted any more. The shared copy is neutral, and each signed-in player's
  own team row is added for them alone ("(your team)" is also read out). Header labels are expanded for screen
  readers (GP, W, L, OT, PTS...), and on phones the default view (Pos / Team / Record / Pts) fits from 360px up
  with no sideways scrolling. The home page division tabs read "Div 1" to "Div 5".
- Layout: pages with a sidebar sit on the same 1200px grid as the header and footer, every band shares the same
  left edge on phones, long text is capped at a readable line length, and the Sponsors page heading is left-aligned
  with its grid.
- Empty states: a playoff round nobody has played yet says "Playoffs start after the regular season." instead of
  a table of zeros, and a player with no stats anywhere gets one "No stats yet." message.
- My Account: "Log out" is the last menu item, set apart by a divider; the Account & Billing dropdown has a
  chevron; each page heading uses the label of the tab that leads to it. Logged-out visitors see "Log In" instead
  of "My ARL Account" in the menu.
- Touch screens: small targets (pad links, scoreboard team names, tabs, "View all games", standings and roster
  links, footer links, the photo pencil and more) are now at least 44px tall.
- Dates, times and scores use one format everywhere (game page, home page, next-game widget, My Account).
- Product pages and the cart no longer show the sidebar (countdown, recent posts, quotes), as on checkout. The
  checkout "Fee Applies" notes use the secondary text colour instead of red. "Register Now", "Join the waitlist",
  "Proceed to checkout" and "Place order" use the same ice ribbon as the site's primary button, and the primary
  button has hover and pressed states.
- Order and account emails are rebuilt on the templates WooCommerce 11.0.1 (and Subscriptions and Store Credit)
  actually ship, keeping the league's own wording, so they pick up WooCommerce's own fixes again. The store-credit
  page shows its expiry date and "Use now" button again, and the store-credit email has the "Redeem now" button.
- The release zip is smaller (build sources, developer tools and a second copy of the fonts are left out: 218
  files and 1.43 MB down to 170 files and 1.17 MB) and is built from the tagged commit only, so building the same
  tag twice gives the same file.
- The updater reports a GitHub rate limit as "rate limited" (not the red token error) and a missing repository on
  its own; a newer release whose zip or manifest is missing or invalid is reported in Site Health instead of "up to
  date". Release tags are only `vX.Y.Z`, `-rc.N` or `-beta.N`.
- Visitor-facing default wording (footer heading, hero headlines, "Never played? Perfect.", account empty states)
  and the occasion presets' names can now be translated. English output is unchanged.
- "Switch to Classic Site" keeps the page's query string.
- Page-cache purge reports honestly (and says Cloudflare's edge cache is not purged by the theme).
- The page's `theme-color` follows the "Ink, deep" brand colour, and the Browser Theme Color plugin's duplicate
  tags are no longer printed.
- Internal: the largest theme files (occasions, home page modules, settings page) are split into smaller files by
  responsibility, stale task-history comments are removed, and `npm run tokens:check` now fails when a copy of a
  design colour outside CSS drifts from `style.css`. No visible change.

### Fixed
- Team pages show their division again, and My Team shows the team's record again, for the 2026-27 season. The
  theme looked for a season's teams in a place SportsPress only fills when a standings table is edited by hand.
  My Team also shows the regular-season record, not the newer empty Playoffs table, and keeps it once playoff
  games are the next ones scheduled.
- A player on two current teams now sees both. "My next game" is the earliest game across all of their teams and
  says which team is playing ("Kings @ Ducks"), My Team shows one card per team with its own roster and "Add your
  season to" calendar buttons, and My Season shows one block per team. The "Jump to a teammate" list on a player
  page lists teammates of every current team.
- Postponed and cancelled games are no longer shown as a player's next game (account dashboard, My Schedule,
  the floating widget, the "Next Puck Drop" countdown), and no longer appear as the next game in the hero, the
  home page's Next games list or the "games this week" count. A game whose status changes counts as "Updated
  since you last checked". The "played in the last 30 days" check no longer counts cancelled or postponed games.
- My Schedule lists the player's next few games (up to five after the next one, across all teams) with postponed
  and cancelled games labelled, and a calendar button per team.
- Single-game "Add to calendar" links give the arena, pad and street address instead of just the pad name. A game
  whose time is still to be decided says "Time TBD" and offers no calendar link. The calendar file shows plain text
  (no `&#8217;`), folds long lines without splitting accented letters and declares UTF-8.
- Turning off "My next game" or "My team" in the theme settings now sends `/account/my-schedule/` and
  `/account/my-team/` to the account dashboard instead of showing a blank page.
- The home page hero (and the header's Register button) no longer quotes prices for registrations the league has
  hidden from the shop, and a $0 waitlist no longer counts as "registration open". The in-season hero shows the
  real week of the season, counted from the season's first game (it used to print the number of games in the next
  seven days), and in a week with no games it names the next game's date instead of "0 games this week".
- The home page's season emphasis is no longer cached for 15 minutes when WooCommerce or SportsPress was
  briefly unavailable, and a sale that changes stock refreshes it straight away.
- Checkout no longer drops keyboard and screen-reader focus on the "Got a credit code?" or "Returning player?"
  prompt when the page opens.
- Edit Account and Addresses: the dropdowns and multi-selects have names a screen reader announces and a visible
  focus outline, and a field the page reports as wrong is marked invalid and linked to its message. Starred
  fields are marked required. The plugin's section titles use the page text colour (readable in dark mode) and are
  announced as headings. The dropdowns' chosen value is readable in dark mode on checkout and Edit Account.
- Contact form: the second name box is announced as "Last name", the name and email boxes tell the browser what
  they hold (autofill works), and form fields on the account, checkout and contact pages are at least 44px tall.
- Checkout: the street address lines no longer switch autofill off, and the terms checkbox no longer shares an id
  with the heading of the Terms page.
- Profile photo: the page says the photo uploads as soon as you choose it, and the 2 MB limit, before you pick one,
  and shows "Uploading photo..." while it works. A photo over 2 MB (most phone photos) is shrunk in the browser and
  uploaded instead of refused. A photo over the server's request limit used to end on a blank page, also with
  JavaScript off, and now returns to Player Profile with the "too large" message.
- Tables inside page content that are wider than the screen can be scrolled with the keyboard.
- Keyboard focus is no longer lost to the top of the page when you dismiss the announcement banner or the
  next-game reminder. The Teams panel closes when keyboard focus leaves it.
- The push-notification bell no longer sits on top of the mobile menu, and it tucks away while the phone
  next-game bar is showing.
- The header sponsor strip no longer jumps the page by 64px about half a second after load (the footer's response
  was marking the still-waiting header slot as empty).
- The signed-in header no longer wraps to two rows at some widths between 1000 and 1156px.
- Sort arrows show again on standings, rosters and player stats for visitors who are not signed in, and phone
  standings no longer scroll a few pixels sideways.
- The footer theme toggle no longer makes the page scroll sideways at 320px with the text-spacing overrides.
- Nav and sitemap links that Page Links To stored with a trailing slash (`/standings/`, `/rosters/`) now print the
  slashless address the site serves, so they no longer cost a redirect.
- The failed-payment email linked to `www.rookiehockey.ca` on every site; it now links to the Registrations page of
  whichever site sent it. Mail links built outside a web request use the primary host when the stored address is a
  legacy alias.
- "Delete all Blueline data" now removes everything the theme created (the SportsPress cache generation, roster and
  league-table cache entries, the update-check result and the scheduled occasion refresh), keeping members' own
  light/dark choice.
- Opening quotes in the Quotes widget are now curled the right way round.
- The "Photos (opens in a new tab) (opens in a new tab)" menu link names itself once.

### Security
- Release signing (ed25519) for update manifests, dormant until a key is added. The signature is checked on the raw
  manifest bytes before any field is read, an unsigned or badly signed release is never offered once a key is
  listed, and the zip's sha256 is still checked on download.
- The page head and feeds no longer advertise the WordPress, WooCommerce and SportsPress versions, and core asset
  URLs carry a salted token instead of the WordPress version (blueline-core).
- Sponsor logos with a website are real, escaped links with `rel="sponsored noopener"`; a sponsor without one is
  plain text.
- Member accounts are kept out of wp-admin and out of the member directory, login and lost-password answers are
  generic, and a report-only Content Security Policy is sent (all in blueline-core 0.1.2; see its changelog).

### Performance
- Pages load only the theme code they render: a small core bundle plus feature bundles. News, FAQ, archives and the
  404 page load about 40% less theme CSS (15.0 to 9.1 KB gzip) and about half the theme JS (11.2 to 5.5 KB gzip);
  registration, product and cart pages and My Account also shrink. jQuery is no longer needed by the theme's own
  code except for one small sponsor script.
- Plugin stylesheets and scripts that blocked the first paint now load only where used (Follow-Up Emails,
  SportsPress add-ons, Quotes Llama, Dashicons for signed-in visitors only). PayPal, Google Pay and Apple Pay
  scripts (about 700 KB) load only on cart, checkout and My Account > Add payment method; the
  `blueline_keep_payment_scripts` filter puts them back. jQuery loads in the footer when nothing in the head needs
  it. WordPress's emoji script is gone.
- The arena map on game pages is a same-height box with an "Open map" button that loads the map in place (the
  Leaflet library no longer loads on game pages; the venue page keeps its live map).
- Team crests and page featured images load first, the header logo does not, and product and scoreboard images use
  correct `sizes`; new product images are generated at 640px and 320px instead of 200px.

### Accessibility
- Selected and checked controls (the division chip, the pressed Light/Dark/Auto option, the current Billing menu
  page, "Show full stats") no longer rely on colour alone.
- Sortable tables no longer claim to be an interactive grid; each sortable column heading is a real sort button,
  and Enter and Space both sort. Table headers are real column headers and each table is named by its heading.
- Table pagination: the invisible "Previous" is no longer a Tab stop, buttons and the current page are announced as
  such, and focus stays on the pagination after choosing "Previous".
- "League Info" (a parent item that only opens a submenu) is a real expand/collapse button in the desktop bar and the
  phone drawer; Enter and Space open and close it.
- Checkout "Click here to login" is announced as an expandable button; "Show next game widget again" is announced;
  urgent announcements start with "Urgent:".
- The theme settings panel: labelled tab navigation, help text read with each field, a live contrast readout, named
  Occasion groups and Remove buttons, and focus moves to new rows.
- Screen readers hear each game's time link as the game ("6:45 pm, Lumberjacks vs Ice Bears, October 16, 2026"),
  the jersey number after a player's name, "at Pylons" for "@ Pylons", and which links open a new tab.
- Calendar link pairs are named groups; game scores and kick-off times in game blocks are no longer headings (no
  more skipped heading levels); the utility menu is labelled "Account links"; the two registration products'
  Description tabs no longer share ids.
- Wide tables that scroll or stack into cards keep their table structure in Safari and VoiceOver.
- On very short or zoomed screens (below 420px high) the header and Teams tab scroll away with the page instead of
  covering over a third of it.

### Upgrade notes
- **Install the blueline-core plugin 0.1.2 by hand first, or together with this theme.** The plugin has no updater:
  download `blueline-core-0.1.2.zip` and its `.sha256` from the release, verify, and upload it over the old one
  (Plugins > Add New > Upload, "Replace current with uploaded"; do not delete the plugin first, uninstalling removes
  its options). Then update the theme. The theme works without the new plugin but lacks its fixes. See
  `docs/RELEASING.md` (Plugin) and `docs/OPERATIONS.md` (Deploy order).
- **A Content-Security-Policy is sent in report-only mode by default** (blueline-core). Nothing is blocked. Browsers
  report violations to `POST /wp-json/blueline/v1/csp-report`, which stores counts only; read them with
  `wp blueline-core csp report` or in Tools > Site Health. Enforcing is a filter (`blueline_core_csp_mode`, values
  `report-only`, `enforce`, `off`); see "Content Security Policy" in `docs/OPERATIONS.md` before turning it on.
- **Signed update manifests are dormant** until the owner adds a public key to `BLUELINE_RELEASE_PUBLIC_KEYS`
  (`inc/updater-rules.php`) after the key ceremony in `docs/RELEASING.md` ("Release signing"). Until then updates
  work as before and Site Health shows the recommendation "Release signing is not enabled". Once a key is listed,
  every release must be signed.
- **New blueline-core modules: `access`, `headers` and `indexing`.** `access` keeps players, customers and subscribers
  out of wp-admin and gives generic login answers; `headers` sends the Permissions-Policy and CSP headers and
  removes version strings; `indexing` adds the "Hide from search engines" checkbox and sitemap clean-up. The go-live
  check in `docs/OPERATIONS.md` lists all 14 modules; each should report `LOADED`.
- The domain router is a must-use plugin and is not shipped by the updater or the deploy scripts: copy
  `mu-plugins/arl-domain-router.php` into `wp-content/mu-plugins/` by hand (`docs/DOMAINS.md`).
- After updating, flush rewrite rules (the plugin does it once by itself when the `rh-royal-mcp-register-fix`
  mu-plugin is present) and purge the Cloudflare cache.

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
