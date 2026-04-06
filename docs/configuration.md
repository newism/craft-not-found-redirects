---
outline: deep
---

# Configuration

Create a `config/not-found-redirects.php` file to customise plugin behaviour. This should have been copied automatically on installation.

Settings can be overridden per-environment using [multi-environment config](https://craftcms.com/docs/5.x/configure.html#multi-environment-configs).

## Configuration options

### Auto Redirects

:::config
setting: createUriChangeRedirects
type: bool
default: true
---
Auto-create redirects when element URIs change.
:::

:::config
setting: autoRedirectStatusCode
type: int
default: 302
---
Status code for auto-created redirects (301 or 302). 302 is chosen by default to avoid unintended consequences of 301s being cached by browsers and CDNs.
:::

### Garbage Collection

See the [Garbage Collection](garbage-collection.md) page for full documentation, console commands, and configuration.

## Pipeline Events

The 404 handling pipeline can be customized using events on `NotFoundUriService`. Four events fire during 404 handling, letting you gate requests, modify URIs, transform destinations, and hook into analytics.

See the [Events](events.md) page for full documentation, event properties, and examples.
