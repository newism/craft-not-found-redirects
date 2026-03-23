<?php

namespace newism\notfoundredirects\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\AdminTable;
use craft\web\RedirectRule;
use Illuminate\Support\Collection;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\NotFoundRedirects;

class RedirectService extends Component
{
    /**
     * Tracks old URIs before element save for auto-redirect creation.
     * @var array<string, string> "elementId-siteId" => old URI
     */
    private array $_oldUris = [];

    // ── Request Matching ───────────────────────────────────────────────

    /**
     * Find the first matching redirect rule for a given URI and site.
     *
     * @return array|null The matching redirect record (raw), or null
     */
    public function findMatch(string $uri, int $siteId): ?array
    {
        $now = new \DateTime();

        // Phase 1: Try exact match via SQL (fast, indexed)
        $exactMatch = (new Query())
            ->from('{{%notfoundredirects_redirects}}')
            ->where(['enabled' => true, 'from' => $uri])
            ->andWhere([
                'or',
                ['siteId' => null],
                ['siteId' => $siteId],
            ])
            ->andWhere([
                'or',
                ['startDate' => null],
                ['<=', 'startDate', $now->format('Y-m-d H:i:s')],
            ])
            ->andWhere([
                'or',
                ['endDate' => null],
                ['>=', 'endDate', $now->format('Y-m-d H:i:s')],
            ])
            ->orderBy(['priority' => SORT_DESC, 'id' => SORT_ASC])
            ->one();

        if ($exactMatch) {
            $matchedUrl = $this->resolveMatchedUrl($exactMatch, $siteId);
            if ($matchedUrl !== null) {
                $exactMatch['_matchedUrl'] = $matchedUrl;
                return $exactMatch;
            }
        }

        // Phase 2: Try regex patterns (must load and iterate)
        $regexRedirects = (new Query())
            ->from('{{%notfoundredirects_redirects}}')
            ->where(['enabled' => true])
            ->andWhere(['like', 'from', '<'])
            ->andWhere([
                'or',
                ['siteId' => null],
                ['siteId' => $siteId],
            ])
            ->andWhere([
                'or',
                ['startDate' => null],
                ['<=', 'startDate', $now->format('Y-m-d H:i:s')],
            ])
            ->andWhere([
                'or',
                ['endDate' => null],
                ['>=', 'endDate', $now->format('Y-m-d H:i:s')],
            ])
            ->orderBy(['priority' => SORT_DESC, 'id' => SORT_ASC])
            ->all();

        foreach ($regexRedirects as $redirect) {
            $matchedUrl = $this->resolveMatchedUrl($redirect, $siteId);
            if ($matchedUrl !== null) {
                $redirect['_matchedUrl'] = $matchedUrl;
                return $redirect;
            }
        }

        return null;
    }

    /**
     * Resolve the destination URL for a matched redirect based on its toType.
     */
    private function resolveMatchedUrl(array $redirect, int $siteId): ?string
    {
        $toType = $redirect['toType'] ?? 'url';

        if ($toType === 'entry') {
            $toElementId = $redirect['toElementId'] ?? null;
            if ($toElementId) {
                // Always resolve from the live entry — gets the current URL
                $entry = Craft::$app->getElements()->getElementById((int) $toElementId, null, $siteId);
                $entryUrl = $entry?->getUrl();
                if ($entryUrl) {
                    return $entryUrl;
                }
            }
            // Entry was deleted (toElementId is null) — fall back to cached `to`
        }

        // URL type or entry fallback: use RedirectRule (supports pattern tokens)
        $rule = Craft::createObject([
            'class' => RedirectRule::class,
            'from' => $redirect['from'],
            'to' => $redirect['to'] ?: '/',
            'statusCode' => (int) $redirect['statusCode'],
        ]);

        return $rule->getMatch();
    }

    /**
     * Execute the redirect (or 410 response) and end the request.
     */
    public function doRedirect(array $redirect, string $matchedUrl): void
    {
        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $db->createCommand()->update(
            '{{%notfoundredirects_redirects}}',
            [
                'hitCount' => new \yii\db\Expression('{{%notfoundredirects_redirects}}.[[hitCount]] + 1'),
                'hitLastTime' => $now,
                'dateUpdated' => $now,
            ],
            ['id' => $redirect['id']],
        )->execute();

        $statusCode = (int) $redirect['statusCode'];

        if ($statusCode === 410) {
            Craft::$app->getResponse()->setStatusCode(410);
            Craft::info("410 Gone: {$redirect['from']}", NotFoundRedirects::LOG);
            return;
        }

        // Guard against self-redirect at runtime
        $currentPath = Craft::$app->getRequest()->getFullPath();
        $destinationPath = Uri::strip(parse_url($matchedUrl, PHP_URL_PATH));
        if (strcasecmp($currentPath, $destinationPath) === 0) {
            Craft::warning("Redirect loop detected: {$redirect['from']} → {$matchedUrl} (same as current path)", NotFoundRedirects::LOG);
            return;
        }

        Craft::info("Redirecting ({$statusCode}): {$redirect['from']} → {$matchedUrl}", NotFoundRedirects::LOG);
        Craft::$app->getResponse()->redirect($matchedUrl, $statusCode);
        Craft::$app->end();
    }

    /**
     * Test if a `from` pattern matches a given URI (offline, no request context).
     */
    public function matchesUri(string $from, string $uri): bool
    {
        if (str_contains($from, '<')) {
            $pattern = $this->toRegexPattern($from);
            return (bool) preg_match($pattern, $uri);
        }

        return strcasecmp($from, $uri) === 0;
    }

    // ── Write Operations ───────────────────────────────────────────────

    public function save(Redirect $model): bool
    {
        // Normalize from
        $model->from = Uri::strip($model->from);

        // 410 Gone has no destination
        if ($model->statusCode === 410) {
            $model->to = '';
            $model->toElementId = null;
        }

        // Normalize destination based on type
        if ($model->toType === 'entry') {
            // Resolve entry URI into `to` as cache
            $element = $model->toElement;
            if ($element && $element->uri) {
                $model->to = Uri::strip($element->uri);
            }
        } elseif ($model->toType === 'url' && $model->to) {
            // Normalize local URLs to relative paths by stripping any matching site base URL
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $siteUrl = rtrim($site->getBaseUrl(), '/');
                if ($siteUrl && str_starts_with($model->to, $siteUrl)) {
                    $model->to = ltrim(substr($model->to, strlen($siteUrl)), '/') ?: '/';
                    break;
                }
            }
            // Strip leading slash for relative paths (preserve '/', '', and full external URLs)
            if (!preg_match('#^https?://#i', $model->to)) {
                $model->to = ($model->to === '/' || $model->to === '') ? $model->to : Uri::strip($model->to);
            }
        }

        if (!$model->validate()) {
            return false;
        }

        // Resolve destination path for self-redirect and loop detection
        $destinationPath = $model->to;
        if ($model->toType === 'entry' && $model->toElementId) {
            $element = $model->toElement;
            $destinationPath = $element ? Uri::strip($element->uri ?? '') : $model->to;
        }

        // Detect self-redirect
        if ($destinationPath && strcasecmp($model->from, $destinationPath) === 0) {
            $errorField = $model->toType === 'entry' ? 'toElementId' : 'to';
            $model->addError($errorField, 'Redirect destination cannot be the same as the source.');
            return false;
        }

        // Detect redirect chains that loop back
        if ($destinationPath) {
            $loopError = $this->detectLoop($model->from, Uri::strip($destinationPath));
            if ($loopError) {
                $errorField = $model->toType === 'entry' ? 'toElementId' : 'to';
                $model->addError($errorField, $loopError);
                return false;
            }
        }

        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $attributes = [
            'siteId' => $model->siteId,
            'from' => $model->from,
            'to' => $model->to ?? '',
            'toType' => $model->toType,
            'toElementId' => $model->toElementId,
            'statusCode' => $model->statusCode,
            'priority' => $model->priority,
            'enabled' => $model->enabled,
            'startDate' => $model->startDate?->format('Y-m-d H:i:s'),
            'endDate' => $model->endDate?->format('Y-m-d H:i:s'),
            'systemGenerated' => $model->systemGenerated,
            'elementId' => $model->elementId,
            'createdById' => $model->createdById,
        ];

        if ($model->id) {
            $attributes['dateUpdated'] = $now;
            $db->createCommand()->update(
                '{{%notfoundredirects_redirects}}',
                $attributes,
                ['id' => $model->id],
            )->execute();
        } else {
            $attributes['hitCount'] = 0;
            $attributes['dateCreated'] = $now;
            $attributes['dateUpdated'] = $now;
            // Set createdById on new records if not already set
            if (!$model->createdById) {
                $user = Craft::$app->getUser()->getIdentity();
                $attributes['createdById'] = $user?->id;
            }
            $db->createCommand()->insert(
                '{{%notfoundredirects_redirects}}',
                $attributes,
            )->execute();
            $model->id = (int) $db->getLastInsertID();
        }

        Craft::info("Redirect saved: #{$model->id} {$model->from} → {$model->to}", NotFoundRedirects::LOG);

        if ($model->enabled) {
            NotFoundRedirects::getInstance()->notFound->markHandled(
                $model->id,
                $model->siteId,
                $model->from,
            );
        }

        return true;
    }

    /**
     * Create an auto-redirect when an element's URI changes.
     * Flattens existing chains by updating all redirects pointing to the old URI.
     */
    public function createAutoRedirect(string $oldUri, string $newUri, int $elementId, int $siteId, int $statusCode = 301): void
    {
        $oldUri = Uri::strip($oldUri);
        $newUri = Uri::strip($newUri);

        if (!$oldUri || !$newUri || strcasecmp($oldUri, $newUri) === 0) {
            return;
        }

        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // 1. Flatten chains: update any existing redirects for this element to point to the new URI
        $updated = $db->createCommand()->update(
            '{{%notfoundredirects_redirects}}',
            ['to' => $newUri, 'dateUpdated' => $now],
            ['elementId' => $elementId],
        )->execute();

        if ($updated > 0) {
            Craft::info("Flattened {$updated} redirect chain(s) for element #{$elementId} → /{$newUri}", NotFoundRedirects::LOG);
        }

        // 2. Also update any redirect (manual or auto) whose `to` matches the old URI
        $db->createCommand()->update(
            '{{%notfoundredirects_redirects}}',
            ['to' => $newUri, 'dateUpdated' => $now],
            ['to' => $oldUri],
        )->execute();

        // 3. Clean up self-redirects created by chain flattening (from === to)
        $deleted = $db->createCommand()
            ->delete('{{%notfoundredirects_redirects}}', new \yii\db\Expression('[[from]] = [[to]]'))
            ->execute();

        if ($deleted > 0) {
            Craft::info("Removed {$deleted} self-redirect(s) after chain flattening", NotFoundRedirects::LOG);
        }

        // 4. Check if a redirect from oldUri already exists (could be from step 1)
        $existing = (new Query())
            ->from('{{%notfoundredirects_redirects}}')
            ->where(['from' => $oldUri, 'siteId' => $siteId])
            ->one();

        if ($existing) {
            // Update existing redirect to point to new URI
            $db->createCommand()->update(
                '{{%notfoundredirects_redirects}}',
                [
                    'to' => $newUri,
                    'elementId' => $elementId,
                    'systemGenerated' => true,
                    'dateUpdated' => $now,
                ],
                ['id' => $existing['id']],
            )->execute();

            NotFoundRedirects::getInstance()->notes->addNote(
                (int) $existing['id'],
                "Auto-updated: URI changed to /{$newUri}",
                systemGenerated: true,
            );

            Craft::info("Auto-updated redirect: /{$oldUri} → /{$newUri} (element #{$elementId})", NotFoundRedirects::LOG);
        } else {
            // Create new redirect
            $model = new Redirect();
            $model->siteId = $siteId;
            $model->from = $oldUri;
            $model->to = $newUri;
            $model->statusCode = $statusCode;
            $model->priority = 0;
            $model->enabled = true;
            $model->systemGenerated = true;
            $model->elementId = $elementId;

            $this->save($model);

            NotFoundRedirects::getInstance()->notes->addNote(
                $model->id,
                "Auto-created: URI changed from /{$oldUri} to /{$newUri}",
                systemGenerated: true,
            );

            Craft::info("Auto-created redirect: /{$oldUri} → /{$newUri} (element #{$elementId})", NotFoundRedirects::LOG);
        }
    }

    // ── URI Change Tracking ───────────────────────────────────────────

    /**
     * Stash an element's current URI before it changes.
     *
     * For EVENT_BEFORE_UPDATE_SLUG_AND_URI, $element->uri may already be updated
     * in memory by setElementUri() before the event fires. So we always read
     * the old URI from the database to get the true pre-change value.
     */
    public function stashOldUri(ElementInterface $element): void
    {
        if (!NotFoundRedirects::getInstance()->getSettings()->createUriChangeRedirects) {
            return;
        }

        if (!$this->shouldTrackElement($element)) {
            return;
        }

        $key = $element->id . '-' . $element->siteId;

        // Only stash once per element per request
        if (isset($this->_oldUris[$key])) {
            return;
        }

        // Read from DB — $element->uri may already be the new value
        $dbUri = Craft::$app->getElements()->getElementUriForSite($element->id, $element->siteId);

        if ($dbUri !== null) {
            $this->_oldUris[$key] = $dbUri;
        }
    }

    /**
     * Compare old vs new URI and create a redirect if it changed.
     */
    public function createRedirectIfUriChanged(ElementInterface $element): void
    {
        $settings = NotFoundRedirects::getInstance()->getSettings();

        if (!$settings->createUriChangeRedirects) {
            return;
        }

        if (!$this->shouldTrackElement($element)) {
            unset($this->_oldUris[$element->id . '-' . $element->siteId]);
            return;
        }

        $key = $element->id . '-' . $element->siteId;
        $oldUri = $this->_oldUris[$key] ?? null;
        unset($this->_oldUris[$key]);

        if (!$oldUri || !$element->uri || $oldUri === $element->uri) {
            return;
        }

        $this->createAutoRedirect(
            $oldUri,
            $element->uri,
            $element->id,
            $element->siteId,
            $settings->autoRedirectStatusCode,
        );
    }

    /**
     * Determine if an element's URI change should be tracked for auto-redirects.
     */
    private function shouldTrackElement(ElementInterface $element): bool
    {
        if ($element->getIsDraft() || $element->getIsRevision()) {
            return false;
        }

        if ($element->propagating) {
            return false;
        }

        if ($element->firstSave) {
            return false;
        }

        if ($element->duplicateOf && $element->getIsCanonical() && !$element->updatingFromDerivative) {
            return false;
        }

        if ($element->resaving) {
            return false;
        }

        if (!$element->uri || $element->uri === Element::HOMEPAGE_URI || str_contains($element->uri, '__temp_')) {
            return false;
        }

        return true;
    }

    public function deleteById(int $id): bool
    {
        NotFoundRedirects::getInstance()->notFound->unmarkHandled($id);

        $rows = Craft::$app->getDb()->createCommand()
            ->delete('{{%notfoundredirects_redirects}}', ['id' => $id])
            ->execute();

        if ($rows > 0) {
            Craft::info("Redirect deleted: #{$id}", NotFoundRedirects::LOG);
        }

        return $rows > 0;
    }

    // ── Read Operations (return models/collections) ────────────────────

    /**
     * @return Collection<int, Redirect>
     */
    public function find(?string $search = null, ?bool $systemGenerated = null, string $sortField = 'priority', int $sortDir = SORT_DESC, int $offset = 0, int $limit = 50): Collection
    {
        $sortColumn = match ($sortField) {
            '__slot:title', 'from' => 'from',
            'to' => 'to',
            'statusCode' => 'statusCode',
            'enabled' => 'enabled',
            'hitCount' => 'hitCount',
            default => 'priority',
        };

        $query = (new Query())->from('{{%notfoundredirects_redirects}}');

        if ($search) {
            $query->andWhere([
                'or',
                ['like', 'from', $search],
                ['like', 'to', $search],
            ]);
        }

        if ($systemGenerated !== null) {
            $query->andWhere(['systemGenerated' => $systemGenerated]);
        }

        $rows = $query
            ->orderBy([$sortColumn => $sortDir])
            ->offset($offset)
            ->limit($limit)
            ->all();

        return Collection::make($rows)->map(fn(array $row) => Redirect::fromRow($row));
    }

    /**
     * @return Collection<int, Redirect>
     */
    public function findByToElementId(int $elementId): Collection
    {
        $rows = (new Query())
            ->from('{{%notfoundredirects_redirects}}')
            ->where(['toElementId' => $elementId, 'toType' => 'entry'])
            ->orderBy(['priority' => SORT_DESC])
            ->all();

        return Collection::make($rows)->map(fn(array $row) => Redirect::fromRow($row));
    }

    public function findById(int $id): ?Redirect
    {
        $row = (new Query())
            ->from('{{%notfoundredirects_redirects}}')
            ->where(['id' => $id])
            ->one();

        return $row ? Redirect::fromRow($row) : null;
    }

    public function count(?string $search = null, ?bool $systemGenerated = null): int
    {
        $query = (new Query())->from('{{%notfoundredirects_redirects}}');

        if ($search) {
            $query->andWhere([
                'or',
                ['like', 'from', $search],
                ['like', 'to', $search],
            ]);
        }

        if ($systemGenerated !== null) {
            $query->andWhere(['systemGenerated' => $systemGenerated]);
        }

        return (int) $query->count();
    }

    // ── Table Data (VueAdminTable formatting) ──────────────────────────

    /**
     * @return array{pagination: array, data: array}
     */
    public function getTableData(int $page = 1, int $limit = 50, ?string $search = null, ?bool $systemGenerated = null, string $sortField = 'priority', int $sortDir = SORT_DESC): array
    {
        $total = $this->count(search: $search, systemGenerated: $systemGenerated);
        $offset = ($page - 1) * $limit;

        $items = $this->find(
            search: $search,
            systemGenerated: $systemGenerated,
            sortField: $sortField,
            sortDir: $sortDir,
            offset: $offset,
            limit: $limit,
        );

        $tableData = $items->map(fn(Redirect $r) => [
            'id' => $r->id,
            'title' => Uri::display($r->from),
            'url' => $r->getCpEditUrl(),
            'to' => match ($r->toType) {
                'entry' => $r->toElement
                    ? $r->toElement->title . ' → ' . Uri::display($r->to)
                    : Uri::display($r->to) . ' (entry deleted)',
                default => Uri::display($r->to),
            },
            'statusCode' => $r->statusCode,
            'priority' => $r->priority,
            'status' => $r->getStatus(),
            'hitCount' => $r->hitCount,
            'hitLastTime' => $r->hitLastTime ? Craft::$app->getFormatter()->asDatetime($r->hitLastTime, 'short') : '-',
        ])->all();

        return [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $tableData,
        ];
    }

    // ── Private ────────────────────────────────────────────────────────

    /**
     * Trace redirect chain from a destination URI to detect loops.
     * Uses matchesUri() for pattern matching (handles both exact and regex rules).
     * Returns error message if loop detected, null if safe.
     */
    private function detectLoop(string $originalFrom, string $destination, int $maxDepth = 10): ?string
    {
        $visited = [$originalFrom];
        $current = Uri::strip($destination);
        $chain = [$originalFrom, $destination];

        $allRedirects = (new Query())
            ->select(['from', 'to'])
            ->from('{{%notfoundredirects_redirects}}')
            ->where(['enabled' => true])
            ->all();

        for ($i = 0; $i < $maxDepth; $i++) {
            // Find any redirect whose `from` pattern matches the current destination
            $next = null;
            foreach ($allRedirects as $redirect) {
                if ($this->matchesUri($redirect['from'], $current)) {
                    $next = $redirect;
                    break;
                }
            }

            if (!$next) {
                return null; // Chain ends, no loop
            }

            $nextTo = Uri::strip($next['to'] ?? '');
            $chain[] = $next['to'];

            // Check if the next destination matches any URI we've already visited
            foreach ($visited as $v) {
                if ($this->matchesUri($v, $nextTo) || $this->matchesUri($nextTo, $v)) {
                    return 'Redirect loop detected: ' . implode(' → ', $chain);
                }
            }

            $visited[] = $current;
            $current = $nextTo;
        }

        return 'Redirect chain exceeds maximum depth (' . $maxDepth . '): ' . implode(' → ', $chain);
    }

    private function toRegexPattern(string $from): string
    {
        $regexTokens = [];

        $tokenizedPattern = preg_replace_callback('/<([\w._-]+):?([^>]+)?>/', function($match) use (&$regexTokens) {
            $name = $match[1];
            $pattern = strtr($match[2] ?? '[^\/]+', \craft\web\UrlRule::regexTokens());
            $token = "<$name>";
            $regexTokens[$token] = "(?P<$name>$pattern)";

            return $token;
        }, $from);

        $replacements = array_merge($regexTokens, [
            '.' => '\\.',
            '*' => '\\*',
            '$' => '\\$',
            '[' => '\\[',
            ']' => '\\]',
            '(' => '\\(',
            ')' => '\\)',
        ]);

        $pattern = strtr($tokenizedPattern, $replacements);

        return "`^{$pattern}$`iu";
    }
}
