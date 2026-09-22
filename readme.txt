=== SocialBUMP Site Kit ===
Contributors: socialbump
Tags: acf, shortcodes, admin
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SocialBUMP base styling, ACF fields, shortcodes and admin tweaks for WordPress sites.

== Description ==

The standard SocialBUMP setup in one plugin: base styles, ACF fields, shortcodes and admin tweaks, so a new site starts configured the way every SocialBUMP site is built.

Every feature is a module. Most can be switched on or off under SB Site Kit, and anything switched off is not loaded at all. Features that need another plugin, such as ACF, Bricks or WooCommerce, switch themselves off when it is not installed.

This plugin works on any WordPress site. It does not require a page builder.

== Changelog ==
= 1.1.16 =
* Images: alt text and tidied titles on upload now keep a dash as a dash. The title was being read after WordPress had turned " - " into an HTML code, which ended up in the alt text as numbers.

= 1.1.15 =
* Made the Save changes button more robust, so a save button that is not a standard form submit button still saves.
* Images: new Gutenberg image zoom option. Switch it on under Image extras and the gallery and image blocks get an Enable zoom toggle and a popup size, while the size on the page stays the block's own Resolution setting. The popup has arrows through a gallery, captions and keyboard support. An image inside a gallery follows the gallery's setting.
* Images: tidying the file name on upload no longer mangles names typed by hand. A name with spaces is kept exactly as written, dashes and capitals included, and slug-style names get capitals without the rest of each word being lowered, so UV stays UV.

= 1.1.14 =
* Image Cleaner: the in-use check now covers every row on very large sites instead of stopping early, AVIF copies made by optimiser plugins are tidied away alongside WebP ones, and big scans run with far fewer database queries.
* General tidy-up: removed a duplicated comment, fixed indentation in the main file, and stripped unused code from the shared admin bar class.

= 1.1.13 =
* The Modules page is now called Features, to leave the word Modules free for what these plugins are about to become inside SocialBUMP Tweaks. Nothing on it has changed.
* Settings are now saved under sb_tweaks_site_kit_ names, ready for the move into the combined plugin. Everything you have set is converted automatically the first time the site loads after updating, including the image sizes the kit owns, the files the cleaner was told to leave alone, the sizes you had ticked on the Image Cleaner page and your card arrangement. The old settings are left in place and backed up.
* The FAQ relationship field is now named sb_tweaks_faq_related_ followed by the post type, instead of sbsk_related_. No site is using one yet, so nothing changes for anyone. A site that already had one can point at it with the field name box on the source.

= 1.1.12 =
* The Post Types for Add-ons list now has the full width of the page and sits below the other cards on Admin Settings, so a site with a lot of post types can actually read it. It has Select all and Select none above the list too.
* FAQ Settings now says where to change which post types are on offer, with a link straight to that setting on the Admin Settings page.
* On the Modules page, Post Types for Add-ons now shows as on, since it has no switch and is always running, and it sits last in the list to match the page it belongs to.
* Reorder Cards has moved up beside the Modules heading, on the right. It used to sit just above Save changes, which made it easy to hit by mistake.
* The Save changes button now fills with your admin colour scheme once there is something to save, instead of the pale yellow. The unsaved changes reminder stays yellow, since it is a notice rather than a button.
* The Save changes button no longer flashes as a live button for a moment when a settings page loads. It now starts in its resting state.
* Settings forms no longer hold on to unsaved changes when you reload the page past the warning. The page now comes back showing what is actually saved, rather than your unsaved edits sitting there looking saved.
* Select all and Select none, Collapse all and the other text links now all look the same and sit in the same place, with a hover colour you can actually see.
* FAQ content left behind on a post type is now found whichever way the post type was switched off: by unticking it on the FAQ Settings page, or by unticking it under Post Types for Add-ons. Before, the second way left the content invisible to the cleanup tool, and the FAQ field kept appearing on that post type as though nothing had changed.
* Post Types for Addons is now spelled Post Types for Add-ons. Nothing else about it has changed.
* SocialBUMP Admin Colours is now called SocialBUMP Admin Colour Scheme, which is what it actually is. Nothing about how it works has changed, and the scheme you have chosen under your profile is untouched.

= 1.1.11 =
* New under Admin Settings: Fix ACF CPT SVG Icons, which makes SVG menu icons on post types created in ACF behave like the rest of the admin menu icons. It has moved here from SocialBUMP Bricks Tweaks, since it was never a Bricks feature. Off by default.

= 1.1.10 =
* New Post Types setting under Admin Settings. It decides which post types the rest of Site Kit offers you, so a site with a lot of them is not listing things you will never use. Anything new is offered by default.
* New FAQ Schema module. It adds the fields for your questions and answers, either on the page itself or from a post type used as a library, and prints the FAQPage schema for them.
* The FAQ Settings page now shows an FAQ Cleanup list when a post type holds FAQ content but no longer offers the field, so old questions can be cleared out without hunting for them. It only appears when there is something to clear.

= 1.1.9 =
* Image sizes are now worked out from the image file itself rather than what WordPress has on record. If a plugin resized your uploads after WordPress saved them, thumbnails that could never be built no longer sit in the list for ever, and files that are genuinely in use are no longer offered for deletion.
* When a file is left alone because something still uses it, the post or page name is now a link straight to its editor.
* Deleting leftover thumbnails now shows a progress bar with elapsed time and an average per file, and repeats both when it finishes.
* Building thumbnails now works through only the images that need one, so the progress bar counts what it will really do rather than every image in the library.

= 1.1.8 =
* Fixed thumbnails being listed as old sizes to clear when another plugin or snippet still registers that size. On a site where image sizes were set up elsewhere, more than a thousand working thumbnails were being offered for deletion.
* The list of sizes on the Image Cleaner page can be a little wider, for sites with long size names.

= 1.1.7 =
* Changing an image size now shows up as thumbnails to rebuild. Before, a size whose dimensions changed was never noticed, because the old files still existed, so only a forced rebuild would update them.

= 1.1.6 =
* Fixed two errors in deciding which thumbnails an image needs. Images the same size as a cropped thumbnail no longer sit in Sizes to build for ever, and cropped sizes that WordPress can make from a short image are no longer skipped.

= 1.1.5 =
* Fixed files being held back as in use when the thing referring to them had already been deleted. The check now reads the site fresh at the start of each scan, and says a file is in use only when it can point at what is using it.

= 1.1.4 =
* Scanning is much faster on sites with a lot of orphaned files. Checking whether a file is still used now reads the site content once instead of searching the database separately for every file, which turned a minute long scan into well under a second.

= 1.1.3 =
* The Sizes to build figure on the Image Cleaner page can now be clicked to see exactly which images are missing sizes, with a Rebuild link for a whole image or for one size at a time.
* Fixed the scan not reporting old thumbnails after you remove an image size. Removing a width now correctly shows its leftover files under Old thumbnails to clear.
* Turning the Image sizes feature off now correctly lists all of its thumbnails under Old thumbnails to clear, so they can be removed.
* Remove old sizes is now a red outlined button, like Delete orphan images, so the two buttons that delete files look like it.
* New Deep scan on the Image Cleaner page. It looks at the actual files in your uploads folder and finds thumbnails whose dimensions no current image size would make, which is how leftovers from an old theme or a removed plugin end up sitting there forever. Results are grouped by size so you can see what they are, and nothing is deleted until you tick a group and confirm.
* Each group in the deep scan opens to show the actual files, with a thumbnail and a link to open each one, so you can see what you are about to delete.
* The deep scan is now called Find leftover thumbnails, sits beside Scan images, and its results carry a clear heading and instruction.
* The leftover thumbnails results can be closed, and running a normal scan clears them rather than leaving the old list on screen.
* Deleting leftover thumbnails now runs in batches with a progress count, so a large clean up cannot time out. Files that are left alone are listed with the reason. Records kept by image optimisers such as WPvivid no longer count as the file being in use.

= 1.1.2 =
* A forced rebuild no longer skips sizes on tall images that a normal build would have made, such as the 1536 size on a portrait photo.
* The list of sizes under each image in the progress panel is now in size order, with the ones that were skipped in their right place rather than all at the end.

= 1.1.1 =
* Fixed thumbnails that were reported as built but still showed as missing. It affected images WordPress had scaled down on upload, where two image sizes have identical dimensions, such as the WooCommerce thumbnail and the WordPress thumbnail. Run Build Thumbnails once after updating to repair any affected images.

= 1.1.0 =
* The progress box now shows how long a run has been going and the average time per image, and keeps both in the finished line. A Cancel button stops a run cleanly, and the empty space down the left of the box is gone.
* The progress list now keeps every image from the run instead of the last twenty, shows each image's original dimensions, and says when a size was skipped because the image is smaller than it.
* The sizes panel on an image in the media library now says why a size was not made, and what size it would have been, instead of just not needed.

= 1.0.9 =
* Long size names on the Image Cleaner page no longer overflow their box; the pixel size drops to its own line. The size rows are striped to be easier to read.

= 1.0.8 =
* The Image Cleaner page now lists every image size, grouped into your sizes, WordPress, WooCommerce and anything else, with a tick per size. The scan, Build Thumbnails, a forced rebuild and Remove old sizes all work on just the sizes you tick. Your choice is saved against your login, so it does not change what anyone else sees.
* The Sizes list has an Edit Sizes link across to the Images page.
* Tidied the Images page: the size name fills the row with any clash warning underneath it, rows are separated, and the remove cross is clearer.
* Rebuilding thumbnails is much faster: each image is opened once and every size made from it, instead of being opened again for each size.
* Scans and orphan clean-ups no longer re-read the whole library between batches, and the scan counts everything in one pass.
* Remove old sizes now checks that nothing else uses a file before deleting it, so two sizes that share a file, or two library entries that point at the same file, are safe.
* In the media library, the sizes panel for an image loads when you open it rather than being built for every image on the page.

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

