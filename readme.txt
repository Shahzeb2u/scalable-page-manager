=== PageScale ===
Contributors: shahzeb2u
Tags: pages, admin, performance, search, page management
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast admin manager for sites with tens of thousands of pages or custom post type entries: lazy tree, virtual scroll, keyset pagination, indexed FULLTEXT search.

== Description ==

PageScale replaces the default WordPress list screen with an admin interface built to stay fast on sites that have tens of thousands (or millions) of entries. The stock list table slows down at that scale because it relies on OFFSET pagination and unindexed queries. PageScale takes a different approach at every layer.

As of 1.6.0, PageScale is no longer limited to Pages. You choose which post types it manages from a settings screen. Hierarchical types (like Pages) keep the lazy parent/child tree; non-hierarchical types (like Posts or a Products CPT) get the fast flat list. Pages behaviour is unchanged by default, so existing sites are unaffected until you opt additional types in.

**How it stays fast**

* Keyset (cursor) pagination instead of OFFSET, so the cost of loading any page of results is constant whether you have 10,000 rows or 1,000,000.
* Lazy hierarchy: only the children of a node you actually expand are queried. The tree never loads the whole site at once.
* Tiered search: numeric input goes straight to the primary key, slugs use an indexed equality/prefix lookup, and title/content/custom-field search uses InnoDB FULLTEXT (MATCH ... AGAINST) rather than a leading-wildcard LIKE. Content and custom-field search is optional via a UI toggle and runs entirely in MySQL, so post content is never loaded into PHP memory.
* Virtual scrolling keeps the DOM bounded (about 50 rows) at any dataset size.
* Cached aggregates: totals, the parents list and duplicate reports are cached with the Transients API, keyed per post type.

**Custom post type support**

* Choose which post types to manage from Settings > PageScale.
* Each managed type gets its own Scalable Manager screen under its menu, with a switcher to move between types.
* Hierarchical types show the parent/child tree and a parents panel; non-hierarchical types show a flat list without a Parent column.
* Search, quick edit, bulk actions, duplicate and CSV export all work per type.

**Other features**

* Elementor-aware quick actions.
* Bulk actions, chunked client-side so large operations don't time out.
* Duplicate finder for titles, exact slugs and similar slugs.
* CSV export of the current view or a selection.
* WP-CLI command to build the search indexes on demand.

**Indexing is safe on large tables**

PageScale never runs a long ALTER TABLE during activation. Instead it records that indexes are pending and builds them lazily, one at a time, on later admin loads (or immediately via WP-CLI). The plugin is fully usable while indexing is in progress, and search simply runs in a basic mode until the FULLTEXT indexes exist.

== Installation ==

1. Upload the `pagescale` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen in WordPress.
2. Activate the plugin through the Plugins screen.
3. Open **Pages > Scalable Manager** in the admin menu.
4. To manage other post types, go to **Settings > PageScale** and tick the types you want.
5. On large sites, optionally run `wp pgscl build-indexes` via WP-CLI to build the search indexes immediately instead of waiting for them to build in the background.

== Frequently Asked Questions ==

= Which post types can PageScale manage? =

Any public post type shown in the admin. Pages is enabled by default; add others from Settings > PageScale. Internal or configuration post types are rarely worth managing this way, so the choice is left to you rather than enabling everything automatically.

= What is the difference between hierarchical and flat types? =

Hierarchical types (like Pages) have parent/child relationships and show a tree with a parents panel. Non-hierarchical types (like Posts) show a fast flat list without a Parent column.

= Will 1.6.0 change my existing Pages setup? =

No. Only Pages is managed by default, exactly as before. Additional post types appear only after you enable them in settings.

= Does this add database indexes to my site? =

Yes. It adds a small number of composite and FULLTEXT indexes to wp_posts and wp_postmeta to make search and sorting fast. They are created lazily, one per request, so activation is instant and no request runs long enough to time out.

= Does it work without the indexes? =

Yes. Until the indexes finish building, search falls back to a bounded, basic mode. Everything else works normally.

= Which capability is required? =

Each managed post type is checked against its own edit capability on every REST route (for pages that is edit_pages). Private entries are only shown to users who can read them, and per-item edit and delete actions are checked individually.

= Does it modify my content? =

No. Read operations never load post content into PHP. Edit and bulk actions use standard WordPress functions and respect per-item capabilities.

== Screenshots ==

1. The scalable list with virtual scrolling and quick actions.
2. Lazy-loading hierarchy tree.
3. Tiered search across titles, slugs, content and custom fields.
4. Settings screen for choosing which post types to manage.

== Changelog ==

= 1.6.0 =
* New: custom post type support. Choose which post types PageScale manages from a new Settings > PageScale screen.
* New: each managed post type gets its own Scalable Manager screen, with a post-type switcher when more than one type is managed.
* New: automatic tree-vs-flat view. Hierarchical types keep the parent/child tree; non-hierarchical types get the fast flat list.
* Improved: duplicate now inherits the source item's post type and copies that type's taxonomies.
* Improved: total and parents caches are keyed per post type to avoid cross-type stale counts.
* Improved: permission checks validate against each post type's own capabilities.
* Fix: duplicating an Elementor page no longer corrupts its layout (meta is now copied with correct slashing, and per-post builder CSS caches are regenerated for the copy).
* Fix: a duplicated page now gets a unique slug (e.g. privacy-policy-2) while the original page keeps its slug unchanged.
* Fix: quick edit saves are slashed correctly and report a clear error if a page builder hook fails, instead of silently not saving.
* Fix: permanently deleting from trash now verifies the result and recovers when a corrupted builder payload blocks deletion.
* Fix: quick edit and drag-and-drop reparenting now save over POST instead of PATCH, which some hosting stacks (LiteSpeed/ModSecurity) block at the server level, making saves silently fail.
* Fix: the virtual-scroll repaint no longer resets an open quick edit form, which silently discarded typed changes (title, status, terms) before saving.
* Fix: taxonomy term changes in quick edit now save (the capability check used a non-existent capability name and always failed).
* Fix: the Scalable Manager submenu now attaches correctly under the built-in Posts menu.
* Pages behaviour is unchanged by default; existing installs see no difference until additional types are enabled.

= 1.5.1 =
* Security: apply private-page readability filtering to the /parents, /export and /default-view REST endpoints so private pages a user cannot read are never exposed, including by direct ID.
* Cache the parents list per user to avoid serving one user's readable set to another.

= 1.5.0 =
* Initial public release: keyset pagination, lazy tree, tiered FULLTEXT search, virtual scrolling.
* Duplicate finder for titles, exact slugs and similar slugs.
* CSV export with formula-injection guarding.
* Lazy, timeout-safe index building with a WP-CLI command.

== Upgrade Notice ==

= 1.6.0 =
Adds custom post type support. Pages behaviour is unchanged; enable other post types in Settings > PageScale.

= 1.5.0 =
Initial public release.
