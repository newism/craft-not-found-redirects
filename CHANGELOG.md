# Release Notes for 404 Redirects

## 1.0.0-beta.1 - 2026-03-26

### Added

- 404 interception via `ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION`
- Redirect rules with Craft's `RedirectRule` pattern matching (exact and regex)
- Auto-redirect creation on element URI change with chain flattening
- 404 logging with hit counts, referrer tracking, and handled/unhandled status
- Redirect loop detection at save time
- Scheduled redirects with start/end date support
- 410 Gone support for permanently removed content
- Configurable pipeline with `shouldHandle`, `normalizeUrlFromRequest`, and `normalizeRedirectUrl` callbacks
- Dry-run mode for testing alongside other redirect plugins
- CP control panel with 404s listing, redirect management, and log viewer
- User permissions: view 404s, delete 404s, manage redirects, delete redirects, view logs
- Pattern matching reference slideout
- Meta sidebar with user chips, element chips, and double-click-to-edit
- GraphQL API with `notFoundRedirects404s`, `notFoundRedirects404`, `notFoundRedirectsRedirects`, `notFoundRedirectsRedirect` queries
- CSV export/import for 404s and redirects
- Retour migration CLI commands
- Console commands for export, import, reprocess, and extraction
- Dedicated log target at `storage/logs/not-found-redirects-*.log`
- URI helper for consistent path normalization
