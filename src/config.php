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
 * @see \newism\notfoundredirects\models\Settings
 */

return [

    // ── Pipeline Step 1: Gate ──────────────────────────────────────────

    // Should the plugin process this 404 at all?
    // Return false for a fast minimal "404 Not Found" text response.
    // No DB query, no logging, no template rendering.
    // Default: null (all requests processed)
    //
    // 'shouldHandle' => function(\craft\web\Request $request): bool {
    //     $path = $request->getFullPath();
    //
    //     // Don't process common bot probes
    //     if (preg_match('#^(wp-|\.env|\.git|xmlrpc|phpmyadmin)#i', $path)) {
    //         return false;
    //     }
    //
    //     // Don't process bot user agents
    //     if (str_contains($request->getUserAgent() ?? '', 'bot')) {
    //         return false;
    //     }
    //
    //     return true;
    // },

    // ── Pipeline Step 2: Normalize URI ─────────────────────────────────

    // Custom callback to normalize the request URI before DB matching.
    // Receives the Craft request, returns the URI string used for
    // matching against redirect rules and 404 aggregation.
    // Default: null ($request->getFullPath() — path only, no query params)
    //
    // 'normalizeUrlFromRequest' => function(\craft\web\Request $request): string {
    //     $uri = $request->getFullPath();
    //
    //     // Preserve the search query param
    //     $q = $request->getQueryParam('q');
    //     if ($q !== null) {
    //         $uri = \craft\helpers\UrlHelper::urlWithParams($uri, ['q' => $q]);
    //     }
    //
    //     return $uri;
    // },

    // ── Pipeline Step 4: Transform Redirect URL ────────────────────────

    // Post-process the redirect destination URL before the Location header
    // is sent. Only called when a redirect rule matches.
    // Default: null (destination URL used as-is)
    //
    // 'normalizeRedirectUrl' => function(
    //     string $destinationUrl,
    //     string $matchedUri,
    //     \newism\notfoundredirects\models\Redirect $redirect,
    // ): string {
    //     // Preserve original query string on temporary redirects
    //     if ($redirect->statusCode === 302) {
    //         $params = \Craft::$app->getRequest()->getQueryParams();
    //         $destinationUrl = \craft\helpers\UrlHelper::urlWithParams($destinationUrl, $params);
    //     }
    //     return $destinationUrl;
    // },

    // ── Dry Run ─────────────────────────────────────────────────────────

    // Run the full pipeline but don't execute redirects or write to the DB.
    // Logs what would have happened. Useful for testing alongside Retour
    // or another redirect plugin before switching over.
    // Default: false
    'dryRun' => false,

    // ── Auto-Redirects ─────────────────────────────────────────────────

    // Automatically create a redirect when an element's slug or URI changes.
    // Default: true
    'createUriChangeRedirects' => true,

    // HTTP status code for automatically created redirects.
    // Default: 302
    'autoRedirectStatusCode' => 302,

];
