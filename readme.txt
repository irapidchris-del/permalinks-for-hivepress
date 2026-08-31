=== Permalinks for HivePress ===
Contributors: chrisb
Tags: hivepress, permalinks, seo, urls, listings
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build SEO-friendly web addresses for listings, vendors and requests by adding their category and region, with new options on the Permalinks page.

== Description ==

HivePress lets you rename the base of your web addresses on the Permalinks page, but a listing's address never includes its category or its region. This plugin adds that, turning an address like `/listing/my-listing/` into `/listing/bikes/london/my-listing/`, which gives search engines more to work with and gives visitors an address they can read.

It works the same way for vendor profiles and for requests, so your whole site can share one readable address style.

Features:

* A new HivePress URLs section on the WordPress Permalinks page (Settings, Permalinks), with a menu for each type of content on your site.
* Choose what goes into the address and in which order: the category, the region, category then region, or region then category. Every choice shows an example address next to it, so you can see what you are picking.
* Works for listings, vendor profiles and requests, and picks up any future HivePress content type by itself.
* Regions come from the official HivePress Geolocation extension. The region choices appear as soon as regions are switched on for a type of content, and disappear again if you switch the extension off, with the addresses rebuilding themselves either way.
* Spell out nested terms in full, so a listing in a city shows as `/country/region/city/` rather than just the city. This is how to get a country, state and city address.
* Old addresses keep working. Anything that arrives on an old address, an address with an out-of-date category or region in it, or the address a listing had before you renamed it, is sent to the current address with a permanent redirect. Links you have shared, and pages search engines have already indexed, do not break.
* Uses whichever base you have set on the Permalinks page, whether the default `listing` or something custom such as `ads`. Changing the base later is picked up automatically.
* Objects with no category or region use `/other/` in their address, so an address is never left with an empty gap in it.
* An option to keep HivePress addresses at the top level, for sites that have given their blog posts a prefix such as `/blog/`.
* Your settings are kept if you delete the plugin, unless you tick the box that asks for them to be removed.

All settings are found under Settings, Permalinks, in the HivePress URLs section.

For developers: the term chosen for an address can be altered with the `hivepress/v1/permalinks/term` filter, the fallback slug with `hivepress/v1/permalinks/placeholder_slug`, and the content types on offer with `hivepress/v1/permalinks/types`.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/permalinks-for-hivepress` directory, or install the plugin zip through the WordPress admin.
2. Activate the plugin through the Plugins screen. HivePress must be installed and active.
3. Go to Settings, Permalinks, choose what you want in the HivePress URLs section, and save your changes.

Once installed, the plugin checks for new versions automatically and updates through the normal WordPress Plugins screen, just like a plugin from the WordPress.org directory.

== Frequently Asked Questions ==

= Will my existing links break? =

No. An old address such as `/listing/my-listing/` answers with a permanent redirect to the new address, so bookmarks, shared links and search results all keep working. The same happens in reverse if you later change your mind and switch the options off.

= What happens when I rename a listing? =

Renaming a listing changes the last part of its address, and the old address keeps working. This is worth calling out because it is the one thing that has caught people out before: with a hand-written code snippet in place, editing a listing's title left every address that had already been shared or indexed showing "Nothing found". This plugin remembers the previous address and redirects it, so renaming is safe.

= How do I get a country, state and city address? =

Switch on regions in the HivePress Geolocation extension, choose Region (or one of the pairs) for your listings, and tick "Spell out nested terms in full". Because regions are stored as a tree, the address then shows the whole branch, for example `/listing/united-kingdom/england/london/my-listing/`.

= Why can I not see the region options? =

The region can only go into an address when your site actually stores regions. That needs the official HivePress Geolocation extension to be active, with "Generate regions from locations" ticked under HivePress, Settings, Geolocation, and with the type of content you are setting up included in its Models setting. Once that is done, the region choices appear by themselves.

= What happens if I switch Geolocation off later? =

The plugin notices on the very next page load. The region drops out of the addresses, the rules rebuild themselves, and every address that still has a region in it redirects to the new one. There is nothing to clear or re-save.

= Which category appears when a listing has more than one? =

The most specific one. A listing in a child category uses the child rather than the parent, so a listing in Bikes, BMX shows `/bmx/` in its address unless you have asked for nested terms to be spelled out in full. Regions work the same way, so a listing in London shows `/london/` rather than the country it sits in.

= What appears when a listing has no category or region? =

The address uses `/other/` in place of the missing one, so every object always has a complete, working address rather than one with an empty gap in it.

= I changed the settings but an old address still shows a page instead of redirecting. Why? =

A page-caching plugin (such as FlyingPress, WP Rocket or W3 Total Cache) may still be serving a saved copy of the old page. Clear your caching plugin's cache after changing these settings and the redirects will take over.

= Does this change my category or region archive pages? =

Not unless you ask it to. The structure menus only change the addresses of listings, vendors and requests themselves; your category and region archive addresses are set by HivePress on the same Permalinks page and are left alone. The one exception is the "Keep HivePress addresses at the top level" option, which by design removes the prefix from every HivePress address including those archives, since leaving half of them prefixed is exactly the problem it exists to solve.

= Does deleting the plugin remove my settings? =

No. Your settings are kept by default, even though the WordPress delete screen warns that data will be removed, so a reinstall picks up where you left off. If you want everything gone, tick "Delete all data when this plugin is deleted" in the HivePress URLs section before deleting. Either way, addresses return to the standard HivePress form.

== Changelog ==

= 1.0.0 =
* Initial release.
* Adds a HivePress URLs section to the Permalinks page, with a menu of address structures for each type of HivePress content.
* Supports the category, the region, and both of them in either order, for listings, vendor profiles and requests.
* Optionally spells out nested categories and regions in full, for country, state and city addresses.
* Detects the HivePress Geolocation extension and offers the region choices only while regions are switched on, rebuilding the addresses by itself if that changes.
* Permanently redirects old addresses, out-of-date addresses, and the addresses used before an object was renamed.
* Optionally keeps HivePress addresses at the top level on sites whose permalink structure has a prefix.
