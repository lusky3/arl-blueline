=== Blueline Core ===
Contributors: lusky3
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.1
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

= Unreleased =
* Player accounts can no longer list the site's members or open author pages. Only administrators can; editors can still pick a post's author in the editor.
* Posts and players in the public REST API no longer show which account owns them.
* Claiming a player follows the season being played. Creating next season's term before its rosters are filled no longer hides this season's players, and a claim card shows the player's current season.
* Site emails are always sent as HTML, so a settings reset in the email template plugin can no longer make them arrive as raw markup.
* New "Hide from search engines" checkbox on pages and posts: it adds noindex, nofollow and leaves the page out of the XML sitemap.
* The XML sitemap leaves out pages that redirect elsewhere (Page Links To), the cart, checkout and account pages, and utility pages such as logout and the vendor pages, which are also marked noindex.
* robots.txt names the XML sitemap when the site is public and nothing else has added it.

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
