=== SocialBUMP Site Kit ===
Contributors: socialbump
Tags: acf, shortcodes, admin
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SocialBUMP base styling, ACF fields, shortcodes and admin tweaks for WordPress sites.

== Description ==

The standard SocialBUMP setup in one plugin: base styles, ACF fields, shortcodes and admin tweaks, so a new site starts configured the way every SocialBUMP site is built.

Every feature is a module. Most can be switched on or off under SB Site Kit, and anything switched off is not loaded at all. Features that need another plugin, such as ACF, Bricks or WooCommerce, switch themselves off when it is not installed.

This plugin works on any WordPress site. It does not require a page builder.

== Changelog ==
= 1.0.7 =
* Images is now two modules. Image Settings keeps the sizes and the upload tidying. Image Cleaner, its own module and off on new sites, holds the scan, the thumbnail rebuild, the old size clean up, the orphan scan and the sizes panel in the media library. With the cleaner off, the media library and the editor no longer load any of it.
* Your existing rebuild switch is carried across as the Image Cleaner switch, so nothing changes on a site until you turn it off.
* The Modules page cards collapse to their title, and Reorder Cards puts them in the order you want. Both are remembered per user, and the order is followed by the tab bar, the sidebar menu and the admin bar.
* Fixed a warning on the Images page for every size that can be edited.

= 1.0.6 =
* The Update now button on the Updates page now runs the update the same way the WordPress dashboard does, under maintenance mode, instead of deactivating and reactivating the plugin. The old way could leave the plugin switched off after an update.

= 1.0.5 =
* A size now counts as built only when its file is really on disk. A size listed in the database with no file behind it was reported as nothing to do, so it never got rebuilt and the gap went unnoticed.
* The image scan no longer lists the files WordPress keeps after you edit an image. They looked abandoned, but deleting them would have removed the ability to restore the original.
* Image sizes on an attachment now appear in a panel titled SocialBUMP Site Kit Sizes, in the media library and on the full edit screen, with each size linking to that file.
* The orphan list now shows a thumbnail and the attachment each file came from.
* A rebuild that loses a request to the server now picks itself up and carries on, instead of stopping with a warning when the work was fine.
* The rebuild progress now gives each image its own row and its own thumbnail, rather than one picture beside a batch of names.
* The unsaved changes reminder now saves with the save button. On the image sizes page it could submit Reset to defaults instead, because that button comes first in the form.

= 1.0.4 =
* Image scan no longer lists the files WordPress keeps after you edit an image. They looked abandoned, but deleting them would have removed the ability to restore the original.
* Orphan list now shows a thumbnail and the attachment it came from.
* A rebuild that loses a request to the server now picks itself up and carries on, instead of stopping with a warning when the work was fine.
* Image sizes on an attachment now appear in a proper panel titled SocialBUMP Site Kit Sizes, in the media modal and on the full edit screen, with each size linking to that file.
* A size counts as present only when its file is really there. A size listed in the metadata with no file behind it was reported as nothing to do, so it never got rebuilt.

= 1.0.3 =
* Settings link on the plugins screen, which Site Kit was missing.
* The banner now lists every page in the plugin, so you can move between them without going back to the admin menu. Updates shows a waiting version and Publishing shows how many changes are queued.

= 1.0.2 =
* New module: Force Gutenberg Page Refresh on Save, which reloads the editor once a save has finished so you see what was actually saved.

= 1.0.1 =
* A SocialBUMP overview page collects every plugin on the site, and lets all of them be published from one screen.
* Updating no longer leaves the plugin missing from the menus until you navigate away.
* A site that is not the publishing hub now clears the GitHub token and release notes it has no use for.
* A SocialBUMP Hub page gathers every plugin on the site, with one place to publish them all from. It only appears on the publishing hub.
* Menus now carry the SocialBUMP mark, and publishing lays out in two columns instead of three stretched cards.

= 1.0.0 =
* Admin bar item is now shared: with more than one SocialBUMP plugin active they sit together under a single SocialBUMP menu, each with its own pages.
* Save buttons stay greyed out until something is actually changed, with a reminder that follows you down the page while changes are unsaved.
* Unsaved changes now also warn before you leave the page with something unsaved.
* Save buttons look the same in every SocialBUMP plugin: a plain grey outline when there is nothing to save, amber when there is.
* Page headings now read the plugin name followed by the page you are on.
* The plugin now carries its own notes at docs/context.md, and they can be read and edited on the Publishing page.
* The plugin notes now describe every module in detail, including how it works and what it can be set to.

= 0.4.8 =
* Exported settings file name reads properly for the site it came from.

= 0.4.7 =
* Fixed saving one group's page wiping the switches for every other group. Modules that reverted to their defaults will need setting again once.
* Updates page can now export the settings to a JSON file and import them on another site.

= 0.4.6 =
* New WooCommerce module: Download Analytics as CSV, which puts a download button on each Analytics report for the range on screen, plus a ZIP of all four on the Overview page.
* Excerpt character counter now follows the WooCommerce product short description as you type, instead of only counting on page load.

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

