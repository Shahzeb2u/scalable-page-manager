=== PageScale ===
Contributors: shahzeb2u
Tags: pages, admin, performance, search, page management
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast Pages screen for sites with tens of thousands of pages: lazy tree, virtual scroll, keyset pagination, indexed FULLTEXT search.

== Description ==

PageScale replaces the default WordPress Pages screen with an admin interface built to stay fast on sites that have tens of thousands — or millions — of pages. The stock list table slows down at that scale because it relies on OFFSET pagination and unindexed queries. PageScale takes a different approach at every layer.

**How it stays fast**

* Keyset (cursor) pagination instead of OFFSET, so the cost of loading any page of results is constant whether you have 10,000 rows or 1,000,000.
* Lazy hierarchy — only the children of a node you actually expand are queried. The tree never loads the whole site at once.
* Tiered search — numeric input goes straight to the primary key, slugs use an indexed equality/prefix lookup, and title/content/custom-field search uses InnoDB FULLTEXT (MATCH ... AGAINST) rather than a leading-wildcard LIKE. Content and custom-field search is optional via a UI toggle and runs entirely in MySQL, so post content is never loaded into PHP memory.
* Virtual scrolling keeps the DOM bounded (about 50 rows) at any dataset size.
* Cached aggregates — totals, the parents list and duplicate reports are cached with the Transients API.

**Other features**

* Elementor-aware quick actions.
* Bulk actions, chunked client-side so large operations don't time out.
* Duplicate finder for titles, exact slugs and similar slugs.
* CSV export of the current view or a selection.
* WP-CLI command to build the search indexes on demand.

**Indexing is safe on large tables**

PageScale never runs a long ALTER TABLE during activation. Instead it records that indexes are pending and builds them lazily, one at a time, on later admin loads (or immediately via WP-CLI). The plugin is fully usable while indexing is in progress — search simply runs in a basic mode until the FULLTEXT indexes exist.

== Installation ==

1. Upload the `pagescale` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen in WordPress.
2. Activate the plugin through the Plugins screen.
3. Open **Pages > Scalable Manager** in the admin menu.
4. On large sites, optionally run `wp pgscl build-indexes` via WP-CLI to build the search indexes immediately instead of waiting for them to build in the background.

== Frequently Asked Questions ==

= Does this add database indexes to my site? =

Yes. It adds a small number of composite and FULLTEXT indexes to wp_posts and wp_postmeta to make search and sorting fast. They are created lazily, one per request, so activation is instant and no request runs long enough to time out.

= Does it work without the indexes? =

Yes. Until the indexes finish building, search falls back to a bounded, basic mode. Everything else works normally.

= Which capability is required? =

Managing pages requires the edit_pages capability, checked on every REST route. Private pages are only shown to users who can read them, and per-page edit and delete actions are checked individually.

= Does it modify my content? =

No. Read operations never load post content into PHP. Edit and bulk actions use standard WordPress functions and respect per-page capabilities.

== Screenshots ==

1. The scalable page list with virtual scrolling and quick actions.
2. Lazy-loading page hierarchy tree.
3. Tiered search across titles, slugs, content and custom fields.
4. Duplicate finder.

== Changelog ==

= 1.5.1 =
* Security: apply private-page readability filtering to the /parents, /export and /default-view REST endpoints so private pages a user cannot read are never exposed, including by direct ID.
* Cache the parents list per user to avoid serving one user's readable set to another.

= 1.5.0 =
* Initial public release: keyset pagination, lazy tree, tiered FULLTEXT search, virtual scrolling.
* Duplicate finder for titles, exact slugs and similar slugs.
* CSV export with formula-injection guarding.
* Lazy, timeout-safe index building with a WP-CLI command.

== Upgrade Notice ==

= 1.5.0 =
Initial public release.
