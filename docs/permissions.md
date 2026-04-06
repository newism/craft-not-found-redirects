# Permissions

The plugin registers ten permissions under the "404 Redirects" heading.

```
- View 404s
  ├── Delete 404s
  ├── Import 404s
  └── Export 404s
- View redirects
  ├── Save redirects
  ├── Delete redirects
  ├── Import redirects
  └── Export redirects
- View logs
```

The CP subnav is permission-gated: "404s" requires `view404s`, "Redirects" requires `viewRedirects`, "Logs" requires `viewLogs`. If a user has no relevant permissions, the plugin nav item is hidden entirely.

Each controller action enforces its own `requirePermission()` - no `beforeAction` gates.
