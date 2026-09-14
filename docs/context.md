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
| Images | Image sizes, tidy file names and alt text on upload, rebuilding thumbnails |
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

## What each module does

Default says whether a fresh install has it on.

| Module | Default | What it does |
| --- | --- | --- |
| Excerpt Character Counter | on | Counts characters as you write an excerpt, in the block editor and the classic box, and warns when it runs long |
| Excerpts For Pages | off | Gives pages an excerpt field, which WordPress only gives posts |
| Shortcodes In Excerpts | off | Runs shortcodes written into an excerpt instead of printing them as text |
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

**Excerpts For Pages.** Adds excerpt support to the page post type on init. One
line of work, but it means pages can carry a meta description and a card summary
like any post. No settings.

**Shortcodes In Excerpts.** Runs shortcodes written into an excerpt rather than
printing them as text, by filtering the_excerpt and get_the_excerpt, and also the
Yoast and Rank Math description filters so a shortcode in a meta description
resolves too. No settings.

### Images

**Images.** The largest module in the kit, and the one to be most careful with.
Four classes: the sizes themselves, the batch rebuild, the media library tools,
and orphan handling.

- Registers the standard sizes on after_setup_theme, and adds them to the size
  chooser in the editor through image_size_names_choose.
- On add_attachment it tidies the file name and fills in alt text from the title,
  so an upload called DSC_0042 does not end up as alt text.
- Rebuilding runs over AJAX in batches, like the exporter: sbsk_images_count then
  sbsk_images_batch, with a progress bar, so a big library cannot time out.
- Never calls wp_create_image_subsizes(). That regenerates everything from the
  original and loses manual crops. It rebuilds size by size instead.
- Sizes the kit did not create are left alone. sbsk_owned_image_sizes records what
  it made, sbsk_kept_orphans records what it was told to leave. The orphans screen
  is how you decide what to do with a size from a theme or a plugin that has gone.

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

The Images module owns the registered image sizes for the site, and can rebuild
thumbnails in batches with a progress bar. Two rules:

- Never call wp_create_image_subsizes() on a rebuild. It regenerates from the
  original and can lose crops. The rebuild works size by size instead.
- Sizes the kit did not create are left alone. sbsk_owned_image_sizes records
  what it made, and sbsk_kept_orphans what it was told to leave.

## What it stores

| Name | Holds |
| --- | --- |
| sbsk_modules | which modules are on |
| sbsk_groups | which groups are on |
| sbsk_module_settings | each module settings |
| sbsk_owned_image_sizes | image sizes the kit created |
| sbsk_kept_orphans | image sizes it was told to leave alone |
| sbsk_github_token | encrypted, hub only |
| sbsk_pending_changes | notes for the next release |

## Where to be careful

- Saving one group page must not wipe another. The save starts from what is
  already stored and updates what was submitted. It once started from an empty
  array, which wiped every other group. Do not undo that.
- The excerpt character counter has to cope with TinyMCE arriving late, which is
  how the WooCommerce short description field behaves. It polls, binds to editor
  events, and counts trailing spaces, because they count in a meta description.
- The admin colour scheme module registers the SocialBUMP scheme. Other code
  reads the current scheme for its accent, so changing it affects all three.

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
  rather than save, such as Full Rebuild.
- data-sb-idle=1: nothing to run right now, so sit inactive until there is.

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
- Publishing retries on a 5xx, checks the zip actually attached, and checks again
  before undoing anything, because GitHub has published a release and then failed
  the response.
- The first release may carry the version already in the files. Every release
  after that has to be higher than the last.
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

<!-- shared:end -->
