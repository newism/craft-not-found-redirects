# Console Commands

All commands are available via `ddev php craft` (or `php craft` outside DDEV).

Export and import commands support stdin and stdout for piping. This makes them useful for scripting, CI/CD pipelines, and AI agent workflows:

```bash
# Pipe 404 export to another tool
php craft not-found-redirects/not-found-uris/export - --format=json | jq '.[] | select(.hitCount > 10)'

# Import from stdin
cat redirects.csv | php craft not-found-redirects/redirects/import - --format=csv
```

## 404s

### Export

Export all 404 records.

:::consolecommand
command: php craft not-found-redirects/not-found-uris/export
arguments:
  - name: path
    accepts: string
    description: File path to write to, or `-` for stdout. Defaults to `-`.
options:
  - name: --format
    accepts: csv|json
    description: File format override. Inferred from file extension if omitted. Defaults to json for stdout.
examples:
  - php craft not-found-redirects/not-found-uris/export
  - php craft not-found-redirects/not-found-uris/export 404s.csv
  - php craft not-found-redirects/not-found-uris/export - --format=csv
:::

### Import

Import 404 records from a CSV or JSON file, or stdin.

:::consolecommand
command: php craft not-found-redirects/not-found-uris/import
arguments:
  - name: path
    accepts: string
    description: Path to the CSV or JSON import file. Use `-` to read from stdin.
options:
  - name: --format
    accepts: csv|json
    description: File format override. Auto-detected from file extension or content if omitted.
  - name: --source
    accepts: native|retour
    description: Data source. Auto-detected from column headers if omitted.
examples:
  - php craft not-found-redirects/not-found-uris/import 404s.csv
  - php craft not-found-redirects/not-found-uris/import 404s.json
  - php craft not-found-redirects/not-found-uris/import - --format=csv --source=retour
:::

### Reprocess

Reprocess unhandled 404s against enabled redirect rules.

:::consolecommand
command: php craft not-found-redirects/not-found-uris/reprocess
examples:
  - php craft not-found-redirects/not-found-uris/reprocess
:::

### Purge

Purge stale 404 records older than the given threshold.

:::consolecommand
command: php craft not-found-redirects/not-found-uris/purge
arguments:
  - name: lastSeen
    accepts: string
    description: Purge cutoff — any strtotime()-compatible value (e.g. "-90 days").
examples:
  - php craft not-found-redirects/not-found-uris/purge "-90 days"
:::

### Reset Hit Counts

Reset hit counts to zero for all 404s and referrers.

:::consolecommand
command: php craft not-found-redirects/not-found-uris/reset-hit-counts
examples:
  - php craft not-found-redirects/not-found-uris/reset-hit-counts
:::

## Redirects

### Export

Export all redirect rules.

:::consolecommand
command: php craft not-found-redirects/redirects/export
arguments:
  - name: path
    accepts: string
    description: File path to write to, or `-` for stdout. Defaults to `-`.
options:
  - name: --format
    accepts: csv|json
    description: File format override. Inferred from file extension if omitted. Defaults to json for stdout.
examples:
  - php craft not-found-redirects/redirects/export
  - php craft not-found-redirects/redirects/export redirects.csv
  - php craft not-found-redirects/redirects/export - --format=csv
  - php craft not-found-redirects/redirects/export redirects.txt --format=json
:::

### Import

Import redirect rules from a CSV or JSON file, or stdin.

:::consolecommand
command: php craft not-found-redirects/redirects/import
arguments:
  - name: path
    accepts: string
    description: Path to the CSV or JSON import file. Use `-` to read from stdin.
options:
  - name: --format
    accepts: csv|json
    description: File format override. Auto-detected from file extension or content if omitted.
  - name: --source
    accepts: native|retour
    description: Data source. Auto-detected from column headers if omitted.
examples:
  - php craft not-found-redirects/redirects/import redirects.csv
  - php craft not-found-redirects/redirects/import redirects.json
  - php craft not-found-redirects/redirects/import - --format=csv --source=retour
:::

## Retour Migration

### Migrate All

Migrate both 404 stats and redirect rules from Retour's database tables.

:::consolecommand
command: php craft not-found-redirects/migrate/retour
examples:
  - php craft not-found-redirects/migrate/retour
:::

### Migrate 404s

Migrate 404 stats from Retour's `retour_stats` table.

:::consolecommand
command: php craft not-found-redirects/migrate/retour-404s
examples:
  - php craft not-found-redirects/migrate/retour-404s
:::

### Migrate Redirects

Migrate static redirect rules from Retour.

:::consolecommand
command: php craft not-found-redirects/migrate/retour-redirects
examples:
  - php craft not-found-redirects/migrate/retour-redirects
:::
