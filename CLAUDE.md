# 404 Redirects Plugin — Agent Reference

Verbose implementation notes for AI agents working on this plugin. Human-facing
feature documentation lives in `docs/` (a full docs site — see `docs/index.md`);
don't duplicate it here. This file documents architecture, conventions, and
invariants that aren't obvious from any single source file.

## Architecture Overview

This is a Craft CMS 5.x **plugin**. The repo root is the package root
(`newism/craft-not-found-redirects`), installed into the craft-cloud dev site as
a symlinked path repository.

**Key design decisions**:
- No ActiveRecord, no Craft elements — models + query classes + direct DB commands for performance
- Models use `DateTime` properties — string conversion happens at presentation boundaries only
- `src/query/` classes (extend `craft\db\Query`) are the read layer; they hydrate models via `populate()`/`one()` and return `Illuminate\Support\Collection<Model>` from `collect()`
- `src/actions/` classes own import/export, shared by CP controllers and CLI
- The 404 pipeline is extended via **events** on `NotFoundUriService` (no config callbacks)
- `getTableData()`-style service methods format for VueAdminTable (HTML, links) — separate from raw model data

## Local Development

**DDEV site**: `https://craft-cloud.ddev.site` (repo: `~/Sites/newism/craft-cloud`)
- The plugin is symlinked into the site's `vendor/newism/craft-not-found-redirects`, so edits here are live.
- **Admin credentials**: `leevi@newism.com.au` / `password`
- Two sites for multi-site testing: Primary Site (`/`) and Second Site (`/en`) — note both base URLs are **relative/hostless**, which is a valuable edge case (see Multi-Site section).
- No PHP on the host. Run tooling in the container, e.g.:
  `cd ~/Sites/newism/craft-cloud && ddev exec "cd /var/www/_plugins/craft-not-found-redirects && composer phpstan"`
- phpstan has 6 known pre-existing errors (`new static()` in query classes, one unreachable-statement, one magic-property access). New code should not add to them.
- Database is **PostgreSQL** — see Known Considerations.
- Seed test data: `craft not-found-redirects/seed/run [count]`, remove with `seed/clean`.

## Plugin Identity

- **Handle**: `not-found-redirects` (translation category, permission prefix, route prefix, log category — all use this)
- **Namespace**: `newism\notfoundredirects`
- **Plugin class**: `newism\notfoundredirects\NotFoundRedirects` extends `craft\base\Plugin`
- **Schema version**: see `$schemaVersion` in `NotFoundRedirects.php` — bump it whenever adding a migration or it won't run on update
- **CP section**: Yes (`hasCpSection = true`) with permission-gated subnav: 404s, Redirects, Logs

## File Structure

```
src/
    NotFoundRedirects.php      # Plugin class: components, events, routes, permissions, log target
    config.php                 # Settings template (copy to config/not-found-redirects.php)
    actions/
        ExportAction.php           # Base: buildResult() derives columns from Model::attributes() + expand
        ExportNotFoundUris.php     # 404s export (JSON expands referrers)
        ExportRedirects.php        # Redirects export (expands notes)
        ImportAction.php           # Base: parse CSV/JSON, detect format + source (native/retour)
        ImportNotFoundUris.php     # 404s import
        ImportRedirects.php        # Redirects import (skips existing from+siteId)
    console/controllers/
        NotFoundUrisController.php # CLI: import, export, reprocess, purge, reset-hit-counts
        RedirectsController.php    # CLI: import, export
        MigrateController.php      # Retour DB migration (retour, retour-404s, retour-redirects)
        SeedController.php         # Dev fixtures: run, clean
        ReadInputTrait.php         # Shared stdin/file input handling
    controllers/
        DashboardController.php    # Root route → redirects to 404s index
        NotFoundUrisController.php # 404s screens, table/chart data, mutations, import/export
        RedirectsController.php    # Redirects screens, table data, mutations, test-match, entry sidebar
        NotesController.php        # Notes CRUD (modal/slideout) + render-list
        LogsController.php         # Log viewer
    db/Table.php                   # Table name constants
    events/
        NotFoundUriEvent.php       # EVENT_BEFORE_HANDLE (cancelable)
        DefineNotFoundUriEvent.php # EVENT_DEFINE_URI (mutable uri)
        BeforeRedirectEvent.php    # EVENT_BEFORE_REDIRECT (mutable destinationUrl, cancelable)
        AfterRedirectEvent.php     # EVENT_AFTER_REDIRECT
    gql/                           # interfaces/, queries/, resolvers/, types/
    helpers/
        Gql.php                    # canQueryRedirects() permission helper
        Uri.php                    # strip(), extractPath(), display()
    jobs/UpdateDestinationUris.php # Queue job: refresh cached `to` for entry-type redirects
    migrations/                    # Install.php + versioned migrations
    models/                        # Settings, NotFoundUri, Redirect, Note, Referrer,
                                   # ImportForm, ImportResult, ExportResult
    query/                         # NotFoundUriQuery, RedirectQuery, NoteQuery, ReferrerQuery
    services/
        NotFoundUriService.php     # 404 pipeline, logging, markHandled/reprocess, gc
        RedirectService.php        # Matching, CRUD, auto-redirects, entry sidebar HTML
        NoteService.php            # Note CRUD
    templates/                     # 404s/, redirects/, notes/, logs/, _widgets/, import-results.twig
    web/assets/                    # Asset bundles + dist/ JS & CSS
    widgets/                       # NotFoundWidget, NotFoundChartWidget, NotFoundCoverageWidget
docs/                              # Human documentation site (markdown)
```

## Data Layer

### Models

All models extend `craft\base\Model` and use native `DateTime` for date properties.

**Factories** (on `NotFoundUri` and `Redirect`):
- `fromDbRow(array $row): self` — generic hydration via `setAttributes($row, false)`; Craft's Typecast handles string→int/bool/DateTime coercion. Because it's attribute-generic, new columns hydrate automatically once added as public properties.
- `fromJsonObject(array $data): self` — constructor path (`App::configure()`), so setter methods (e.g. `setNotes()`) are invoked
- `fromCsvRow(array $row, array $labelMap): self` — remaps CSV labels to attribute names, delegates to `fromJsonObject`
- `fromRetourDbRow()` / `fromRetourCsvRow()` — Retour column mapping

| Model | Notable properties | Notes |
|-------|-----------|-------|
| `NotFoundUri` | id, siteId, uri, hitCount, hitLastTime, handled, redirectId, source, referrerCount | `referrerCount` via subquery. Implements `Chippable`, `Statusable`, `CpEditable` |
| `Redirect` | id, siteId, from, to, toType, toElementId, toElementSiteId, statusCode, priority, enabled, startDate, endDate, regexMatch, systemGenerated, elementId, createdById, hitCount, hitLastTime | Implements `Actionable`, `Chippable`, `Statusable`, `CpEditable`. `getStatus()` derives live/disabled/pending/expired. Relations: `createdBy`, `element`, `toElement`, `notes` |
| `Note` | id, redirectId, note, systemGenerated, createdById | Belongs to Redirect |
| `Referrer` | id, notFoundId, referrer, hitCount, hitLastTime | |

`Redirect::rules()` allows `statusCode` in **301, 302, 307, 404, 410, 444**.

### Query classes (`src/query/`)

Extend `craft\db\Query` with typed filter properties applied in `prepare()`
(e.g. `RedirectQuery`: `id`, `siteId`, `search`, `systemGenerated`, `enabled`,
`activeNow`). `siteId` filtering means "this site OR all-sites (`siteId IS NULL`)".
`one()`/`populate()` hydrate models; `collect()` returns a Collection.

### Services

Registered in `NotFoundRedirects::config()`:

| Component id | Class | Accessor |
|---------|---------------|---------|
| `notFoundUriService` | `NotFoundUriService` | `NotFoundRedirects::getInstance()->getNotFoundUriService()` |
| `redirectService` | `RedirectService` | `...->getRedirectService()` |
| `noteService` | `NoteService` | `...->getNoteService()` |

(Magic access `->redirectService` also works but the getters are the convention.)

## Database Schema

### `{{%notfoundredirects_redirects}}`

| Column | Type | Notes |
|--------|------|-------|
| id | PK | |
| siteId | int, nullable | null = all sites, FK to sites (CASCADE) |
| from | string(500), not null | Source pattern, site-relative path. **Indexed** |
| to | string(500), not null, default '' | Destination — see Multi-Site section for coordinate rules |
| toType | string(10), default 'url' | 'url' or 'entry' |
| toElementId | int, nullable | FK to elements (SET NULL), destination entry |
| toElementSiteId | int, nullable | FK to sites (SET NULL), destination entry's **site** |
| statusCode | int, default 302 | 301, 302, 307, 404, 410, 444 |
| priority | int, default 0 | Higher = checked first |
| enabled | bool, default true | |
| startDate / endDate | datetime, nullable | Active window |
| regexMatch | bool, default false | `from` is a raw regex |
| systemGenerated | bool, default false | Auto-created on URI change |
| elementId | int, nullable | Source element for auto-created redirects |
| createdById | int, nullable | FK to users, SET NULL |
| hitCount / hitLastTime | | |

**Indexes**: from, siteId, enabled, priority, systemGenerated, toElementId, toElementSiteId, elementId, createdById

### `{{%notfoundredirects_404s}}`

id, siteId (FK, not null), uri (500, site-relative, no query string), hitCount,
hitLastTime, handled (bool), redirectId (FK SET NULL), source (string 50,
origin of the record). **Unique index** `uri + siteId` (upsert target);
indexes on handled, siteId, redirectId. There is no `fullUrl` column.

### `{{%notfoundredirects_notes}}`

id, redirectId (FK CASCADE), note (text), systemGenerated, createdById (FK SET NULL).

### `{{%notfoundredirects_referrers}}`

id, notFoundId (FK CASCADE), referrer (500), hitCount, hitLastTime.
**Unique index** `notFoundId + referrer` (upsert target).

## 404 Pipeline

`ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION` → `NotFoundUriService::handleException()`.
Guards: 404 `HttpException` only (unwraps Twig `RuntimeError`), site requests only.

Extension points are **events on NotFoundUriService** (see `src/events/`):

```
1. EVENT_BEFORE_HANDLE   (NotFoundUriEvent)       — set $event->isValid = false to skip
                                                     the plugin entirely (Craft renders its 404)
2. EVENT_DEFINE_URI      (DefineNotFoundUriEvent) — mutate $event->uri before matching.
   Default uri: $request->getPathInfo() — already site-relative (Craft strips the
   site's base path, e.g. /en/, before the plugin sees it)
3. Match (RedirectService::matchesUri via two-phase lookup):
   Phase 1: exact SQL match on `from` (indexed)
   Phase 2: load regexMatch=true OR from LIKE '%<%' candidates, test each
   Both phases: enabled + activeNow (start/end dates) + siteId-or-null, priority DESC
4. No match  → log 404 unhandled, Craft renders its error template
   Match     → resolve destination (entry live URL / regex $1 backrefs / <param> tokens)
5. EVENT_BEFORE_REDIRECT (BeforeRedirectEvent)    — mutate destinationUrl or cancel
   (404 is still logged as handled either way; hit count increments only when redirecting)
6. Execute by statusCode:
   404 → raw text "404 Not Found", end()          (Block)
   410 → set status, let Craft render template     (Gone)
   444 → raw empty body, end()                     (nginx closes connection)
   else → resolve relative path via UrlHelper::siteUrl (see Multi-Site), self-redirect
          guard (same-host only), response->redirect(), end()
7. EVENT_AFTER_REDIRECT  (AfterRedirectEvent)     — post-redirect hook (analytics)
```

## Multi-Site & Destination Resolution

The invariant that spans `saveRedirect()`, the runtime, `UpdateDestinationUris`,
and `Redirect::getToElement()` — read this before touching any of them.

**Coordinate system**: `from`, 404 `uri`s, and entry-type `to` values are
**site-relative paths** (no site base path, no leading slash, `''` = homepage).
Craft strips the site's base path from requests and element URIs use the same
convention, so matching is symmetric. Site identity is carried by columns
(`siteId`, `toElementSiteId`), **never inside path strings**.

**Entry-type destinations** (`toType = 'entry'`):
- `toElementSiteId` records which site the entry was selected on (an element ID
  is global; each site has its own row in `elements_sites` with its own URI).
- `to` caches the entry's URI **in the destination site's coordinate system** —
  `''` for the homepage, never the literal `__home__` token.
- Resolution fallback chain everywhere: `toElementSiteId ?? siteId ?? <context site>`
  (request site at runtime, current site in CP).
- At fire time the runtime prefers the live element URL
  (`getElementById(toElementId, null, $entrySiteId)->getUrl()`) and uses it
  **verbatim** — `getUrl()` may legitimately return a root-relative URL when the
  site's base URL is hostless, and re-resolving it would double the prefix.
  Only the cached fallback path goes through
  `UrlHelper::siteUrl($to, ..., $destSiteId)` with the destination site.
- **Never cache output of `UrlHelper::siteUrl()`/`getUrl()`**: with hostless
  base URLs (`/`, `/en`) it returns different strings in web vs console
  contexts, which caused value churn and wrong-site prefixes historically.
- Self-redirect and loop validation in `saveRedirect()` only apply when the
  destination site equals the redirect's site — a cross-site destination
  sharing the source's path is a different URL, not a loop.

**URL-type destinations** (`toType = 'url'`):
- Same-site URLs pasted with the site's base are stripped to a relative path;
  cross-site absolute URLs stay absolute (the host carries the site identity)
  and pass through the runtime untouched.
- `saveRedirect()` also strips the redirect site's base *path* prefix from
  `from` if a user pasted e.g. `en/old-blog` on a site whose base path is `/en`.

**`UpdateDestinationUris` job**: only updates redirects whose destination site
(`toElementSiteId ?? siteId ?? primary`) matches the saved element's site — an
entry has one URI per site, and a save on site A must not overwrite the cached
destination of a redirect targeting site B. Caches the raw site-relative URI
(`''` for `__home__`); adds a system note only when the value actually changed.

**Entry selector UI**: the redirect form's `elementSelect` uses
`showSiteMenu: true` + `criteria: { siteId: '*' }`; `redirect-form.js` copies
the selected element's `siteId` into the hidden `toElementSiteId` input.

## Auto-Redirect on URI Change

1. **BEFORE_SAVE_ELEMENT / BEFORE_UPDATE_SLUG_AND_URI**: stash old URI from DB
2. **AFTER_SAVE_ELEMENT / AFTER_UPDATE_SLUG_AND_URI**: compare, then `createAutoRedirect()`:
   chain flattening (existing redirects for the element point to the newest URI),
   self-redirect cleanup (`from === to` deleted), create/update with `systemGenerated = true` + system note
3. Push `UpdateDestinationUris` (per saved site) to refresh cached `to` values
4. Settings: `createUriChangeRedirects` (on/off), `autoRedirectStatusCode` (301/302)

**Excludes**: drafts, revisions, new elements, duplicates, propagated elements, resaves.

### Element Deletion

`BEFORE_DELETE_ELEMENT` adds a system note to affected redirects ("Destination
element was deleted. Redirect using cached URI…"). FKs then SET NULL
`toElementId`, so the cached `to` becomes the fallback.

## Entry Sidebar

Entries get a "Redirects" sidebar section via `Element::EVENT_DEFINE_SIDEBAR_HTML`,
rendered by `RedirectService::getEntrySidebarHtml()` (shared with the AJAX
refresh endpoint `redirects/render-entry-sidebar`). Uses `$entry->getCanonicalId()`
since the editor may hold a draft ID.

- Chips rendered via `chip()` with action menus; `Redirect` implements
  `Actionable` and registers `activate` handlers via `registerJsWithVars()`
- `entry-sidebar.js` (Garnish component) handles "Add Redirect" (opens a
  `CpScreenSlideout` pre-filled with the entry as destination) and re-renders on
  `notFoundRedirects:redirectSaved` / `notFoundRedirects:redirectDeleted`
  document events, then runs `Craft.appendHeadHtml/appendBodyHtml/initUiElements`

## JS Widget Patterns

- **Slideout-safe components**: all Garnish components use `formAttributes` +
  `registerJsWithVars` + `data-*` selectors. **Never `id` selectors in JS** —
  Craft namespaces ids in slideouts. (Verified in practice: `{% namespace %}`
  also rewrites `aria-describedby`/`for`, so hidden-description patterns are safe.)
- Sub-components that register their own JS (like `elementSelect`) must be
  rendered inside templates via `{% include %}`, not pre-rendered in controllers
- Action menu items follow the core `Asset.php`/`Entry.php` pattern:
  `registerJsWithVars` + `$view->namespaceInputId($id)` + `on('activate')`

## User Permissions

Registered under the "404 Redirects" heading, all prefixed `not-found-redirects:`:

```
- not-found-redirects:view404s
  ├── delete404s / import404s / export404s
- not-found-redirects:viewRedirects
  ├── manageRedirects / deleteRedirects / importRedirects / exportRedirects
- not-found-redirects:viewLogs
```

Nesting is visual only — Craft flattens for checking. No `beforeAction()` gates;
each action calls `$this->requirePermission()` directly. Buttons/menu items are
conditionally rendered on the same permissions.

## Route Map

### CP URL Rules (registered in `attachEventHandlers()`)

| Route | Controller action |
|-------|-------------------|
| `not-found-redirects` | `dashboard/index` (redirects to 404s) |
| `not-found-redirects/404s` | `not-found-uris/index` |
| `not-found-redirects/404s/detail/<notFoundId>` | `not-found-uris/detail` |
| `not-found-redirects/404s/import` / `export` | `not-found-uris/import` / `export` |
| `not-found-redirects/redirects` | `redirects/index` |
| `not-found-redirects/redirects/new`, `edit/<redirectId>` | `redirects/edit` |
| `not-found-redirects/redirects/import` / `export` | `redirects/import` / `export` |
| `not-found-redirects/logs` | `logs/index` |

Notes screens use action URLs (`asCpModal()`), no route registration.

### Action Endpoints (via `Craft.getActionUrl()`)

Prefix all with `not-found-redirects/`:

- `not-found-uris/`: `table-data`, `referrers-table-data`, `chart-data`,
  `coverage-chart-data`, `delete`, `delete-all`, `delete-referrer`,
  `delete-all-referrers`, `reprocess`, `reset-hit-counts`, `do-import`, `export`
- `redirects/`: `table-data`, `save`, `delete`, `delete-all`, `test-match`,
  `element-url`, `render-entry-sidebar`, `pattern-reference`, `do-import`, `export`
- `notes/`: `save`, `delete`, `render-list`

### CLI Commands

| Command | Purpose |
|---------|---------|
| `craft not-found-redirects/not-found-uris/export [path\|-]` | Export 404s (`--format=csv\|json`) |
| `craft not-found-redirects/not-found-uris/import <path\|->` | Import 404s (`--format`, `--source=native\|retour`, auto-detected if omitted) |
| `craft not-found-redirects/not-found-uris/reprocess` | Re-match unhandled 404s against redirects |
| `craft not-found-redirects/not-found-uris/purge <last-seen>` | Purge stale 404s (e.g. "-90 days") |
| `craft not-found-redirects/not-found-uris/reset-hit-counts` | Zero all 404 + referrer hit counts |
| `craft not-found-redirects/redirects/export [path\|-]` | Export redirects (`--format`) |
| `craft not-found-redirects/redirects/import <path\|->` | Import redirects (`--format`, `--source`) |
| `craft not-found-redirects/migrate/retour` | Migrate 404s + redirects from Retour DB tables |
| `craft not-found-redirects/migrate/retour-404s` / `retour-redirects` | Individually |
| `craft not-found-redirects/seed/run [count]` / `seed/clean` | Dev fixtures |

Console controllers live under `console/controllers/`, namespace set in `init()`
for console requests. Human docs: `docs/console-commands.md`.

## GraphQL

- `Gql::EVENT_REGISTER_GQL_TYPES` registers `NotFoundInterface` + `RedirectInterface`
- `Gql::EVENT_REGISTER_GQL_QUERIES` registers via `RedirectsQuery::getQueries()`:
  `notFoundRedirects404s(handled, limit, offset)`, `notFoundRedirects404(id, uri)`,
  `notFoundRedirectsRedirects(limit, offset)`, `notFoundRedirectsRedirect(id)`
- `Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS` registers `not-found-redirects.all:read`
- Resolvers call query classes and return models directly; types read model
  properties via `match` expressions; DateTime → ISO 8601 (`->format('c')`)
- Use `GqlEntityRegistry::getOrCreate()` (`getEntity()` returns `false`, not `null`)
- The derived `status` and `toElementSiteId` are **not** exposed (known gap for the latter)

## Import/Export (`src/actions/`)

- `ExportAction::buildResult()` derives columns from `Model::attributes()` plus
  an `$expand` list (maps to `extraFields()`), so **new model properties export
  automatically**. CSV headers come from `getAttributeLabel()`.
  Filenames: `not-found-redirects-{404s|redirects}-YYYY-mm-dd-His.{csv|json}`.
  Redirects export expands `notes` (with authors); 404 JSON export expands `referrers`.
- `ImportAction` parses CSV or JSON (format auto-detected by extension/content),
  detects source (`native` vs `retour`) from column headers, hydrates via the
  model factories. Redirect import skips rows matching existing `from + siteId`.
- Shared by CP (`do-import`/`export` actions) and CLI.

## Logging

Dedicated Monolog target, category `not-found-redirects`, 14-day rotation:
```php
Craft::info('message', NotFoundRedirects::LOG);
```
→ `storage/logs/not-found-redirects-YYYY-MM-DD.log`. Viewable in CP under Logs.

## Pattern Matching

Three match styles, unified behind `RedirectService::matchesUri(Redirect $redirect, string $uri): bool`
(used by `findMatch`-phase-2, `markHandled`, `reprocessNotFoundUris`, `detectLoop`):

1. **Exact**: `regexMatch` false, no `<` tokens → case-insensitive `strcasecmp`
   (phase 1 does this in SQL)
2. **Named parameters**: `<name>` / `<name:regex>` tokens compiled via
   `toRegexPattern()`; bare `<name>` defaults to `[^\/]+` (single segment)
3. **Raw regex**: `regexMatch` true → pattern run as `` `{from}`i `` —
   **unanchored** (no implicit `^...$`; users anchor explicitly). Destination
   supports `$1`/`$2` backreferences.

## Handled Flag & Reprocessing

`handled` + `redirectId` on 404s are a denormalized cache; source of truth is
whether a redirect matches the URI.

- `markHandled(Redirect $redirect)` — after save/enable. Exact-match redirects:
  single SQL UPDATE. Pattern/regex: iterate unhandled 404s via `matchesUri()`
- `unmarkHandled(int $redirectId)` — before delete; resets handled + redirectId
- `reprocessNotFoundUris()` — all enabled redirects (priority order) vs all
  unhandled 404s; exposed in CP and CLI

## Test URL Feature

Redirect form "Test URLs" textarea → debounced POST to `redirects/test-match`
(`from`, `to`, `toType`, `toElementId`, `toElementSiteId`, `testUris`,
`regexMatch`) → server splits lines, normalizes via `Uri::strip()`, resolves
entry URLs (per selected site), delegates to `RedirectService::testMatch()` →
server-rendered `_test-results.twig` returned as HTML.

## Settings & Garbage Collection

`config/not-found-redirects.php` (template: `src/config.php`) →
`models/Settings.php`:

- `createUriChangeRedirects` (bool), `autoRedirectStatusCode` (301|302)
- `maxReferrersPerNotFoundUri` (default 100, 0 = unlimited) — trimmed during GC
  and probabilistically on write
- `purgeStaleNotFoundUriDuration` / `purgeStaleReferrerDuration` — seconds,
  duration string (`'P90D'`), or 0 to disable

GC hooks `craft\services\Gc::EVENT_RUN` → `NotFoundUriService::gc()`.

## Dashboard Widgets

| Widget | Class | Data endpoint |
|--------|-------|---------------|
| Latest/Top 404s | `NotFoundWidget` | (server-rendered body) |
| 404 Trends | `NotFoundChartWidget` | `not-found-uris/chart-data` |
| 404 Coverage | `NotFoundCoverageWidget` | `not-found-uris/coverage-chart-data` |

## Status Display Patterns

Boolean indicators and computed statuses use `Cp::statusLabelHtml()` — a static
PHP helper, **not available in Twig**; pre-render in controllers/services:

```php
Cp::statusLabelHtml(['color' => $v ? Color::Teal : Color::Red, 'icon' => $v ? 'check' : 'xmark', 'label' => ...]);
Cp::statusLabelHtml(['color' => Color::tryFromStatus($status) ?? Color::Gray, 'label' => ucfirst($status)]);
```

Status values follow Craft conventions: `live` (Teal), `pending` (Orange),
`expired` (Red), `disabled` (Gray — not in `Color::tryFromStatus()`).

## Known Considerations

- **PostgreSQL upsert**: table-qualify columns in `ON CONFLICT DO UPDATE SET`
  (e.g. `{{%notfoundredirects_404s}}.[[hitCount]]`)
- **PostgreSQL DateTime**: DB may return DateTime objects for date columns —
  factories handle both DateTime and string inputs
- **Hostless site base URLs** (`/`, `/en`): `UrlHelper::siteUrl()`/`getUrl()`
  output differs between web and console contexts — never persist their output
  (see Multi-Site section). The dev site is configured this way on purpose.
- **`->after()` in migrations** is MySQL-only; harmless no-op on PostgreSQL
- **410**: sets status without `end()` so Craft renders the 410 template;
  404-Block and 444 send raw responses and `end()`
- **Uri::display()**: URIs as-is (no leading slash, Craft convention), `/` for
  null/empty, absolute URLs pass through
- **Site column in tables**: shown only when >1 site with URLs exists;
  services add `siteName` to table data
- **Date form fields**: Craft's `dateTimeField` POSTs `['date' => ...]` arrays —
  controllers extract and convert
- **Notes rendering**: `|md(encode=true)` for XSS-safe Markdown
- **Feed Me**: not supported (needs element types)

## Documentation

- `README.md` + `docs/` — human documentation site (features, how-it-works,
  configuration, pattern matching, multi-site, Retour migration, etc.).
  Update the relevant `docs/*.md` page when changing user-facing behavior.
- `docs/screenshots/` — CP screenshots referenced by README/docs, taken via
  Chrome DevTools MCP against the DDEV site at 1440x810
- `CHANGELOG.md` — keep-a-changelog format; add entries with user-facing changes

## Future Work

- Rulesets (pre-built redirect sets for WordPress, security probes, etc.)
- Bulk actions with checkboxes in VueAdminTable
- GA4 data import for historical 404 backfill
- URL-based scheduled import (fetch CSV/JSON from URL on cron)
- Per-request 404 log with IP/user agent/referrer — needs purge strategy and
  GDPR-aware IP anonymization
- Persist import results (currently rendered inline on POST, lost on refresh)
- Incoming URI prefix display + paste normalization (NCP-13)
- Expose `toElementSiteId` in GraphQL
