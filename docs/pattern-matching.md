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

## Raw Regex Match

Enable **Raw Regex Match** when you want full control over regular expression behavior.

- Patterns are passed directly to PHP regex with backticks as delimiters (for example: `` `blog/(.*)`i ``).
- Do **not** include delimiters in the field value — enter only the pattern.
- `^` and `$` are **not** added automatically. Add them yourself if you want to force a full-string match.
- Destination URLs can use `$1`, `$2`, etc. from capture groups.

Examples:

```
blog/(.*)  ->  news/$1
^legacy/(.*)$  ->  archive/$1
```
