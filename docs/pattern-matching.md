# Pattern Matching

Redirect rules use Craft's built-in `RedirectRule` class for matching. A pattern reference slideout is available from the redirect edit screen's meta sidebar.

![Pattern reference slideout](./screenshots/pattern-reference-slideout.png)

## Exact Match (case-insensitive)

```
old-page  ->  new-page
about/team  ->  team
```

## Named Parameters

Named parameters use angle brackets to capture URL segments. Without a regex, `<name>` matches any single path segment (`[^\/]+`):

```
blog/<slug>  ->  news/<slug>
```

Add a regex after the colon for more control:

```
products/<id:\d+>  ->  shop/<id>
blog/<year:\d{4}>/<month:\d{2}>/<slug>  ->  news/<year>/<month>/<slug>
old/<path:.*>  ->  new/<path>
```
