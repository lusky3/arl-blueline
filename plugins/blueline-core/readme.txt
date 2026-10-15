=== Blueline Core ===
Contributors: lusky3
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

League functionality for the Blueline theme: player linking and photos, avatars, My Account routing, mail wrapper, checkout guidance, SEO tags, search ranking and privacy hardening.

== Description ==

Blueline Core holds the league features that must keep working when the theme changes. It is built for the Blueline theme and works beside it: the theme keeps the presentation, the plugin keeps the behaviour.

* **Player linking and photos.** Members claim their own player page, and the owner of a linked player can upload a photo (size-capped, with the image metadata stripped).
* **Avatars.** Custom member avatars, including those migrated from the YITH My Account plugin.
* **My Account routing.** League pages (team, schedule, player profile, preferences) inside the WooCommerce My Account area.
* **Mail wrapper.** Branded HTML around transactional email, with a plain-text fallback.
* **Checkout guidance.** Plain-language hints on the checkout fields.
* **SEO tags.** Open Graph and Twitter card tags, plus event structured data with the real event status, unless another SEO plugin is active.
* **Search ranking.** An exact or leading title match ranks ahead of a match buried in the text.
* **Privacy hardening.** The user list is no longer published, and the plugin registers personal-data export and erase for the data it owns.
* **Search-engine indexing.** A per-page "Hide from search engines" checkbox, and an XML sitemap without redirecting, shop or utility pages.

Each feature is a module that can be switched off with the `blueline_core_modules` filter.

== Installation ==

1. Upload the zip under Plugins > Add New > Upload Plugin, or deploy it with the repository's deploy script.
2. Activate the plugin before, or together with, Blueline theme 1.1.0 or newer.
3. Against an older theme the plugin does nothing and shows an admin notice, because that theme still provides these features itself.

== Changelog ==

= 0.1.3 =
* My Account tabs now carry the league's wording: My Registrations, My Address, Change My Details and, when WooCommerce Subscriptions is active, My Installments (right after My Registrations). The site Code Snippet "My Account Tab Modifications" is no longer needed and can be switched off. Page addresses are unchanged, and a site can still rename a tab with the `woocommerce_account_menu_items` filter.
* New `season` module: one shared definition of the current season, read from SportsPress > Settings > General > Current Season. If that setting is empty or names a deleted season, the season of the next game on the schedule (or of the last game played) is used instead. The claim pool (the "find my player profile" list) and the search/preview season name now use it, so they no longer drift from the rest of the site. Change the setting on the day the new season starts playing.

= 0.1.2 =
* A profile photo larger than the server's request limit now returns to Player Profile with the "too large" message instead of ending on a blank page.
* Team and player pages now have a search/preview description (team, division and season; player, team and season) instead of none.
* Game page titles include the date and stay within 60 characters, so rematches no longer share one title, and a game's search result no longer repeats the sponsor name.
* Game structured data now includes the rink's street address and coordinates (needed for Google's event results) and names the home and away teams.
* Team pages add team structured data; team, game and venue pages add a breadcrumb trail.
* Descriptions no longer show leftover "[shortcode]" text, are cut at a whole word, and archives (news, venues, search, 404) no longer repeat the site tagline. The news page and each venue page get their own description.
* Archives and page-2+ lists now name themselves as the canonical address, so tracking links are not indexed as copies.
* Link previews: pages, teams and games are "website" (posts "article", players "profile"); a square logo or photo uses the small preview card instead of being cropped; image size and alt text are included; banner-shaped images are skipped in favour of an optional default share image (attachment id in the `blueline_core_default_share_image` option).
* Player accounts can no longer list the site's members or open author pages. Only administrators can; editors can still pick a post's author in the editor.
* Posts and players in the public REST API no longer show which account owns them.
* Claiming a player follows the season being played. Creating next season's term before its rosters are filled no longer hides this season's players, and a claim card shows the player's current season.
* Site emails are always sent as HTML, so a settings reset in the email template plugin can no longer make them arrive as raw markup.
* New "Hide from search engines" checkbox on pages and posts: it adds noindex, nofollow and leaves the page out of the XML sitemap.
* The XML sitemap leaves out pages that redirect elsewhere (Page Links To), the cart, checkout and account pages, and utility pages such as logout and the vendor pages, which are also marked noindex.
* robots.txt names the XML sitemap when the site is public and nothing else has added it.
* New `access` module: players, customers and subscribers can no longer open wp-admin (they are sent to their account page, also after logging in through wp-login.php) or create and edit posts, upload media, or edit players, games and teams, in wp-admin or through the REST API. Staff roles are not affected, stored roles are unchanged, and everything members do on the site (claiming a player, their own photo, account and billing details, registering and paying) keeps working.
* Login failures now give one message ("The username or password is incorrect.") whether the username, the email address or the password is wrong, and lost-password requests give one answer whether or not the account exists, on the account page and wp-login.php.
* New `headers` module: a `Permissions-Policy` header that turns off camera, microphone and geolocation; the WordPress, WooCommerce and SportsPress version tags are removed from pages and feeds, and the WordPress version in core asset URLs is replaced by a salted token that still changes on every update.
* Every public page sends a report-only Content-Security-Policy: nothing is blocked. Browsers report to `POST /wp-json/blueline/v1/csp-report`, which keeps only counts per directive, origin and page path (no IP addresses or user agents). Read them with `wp blueline-core csp report` or in Tools > Site Health. The `blueline_core_csp_mode` filter takes `report-only` (default), `enforce` or `off`.
* The XML sitemap leaves out archival SportsPress pages (past-season games, players, teams, staff, tables, lists and calendars tied only to seasons older than the 2 most recent; a season needs at least 10 games to count). They stay searchable and are not marked noindex. A new Site Health test reports the counts, and the filters `blueline_core_archival_recent_seasons`, `blueline_core_archival_min_season_events`, `blueline_core_is_archival_post` and `blueline_core_archival_post_types` adjust the rule.
* The sitemap index and event sitemap build in well under a second instead of five to six seconds.
* The claim pool follows the season of the next game on the schedule (including scheduled games), or of the most recent game played between seasons.
* The My Account billing address saves again when the checkout form has no Country field of its own (the form now carries the customer's country, or the store's).
* On the old domain (rookiehockey.ca) a page's `og:url` matches its canonical address and the plugin's archive canonical steps aside. On paginated archives `og:url` is the page's own address.
* The plugin declares its translation folder and loads it.

= 0.1.1 =
* The plugin's homepage link follows the repository rename to arl-blueline.

= 0.1.0 =
* First release as a companion plugin: the league features moved out of the Blueline theme.
* Player claim is atomic, and name matching is stricter.
* Player photo upload has a pixel cap, and the metadata strip uses GD first.
* Personal-data export and erase for the avatar, the player link and the player photo.
* Mail header fixes.
* Event structured data uses the real event status.
* A module that fails to load is reported loudly (error log, admin notice) and by a Site Health test.
