---
outline: deep
---

# Export & Import

Export and import 404s and redirects as CSV or JSON files. Useful for backups, bulk loading, or transferring data between environments.

## Exporting

Export via the control panel (**404s → Export** or **Redirects → Export**) or the [console commands](console-commands.md). Both CSV and JSON formats are supported.

Redirect exports include all notes per redirect.

## Importing

Import via the control panel (**404s → Import** or **Redirects → Import**) or the [console commands](console-commands.md). File format is auto-detected from the file extension, with content sniffing as a fallback for stdin or temp uploads.

::: tip Migrating from Retour?
See [Migration From Retour](migration-from-retour.md) for Retour-specific import instructions.
:::
