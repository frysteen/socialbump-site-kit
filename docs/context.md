# SocialBUMP Site Kit

Notes for whoever picks this up next, most likely a new chat with no memory of
how any of it came about. Read this first.

**Keep it current.** Change how something works, add a feature, or learn
something painful, and write it here in the same session. A note that is wrong is
worse than no note, so fix anything you find that has gone stale.

That means all of it, not just the overview. Add a module and it gets its own
entry under the detailed list, describing what it does, how it does it, and what
it can be set to. Change how a setting behaves and the entry for that setting
changes with it. Add a setting to an existing module and add it to that entry.
The detail is the point: an overview that says a module exists helps nobody who
has to change it.

## What it does

The tweaks a SocialBUMP site expects, in one plugin: editor and excerpt tools,
image sizes and upload tidying, admin access and appearance, and a set of
WooCommerce corrections. It needs no page builder and runs on any site. Anything
that needs Bricks lives in Bricks Tweaks instead.

It does not ship base styles or ACF field groups. That was once the plan and the
readme still hints at it; if either ever arrives, it arrives as a module.

## How it is organised

Everything is a module, and every module can be switched off. A module that is
off is never loaded, so it costs nothing. Modules are grouped, and a group that is
switched on gets its own page in the menu.

| Group | What is in it |
| --- | --- |
| Content | Excerpt character counter, excerpts for pages, shortcodes in excerpts |
| Images | Image sizes, tidy file names and alt text on upload |
| Image Cleaner | Missing thumbnails, old sizes, orphaned files, and the sizes panel in the media library. Off by default |
| WooCommerce | Corrections and tweaks, listed below |
| Admin Settings | Block admin access, hide the front end toolbar, SocialBUMP admin colours |

The WooCommerce group only appears when WooCommerce is active, and its card greys
out and cannot be switched on without it. The modules in it:

- Download Analytics as CSV, buttons on the WooCommerce Analytics screens.
- Fix Default Category Title Bug, a WooCommerce bug from 10.9.4 that blanks the
  default category name in the admin list.
- From Price on Variable Products, in loops and optionally on the product page.
- Hide Empty Decimals on Prices.
- Require Login to Check Out. Greys out when guest checkout is on, and links to
  that setting.
- Show Both Prices in the Cart, the regular price struck through on a sale item.
- Turn Off Product Image Zoom, which WooCommerce has no setting for.

These were built on drivingevents.com.au, because the hub has no WooCommerce. See
the shared notes on working from a client site.

A group is on until switched off, unless its section carries default false, which
is how Image Cleaner starts off on a fresh site. A group with one module shows
that module's page as its own; Images and Image Cleaner are both like that.

The Modules page cards can be collapsed to their title, by the chevron or by
clicking the title, and put in any order with Reorder Cards. Both are per user,
kept in user meta by the shared SocialBUMP_Cards class, and alphabetical until
changed. The order is the same everywhere: ordered_groups() feeds the cards, the
tab bar, the sidebar submenu and the admin bar, with Modules first and Updates
and Publishing last. Saving an order also drops this menu's entry from Admin and
Site Enhancements' submenu order, so ASE stops sitting on top of it. Only the
Modules page does any of this; group pages stay plain.

## What each module does

Default says whether a fresh install has it on.

| Module | Default | What it does |
| --- | --- | --- |
| Excerpt Character Counter | on | Counts characters as you write an excerpt, in the block editor and the classic box, and warns when it runs long |
| Excerpts For Pages | off | Gives pages an excerpt field, which WordPress only gives posts |
| Shortcodes In Excerpts | off | Runs shortcodes written into an excerpt instead of printing them as text |
| Force Gutenberg Page Refresh on Save | off | Refreshes the editor once a save has finished, so you see what was actually saved |
| Images | on | The standard image sizes, plus tidy titles and alt text on upload, and the rebuild tools |
| SocialBUMP Admin Colours | on | Adds the SocialBUMP admin colour scheme, chosen under Users, Profile. Based on Midnight with our palette in place of the red |
| Hide Toolbar On The Front End | on | Tick the roles that lose the front end toolbar. Administrators keep their own profile setting |
| Block Admin Access | off | Sends the ticked roles back to the front end if they open wp-admin. Administrators are never affected |
| Fix Default Category Title Bug | on | WooCommerce blanks the default category name, edit link and row actions on Products, Categories. This puts them back |
| Download Analytics as CSV | off | A Download CSV button on the Analytics reports for the range on screen. WooCommerce normally builds it in the background and emails a link |
| From Price on Variable Products | off | The lowest price with a label, instead of a range. Loops by default, product page optional |
| Hide Empty Decimals on Prices | off | 10.00 shows as 10. Real decimals such as 9.99 are left alone |
| Require Login to Check Out | off | Sends a logged out customer to My Account, then straight back to the checkout |
| Show Both Prices in the Cart | off | On a sale item the cart shows the regular price struck through, so the saving is visible |
| Turn Off Product Image Zoom | off | Stops the magnifier over product images. WooCommerce has no setting for it |

## Every module in detail

What it does, how it does it, and what it can be set to. The hook named is the
one to look at first when something misbehaves.

### Content

**Excerpt Character Counter.** Counts as you type and warns when the excerpt runs
past the limit, which defaults to 160, the length Google tends to show. Loads on
admin_enqueue_scripts. It has to deal with three different editors: the block
editor sidebar, the classic excerpt box, and TinyMCE over the WooCommerce short
description. TinyMCE is the awkward one, because it arrives after the page has
loaded, so the script polls for it for ten seconds and binds to the editor own
events rather than to the textarea. It counts trailing spaces, because they count
in a meta description. Setting: max, a number.

**Force Gutenberg Page Refresh on Save.** The block editor saves in the
background and leaves you where you were, so anything the server changed during
the save is not in front of you until you reload by hand. This watches the
editor own save state through wp.data and reloads once it is done.

Three things it has to get right, and they are the whole module:

- An autosave is not a save you asked for, so it is ignored. Otherwise the page
  would reload every minute while you type.
- It waits for meta boxes as well as the post. WordPress sends those after the
  post itself, so reloading when the post save finishes would cut off whatever
  they were writing. ACF fields are the usual casualty. Tested with content and
  ACF fields together: both survive.
- A save that failed does not reload, so a dropped connection does not cost you
  your work.

The reload uses replace rather than a normal navigation, so the back button does
not walk back through every save, and it carries a flag that raises a Saved
snackbar afterwards, since the editor own notice does not survive a page load.
It only loads where the block editor is running: the classic editor reloads on
save already. Off by default, since it changes how saving feels.
**Excerpts For Pages.** Adds excerpt support to the page post type on init. One
line of work, but it means pages can carry a meta description and a card summary
like any post. No settings.

**Shortcodes In Excerpts.** Runs shortcodes written into an excerpt rather than
printing them as text, by filtering the_excerpt and get_the_excerpt, and also the
Yoast and Rank Math description filters so a shortcode in a meta description
resolves too. No settings.

### Images

**Images and Image Cleaner.** The largest part of the kit, and the one to be most
careful with. Two modules since the split. Images (modules/images) holds
SBSK_Images for the sizes and the settings page, and _Rebuild, the size engine:
building, clearing, present(), owned sizes. Image Cleaner (modules/image-cleaner)
holds _Tools for the page, the AJAX handlers and the attachment panels, _Orphans
for what is on disk but not in the database, and _Cleaner for its page. The
engine stays with Images because registering a size is what records it as ours,
and that has to keep happening whether or not the cleaner is on. The cleaner does
not need Images switched on: its boot loads the two Images classes itself, which
registers nothing, and it covers whatever sizes anyone has registered.

With the cleaner off nothing of it loads, and that is the point: the sizes panel
used to be built for every attachment the media library listed, about 600 file
checks a page, and the edit screens carried a stylesheet, a script and jQuery
for it. styles() only enqueues on upload.php, post.php and post-new.php while
the cleaner is on. The old rebuild_on switch became the cleaner's group switch:
sbsk_split_image_cleaner() in the main file carries it across once, records
scheme 2 in sbsk_image_split, and drops rebuild_on from the settings.

#### The rule that everything else depends on

A size counts as present only when its file is really there. Metadata is not
proof, and treating it as proof caused the worst bug in this module.

SBSK_Images_Rebuild::present( $id, $meta ) answers it, by checking each entry in
the metadata against the disk. missing(), missing_all() and the per attachment
panel all go through it. Anything new that asks what an attachment has must go
through it too.

What happened without it: a size was removed, its files were deleted, and the
metadata entries stayed behind. Adding the size back left an entry pointing at a
file that no longer existed, so the scan saw the entry, called it present, and
reported nothing to build. 638 images sat with no 480 and nothing said a word.
The same gap was showing in the details panel, which printed dimensions for a
file that was not there. Three places, one wrong assumption.

#### Which sizes the cleaner works on

The Image Cleaner page carries the list: every registered size, grouped as Image
Sizes (ours), WordPress, WooCommerce and Other Image Sizes, with all and none per
group and Select all and none at the bottom. Whatever is ticked is what the scan
counts, what Build fills in, what a forced rebuild remakes, and what Remove old
sizes clears. It replaced the picker that only appeared when forcing.

The choice is per user, in the sbsk_cleaner_sizes user meta, and everything is
ticked until a choice is saved. A name that is no longer registered is dropped on
the way out of chosen(), so removing a width cannot leave a stale tick behind.

It is a plain form posting to admin-post, not AJAX, so save-state.js does the
rest: the button stays quiet until a tick changes, the reminder follows you down
the page, and leaving with changes pending warns first. Reloading without saving
throws the ticks away, which is the point. The all and none links are marked
data-sb-always-on so the reminder never mistakes one for the save button, and
they dispatch a real DOM change event: save-state listens with addEventListener,
and a jQuery trigger never reaches it, which left the button disabled while the
ticks plainly changed.

The engine takes it as an $only argument on missing_all() and build(). Null
means every size, which is what the attachment panel and a single image rebuild
still pass.

It deliberately does not reach stale(), stale_files() or clean(). A size that has
been removed is no longer registered, so it can never appear in the tick list,
and filtering by that list meant removing a width left its files behind with the
scan reporting nothing to clear. Clearing old sizes always covers every removed
size, whatever is ticked.

wanted() returns nothing at all while the Image sizes switch is off, because
nothing is registered then, so every image-* file an attachment carries becomes
a leftover to clear. Reading the saved widths without checking the switch meant
turning the feature off left every file in place with the scan reporting nothing
to remove.

Each row is a flex line that wraps: a long size name such as
woocommerce_gallery_thumbnail pushes its pixels down to their own line, right
aligned, rather than out of the box. Rows are striped, every other one on a faint
grey, purely to be easier to read down a long list.

The progress box carries a clock on the right: how long the run has been going,
and the average per image, which is the half worth reading because it says
whether a large library means two minutes or twenty. Both are repeated in the
finished line. Cancel sits where the close cross goes, and they swap: Cancel
while it runs, the cross once it has stopped. Cancelling lets the batch in
flight finish and asks for nothing after it, so nothing is half written; the
line then reads Stopped rather than Finished. There is no thumbnail column any
more: each row in the log carries its own image, and the column only left a gap
down the left of everything.

The log keeps every image of the run rather than the last twenty, and the box
scrolls. Each row carries the file name, the original dimensions under it, the
sizes made, and any size that was asked for and not made, which says why. All of it used to
show as an image with nothing under it, reading like a failure.

skip_reason() works out the wording, and the cropped and uncropped cases are not
the same. A cropped size needs the original to be at least that big in both
directions, so a wide but short original fails on the height. An uncropped size
is a box to fit inside, so its height is a maximum, not a requirement: a
1920 x 1280 original needs no 1920 x 1920 large because it already fits, and
calling that image is smaller was simply wrong. The wordings are: already this
size when the original sits on an edge of the box, image is smaller when it fits
inside with room to spare, and not needed when it is bigger than the box, which
means something other than the dimensions stopped it and guessing would lie.

The sizes panel on an attachment says the same three things. A row that is not
there now gives the size it would have been and why it was not made, rather than
a bare not needed, which covered all three reasons at once and told you nothing. A clean
logs its rows too. log_item() in the tools class builds all of it.

A plain build only logs images something was actually done to, since it passes
over most of the library and a row per untouched image says nothing. A forced
run logs every image, because there the skipped ones are the interesting part.

#### How the engine and the tools keep their cost down

- make_sizes() decodes an image once and makes every wanted size from it with
  multi_resize(), which on both GD and Imagick works from the original pixels
  for each size. build() and rebuild() used to call wp_get_image_editor() per
  size: thirteen decodes of one photo for one rebuild. multi_resize() names
  files after the file it loaded, and the attached file can be the -scaled one
  while the thumbnails carry the original name, so each made file is moved to
  the base_name() the set uses, replacing what was there. Two sizes with the
  same dimensions share one file, thumbnail and woocommerce_thumbnail both
  being 300 x 300 cropped, so the first one moves it and the second finds its
  source gone: it has to point at where the file went instead. Missing that
  wrote metadata naming a file that was not there, and the size showed as
  missing straight after being built. It only shows on a -scaled original,
  where the names differ at all, and only with two sizes of equal dimensions,
  so a site without WooCommerce never sees it. Test rebuilds on a site that
  has both.
- can_make() is the one rule for whether a size is worth making, used by both
  missing_all() and rebuild() so forcing is never a smaller job than a plain
  build. A cropped size needs the original to be at least that big both ways;
  an uncropped size is a box, so it is worth making when the original
  overflows it either way. Judging on width alone made a forced rebuild skip
  a 1536 x 1536 on a 1281 x 1920 portrait, which a plain build would have made
  at 1024 x 1536.
- log_item() returns one list of rows, made and skipped together, smallest
  size first, so the log reads as the set of sizes rather than two lists stuck
  end to end.
- The Sizes to build figure is a button when it is not zero, and opens the list
  of images behind it: a row each, with the sizes that image is missing, a
  Rebuild by the name for the whole image and one by each size for that size
  alone. summary() collects the rows on the pass it already makes, capped at
  LIST_CAP images, because adding a width makes the whole library missing it
  and nobody reads six hundred rows. ajax_fix() does the work and returns what
  is left, so a row updates itself or disappears.
- clean() drops the metadata entry for a stale size, but the file only goes if
  file_has_other_owner() says nothing else uses it: another size of the same
  attachment with the same dimensions (medium at 480 and image-480 share one
  file), the original itself, or any other attachment whose metadata names
  the file. That last check is one LIKE on postmeta per stale file.
- A run holds its id list in a transient for ten minutes (sbsk_images_run_ per
  user; sbsk_orphans_run_ for the orphan list) instead of every batch fetching
  the whole library again. Scan starts a run fresh; the last batch clears it.
  The orphan run steps a whole batch on each time because the list is fixed;
  remove() checks every file again before touching it, so a list gone a
  little stale costs nothing.
- summary() is one pass over the library and gathers the per size totals the
  report needs on that pass; the report used to walk it all again. The orphan
  reference lookups, three unindexed queries per file, run once per file and
  the rows reuse the answer.
- The media modal panel is a placeholder until its pane is on screen, then
  sbsk_images_panel fetches the list. attachment_fields_to_edit runs for every
  attachment the library sends to the browser, forty a page, and the list is a
  file check per size, so building it there cost hundreds of stats a page for
  panes nobody opened. admin.js watches the page for new panes with a
  MutationObserver and fills each once. The full edit screen still builds its
  meta box in place, one image, no cost worth saving.

#### Find leftover thumbnails (the deep scan)

Three things can be wrong with a size file, and each needs its own check.

- Missing: a registered size with no file. missing_all(), fixed by Build.
- Removed: a file the metadata still names, for a size the kit no longer
  registers. stale(), fixed by Remove old sizes, and only ever our own names.
- Unaccounted: a file on disk that nothing in the database names, whose
  dimensions no registered size would produce. unaccounted(), fixed by the
  deep scan.

The third one exists because the orphan scan deliberately gives a free pass to
any file whose name matches a real attachment, through belongs_to_attachment(),
so that a WebP sibling or a thumbnail missing from its metadata is never
deleted. The cost is that an old theme's photo-1300x200 is invisible: not an
orphan, because it looks like it belongs, and not a removed size, because it
was never in the metadata at all. On an old site that is most of the junk.

unaccounted() walks the uploads folders, takes every file whose name ends in a
WxH, and keeps the ones where the base is a real attachment, nothing in the
database names the file, and those dimensions are not among the ones
expected_dimensions() says the currently registered sizes would produce for
that attachment. That last test is what protects a valid thumbnail whose
metadata went missing: it would be rebuilt at those exact dimensions, so it is
left alone. expected_dimensions() is pure arithmetic through
image_resize_dimensions(), no files touched.

Results are grouped by dimensions, not by image, because that is the shape a
person can judge: 1024 x 750, 412 files, 180 MB is recognisably the old
WordPress large, where 412 file names would tell you nothing. You tick groups
and confirm; nothing is deleted by scanning. The list carries a red heading, the
instruction, and one line saying a plugin switched off right now will appear
here as well. That last line is the only way this feature can bite, so keep it.

The panel has its own cross, and a normal Scan images clears it, because by then
it is the answer to the previous question. The quiet re-scan after a deletion
leaves it alone, since that run redraws it with what is left.

Deleting runs in batches of DEEP_BATCH files, the browser calling until done,
behind the same bar the rebuild uses, with the count under it. Each batch is checked again server side: still
unaccounted, not kept, and not referenced, through references_many(), which
does the three LIKE scans once per fifty names with the names OR'd together
rather than three per file. That is the slow part of a deletion, and on a big
database it is still a few seconds per fifty, so a thousand files is a couple
of minutes; per file it was closer to ten. Files left alone come back with a
reason and are listed above the refreshed panel rather than quietly dropping
out of the count.

Image optimisers keep a record of every file they have compressed, which
mentions the file without using it. WPvivid's stopped a plainly stale thumbnail
being deleted. Those meta keys are in bookkeeping_keys(), filterable, and both
reference checks ignore them. Each group opens to the files behind
it, with a thumbnail, the file name and a link that opens it in a new tab, capped
at LIST_CAP per group, because dimensions alone are not enough to judge a
deletion by.

The honest limit, and it is on the screen: a size is only known to be
registered if something registers it now. Deactivate WooCommerce and its three
sizes look unaccounted. That is why this is ticked and confirmed rather than a
single button, and why remove_unaccounted() recomputes the list and rechecks
is_kept() and references() per file rather than trusting the paths the browser
sends.

#### Sizes

- Registered on after_setup_theme, and added to the editor size chooser through
  image_size_names_choose.
- Widths live in the module settings. Saving them changes what the site wants;
  it does not touch a single file. The scan, then Build or Clear, brings the
  disk into line. That is deliberate: on a few hundred images it is a long job
  and belongs behind a progress bar, not a form submit.
- Never calls wp_create_image_subsizes(). That regenerates everything from the
  original and loses manual crops. It builds size by size instead.
- Sizes the kit did not create are left alone. sbsk_owned_image_sizes records
  what it made, so a theme size is never cleared as though it were ours.
- Removing a size deletes the file, the WebP beside it, and the metadata entry,
  in one pass. The metadata matters as much as the file: an entry pointing at a
  deleted file makes WordPress hand out a URL for an image that is not there.

#### Rebuilding

- Batches of five over AJAX: sbsk_images_count, then sbsk_images_batch, then a
  final sbsk_images_report. Each request is told its offset, so nothing depends
  on order and nothing is redone.
- A request that comes back empty is retried once, one image at a time, and only
  a second failure is reported. Each request starts where the last finished, so
  a failure costs nothing and what is built is kept.
- All three handlers raise the time limit. Measured on a 672 image library the
  scan takes well under a second, so slowness was not the cause of the one
  failure seen in the wild: the rebuild had finished and a later request simply
  never answered. The raised limits are insurance for a slower host.
- The progress log gives each image its own row: its own thumbnail, its name,
  and the sizes built for it underneath. It used to show one thumbnail beside a
  batch of names, which never matched what you were reading.

#### The orphan scan

- Compares the uploads folder against every place the database records a file:
  _wp_attached_file, _wp_attachment_metadata, and _wp_attachment_backup_sizes.
- That last one is easy to forget and was missed at first. Editing an image in
  WordPress writes a new set of files and keeps the old ones so Restore original
  image still works, and those are recorded only in the backup meta. Without
  reading it, every edited image contributed a pile of false orphans, and
  deleting them would have quietly taken the undo away. If a site ever shows
  orphans that are plainly in use, look for another meta key like that one.
- Every known file is recorded along with its WebP twin, since an optimiser
  writes photo.jpg.webp, keeping the original extension in front. The scan
  strips the .webp before checking the extension, so those count as images.
- A leftover thumbnail from an attachment that no longer exists is found, and
  so is its WebP. Tested by planting three such files and scanning.
- It walks the uploads root and its year and month folders only. A folder
  belonging to another plugin is left alone on purpose.
- Anything referenced anywhere, or marked Keep, is listed but never bulk
  deleted, and the button counts only what it will actually remove. A list of
  fourteen with a button offering two is correct, though it reads like a fault.
  sbsk_kept_orphans holds what you told it to leave.
- Each row shows a thumbnail and, where the file can be traced, the attachment
  id as a grey pill linking to the media library. Tracing tries the filename,
  then the same name with a size suffix stripped, then the backup data.

#### On an attachment

The list of sizes appears in two places, and they are not the same mechanism.

- The full edit screen gets a real metabox, SocialBUMP Site Kit Sizes, through
  add_meta_boxes_attachment. It can be dragged and collapsed like any other.
- The media modal cannot have metaboxes at all, so the field built through
  attachment_fields_to_edit carries its own postbox markup: an empty label, and
  html holding a div.postbox with a postbox-header and an inside. That is how
  the Replace Media panel does it, and it is the only way to get a title bar in
  the modal.
- The field bows out when the screen base is post, or the edit screen would
  show the same thing twice.
- WordPress lays an extra field out as a table row: a label column, and a field
  cell floated right at 65 per cent to sit beside it. With an empty label that
  leaves a gap, so the CSS takes the label column to zero and drops the float,
  the width and the 1px margin from the field. The margin is the part people
  miss: it is why a full width float overflows, and why other plugins use 99.8
  per cent instead of removing it.
- Each size that exists links to that file. A missing one stays plain text,
  since there would be nothing to open.

#### Uploads

- On add_attachment the file name is tidied and alt text filled in from the
  title, so an upload called DSC_0042 does not end up as alt text. Alt text
  written by hand is left alone, and the file on disk is never renamed.

### Admin Settings

**SocialBUMP Admin Colours.** Registers a colour scheme on admin_init, based on
Midnight with the SocialBUMP palette in place of the red, chosen per user under
Users then Profile. The force setting applies it to everyone by filtering
get_user_option_admin_color, which is how a client site ends up looking the same
for whoever logs in. It also styles the block editor through enqueue_block_assets.
Worth knowing: all three plugins read the current scheme for their accent colour,
so this module quietly decides how the others look.

**Hide Toolbar On The Front End.** Filters show_admin_bar for the roles you tick.
Administrators are left to their own profile setting, so you cannot lock yourself
out of the toolbar. Setting: roles, a list of checkboxes.

**Block Admin Access.** On admin_init, sends the ticked roles back to the front
end if they try to open wp-admin, to a URL you choose. AJAX requests are let
through, or half the front end would break for those users. Administrators are
never affected, deliberately and without an option to change it.
Settings: roles, and redirect.

### WooCommerce

The whole group hides when WooCommerce is not active, and every module in it
declares woocommerce in requires. These were built on drivingevents.com.au,
because the hub has no WooCommerce to test against.

**Fix Default Category Title Bug.** On by default, because it fixes something
broken rather than changing a preference. Since WooCommerce 10.9.4 the default
product category shows with no name, no edit link and no row actions on Products,
Categories, which makes it look corrupted. WooCommerce prints a script in the
footer that rewrites that row; this removes that script on current_screen and
prints its own on admin_footer, which puts the name and links back and appends
the tooltip after the name rather than replacing it.

**Download Analytics as CSV.** WooCommerce builds an export in the background and
emails a link, which is slow and often never arrives. This adds a Download CSV
button that exports the range on screen there and then. The Analytics screens are
React, so the script polls for the toolbar to appear rather than assuming it is
there, and reads the report data WooCommerce has already fetched. On the Overview
page a Download All button zips every report together.

**From Price on Variable Products.** Filters woocommerce_get_price_html to show
the lowest price with a label in front of it instead of a range, so a product
reads From $49 rather than $49 to $149. Settings: on_loops, on by default;
on_single, off, since a product page usually wants the full range; and label, the
wording in front of the price.

**Hide Empty Decimals on Prices.** Filters woocommerce_price_trim_zeros so 10.00
shows as 10. A price with real decimals, 9.99, is untouched. Sounds trivial, but
on a store with round prices it removes a lot of visual noise.

**Require Login to Check Out.** On template_redirect, a logged out customer
reaching the checkout is sent to My Account, and the WooCommerce login and
registration redirects send them straight back to the checkout afterwards, so the
cart is not lost. The module greys itself out when WooCommerce guest checkout is
switched on, since the two contradict each other, and links to that setting.

**Show Both Prices in the Cart.** Filters woocommerce_cart_item_price so a sale
item shows the regular price struck through next to the sale price. The saving is
visible on the product page but disappears in the cart, which is exactly where it
matters. Off by default, because it changes a customer facing layout.

**Turn Off Product Image Zoom.** Dequeues the zoom script on wp. WooCommerce has
no setting for it, so it has to be switched off in code. Worth reaching for when
the magnifier fights a lightbox or a custom gallery.

## The files, and what each one is for

| File | What it is |
| --- | --- |
| socialbump-site-kit.php | constants, updater, hub check, sbsk_log_change(), loads everything |
| includes/class-sbsk-settings.php | the Modules page, group pages, banner, menu, admin bar |
| includes/class-sbsk-modules.php | finds every module and works out what can run |
| includes/class-sbsk-release.php | publishing, hub only |
| includes/class-sbsk-updates.php | the Updates page |
| includes/class-sbsk-transfer.php | settings export and import |
| includes/class-sbsk-docs.php | these notes and the Publishing panel |
| assets/js/admin.js | the module cards, the image tools, the rebuild progress |

Every module is includes/modules/<slug>/, with a module.php and one class file
per job. The ones with more than one file:

| Module | Files | Why more than one |
| --- | --- | --- |
| images | class-sbsk-images.php, -rebuild.php, -tools.php, -orphans.php | the sizes, the batch rebuild, the media tools, and sizes the kit did not create |
| admin-colour-scheme | class plus assets | registers the SocialBUMP scheme, which the other plugins read for their accent |
| excerpt-counter | class plus assets | has to cope with TinyMCE arriving late |
| woo-analytics-csv | class plus assets | the buttons are injected into a React screen, so the script polls for it |

The rest are a module.php and a single class: admin-access, front-toolbar,
excerpt-shortcodes, page-excerpts, and the six other WooCommerce modules.

## Writing a module

A folder under includes/modules/<slug>/ with a module.php returning an array:
id, title, description, section, default, requires, optional settings and
features, and a boot callback that does the work.

- requires names what it needs: acf, bricks, woocommerce. A module that needs
  something missing shows why, and cannot be switched on.
- Write the class file before the module.php that loads it, so a failed write
  never leaves a module pointing at a file that is not there.
- A module with its own settings page provides admin_page with a title,
  description and render callback.
- A fatal inside one module is caught, so it cannot take the site down.

## Images, the one with teeth

The Images module owns the registered sizes, rebuilds thumbnails in batches, and
decides what in the uploads folder is rubbish. It can delete files, so it gets
its own warning here. The full account is under Every module in detail; these
are the three rules worth reading before you touch it.

- A size counts as present only when its file is really there. Never ask the
  metadata on its own. SBSK_Images_Rebuild::present() is the only answer.
- Never call wp_create_image_subsizes() on a rebuild. It regenerates from the
  original and can lose crops. The rebuild works size by size instead.
- Only touch what we made. sbsk_owned_image_sizes records what the kit created,
  sbsk_kept_orphans what it was told to leave, and files WordPress keeps so an
  image edit can be undone are never treated as strays.

## What it stores

| Name | Holds |
| --- | --- |
| sbsk_modules | which modules are on |
| sbsk_groups | which groups are on |
| sbsk_module_settings | each module settings |
| sbsk_owned_image_sizes | image sizes the kit created |
| sbsk_kept_orphans | files in uploads it was told to leave alone, kept as paths relative to the uploads folder |
| sbsk_image_split | that the Image Cleaner split migration has run, and which scheme |
| sbsk_cleaner_sizes (user meta) | the sizes each user has ticked on the Image Cleaner page |
| sbsk_images_run_<user>, sbsk_orphans_run_<user> (transients) | the id or path list held for a run, ten minutes |
| socialbump_cards (user meta) | each user's card order and collapsed cards, per page key |
| sbsk_github_token | encrypted, hub only, and deleted on any site that is not the hub |
| sbsk_pending_changes | notes for the next release, hub only, deleted elsewhere |

## Where to be careful

- Saving one group page must not wipe another. The save starts from what is
  already stored and updates what was submitted. It once started from an empty
  array, which wiped every other group. Do not undo that.
- The excerpt character counter has to cope with TinyMCE arriving late, which is
  how the WooCommerce short description field behaves. It polls, binds to editor
  events, and counts trailing spaces, because they count in a meta description.
- The admin colour scheme module registers the SocialBUMP scheme. Other code
  reads the current scheme for its accent, so changing it affects all three.
- Anything that reports on image sizes must go through present(). Three separate
  places trusted the metadata instead, and all three quietly lied: the library
  scan, the per image check, and the attachment panel.
- A form can hold more than one submit. If you add one that is not a save, mark
  it data-sb-always-on, or the unsaved changes reminder may submit it instead of
  the save.
- When testing a change in the same request that wrote the file, the old class
  is already loaded and you will see the old behaviour. Check in a fresh
  request before believing a change did not work. This wasted time twice.
- A module's unavailable callable must never call is_enabled() on another
  module: is_enabled() asks every module in the group whether it is unavailable,
  so that goes round in a circle. Read get_states() instead. The cleaner had a
  dependency on Images written that way before it became its own group.
- render_other_sizes() reads a note key that editable_sizes() does not set;
  it is read with empty() now. Reading it blind warned on every size row.
- Remove old sizes still only ever deletes sizes the kit registered itself.
  Ticking a WordPress or a third party size on the cleaner page lets it be
  built, never cleared. Do not widen that.
- Anything that changes a form field from script has to dispatch a real DOM
  event for save-state.js to see it. jQuery trigger does not.

<!-- shared:start -->

## House rules, shared by all three SocialBUMP plugins

This block is identical in the docs of all three plugins. Change it in one and
copy it to the other two in the same session. They all live on the hub, so that
is a two minute job, and the Publishing page warns you when they have drifted.

### The three plugins

| Plugin | Folder | Prefix | Menu |
| --- | --- | --- | --- |
| SocialBUMP Bricks Tweaks | socialbump-bricks-tweaks | SBBT_ / sbbt_ | SB Bricks Tweaks |
| SocialBUMP Site Kit | socialbump-site-kit | SBSK_ / sbsk_ | SB Site Kit |
| SocialBUMP SEO for AI | socialbump-ai-knowledge-exporter | SBAIKE_ / sbaike_ | SB SEO for AI |

SEO for AI was called AI Knowledge Exporter until September 2026. Its folder,
text domain, option names and GitHub repo still say so, deliberately: renaming
them would break the update checker and the saved settings on every site.

Which plugin does a job belong in? Needs the Bricks theme, Bricks Tweaks.
Useful on any site, Site Kit. About what AI crawlers read, SEO for AI.

### Files that are identical in each plugin

- includes/class-socialbump-admin-bar.php
- assets/js/save-state.js

Change one, change all three, then check the md5s match. Both are written so
that whichever plugin loads first wins and the others stand aside, so a site
running mixed versions still works.

### The Modules page cards

SocialBUMP_Cards and module-cards.js give a page of cards a chevron to collapse
each to its title (the title toggles too), Collapse all, Expand all and Collapse
disabled links above the grid, and a Reorder Cards button below it that opens a
list to drag. Order and collapsed state are per user, in user meta, alphabetical
until changed, and saved over AJAX as they change, never through the form. A
plugin wires it with register( prefix, menu slug ) at boot, sort() for the order,
container_attributes() on the grid, card_attribute() on each card, and toolbar()
twice, links above and reorder below. A card's first child must be its head. The
saved order is meant to drive the menu, the tab bar and the admin bar as well,
and saving one drops that menu's entry from ASE's submenu order. The CSS is the
chunk at the end of Site Kit's admin.css marked Shared, generic .sb- classes,
to be copied as is. Both files are identical wherever they exist.

### The shared admin bar item

SocialBUMP_Admin_Bar::register() takes id, label, href and items, and optionally
actions, attention, attention_title and current. Everything is drawn once, at
admin_bar_menu priority 200.

- One plugin active: that plugin sits on the bar on its own.
- Two or more: a single SocialBUMP item, each plugin a row inside it, its pages
  on a flyout from that row.
- Each row has a dot: green when there is nothing to do, amber when there is.
  Any amber row makes the SocialBUMP dot amber, so the top of the bar is the
  only thing that needs watching.
- attention means an update is waiting. SEO for AI also counts stale posts.
- The current page is white and bold, never the admin colour scheme accent:
  some accents are unreadable on the dark bar.
- An action row marked sb-bar-action is-idle looks inactive and ignores hover.

Two signals, and they mean different things. Keep them apart:

- The dot is about this site: content waiting to be rebuilt, an update ready to
  install. It is what someone looking after the site cares about.
- Amber wording, and a small count beside it, is about the hub: changes noted but
  not yet released. Publishing carries it, through attention and count on that
  item. Never fold this into the dot, and never colour the dot for it: on a client
  site there is nothing to publish and the distinction is the whole point.

### Getting between the pages

The banner carries a row of links to every page in the plugin, with the one you
are on marked. The admin menu lists them too, but on a long menu the plugin can
be a scroll away and its pages only show while you are already on one of them.

- Updates shows the new version number when one is waiting.
- Publishing shows how many changes are queued, and only exists on the hub, so a
  client site gets a shorter row and no badges.
- render_nav() builds it from bar_items(), the same list the admin bar uses, so
  a new page appears in the menu, the admin bar and the banner at once.
- It hides itself when a plugin has fewer than two pages.
### The SocialBUMP Hub page

class-socialbump-overview.php, identical in each plugin, same arrangement as the
admin bar: first to load defines the class, the others register with it.

- A top level SocialBUMP Hub menu, but only on the hub and only when more than
  one plugin is active. It therefore disappears by itself on every site built
  from the blueprint, which is the point: there is nothing to publish there.
- A card per plugin: version, whether an update is waiting, how many changes are
  queued for the next release, and links to its pages. The count is an amber pill
  that jumps down to that plugin publishing panel.
- Below that, each plugin publishing panel in turn, with the plugin name slid in
  as the heading inside the panel, so all of them go out from one screen.
- The item in the admin bar opens this page when it exists, and the first
  plugin otherwise.
- Between the cards and the publishing panels sits a master prompt for starting a
  chat that could touch more than one plugin. It builds itself from whatever is
  registered, so a fourth plugin would appear in it without being told, and it
  covers what the per plugin prompts cannot: that shared code lands everywhere,
  and that the shared block of the notes must stay identical in every copy.

register() takes id, name, version, file and pages, and optionally notes, css,
css_time, logo, accent_var, hub, and release, a callback that draws that plugin
publishing panel.

Two things about the page are easy to get wrong, and both have been:

- It belongs to no plugin in particular, so it loads every registered stylesheet,
  and each one is versioned by when the file changed rather than by the plugin
  version. Version it by the plugin and a browser serves yesterday CSS after every
  edit, which is exactly what happened.
- Each plugin styles itself from its own CSS variable, and nothing sets those on a
  page that belongs to none of them, so the page works out the accent itself and
  sets every registered variable. Without that the panels fall back to the
  WordPress blue and look nothing like the rest.

### After an update

Updating a plugin swaps its files out mid request. If you were on one of its own
pages, the page you land on afterwards can still be running the old code, so its
menus never register and the plugin appears to have vanished until you navigate
somewhere else. Each plugin now clears the compiled copies of its own files on
upgrader_process_complete, which settles it.

The Update now button on each Updates page goes through update-core.php, the
bulk path the dashboard uses: maintenance mode on, files swapped, maintenance
mode off, plugin never deactivated. It used to go through update.php, the
single plugin path, which deactivates the plugin first and does not reactivate
it in PHP at all: the results page carries a hidden iframe that loads
update.php?action=activate-plugin, and that iframe is the reactivation. Leave
the page before it loads, or have anything block it, and the plugin stays off
with nothing in any log. That happened twice on a client site. Keep the bulk
path.

### What a client site must not carry

The hub is the blueprint new sites are built from, so whatever is in its database
travels with every copy. On any site that is not the hub, each plugin deletes its
GitHub token, its queued release notes and its release cache when an admin page
loads. A token has no business on a client site.

If you add anything else that only the hub should know, delete it there too.
### Unsaved changes, and the save button

Any form marked data-sb-dirty is watched. The save button sits disabled reading
Nothing to save until something changes, then wakes up with its own wording and
an amber reminder appears top right and follows you down the page. Put the change
back the way it was and both go quiet. Leaving with something unsaved warns you.

Attributes a button can carry:

- data-sb-save: treat as a save button even though it is not a submit.
- data-sb-label-dirty: the wording to use when there is something to save, for a
  button whose resting label says there is nothing.
- data-sb-always-on: never disable this one. Used for buttons that do work
  rather than save, such as Full Rebuild, and for any submit that is an action
  rather than a save, such as Reset to defaults.
- data-sb-idle=1: nothing to run right now, so sit inactive until there is.

The reminder saves with the button that actually saves: one marked data-sb-save,
then the primary button, and only then the first submit in the form. A form can
hold more than one submit and not all of them save. The image sizes form has
Reset to defaults sitting above Save changes, and the reminder used to submit
whichever came first, so clicking it reset the sizes rather than saving them.
Worth remembering when adding any second submit to a form.

Styling: .sb-save--clean is a grey outline on transparent, .sb-save--dirty is
pale yellow with an amber border, matching the reminder. Both selectors lead with
.wp-core-ui and .button, because WordPress styles disabled and primary buttons
with important and would otherwise win.

### The look

- One stylesheet per plugin at assets/css/admin.css, every class prefixed.
- Dark banner: SocialBUMP logo, plugin name, page name in a span in the accent
  colour, then a version badge linking to Updates that turns amber when a
  release is waiting. The heading reads plugin name then page name, including on
  a landing page: Site Kit Modules, Bricks Tweaks Features, SEO for AI Content.
- Panels: prefix-section, with __head for the heading and description and __body
  for the content.
- Cards: prefix-card, is-on for a live one, is-unavailable for one waiting on
  something missing. The left edge carries the accent when live.
- Pills: prefix-status__pill, is-good green, is-stale amber. An amber one that
  can be acted on is a link, and clicking it does the thing it describes.
- Menu icon: the SocialBUMP exclamation, shared by all four items through
  SocialBUMP_Overview::brand_icon(). Each plugin positions its menu next to the
  others rather than at a fixed spot.
- WordPress does not recolour an SVG menu icon. It only recolours Dashicons,
  which are a font. An SVG given as a menu icon becomes a background image and
  keeps whatever colour is baked into it, so ours is white and the dimming when
  idle, and the brightening on hover, are done in CSS to match the icons around
  it. Build the SVG by concatenation with chr( 34 ): a quote mangled in the
  middle of it produces markup that silently draws nothing.
- Publishing lays out as two columns: the token and the zip stacked on the left,
  publishing beside them. The cards are placed with CSS grid rather than
  reordered, so the markup and the reading order stay as they are.
- The accent comes from the admin colour scheme, chosen by saturation so a
  washed out swatch is never picked, and exposed as --prefix-accent.

### Releasing

Everything is developed and released on the hub, bricks.socialbump.com.au. Each
plugin decides it is on the hub by host name, and only then loads its release
code and shows a Publishing page.

- Publishing pushes the code to GitHub, builds a zip, creates a release and
  attaches the zip. Sites update through the plugin update checker.
- One fine grained GitHub token per plugin, stored encrypted, scoped to that one
  repo with Contents read and write. A token cannot create repositories, so a new
  repo is made by hand first.
- The notes box fills from prefix_log_change() calls made since the last release,
  and the list empties once a release goes out. Call it after any change worth
  telling someone about, in their words rather than yours.
- Only log what a client site would notice. The Hub page, the Publishing page and
  anything else that exists only on the hub never reach a client site, so a change
  to them earns no note and no release of its own. It rides along with the next
  real one. A release exists to tell other sites something changed for them.
- Publishing retries on a 5xx, checks the zip actually attached, and checks again
  before undoing anything, because GitHub has published a release and then failed
  the response.
- The first release may carry the version already in the files. Every release
  after that has to be higher than the last.
- A version needs all three parts, so 1.1 is padded to 1.1.0 when you leave the
  field, and again on save in case the form never lost focus. Typing 1.1 used
  to get you the browser complaining about a pattern it does not explain.
- Everything in the plugin folder is published except .git, .github, node_modules
  and .DS_Store. These docs ship with the plugin, so they reach every site, and
  the repos are public: nothing private goes in them.

### How work actually gets done here

There is no local checkout and no git client. Everything happens on the live hub
through its Novamira MCP connector, by running PHP on the site. That shapes how
to work:

- Read a file with file_get_contents, write it with file_put_contents.
- Lint before you write. Put the new contents in a temporary file, run php -l on
  it, and only write the real file when it passes. A fatal in a plugin file takes
  the site down, and you are editing the site you would need to fix it.
- JavaScript has no linter here. Walk the brackets, minding strings, comments
  and regular expressions, before writing.
- Call opcache_invalidate() on a file after writing it.
- A class already loaded in the current request is still the old one. Check your
  work in a fresh call, not the one that wrote the file.
- Anchor edits on a unique string and check it matches exactly once. If it
  matches twice, widen it until it does not.
- Keep a copy before a risky edit. copy( $file, sys_get_temp_dir() . ... ) costs
  nothing and has saved a rewrite more than once.
- Verify after every write. A write that silently did nothing, because the anchor
  never matched or the function returned early, has cost more time here than any
  actual bug.

Watch out for quoting when building PHP through a JSON tool call. A backslash in
a regular expression, or a quote in a string, has to survive JSON, then PHP, then
whatever it is written into. Building strings with chr( 34 ) and concatenation is
uglier to read but far less likely to arrive mangled.

### Where things live

The hub is bricks.socialbump.com.au, and the plugins are in the usual place:
wp-content/plugins/<folder>/. Client sites each have their own connector and the
same folder structure.

Every plugin has the same shape:

| File | What it is |
| --- | --- |
| <plugin>.php | constants, updater, hub check, log_change(), loads everything |
| includes/class-<pre>-settings.php or -admin.php | menu, pages, banner, admin bar registration |
| includes/class-<pre>-modules.php | finds and boots the modules |
| includes/class-<pre>-release.php | publishing to GitHub, hub only |
| includes/class-<pre>-updates.php | the Updates page and the update check |
| includes/class-<pre>-transfer.php | settings export and import |
| includes/class-<pre>-docs.php | these notes, and the panel on Publishing |
| includes/class-socialbump-admin-bar.php | shared, identical in all three |
| includes/class-socialbump-cards.php | shared: collapsible, reorderable cards. Site Kit has it; the others get it with their Modules pages |
| assets/js/module-cards.js | shared, goes with the cards class |
| assets/css/admin.css | everything the admin pages look like |
| assets/js/save-state.js | shared, identical in all three |
| vendor/plugin-update-checker | the updater library, left alone |

### Working on a plugin from a client site

Work on the hub by default. Build on a client site only when it has something
the hub has not, which in practice means WooCommerce: the WooCommerce modules in
Site Kit were built on drivingevents.com.au for that reason.

When you have, bring it home carefully. Assume nothing at any step:

1. List both folders and compare every file by md5 and by modified time. Not just
   the files you think you touched: a file you did not expect to differ is
   exactly the one worth knowing about.
2. For each file that differs, work out which side is newer and why before you
   move anything. The hub may have moved on while you were working elsewhere, and
   the client copy may be an older release rather than your new work.
3. Read any file the hub has changed, in full, before overwriting it. Two people
   editing the same file from different directions is how work disappears.
4. Copy back only the files that genuinely differ, one at a time.
5. Compare the md5s again afterwards and confirm each one matches.
6. Update these docs on the hub, never on the client site.
7. Call prefix_log_change() on the hub, so the work appears in the next release.

If the two sides have both changed the same file, stop and say so rather than
picking one. Merging by hand with both versions in front of you takes minutes.
Guessing wrong costs whatever was on the losing side, and nobody finds out until
later.

Nothing may live only on a client site. The next update overwrites the plugin
folder, and anything not carried back to the hub is gone.

### Habits that have paid off

- Lint every PHP file before writing it, and bracket check any JavaScript.
  Write to a temporary file, lint that, and only then put it in place.
- Write a class file before the loader that requires it, so a failure never
  leaves a plugin pointing at a file that is not there.
- Keep each edit small and check it took. A write that silently did nothing has
  cost more time here than any bug.
- Anchor edits on unique strings. If an anchor matches twice, stop and widen it.
- After editing a file, the class already loaded in that same request is still
  the old one. Verify in a fresh request, not the one that wrote the file.

### Things learned the hard way

- PHP declares top level classes and functions while compiling the file, before
  a line of it runs. A class_exists() guard inside the file that declares the
  class always sees its own class and returns, and the file never finishes. This
  broke SEO for AI once. Guard by other means.
- WordPress styles disabled and primary buttons with important. Beat it with
  specificity, not with another important on its own.
- admin_head has already been sent by the time the admin bar is built, so a
  style hooked only there never appears. Hook the footer as well.
- A settings page that submits only part of the settings must merge rather than
  replace, or saving one page wipes the others. Site Kit and SEO for AI have both
  had this bug. Both now post a marker of which sections were on the page.
- An element with no link is rendered by the admin bar as an empty item, not an
  anchor, so style both.
- Nested admin bar flyouts need position relative on the row, or they fly off
  to the right of the whole menu.
- The admin menu can be renamed by an admin menu plugin. Admin and Site
  Enhancements holds its own titles and wins over whatever the plugin registers.
- opcache_invalidate() only reaches the PHP process it runs in. On a LiteSpeed
  host with opcache.revalidate_freq set to 60, every other process keeps running
  the old file for up to a minute after a write. A rebuild started in that
  window ran half on old code and half on new, and stamped the cache both ways.
  A fresh request is not proof until a minute has passed, and nothing that
  writes stamps or data formats should be exercised in that minute.

<!-- shared:end -->
