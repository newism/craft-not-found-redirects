<?php

namespace newism\notfoundredirects\helpers;

use Craft;

/**
 * URI normalization helpers.
 *
 * URIs are stored without a leading slash (e.g. "blog/my-post"),
 * following Craft's element URI convention. Displayed as-is.
 */
class Uri
{
    /**
     * Strip the leading slash from a URI for storage.
     * Handles null gracefully, returns empty string.
     */
    public static function strip(?string $uri): string
    {
        return ltrim($uri ?? '', '/');
    }

    /**
     * Extract the path from a full URL, stripping scheme, domain, query string, and fragment.
     * If already a relative path, just strips the leading slash.
     *
     * Examples:
     *   https://example.com/blog/post?q=1  →  blog/post
     *   /blog/post                          →  blog/post
     *   blog/post                           →  blog/post
     */
    public static function extractPath(string $url): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return self::strip($url);
        }

        $path = parse_url($url, PHP_URL_PATH);

        return self::strip($path ?? '');
    }

    /**
     * Strip a site's base path prefix from a URI, converting a browser-style
     * path into the site-relative form used for matching (e.g. "en/old-blog"
     * → "old-blog" for a site based at /en/).
     *
     * With a site ID, only that site's base path is considered. Without one,
     * every site's base path is tried — mirroring runtime matching, where each
     * request has its own site's prefix stripped before the plugin sees it.
     */
    public static function stripSiteBasePath(string $uri, ?int $siteId = null): string
    {
        $uri = self::strip($uri);

        $sites = $siteId !== null
            ? array_filter([Craft::$app->getSites()->getSiteById($siteId)])
            : Craft::$app->getSites()->getAllSites();

        foreach ($sites as $site) {
            $basePath = trim(parse_url($site->getBaseUrl() ?? '', PHP_URL_PATH) ?? '', '/');
            if ($basePath && ($uri === $basePath || str_starts_with($uri, $basePath . '/'))) {
                return substr($uri, strlen($basePath) + 1);
            }
        }

        return $uri;
    }

    /**
     * Format a URI for display. Returns '/' for empty/null (homepage).
     * Absolute and protocol-relative URLs pass through unchanged.
     * Relative paths returned as-is (no leading slash — Craft convention).
     */
    public static function display(?string $uri): string
    {
        if ($uri === null || $uri === '') {
            return '/';
        }

        return $uri;
    }
}
