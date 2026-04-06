<p align="center"><img src="src/icon.svg" width="100" height="100" alt="404 Redirects icon"></p>
<h1 align="center">404 Redirects for Craft CMS</h1>

**Catch every broken link. Know where they come from. Fix them fast.**

This plugin captures 404s at the end of the request lifecycle — after Craft's routing has already failed — giving you full visibility into broken links and the tools to resolve them.

## Features

- **404 Monitoring** — Capture every 404 with hit counts, timestamps, and handled/unhandled status
- **Referrer Tracking** — Know exactly where broken links come from, with multiple referrers per 404
- **Smart Redirects** — Exact match, named parameters (`<slug>`, `<year:\d{4}>`), or pure regex
- **Auto-Redirects** — Automatic redirects when editors move entries or update URLs, with chain flattening
- **Entry Sidebar** — View and manage incoming redirects directly from the entry editor
- **Dashboard Widgets** — Table, trend chart, and coverage chart widgets
- **Native Craft 5.x UI** — Fast, familiar, consistent. No custom frameworks.
- **Export & Migration** — CSV/JSON export. Retour migration tools included.

## Requirements

- Craft CMS 5.6+
- PHP 8.2+

## Installation

```bash
composer require newism/craft-not-found-redirects
php craft plugin/install not-found-redirects
```

## Documentation

Full documentation is available at https://plugins.newism.com.au/not-found-redirects.

## Support

For support, please [open an issue on GitHub](https://github.com/newism/craft-not-found-redirects/issues).
