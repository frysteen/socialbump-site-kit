=== SocialBUMP Site Kit ===
Contributors: socialbump
Tags: acf, shortcodes, admin
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SocialBUMP base styling, ACF fields, shortcodes and admin tweaks for WordPress sites.

== Description ==

The standard SocialBUMP setup in one plugin: base styles, ACF fields, shortcodes and admin tweaks, so a new site starts configured the way every SocialBUMP site is built.

Every feature is a module. Most can be switched on or off under SB Site Kit, and anything switched off is not loaded at all. Features that need another plugin, such as ACF, Bricks or WooCommerce, switch themselves off when it is not installed.

This plugin works on any WordPress site. It does not require a page builder.

== Changelog ==
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

