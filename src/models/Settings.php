<?php

namespace newism\notfoundredirects\models;

use craft\base\Model;

/**
 * 404 Redirects settings.
 *
 * Configure via config/not-found-redirects.php
 *
 * @see \newism\notfoundredirects\NotFoundRedirects
 */
class Settings extends Model
{
    /**
     * Automatically create redirects when an element's URI changes.
     */
    public bool $createUriChangeRedirects = true;

    /**
     * HTTP status code for auto-created redirects (301 or 302).
     */
    public int $autoRedirectStatusCode = 302;

    /**
     * Maximum referrers to keep per 404. Oldest trimmed during GC and probabilistically on write.
     * 0 = unlimited.
     */
    public int $maxReferrersPerNotFoundUri = 100;

    /**
     * Auto-purge 404s not seen within this duration. Accepts int (seconds), DateInterval string ('P90D'), or 0 to disable.
     * @see \craft\helpers\ConfigHelper::durationInSeconds()
     */
    public mixed $purgeStaleNotFoundUriDuration = 0;

    /**
     * Auto-purge referrers not seen within this duration. Same format as above.
     * @see \craft\helpers\ConfigHelper::durationInSeconds()
     */
    public mixed $purgeStaleReferrerDuration = 0;

    protected function defineRules(): array
    {
        return [
            [['createUriChangeRedirects'], 'boolean'],
            [['autoRedirectStatusCode'], 'in', 'range' => [301, 302]],
            [['maxReferrersPerNotFoundUri'], 'integer', 'min' => 0],
        ];
    }
}
