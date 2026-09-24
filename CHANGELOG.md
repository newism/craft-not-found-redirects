# Release Notes for 404 Redirects

## 1.1.1 - 2026-09-24

### Fixed

- Fixed an uncaught exception when a request's `Referer` header exceeded the 500-character referrer column, which could return a 500 error in place of the 404 page. Referrers are now sanitised and truncated before being stored.
- A referrer that still can't be stored no longer prevents the 404 itself from being logged.
- Fixed the same issue on the 404 import path, where a long or malformed referrer could abort an in-progress import.
- Guarded 404 handling and garbage collection so an unexpected plugin error can no longer escape into Craft's error handler and turn a 404 into a fatal error.

### Changed

- 404s rejected by validation, such as a URI over 500 characters, now log a warning instead of a debug message, so they are visible outside of Dev Mode.
- Adjusted the plugin log level to include debug messages.

## 1.1.0 - 2026-07-09

### Added

- Added support for `444 — No Response (nginx)` redirect rules without a destination URL.
- Added a sortable `Date Created` column to the redirects index table.

### Changed

- Changed default redirects index sorting to `dateCreated DESC` (newest first).
- Raw regex redirect patterns are now evaluated exactly as written (no implicit `^`/`$` anchors).

## 1.0.0 - 2026-04-09

### Added

- 404 interception via `ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION`
- Redirect rules with Craft's `RedirectRule` pattern matching (exact and regex)
- Auto-redirect creation on element URI change with chain flattening
- Entry destinations with element chip display in redirects table
- 404 logging with hit counts, referrer tracking, and handled/unhandled status
- Redirect loop detection at save time
- Scheduled redirects with start/end date support
- 410 Gone support for permanently removed content
- Configurable pipeline with `shouldHandle`, `normalizeUrlFromRequest`, and `normalizeRedirectUrl` callbacks
- Dry-run mode for testing alongside other redirect plugins
- Multi-site support with `UrlHelper::siteUrl()` redirect resolution for correct site prefixes
- Conditional site column in tables and detail pages when multiple sites exist
- CP control panel with 404s listing, redirect management, and log viewer
- Dashboard widgets: latest/top 404s table, 404 trend area chart, handled/unhandled coverage doughnut
- Inline pattern testing with debounced live results on the redirect edit form
- Status indicators using `Cp::statusLabelHtml()` for handled, enabled, and redirect status
- User permissions: view 404s, delete 404s, manage redirects, delete redirects, view logs
- Pattern matching reference slideout
- Meta sidebar with user chips, element chips, and double-click-to-edit
- GraphQL API with `notFoundRedirects404s`, `notFoundRedirects404`, `notFoundRedirectsRedirects`, `notFoundRedirectsRedirect` queries
- CSV export/import for 404s and redirects with format selector (native + Retour)
- Retour CSV import with automatic regex-to-Craft pattern conversion
- Import results page with per-row status and error reporting
- Retour migration CLI commands
- Console commands for export, import, reprocess, purge, and migration
- Entry sidebar widget showing redirects pointing to an entry with chip action menus
- Quick add redirect from entry sidebar via slideout
- Redirect edit/delete via slideout from entry sidebar chips
- Slideout-safe Garnish JS components using `formAttributes` + `registerJsWithVars`
- Custom event system (`notFoundRedirects:redirectSaved`, `notFoundRedirects:redirectDeleted`) for sidebar refresh
- Dedicated log target at `storage/logs/not-found-redirects-*.log`
- URI helper following Craft convention (no leading slashes)
- Homepage redirect support (empty `to` displayed as `/`)
