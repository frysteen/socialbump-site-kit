=== SocialBUMP Site Kit ===
Contributors: socialbump
Tags: acf, shortcodes, admin
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SocialBUMP base styling, ACF fields, shortcodes and admin tweaks for WordPress sites.

== Description ==

The standard SocialBUMP setup in one plugin: base styles, ACF fields, shortcodes and admin tweaks, so a new site starts configured the way every SocialBUMP site is built.

Every feature is a module. Most can be switched on or off under SB Site Kit, and anything switched off is not loaded at all. Features that need another plugin, such as ACF, Bricks or WooCommerce, switch themselves off when it is not installed.

This plugin works on any WordPress site. It does not require a page builder.

== Changelog ==
= 0.4.5 =
* New WooCommerce module: require login to check out, which sends a logged out customer to My Account and back again. Greys itself out while guest checkout is switched on, with a link to that setting.

= 0.4.4 =
* New WooCommerce group with five switchable fixes: default category title, from price on variable products, hiding empty decimals, both prices in the cart, and turning off product image zoom.
* A module group whose features all need a missing plugin is now greyed out and cannot be switched on.

= 0.4.3 =
* Publishing now retries GitHub when it fails, checks the zip attached, and no longer undoes a release that actually went out.
* Publish page fills in the notes box from changes logged since the last release.
* Added an SB Site Kit shortcut to the admin bar, with a dropdown to each of its pages.
* Admin bar shortcut highlights the plugin and the page you are on.

= 0.4.2 =
* Maintenance release.

= 0.4.1 =
* Fix: a stray bracket in the admin script stopped every button on the Images page from working.

= 0.4.0 =
* Orphaned files are now checked against the site before they can be deleted in bulk. Anything mentioned in a page, a setting or a template is left alone and shown with where it turned up.
* A file can be marked as one to keep, which shades its row and takes it out of the bulk delete.
* Every orphan is listed rather than the first 25, so any of them can be kept, with the rest folded behind a button.
* Orphan file names link to the file, and clearing them runs in batches with a progress bar.
* The rebuild progress shows the image being worked on.
* Adding an image size warns about duplicates and refuses a name another plugin has registered.

= 0.3.5 =
* Change: a new thumbnail follows whichever naming most of the existing thumbnails use, rather than the first one it happens to find.

= 0.3.4 =
* Change: a file whose name matches an image still in the library is never treated as an orphan, even when its metadata is incomplete. A large upload keeps its untouched original alongside the scaled copy, and that note is sometimes missing.

= 0.3.3 =
* Fix: building a missing size no longer asks WordPress to rebuild the whole attachment.
* On a large upload that renamed every thumbnail after the scaled copy, leaving the previous set behind as orphans. Sizes are now made one at a time, keeping the names already in use.

= 0.3.2 =
* Change: a file is opened and checked before it is deleted as an orphan, so something merely named like an image is left alone.

= 0.3.1 =
* Fix: preview images belonging to PDFs were being counted as orphans, because their metadata has no path of its own.
* Change: only picture files are ever treated as orphans, so data files other plugins keep in the uploads folder are left alone.

= 0.3.0 =
* The plugin now lands on a Modules page, with Content, Images and Admin Settings as switchable groups, each with a page of its own.
* Images: sizes can be switched off, the list can be reset to the standard set, and Rebuild Thumbnails now scans first and only offers the jobs worth doing.
* Images: building and clearing are separate, orphaned files in the uploads folders can be found and deleted one at a time or together, and only file types that can have thumbnails are counted.

= 0.2.2 =
* Fix: the header logo markup was broken by the last release, so the logo did not show.

= 0.2.1 =
* The logo in the header now takes you back to the Features page.

= 0.2.0 =
* Content: Images page with editable sizes, tidy titles and alt text on upload, plus rebuilding and cleanup of sizes.
* Content: excerpt character counter, excerpts for pages, shortcodes in excerpts.
* Admin settings: SocialBUMP admin colour scheme, front end toolbar control, blocking admin access by role.
* ACF features switch themselves off when ACF is only present as the copy bundled with Advanced Themer.

