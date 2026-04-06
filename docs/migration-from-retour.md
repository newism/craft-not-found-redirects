---
outline: deep
---

# Migration From Retour

This plugin can run alongside Retour as long as Retour is **disabled**. This lets you migrate your data and test everything before committing to the switch.

1. Install and enable 404 Redirects
2. Run the database migration via console command (recommended) or CSV import (see below)
3. Review the migrated data in the control panel
4. Disable Retour
5. Run 404 Redirects for a while and verify everything works as expected
6. When you're happy, uninstall Retour

## Database Migration via Console Command (recommended)

If Retour is still installed alongside 404 Redirects, run the migration console command to import directly from Retour's database tables. This is the most complete migration path, importing 404 stats, referrers, hit counts, redirect rules, status codes, and priorities.

:::consolecommand
command: php craft not-found-redirects/migrate/retour
:::

You can also migrate 404s and redirects separately:

:::consolecommand
command: php craft not-found-redirects/migrate/retour-404s
:::

:::consolecommand
command: php craft not-found-redirects/migrate/retour-redirects
:::

::: warning
Retour's database tables must still exist when running the migration. Uninstall Retour **after** you've verified the data.
:::

The migration:

- Imports 404 stats from `retour_stats` including hit counts and referrers
- Imports static redirect rules from `retour_static_redirects` with status codes and priorities
- Skips records that already exist (matched by URI), safe to run multiple times

## CSV Import via Control Panel (across environments)

Use CSV import when migrating data between environments, for example exporting from a production Retour install and importing into a local or staging site running 404 Redirects.

::: warning Limited compared to console command
CSV import does not capture all data that the console command migration does. If both plugins are on the same server, prefer the console command above.
:::

First export your data from Retour's control panel (redirects and/or 404 statistics) and transfer the CSV files to the target environment.

### Using the Console

:::consolecommand
command: php craft not-found-redirects/redirects/import
arguments:
  - name: path
    accepts: string
    description: Path to the Retour CSV export file.
options:
  - name: --source
    accepts: native|retour
    description: Import source format. Auto-detected from column headers if omitted.
examples:
  - php craft not-found-redirects/redirects/import retour-redirects.csv
:::

:::consolecommand
command: php craft not-found-redirects/not-found-uris/import
arguments:
  - name: path
    accepts: string
    description: Path to the Retour CSV export file.
options:
  - name: --source
    accepts: native|retour
    description: Import source format. Auto-detected from column headers if omitted.
examples:
  - php craft not-found-redirects/not-found-uris/import retour-404s.csv
:::

### Using the Control Panel

Import via the control panel: go to **Redirects → Import** or **404s → Import** and select "Retour" as the source format.
