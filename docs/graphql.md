# GraphQL

The plugin registers four root queries. Enable "Query for 404 Redirects data" in your GraphQL schema settings.

```graphql
# List 404s (with optional filtering)
{
  notFoundRedirects404s(handled: false, limit: 10, offset: 0) {
    id siteId uri fullUrl hitCount hitLastTime handled redirectId referrerCount dateCreated dateUpdated
  }
}

# Single 404 by ID or URI
{
  notFoundRedirects404(id: 1) {
    id siteId uri fullUrl hitCount hitLastTime handled redirectId referrerCount dateCreated dateUpdated
  }
}

# List redirect rules
{
  notFoundRedirectsRedirects(limit: 10) {
    id siteId from to toType toElementId statusCode priority enabled startDate endDate
    systemGenerated elementId createdById hitCount hitLastTime dateCreated dateUpdated
  }
}

# Single redirect by ID
{
  notFoundRedirectsRedirect(id: 1) {
    id siteId from to toType toElementId statusCode priority enabled startDate endDate
    systemGenerated elementId createdById hitCount hitLastTime dateCreated dateUpdated
  }
}
```

DateTime fields return ISO 8601 format (e.g. `2026-03-21T16:17:45-07:00`).
