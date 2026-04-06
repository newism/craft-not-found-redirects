---
outline: deep
---

# Garbage Collection

Over time, 404 records and referrers accumulate. The plugin provides two console commands for manual cleanup, and can also run automatically during Craft's [garbage collection](https://craftcms.com/docs/5.x/system/gc.html) cycle.

## Manual Cleanup

### Purge Stale 404s

Remove 404 records not seen since a given date. Accepts any `strtotime()`-compatible value:

```bash
# Purge 404s not seen in 90 days
php craft not-found-redirects/not-found-uris/purge "-90 days"
```

When a 404 is purged, its referrers are cascade-deleted. Orphaned system-generated redirects that no longer handle any remaining 404s are also cleaned up.

::: warning Side Effects
System-generated redirects (auto-created from URI changes) are deleted if they no longer handle any remaining 404 records. This means old auto-redirects can be removed if their associated 404s age out. Manually created redirects are never affected.
:::

### Reset Hit Counts

Reset all 404 and referrer hit counts to zero. Useful for starting fresh after a migration or bulk import:

```bash
php craft not-found-redirects/not-found-uris/reset-hit-counts
```

See [Console Commands](console-commands.md) for the full command reference.

## Automatic Cleanup

The plugin also runs cleanup tasks during Craft's garbage collection cycle, probabilistically on web requests and via `php craft gc`.

::: tip Use the plugin's own commands for targeted cleanup
`php craft gc` runs **all** of Craft's garbage collection, not just this plugin's. That includes hard-deleting soft-deleted elements, purging expired tokens, clearing stale sessions, and any other plugin's GC tasks. If you only want to clean up 404 data, use the plugin's own purge and reset commands above.
:::

## Configuration

Add these settings to your `config/not-found-redirects.php` file.

:::config
setting: maxReferrersPerNotFoundUri
type: int
default: 100
---
Maximum number of referrers to keep per 404 record. When exceeded, the oldest referrers (by last seen time) are trimmed.

Set to `0` for unlimited.
:::

:::config
setting: purgeStaleNotFoundUriDuration
type: mixed
default: 0
---
Auto-purge 404 records not seen within this duration. Accepts an `int` (seconds), a [`DateInterval` string](https://www.php.net/manual/en/dateinterval.construct.php) (e.g., `'P90D'` for 90 days), or `0` to disable.

See [`ConfigHelper::durationInSeconds()`](https://docs.craftcms.com/api/v5/craft-helpers-ConfigHelper.html#method-durationinseconds) for supported value types.
:::

:::config
setting: purgeStaleReferrerDuration
type: mixed
default: 0
---
Auto-purge referrer records not seen within this duration. Same format as `purgeStaleNotFoundUriDuration`.

Useful for cleaning up referrers that are no longer relevant without removing the parent 404 record.
:::
