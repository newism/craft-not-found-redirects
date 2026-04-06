---
outline: deep
---

# Logs

View the plugin's log files directly in the control panel under **404 Redirects → Logs**.

A dropdown selects between available log files (rotated daily, 14-day retention). Only files matching the plugin's log prefix are shown.

Log files are stored at `storage/logs/not-found-redirects-YYYY-MM-DD.log`.

The plugin logs key events including request handling, redirect matching, redirect CRUD operations, auto-redirect creation, chain flattening, 404 management, and CLI operations.
