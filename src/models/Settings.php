<?php

namespace newism\notfoundredirects\models;

use craft\base\Model;

/**
 * 404 Redirects settings.
 *
 * Configure via config/not-found-redirects.php
 *
 * @see \newism\notfoundredirects\Redirects
 */
class Settings extends Model
{
    /**
     * Gate callback — should the plugin process this 404 at all?
     * Return false for a fast minimal 404 text response (no DB, no logging, no template).
     * Runs before any plugin logic.
     *
     * function(\craft\web\Request $request): bool
     *
     * @var callable|null
     */
    public mixed $shouldHandle = null;

    /**
     * Custom URI normalization callback.
     * Receives the request, returns the normalized URI for DB matching.
     * Default: $request->getFullPath() (path only, no query params).
     *
     * function(\craft\web\Request $request): string
     *
     * @var callable|null
     */
    public mixed $normalizeUrlFromRequest = null;

    /**
     * Transform the redirect destination URL before the Location header is sent.
     * Only called when a redirect rule matches.
     *
     * function(string $destinationUrl, string $matchedUri, \newism\notfoundredirects\models\Redirect $redirect): string
     *
     * @var callable|null
     */
    public mixed $normalizeRedirectUrl = null;

    /**
     * Dry-run mode — run the full pipeline but don't execute redirects or write to the DB.
     * Logs what would have happened. Useful for testing alongside another redirect plugin.
     */
    public bool $dryRun = false;

    /**
     * Automatically create redirects when an element's URI changes.
     */
    public bool $createUriChangeRedirects = true;

    /**
     * HTTP status code for auto-created redirects (301 or 302).
     */
    public int $autoRedirectStatusCode = 302;

    protected function defineRules(): array
    {
        return [
            [['createUriChangeRedirects'], 'boolean'],
            [['autoRedirectStatusCode'], 'in', 'range' => [301, 302]],
        ];
    }
}
