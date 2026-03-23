# 404 Redirects Plugin — Agent Reference

Verbose implementation notes for AI agents working on this plugin.

## Architecture Overview

This is a Craft CMS 5.x **plugin** (not a module). It lives in `plugins/newism/craft-not-found-redirects/` with its own `composer.json` and is referenced as a local path repository in the project's root `composer.json`.

**Key design decisions**:
- No ActiveRecord, no Craft elements — models + direct DB commands for performance
- Models use `DateTime` properties — string conversion happens at presentation boundaries only
- Services return `Illuminate\Support\Collection<Model>` — shared across CP, CLI, CSV, and GraphQL
- `getTableData()` methods format for VueAdminTable (HTML, links) — separate from raw model data
- `CsvService` iterates collections and formats DateTime to strings for export

## Plugin Identity

- **Handle**: `not-found-redirects`
- **Namespace**: `newism\notfoundredirects`
- **Plugin class**: `newism\notfoundredirects\NotFoundRedirects` extends `craft\base\Plugin`
- **Schema version**: `1.0.0`
- **CP section**: Yes (`hasCpSection = true`) with subnav: 404s, Redirects, Logs

## File Structure

```
plugins/newism/craft-not-found-redirects/
    composer.json              # type: craft-plugin, handle: not-found-redirects
    README.md                  # Human documentation
    AGENTS.md                  # This file
    src/
        NotFoundRedirects.php    # Main plugin class
        config.php             # Configuration template
        console/controllers/
            RedirectsController.php    # CLI commands (export, import, reprocess, purge)
            MigrateController.php      # Retour migration commands
            OutputResultTrait.php      # Shared CLI output formatting
        controllers/
            NotFoundUrisController.php # 404s CP screens, table data, mutations
            RedirectsController.php    # Redirects CP screens, table data, mutations
            LogsController.php         # Log viewer
            NotesController.php        # Notes CRUD (modal/slideout)
        gql/
            interfaces/
                NotFoundInterface.php  # GQL interface for 404s
                RedirectInterface.php  # GQL interface for redirects
            queries/
                RedirectsQuery.php     # Root GQL query definitions
            resolvers/
                RedirectsResolver.php  # GQL data resolution
            types/
                NotFoundType.php       # GQL object type for 404s
                RedirectType.php       # GQL object type for redirects
        helpers/
            Gql.php                    # canQueryRedirects() permission helper
            Uri.php                    # URI normalization (strip, extractPath, display)
        jobs/
            UpdateDestinationUris.php  # Queue job: update cached `to` for entry-type redirects
        migrations/
            Install.php                # Creates 4 tables with indexes and FKs
        models/
            Settings.php       # Plugin settings (config file driven)
            NotFoundUri.php    # 404 record model (Chippable, Statusable, CpEditable)
            Redirect.php       # Redirect rule model (Chippable, Statusable, CpEditable)
            Note.php           # Timestamped note model (belongs to Redirect)
            Referrer.php       # Referrer audit model
        services/
            CsvService.php         # CSV export/import (uses Collections from other services)
            NoteService.php        # Note CRUD (addNote, findByRedirectId, save, deleteById)
            NotFoundService.php    # 404 logging, normalization, queries, table data, reprocess, purge
            RedirectService.php    # Redirect matching, CRUD, pattern matching, auto-redirects, table data
        templates/
            _404s.twig             # VueAdminTable for 404 list
            _detail.twig           # 404 detail page (info table + referrers)
            _entry-sidebar.twig    # Entry sidebar: incoming redirects for an entry
            _import.twig           # CSV import form
            _logs.twig             # Log viewer with file selector
            _note-form.twig        # Note create/edit modal form
            _redirect-form.twig    # Redirect create/edit form (with notes list)
            _redirect-meta.twig    # Redirect meta sidebar (ID, status, hits, created by, source element)
            _rules.twig            # VueAdminTable for redirects list
            _pattern-reference.twig # Pattern matching reference (slideout content)
            _redirects-sidebar.twig # Page sidebar for redirect filters (All/Manual/Auto-created)
            _sidebar.twig          # Page sidebar for 404 filters (All/Unhandled/Handled)
        web/assets/
            dist/
                redirect-form.css  # Redirect form styles (notes, URL fields)
                redirect-form.js   # Redirect form JS (destination type toggle, notes management)
            RedirectFormAsset.php  # Asset bundle registration
```

## Data Layer

### Models

All models extend `craft\base\Model` and use native `DateTime` for date properties. Each has a `fromRow(array $row): self` factory that handles type coercion from database rows (PostgreSQL returns DateTime objects for date columns).

**Important**: `fromRow()` sets properties directly (not via constructor) to avoid Craft's `App::configure()` which fails on DateTime-to-string assignment.

| Model | Properties | Notes |
|-------|-----------|-------|
| `NotFoundUri` | id, siteId, uri, fullUrl, hitCount, hitLastTime, handled, redirectId, source, referrerCount, dateCreated, dateUpdated, uid | `referrerCount` populated via subquery. Implements `Chippable`, `Statusable`, `CpEditable` |
| `Redirect` | id, siteId, from, to, toType, toElementId, statusCode, priority, enabled, startDate, endDate, systemGenerated, elementId, createdById, hitCount, hitLastTime, dateCreated, dateUpdated, uid | Implements `Chippable`, `Statusable`, `CpEditable`. `getStatus()` derives enabled/disabled/scheduled/expired from `enabled`, `startDate`, `endDate`. Relations: `createdBy` (User), `element` (source), `toElement` (destination entry), `notes` (Collection<Note>) |
| `Note` | id, redirectId, note, systemGenerated, createdById, dateCreated, dateUpdated, uid | Belongs to Redirect. Relation: `createdBy` (User) |
| `Referrer` | id, notFoundId, referrer, hitCount, hitLastTime, dateCreated, dateUpdated, uid | |

### Services

Services are registered via `config()` static method on the plugin class:

| Service | Component name | Purpose |
|---------|---------------|---------|
| `NotFoundService` | `notFound` | 404 logging, normalization, queries, reprocess, purge |
| `RedirectService` | `redirects` | Redirect matching, CRUD, pattern matching, auto-redirects |
| `NoteService` | `notes` | Note CRUD (addNote, findByRedirectId, save, deleteById) |
| `CsvService` | `csv` | CSV export/import |

Access: `NotFoundRedirects::getInstance()->notFound`, `NotFoundRedirects::getInstance()->redirects`, `NotFoundRedirects::getInstance()->notes`, `NotFoundRedirects::getInstance()->csv`

### Service Method Patterns

**Read methods** return models/collections (no HTML, no formatting):
- `find(...)` → `Collection<Model>` — paginated, filtered, sorted query
- `findById(int $id)` → `?Model` — single record
- `count(...)` → `int` — count with same filters as find

**Table data methods** format for VueAdminTable (HTML links, formatted dates):
- `getTableData(...)` → `['pagination' => [...], 'data' => [...]]`
- Uses `Craft::$app->getFormatter()->asDatetime()` for locale-aware date formatting
- Uses `Html::a()` and `UrlHelper::cpUrl()` for links

**Write methods** operate on raw data or models:
- `log()`, `save()`, `deleteById()`, `markHandled()`, `unmarkHandled()`

## Database Schema

### `{{%notfoundredirects_redirects}}`

| Column | Type | Notes |
|--------|------|-------|
| id | PK | |
| siteId | int, nullable | null = all sites, FK to sites |
| from | string(2000), not null | Source pattern, supports `<param>` syntax. **Indexed** |
| to | string(2000), not null, default '' | Destination URI, empty for 410 |
| toType | string(10), not null, default 'url' | Destination type: 'url' or 'entry' |
| toElementId | int, nullable | FK to elements (SET NULL), destination entry for entry-type redirects |
| statusCode | int, default 302 | 301, 302, 307, 410 |
| priority | int, default 0 | Higher = checked first |
| enabled | bool, default true | |
| startDate | datetime, nullable | Redirect active after this time |
| endDate | datetime, nullable | Redirect inactive after this time |
| systemGenerated | bool, default false | Whether auto-created on URI change |
| elementId | int, nullable | Source element for auto-created redirects |
| createdById | int, nullable | FK to users, SET NULL on delete |
| hitCount | int, default 0 | |
| hitLastTime | datetime, nullable | |
| dateCreated, dateUpdated, uid | standard | |

**Indexes**: from, siteId, enabled, priority, systemGenerated, toElementId, elementId, createdById

### `{{%notfoundredirects_404s}}`

| Column | Type | Notes |
|--------|------|-------|
| id | PK | |
| siteId | int, not null | FK to sites |
| uri | string(2000), not null | Normalized path (no tracking params) |
| fullUrl | string(2000), not null | Original URL for reference |
| hitCount | int, default 1 | Incremented via upsert |
| hitLastTime | datetime, not null | |
| handled | bool, default false | |
| redirectId | int, nullable | FK to notfoundredirects_redirects, SET NULL on delete |
| source | string(50), nullable | Origin of the 404 record (default: 'request') |
| dateCreated, dateUpdated, uid | standard | |

**Unique index**: `uri + siteId` (used for upsert)
**Indexes**: handled, siteId, redirectId

### `{{%notfoundredirects_notes}}`

| Column | Type | Notes |
|--------|------|-------|
| id | PK | |
| redirectId | int, not null | FK to notfoundredirects_redirects, CASCADE on delete |
| note | text, not null | Note content |
| systemGenerated | bool, default false | Whether auto-created by the system |
| createdById | int, nullable | FK to users, SET NULL on delete |
| dateCreated, dateUpdated, uid | standard | |

**Indexes**: redirectId

### `{{%notfoundredirects_referrers}}`

| Column | Type | Notes |
|--------|------|-------|
| id | PK | |
| notFoundId | int, not null | FK to notfoundredirects_404s, CASCADE on delete |
| referrer | string(2000), not null | The referring URL |
| hitCount | int, default 1 | |
| hitLastTime | datetime, not null | |
| dateCreated, dateUpdated, uid | standard | |

**Unique index**: `notFoundId + referrer` (used for upsert)
**Indexes**: notFoundId

## 404 Redirects Pipeline

Config callbacks bookend the plugin's core logic. The request object is read-only throughout — callbacks receive and return plain strings or booleans.

```
Request → Craft routing fails → 404 HttpException
    ↓
ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION fires
    ↓
NotFoundRedirects::handleException()
    ├── Guard: 404 + site request only (not CP, not console)
    ├── Unwrap Twig RuntimeError if needed
    │
    ├── Step 1: GATE [CONFIG: shouldHandle($request) → bool]
    │   Default: true (process all requests)
    │   false → response 404 text "404 Not Found" + end()
    │           No DB query, no logging, no template. Fast exit.
    │
    ├── Step 2: NORMALIZE [CONFIG: normalizeUrlFromRequest($request) → string $uri]
    │   Default: $request->getFullPath() (path only, no query params)
    │
    ├── Step 3: DB LOOKUP [PLUGIN]
    │   RedirectService::findMatch($uri, $siteId)
    │   ├── Phase 1: exact match via SQL (fast, indexed)
    │   ├── Phase 2: regex patterns (load and iterate)
    │   ├── Date range check (startDate/endDate)
    │   ├── Entry-type: resolve live URL from element, fallback to cached `to`
    │   └── Return first match or null
    │
    ├── Match found:
    │   ├── Log 404 as handled
    │   ├── Step 4: TRANSFORM [CONFIG: normalizeRedirectUrl($url, $matchedUri, $redirect) → string]
    │   │   Default: return $url unchanged
    │   ├── Self-redirect guard (compare destination to current path)
    │   ├── If 410: set status 410, return (let Craft render 410 template)
    │   └── If 301/302/307: response->redirect($finalUrl) + end()
    │
    └── No match:
        ├── Log 404 as unhandled
        └── Let Craft render its error template (normal 404 page)
```

### Config callbacks summary

| Callback | Signature | Default | When |
|----------|-----------|---------|------|
| `shouldHandle` | `(Request $request): bool` | `true` | Before any plugin logic |
| `normalizeUrlFromRequest` | `(Request $request): string` | `getFullPath()` | Before DB lookup |
| `normalizeRedirectUrl` | `(string $url, string $matchedUri, Redirect $redirect): string` | return `$url` | After match, before redirect |

## Auto-Redirect on URI Change

When an element's URI changes (via save, rename, structure move, or parent rename):

1. **BEFORE_SAVE_ELEMENT** / **BEFORE_UPDATE_SLUG_AND_URI**: Stash old URI from database (`$element->uri` may already be new)
2. **AFTER_SAVE_ELEMENT** / **AFTER_UPDATE_SLUG_AND_URI**: Compare old vs new URI
3. **Chain Flattening**: All existing redirects for the element point to the latest URL (no chains)
4. **Self-Redirect Cleanup**: Delete redirects where `from === to`
5. **Auto-Redirect Creation**: Create/update redirect with `systemGenerated=true` and system note
6. **Queue Job**: `UpdateDestinationUris` updates cached `to` URIs for entry-type redirects pointing to this element

**Excludes**: Drafts, revisions, new elements, duplicates, propagated elements, resaves

### Element Deletion

When an element is deleted (`BEFORE_DELETE_ELEMENT`), redirects pointing to it as `toElement` get a system note: "Destination element was deleted. Redirect using cached URI." The FK `SET NULL` clears `toElementId`, so the cached `to` URI becomes the fallback.

### Entry Sidebar

Entries with incoming entry-type redirects show an "Incoming Redirects" section via `Element::EVENT_DEFINE_SIDEBAR_HTML`.

## User Permissions

Five permissions registered under "404 Redirects" heading in `attachEventHandlers()`:

| Permission | Controls |
|-----------|----------|
| `not-found-redirects:view404s` | View 404s listing |
| `not-found-redirects:delete404s` | Delete 404 records |
| `not-found-redirects:manageRedirects` | Create and edit redirects |
| `not-found-redirects:deleteRedirects` | Delete redirects |
| `not-found-redirects:viewLogs` | View plugin logs |

- `getCpNavItem()` gates subnav items: "404s" requires `view404s`, "Redirects" requires `manageRedirects` or `deleteRedirects`, "Logs" requires `viewLogs`
- Returns `null` (hides entire nav item) if user has no permissions
- Each controller has its own `beforeAction()` with appropriate permission checks
- Mutation actions use `$this->requirePermission()`:
  - `saveRedirect`, `reprocess` → `manageRedirects`
  - `deleteRedirect`, `deleteAllRedirects` → `deleteRedirects`
  - `delete404`, `deleteAll404s`, `deleteReferrer`, `deleteAllReferrers` → `delete404s`
- Action menu items and buttons are conditionally rendered based on permissions

## Dry-Run Mode

`Settings::$dryRun` (default: `false`). When enabled:
- The full pipeline runs (shouldHandle → normalize → DB lookup → transform)
- 404s are still logged and referrers tracked
- Redirects are **not** executed (no Location header, no `Craft::$app->end()`)
- Logs what would have happened for debugging
- Useful for running alongside Retour to validate before switching

Also available via `--dry-run` flag on CLI import, reprocess, and purge commands.

## CP Layout Architecture

### Global Navigation
Plugin uses `hasCpSection = true` with `getCpNavItem()` override for permission-gated subnav:
- **404s** → `/not-found-redirects/404s` → `NotFoundUrisController::actionIndex()` (requires `view404s`)
- **Redirects** → `/not-found-redirects/redirects` → `RedirectsController::actionIndex()` (requires `manageRedirects` or `deleteRedirects`)
- **Logs** → `/not-found-redirects/logs` → `LogsController::actionIndex()` (requires `viewLogs`)

### Page Layout (asCpScreen)
- `->title()`, `->selectedSubnavItem()`, `->addCrumb()`
- `->pageSidebarTemplate()` — left sidebar for 404 filters (All/Unhandled/Handled)
- `->additionalButtonsHtml()` — header buttons (New Redirect, Edit/Create Redirect)
- `->actionMenuItems()` — "..." dropdown (export, import, reprocess, delete)
- `->contentTemplate()` — main content area
- `->metaSidebarTemplate()` — right sidebar (redirect edit: ID, status, hits, created at/by, updated at, source element)
- `->formAttributes()` — for file upload (import page uses `enctype: multipart/form-data`)

### Notes Management
Notes are managed via `NotesController` using `asCpModal()` for slideout forms. The redirect edit form displays notes inline with system-generated notes distinguished by a "System" avatar. User notes are rendered with `|md(encode=true)` for safe Markdown formatting.

### VueAdminTable
All data tables use `Craft.VueAdminTable` with server-side JSON endpoints:
- `NotFoundUrisController::actionTableData()` → delegates to `NotFoundService::getTableData()`
- `RedirectsController::actionTableData()` → delegates to `RedirectService::getTableData()`
- `NotFoundUrisController::actionReferrersTableData()` → delegates to `NotFoundService::getReferrersTableData()`

Controller actions are thin pass-throughs — extract request params, delegate to service, return `$this->asSuccess(data: ...)`.

## Route Map

### CP URL Rules
| Route | Controller Action |
|-------|-------------------|
| `not-found-redirects` | `not-found-uris/index` (defaults to all 404s) |
| `not-found-redirects/404s` | `not-found-uris/index` |
| `not-found-redirects/404s/detail/<notFoundId>` | `not-found-uris/detail` |
| `not-found-redirects/redirects` | `redirects/index` |
| `not-found-redirects/redirects/import` | `redirects/import` |
| `not-found-redirects/redirects/new` | `redirects/edit` |
| `not-found-redirects/redirects/edit/<redirectId>` | `redirects/edit` |
| `not-found-redirects/logs` | `logs/index` |
| `not-found-redirects/notes/new/<redirectId>` | `notes/edit` |
| `not-found-redirects/notes/edit/<noteId>` | `notes/edit` |

### Action Endpoints (JSON, via `Craft.getActionUrl()`)
| Action | Method | Purpose |
|--------|--------|---------|
| `not-found-redirects/not-found-uris/table-data` | GET/JSON | 404s table data |
| `not-found-redirects/not-found-uris/referrers-table-data` | GET/JSON | Referrers table data |
| `not-found-redirects/not-found-uris/delete` | POST/JSON | Delete 404 |
| `not-found-redirects/not-found-uris/delete-all` | POST | Delete all 404s |
| `not-found-redirects/not-found-uris/delete-referrer` | POST/JSON | Delete referrer |
| `not-found-redirects/not-found-uris/delete-all-referrers` | POST | Delete all referrers |
| `not-found-redirects/not-found-uris/reprocess` | POST | Reprocess 404s against redirects |
| `not-found-redirects/not-found-uris/export` | GET | Download 404s CSV |
| `not-found-redirects/redirects/table-data` | GET/JSON | Redirects table data |
| `not-found-redirects/redirects/save` | POST | Save redirect |
| `not-found-redirects/redirects/element-url` | GET/JSON | Resolve element URL/URI by ID |
| `not-found-redirects/redirects/delete` | POST/JSON | Delete redirect |
| `not-found-redirects/redirects/delete-all` | POST | Delete all redirects |
| `not-found-redirects/redirects/export` | GET | Download redirects CSV |
| `not-found-redirects/redirects/do-import` | POST | Import redirects from CSV |
| `not-found-redirects/redirects/pattern-reference` | GET | Pattern reference slideout |
| `not-found-redirects/notes/save` | POST | Save note |
| `not-found-redirects/notes/delete` | POST/JSON | Delete note |

### CLI Commands
| Command | Purpose |
|---------|---------|
| `craft not-found-redirects/redirects/export-404s` | Export 404s (table/JSON/CSV) |
| `craft not-found-redirects/redirects/export-redirects` | Export redirects (table/JSON/CSV) |
| `craft not-found-redirects/redirects/import-redirects` | Import redirects from CSV |
| `craft not-found-redirects/redirects/reprocess` | Reprocess unhandled 404s against redirects |
| `craft not-found-redirects/redirects/purge-404s` | Purge stale 404s by --last-seen threshold |
| `craft not-found-redirects/migrate/retour` | Migrate both 404s and redirects from Retour |
| `craft not-found-redirects/migrate/retour-404s` | Migrate 404 stats from Retour |
| `craft not-found-redirects/migrate/retour-redirects` | Migrate redirect rules from Retour |

Console controllers registered via `$this->controllerNamespace` in `init()` when `getIsConsoleRequest()`.

## GraphQL

### Registration
Events registered in `attachEventHandlers()`:
- `Gql::EVENT_REGISTER_GQL_QUERIES` — registers 4 root queries
- `Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS` — registers `not-found-redirects.all:read` permission

### Queries
| Query | Type | Resolver |
|-------|------|----------|
| `notFoundRedirects404s(handled, limit, offset)` | `[NotFoundRedirects404]` | `resolveNotFounds` |
| `notFoundRedirects404(id, uri)` | `NotFoundRedirects404` | `resolveNotFound` |
| `notFoundRedirectsRedirects(limit, offset)` | `[NotFoundRedirectsRedirect]` | `resolveRedirects` |
| `notFoundRedirectsRedirect(id)` | `NotFoundRedirectsRedirect` | `resolveRedirect` |

### Type Registration
Types created via `GqlEntityRegistry::getOrCreate()` (not `getEntity() ?? createEntity()` — the latter fails because `getEntity()` returns `false` not `null`).

### Resolution Flow
1. `RedirectsQuery::getQueries()` defines root queries with string-format resolvers
2. `RedirectsResolver::resolveX()` calls service `find()`/`findById()` methods
3. Services return `Collection<Model>` or `?Model`
4. `NotFoundType`/`RedirectType` resolve individual fields via `match` expression
5. DateTime fields formatted as ISO 8601 (`->format('c')`) at the type level

### Key Pattern
Resolvers return model objects directly — the ObjectType's `resolve()` method reads properties from the model. No intermediate array conversion needed.

### GQL Fields
**NotFoundRedirects404**: id, siteId, uri, fullUrl, hitCount, hitLastTime, handled, redirectId, referrerCount, dateCreated, dateUpdated.

**NotFoundRedirectsRedirect**: id, siteId, from, to, toType, toElementId, statusCode, priority, enabled, startDate, endDate, systemGenerated, elementId, createdById, hitCount, hitLastTime, dateCreated, dateUpdated.

The derived `status` field (enabled/disabled/scheduled/expired) is **not** in GQL — clients can compute it from `enabled`, `startDate`, `endDate`.

## CSV Export/Import

### CsvService
Uses `League\Csv\Writer`/`Reader` (already a Craft dependency).

**Export**: Calls service `find()`, batch-loads latest notes per redirect in a single query, maps Collection to arrays with DateTime formatting, writes CSV.
**Import**: Reads CSV with `setHeaderOffset(0)`, flexible column names (`From`/`from`), validates and saves via `RedirectService::save()`.

Both CP controller and CLI controller delegate to the same `CsvService` methods.

### CSV Columns (Redirect Import/Export)
From, To, To Type, To Element ID, Status Code, Priority, Enabled, System Generated, Start Date, End Date, Hits, Last Hit, Note

## Logging

Dedicated Monolog target: `not-found-redirects` category, 14-day rotation.
```php
Craft::info('message', NotFoundRedirects::LOG);
```
Logs to `storage/logs/not-found-redirects-YYYY-MM-DD.log`.

## Pattern Matching

Uses Craft's `RedirectRule` class. Two match styles, auto-detected based on `<` presence:

### Exact match
`from` has no `<` tokens → case-insensitive string comparison via SQL.

### Named parameters
`from` contains `<name>` or `<name:regex>` tokens → compiled to regex.

- `<name>` without regex defaults to `[^\/]+` (single segment)
- `<name:regex>` uses the provided regex
- Craft's `UrlRule::regexTokens()` expands `{slug}`, `{handle}`, `{uid}` inside the regex position

### Two-phase lookup in `findMatch()`
1. **Phase 1**: Exact match via SQL `WHERE from = $uri` (fast, indexed)
2. **Phase 2**: Load all regex patterns (`from LIKE '%<%'`), iterate and test each with `RedirectRule::getMatch()`

Both phases filter by `enabled`, `siteId`, `startDate`/`endDate`, ordered by `priority DESC`.

### Pattern reference slideout
The redirect edit screen has a "View Pattern Reference" button in the meta sidebar that opens a `CpScreenSlideout` (with `containerElement: 'div'` for close-only footer) via `actionPatternReference()`. No route registration needed — accessed via action URL.

## Known Considerations

- **PostgreSQL upsert**: Column references must be table-qualified in `ON CONFLICT DO UPDATE SET` (e.g. `{{%notfoundredirects_404s}}.[[hitCount]]`)
- **PostgreSQL DateTime**: DB returns native DateTime objects for date columns — `fromRow()` handles both DateTime and string inputs via `toDateTime()` helper
- **Subdirectory installs**: `normalizeUrlFromRequest()` uses `parse_url()` for path extraction — may need `getFullPath()` if Craft is in a subdirectory (needs testing)
- **410 handling**: Sets status 410 but doesn't call `Craft::$app->end()` — lets ErrorHandler render the 410 template
- **GqlEntityRegistry**: Use `getOrCreate()` not `getEntity() ?? createEntity()` — `getEntity()` returns `false` not `null`
- **Feed Me**: Not supported — requires Craft element types, incompatible with direct database approach
- **Date form fields**: Craft's `dateTimeField` macro POST data arrives as `['date' => '...']` array — controller extracts and converts to DateTime
- **Note rendering**: Use `|md(encode=true)` to safely render notes as Markdown — `encode=true` pre-encodes HTML special characters to prevent XSS while preserving Markdown formatting
- **markHandled fast path**: Exact-match redirects use a single SQL UPDATE; pattern-match redirects iterate all unhandled 404s

## Documentation & Screenshots

Screenshots live in `docs/screenshots/` and are referenced from `README.md`.

### Retaking Screenshots

Screenshots are taken via Chrome DevTools MCP against the local DDEV site (`https://craft-members.ddev.site`). The process:

1. Navigate to the CP page
2. Wait for content to load
3. Take viewport screenshot with `take_screenshot` and `filePath` pointing to `plugins/newism/craft-not-found-redirects/docs/screenshots/`

**Element-level screenshots** (e.g. permissions): Set a temporary `role="region"` + `aria-label` on the target element via `evaluate_script`, retake the snapshot so the element gets a uid, then screenshot that uid. Clean up the attributes afterwards.

**Slideout screenshots**: After opening, scroll the `.so-body` element to top via `evaluate_script` before capturing.

### Screenshot Inventory

| File | Page | Notes |
|------|------|-------|
| `404s-list.png` | `/not-found-redirects/404s` | 404s listing with sidebar filters |
| `404-detail.png` | `/not-found-redirects/404s/detail/<id>` | Individual 404 with handled status, redirect link, zilch referrers |
| `redirects-list.png` | `/not-found-redirects/redirects` | Redirects listing with status, hits, sidebar |
| `redirect-new.png` | `/not-found-redirects/redirects/new?from=...` | New redirect form pre-filled from a 404 |
| `redirect-edit.png` | `/not-found-redirects/redirects/edit/<id>` | Edit form with meta sidebar (user chip, status, hits) |
| `pattern-reference-slideout.png` | Slideout from edit page | Pattern matching reference, scrolled to top |
| `permissions.png` | `/settings/users/groups/<id>` | Element screenshot of `.user-permissions` div for 404 Redirects |

## Future Work

- Rulesets (pre-built redirect sets for WordPress, security probes, etc.)
- Bulk actions with checkboxes in VueAdminTable
- GA4 data import for historical 404 backfill
- URL-based scheduled import (fetch CSV/JSON from URL on cron)
