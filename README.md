# Scalable Page Manager

High-performance page management for WordPress sites with 10,000+ pages. Replaces the default admin pages list with a lazy-loaded hierarchy tree, a virtual-scroll list, keyset (cursor) pagination, and indexed FULLTEXT search across title, slug, content, and ACF fields. Elementor-aware quick actions included.

Single-file plugin. No build step: the admin UI is vanilla JS, served inline and powered by a custom REST namespace (`spm/v1`).

## Why

The stock WordPress pages screen falls over on large sites. It uses `OFFSET` pagination (cost grows with dataset size), loads full post content into PHP, and its search is a leading-wildcard `LIKE` that can't use an index. On a site with tens of thousands of pages this means slow loads, timeouts, and unusable search. Scalable Page Manager is built so per-request cost stays roughly constant from 10k to 1M+ rows.

## Features

- **Keyset (cursor) pagination** — never `OFFSET`, so per-request cost is constant regardless of dataset size.
- **Lazy hierarchy tree** — only the children of an expanded node are queried.
- **Tiered, indexed search** — numeric input resolves against the primary key; slug against an indexed equality/prefix match; title/content/ACF via InnoDB FULLTEXT (`MATCH ... AGAINST`), never a leading-wildcard `LIKE`. Content and ACF search is optional via a UI toggle and runs entirely in MySQL — post content is never pulled into PHP memory.
- **Virtual scrolling** — the DOM stays bounded (~50 rows) at any dataset size.
- **Chunked bulk actions** — bulk operations are batched client-side to avoid timeouts.
- **Elementor-aware quick actions** — edit-with-Elementor and related shortcuts surfaced inline.
- **Duplicate detection** — find pages sharing a title/slug.
- **Export** — export the current filtered view.
- **Safe activation on huge tables** — activation never runs `ALTER TABLE` inline (which can trip a Cloudflare 524 on large `wp_posts` / `wp_postmeta`). Indexes are recorded as pending and built lazily, one at a time, on later admin loads. The plugin works fully — just unindexed — until they exist.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- MySQL / MariaDB with InnoDB FULLTEXT support (MySQL 5.6+ / MariaDB 10.0.5+)

## Installation

1. Download the latest release (or clone this repo).
2. Upload the `scalable-page-manager` folder to `wp-content/plugins/`, or zip it and install via **Plugins → Add New → Upload Plugin**.
3. Activate through the **Plugins** menu in WordPress.
4. Open the **Scalable Page Manager** admin menu item. On first loads the FULLTEXT indexes build lazily in the background; search is fully functional (falling back to unindexed matching) until they complete.

## REST API

All endpoints live under the `spm/v1` namespace and require appropriate capabilities:

| Endpoint | Purpose |
| --- | --- |
| `GET /pages` | Keyset-paginated list |
| `GET /tree/{parent}` | Lazy children of a node |
| `GET /search` | Tiered indexed search |
| `GET /stats` | Dataset stats |
| `GET /page/{id}` | Single page detail |
| `POST /page/{id}/edit` | Inline edit |
| `POST /action` | Bulk / single actions |
| `GET /duplicates` | Duplicate detection |
| `GET /parents` | Parent options |
| `GET /export` | Export current view |
| `GET/POST /default-view` | Saved default view |

## Notes

Content/ACF FULLTEXT matching only runs when the relevant index is present; the plugin detects this and falls back gracefully to unindexed matching if the indexes have not finished building. `MATCH ... AGAINST` uses BOOLEAN MODE.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
