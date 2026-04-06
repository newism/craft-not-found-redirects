<?php

namespace newism\notfoundredirects\helpers;

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
