---
outline: deep
---

# 404 Logging

Every time a visitor hits a page that doesn't exist, this plugin captures it. Unlike Craft's built-in redirects, which fire at the start of the request before a page is even rendered, this plugin intercepts 404 errors at the **end of the request lifecycle**, after Craft's routing has already failed. Every genuine 404 is caught, logged, and available for you to act on.

Each unique URI is aggregated into a single record with hit counts and timestamps, so you can see at a glance which broken links are most common and how recently they were hit.

## The 404s Index

The 404s screen shows all captured 404 errors with search, sort, and pagination.

![404s listing](./screenshots/404s-index.png)

Use the page sidebar to filter:

- **All**: every captured 404
- **Unhandled**: 404s that don't have a matching redirect yet
- **Handled**: 404s that are covered by a redirect rule

Each row shows the URI, hit count, first seen, last seen, and whether it's been handled. In multi-site installs, a "Site" column shows which site each 404 was recorded on. You can filter by site using Craft's standard breadcrumb site selector.

From this page you can also:

- [Import and export](import-export.md) 404s as CSV or JSON
- [Reprocess](#reprocessing) unhandled 404s against current redirect rules
- Reset hit counters for all 404s and referrers
- Delete all 404s for a clean slate

## 404 Detail Page

Click any 404 to see its detail page:

- **Site**: the site the 404 was recorded on
- **URI**: the normalised path
- **Hits**: total hit count
- **First Seen / Last Seen**: timestamps
- **Handled**: whether a matching redirect exists
- **Referrers**: a full list of incoming referrers (see [Referrer Tracking](#referrer-tracking))

The header shows a "Create Redirect" or "Edit Redirect" button for quick resolution. The "..." action menu includes options to delete all referrers and delete the 404 record.

![404 detail page](screenshots/404-detail.png)

## Referrer Tracking

Every 404 tracks the referrers that sent visitors to it. Each referrer is recorded with its own hit count and timestamps, so you know exactly **who's linking to broken pages**.

This makes it easy to prioritise fixes: a 404 referred by your own site navigation is more urgent than one from a random external crawler. Referrers are visible on the 404 detail page as a paginated table.

Referrers are tracked with a configurable maximum per 404 record. Old referrers are automatically cleaned up via [garbage collection](garbage-collection.md).

## Creating Redirects from 404s

When you find a 404 that needs fixing, click the "Create Redirect" button in the header. The redirect form opens with the 404's URI pre-filled as the incoming URL, so you can quickly set a destination and save.

Once a redirect is created that matches the 404, it's automatically marked as "handled" and you'll see the link to the redirect rule on the detail page.

## Reprocessing

Reprocessing rechecks all unhandled 404s against your current redirect rules. Any 404 that matches a rule is marked as handled and linked to that redirect. This is useful after adding or updating redirect rules, as 404s captured before the rule existed won't have been matched automatically.

Available from the "..." action menu on the 404s index, or via [console commands](console-commands.md):

```bash
php craft not-found-redirects/not-found-uris/reprocess
```

## Garbage Collection

Over time, 404 records accumulate. The plugin supports automatic cleanup of stale records and manual purge commands. See the [Garbage Collection](garbage-collection.md) page for full documentation, console commands, and configuration.

