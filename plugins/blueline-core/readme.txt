=== Blueline Core ===
Contributors: lusky3
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
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

Each feature is a module that can be switched off with the `blueline_core_modules` filter.

== Installation ==

1. Upload the zip under Plugins > Add New > Upload Plugin, or deploy it with the repository's deploy script.
2. Activate the plugin before, or together with, Blueline theme 1.1.0 or newer.
3. Against an older theme the plugin does nothing and shows an admin notice, because that theme still provides these features itself.

== Changelog ==

= 0.1.0 =
* First release as a companion plugin: the league features moved out of the Blueline theme.
* Player claim is atomic, and name matching is stricter.
* Player photo upload has a pixel cap, and the metadata strip uses GD first.
* Personal-data export and erase for the avatar, the player link and the player photo.
* Mail header fixes.
* Event structured data uses the real event status.
* A module that fails to load is reported loudly (error log, admin notice) and by a Site Health test.
