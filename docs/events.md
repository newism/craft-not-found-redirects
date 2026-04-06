---
outline: deep
---

# Events

The 404 handling pipeline can be customized using events on `NotFoundUriService`. These follow Craft's standard [event patterns](https://craftcms.com/docs/5.x/extend/events.html).

Register event handlers in your module or plugin's `init()` method:

```php
use craft\base\Event;
use newism\notfoundredirects\services\NotFoundUriService;
```

See [How It Works](how-it-works.md) for the full pipeline flowchart and event sequence.

## `EVENT_BEFORE_HANDLE`

Fires before the plugin does anything. Cancel to skip plugin handling entirely. Craft will render its normal 404 template.

**Event class:** `newism\notfoundredirects\events\NotFoundUriEvent` (extends `CancelableEvent`)

| Property | Type | Description |
|---|---|---|
| `request` | `craft\web\Request` | The current request |
| `siteId` | `int` | The current site ID |
| `isValid` | `bool` | Set to `false` to cancel |

### Example: Skip handling for bot probes

::: tip Block bots at the edge
For best performance, block known bot probes and junk requests at the web server or CDN level (Nginx, Cloudflare, or a bad bot blocker). A request that never reaches Craft is always faster than one Craft has to process. Use this event for anything that slips through.
:::

```php
use newism\notfoundredirects\events\NotFoundUriEvent;

Event::on(
    NotFoundUriService::class,
    NotFoundUriService::EVENT_BEFORE_HANDLE,
    function(NotFoundUriEvent $event) {
        // Don't log or redirect known junk paths
        if (preg_match('#^(wp-|\.env|\.git)#i', $event->request->getPathInfo())) {
            $event->isValid = false;
        }
    }
);
```

### Example: Skip handling for specific file extensions

```php
use newism\notfoundredirects\events\NotFoundUriEvent;

Event::on(
    NotFoundUriService::class,
    NotFoundUriService::EVENT_BEFORE_HANDLE,
    function(NotFoundUriEvent $event) {
        // Don't process 404s for image or font files
        if (preg_match('/\.(jpg|png|gif|svg|woff2?|ttf|eot)$/i', $event->request->getPathInfo())) {
            $event->isValid = false;
        }
    }
);
```

## `EVENT_DEFINE_URI`

Fires after the URI has been extracted from the request. Modify `$event->uri` to change what the plugin matches against.

The default URI comes from `$request->getPathInfo()`, which strips the site base URL prefix for multi-site subfolder setups.

**Event class:** `newism\notfoundredirects\events\DefineNotFoundUriEvent` (extends `Event`)

| Property | Type | Description |
|---|---|---|
| `request` | `craft\web\Request` | The current request |
| `uri` | `string` | The URI to match against (mutable) |
| `siteId` | `int` | The current site ID |

### Example: Preserve query parameters

```php
use newism\notfoundredirects\events\DefineNotFoundUriEvent;

Event::on(
    NotFoundUriService::class,
    NotFoundUriService::EVENT_DEFINE_URI,
    function(DefineNotFoundUriEvent $event) {
        $q = $event->request->getQueryParam('q');
        if ($q !== null) {
            $event->uri .= '?q=' . $q;
        }
    }
);
```

### Example: Strip trailing slashes

```php
use newism\notfoundredirects\events\DefineNotFoundUriEvent;

Event::on(
    NotFoundUriService::class,
    NotFoundUriService::EVENT_DEFINE_URI,
    function(DefineNotFoundUriEvent $event) {
        $event->uri = rtrim($event->uri, '/');
    }
);
```

## `EVENT_BEFORE_REDIRECT`

Fires after a matching redirect is found but before the redirect is sent. Modify `$event->destinationUrl` to change the destination, or cancel to prevent the redirect (the 404 is still logged as handled).

**Event class:** `newism\notfoundredirects\events\BeforeRedirectEvent` (extends `CancelableEvent`)

| Property | Type | Description |
|---|---|---|
| `request` | `craft\web\Request` | The current request |
| `uri` | `string` | The matched URI |
| `redirect` | `Redirect` | The matched redirect model |
| `destinationUrl` | `string` | The destination URL (mutable) |
| `isValid` | `bool` | Set to `false` to cancel the redirect |

### Example: Preserve query string on temporary redirects

```php
use newism\notfoundredirects\events\BeforeRedirectEvent;

Event::on(
    NotFoundUriService::class,
    NotFoundUriService::EVENT_BEFORE_REDIRECT,
    function(BeforeRedirectEvent $event) {
        if ($event->redirect->statusCode === 302) {
            $params = \Craft::$app->getRequest()->getQueryParams();
            $event->destinationUrl = \craft\helpers\UrlHelper::urlWithParams(
                $event->destinationUrl,
                $params,
            );
        }
    }
);
```

### Example: Add UTM tracking to redirects

```php
use newism\notfoundredirects\events\BeforeRedirectEvent;

Event::on(
    NotFoundUriService::class,
    NotFoundUriService::EVENT_BEFORE_REDIRECT,
    function(BeforeRedirectEvent $event) {
        $event->destinationUrl = \craft\helpers\UrlHelper::urlWithParams(
            $event->destinationUrl,
            ['utm_source' => '404-redirect'],
        );
    }
);
```

## `EVENT_AFTER_REDIRECT`

Fires after the redirect has been sent. This is informational only and you cannot modify the response at this point. Useful for analytics, logging, or sending notifications.

**Event class:** `newism\notfoundredirects\events\AfterRedirectEvent` (extends `Event`)

| Property | Type | Description |
|---|---|---|
| `request` | `craft\web\Request` | The current request |
| `uri` | `string` | The matched URI |
| `redirect` | `Redirect` | The matched redirect model |
| `destinationUrl` | `string` | The final destination URL |
| `statusCode` | `int` | The HTTP status code used |

### Example: Log redirects to an external service

```php
use newism\notfoundredirects\events\AfterRedirectEvent;

Event::on(
    NotFoundUriService::class,
    NotFoundUriService::EVENT_AFTER_REDIRECT,
    function(AfterRedirectEvent $event) {
        // Send to your analytics or monitoring service
        MyAnalytics::trackRedirect(
            from: $event->uri,
            to: $event->destinationUrl,
            statusCode: $event->statusCode,
        );
    }
);
```
