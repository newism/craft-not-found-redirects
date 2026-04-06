<?php

/**
 * 404 Redirects config.php
 *
 * This file exists as a template for the 404 Redirects settings.
 * It does nothing on its own.
 *
 * To use it, copy it to config/not-found-redirects.php and make your changes there.
 * Settings can be overridden per-environment using multi-environment config:
 * https://craftcms.com/docs/5.x/configure.html#multi-environment-configs
 *
 * To customize the 404 handling pipeline, use events on NotFoundUriService:
 * - EVENT_BEFORE_HANDLE — cancel to skip plugin handling (Craft renders its 404 template)
 * - EVENT_DEFINE_URI — modify the URI used for redirect matching
 * - EVENT_BEFORE_REDIRECT — modify the destination URL or cancel the redirect
 * - EVENT_AFTER_REDIRECT — post-redirect hook for analytics/logging
 *
 * @see \newism\notfoundredirects\models\Settings
 * @see \newism\notfoundredirects\services\NotFoundUriService
 */

return [

    // Automatically create a redirect when an element's slug or URI changes.
    // Default: true
    'createUriChangeRedirects' => true,

    // HTTP status code for automatically created redirects.
    // Default: 302
    'autoRedirectStatusCode' => 302,

    // ── Garbage Collection ────────────────────────────────────────────

    // Maximum referrers per 404. Oldest trimmed during GC and probabilistically on write.
    // Default: 100. Set to 0 for unlimited.
    'maxReferrersPerNotFoundUri' => 100,

    // Auto-purge 404s not seen within this duration. Runs during Craft's GC.
    // Accepts int (seconds), DateInterval string ('P90D'), or 0 to disable.
    // Default: 0 (disabled)
    // 'purgeStaleNotFoundUriDuration' => 'P90D',

    // Auto-purge referrers not seen within this duration.
    // Same format as above. Default: 0 (disabled)
    // 'purgeStaleReferrerDuration' => 'P90D',

];
