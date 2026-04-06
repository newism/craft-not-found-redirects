# Release Notes for 404 Redirects

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
