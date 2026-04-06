---
outline: deep
---

# How It Works

The plugin hooks into Craft's `ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION` event. This fires at the **end of the request lifecycle**, after Craft's routing has already failed. This is different from Craft's [built-in redirects](https://craftcms.com/docs/5.x/system/routing.html#redirection) via `config/redirects.php`, which fire at the start of the request before a page is even rendered.

```mermaid
flowchart TD
    A[404 Exception] --> B{EVENT_BEFORE_HANDLE}
    B -->|canceled| C[Craft renders 404 template]
    B -->|continues| D[EVENT_DEFINE_URI]
    D --> E[DB Lookup]
    E --> F{Match found?}
    F -->|Yes| G[Log as handled]
    G --> H[EVENT_BEFORE_REDIRECT]
    H --> I{Status code?}
    I -->|301 / 302 / 307| J[Redirect + end]
    I -->|404| J2[Fast 404 text response]
    I -->|410| K[410 Gone response]
    J --> L2[EVENT_AFTER_REDIRECT]
    F -->|No| L[Log as unhandled]
    L --> M[Craft renders 404 page]
    style B fill: #f9f, stroke: #333
    style F fill: #f9f, stroke: #333
    style I fill: #f9f, stroke: #333
    style C fill: #fee, stroke: #c33
    style J fill: #efe, stroke: #3c3
    style J2 fill: #fee, stroke: #c33
    style K fill: #fee, stroke: #c33
    style M fill: #fee, stroke: #c33
```

## Pipeline

Every 404 flows through this pipeline. [Events](events.md) on `NotFoundUriService` allow you to customize each stage.

1. `EVENT_BEFORE_HANDLE` fires. Cancel to skip plugin handling entirely (Craft renders its normal 404 template).
2. `EVENT_DEFINE_URI` fires. Modify the URI used for redirect matching (defaults to `$request->getPathInfo()`).
3. Exact-match redirects are checked first via a fast SQL query (filtered by `enabled`, `siteId`, `startDate`/`endDate`).
4. If no exact match, pattern and regex redirects are loaded and tested in priority order.
5. If a match is found:
   - The 404 is logged as "handled"
   - `EVENT_BEFORE_REDIRECT` fires. Modify the destination URL or cancel the redirect.
   - The visitor is redirected (301/302/307), shown a 410 Gone page, or given a fast 404 text response.
   - `EVENT_AFTER_REDIRECT` fires. Post-redirect hook for analytics or logging.
6. If no match is found, the 404 is logged as "unhandled" and the error page renders normally.
7. The referrer (if present) is recorded for audit.
