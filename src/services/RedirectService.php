<?php

namespace newism\notfoundredirects\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\enums\Color;
use craft\helpers\AdminTable;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\web\UrlRule;
use DateTime;
use Illuminate\Support\Collection;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\NotFoundRedirects;
use newism\notfoundredirects\query\RedirectQuery;
use newism\notfoundredirects\web\assets\EntrySidebarAsset;
use yii\data\Pagination;
use yii\db\Expression;

class RedirectService extends Component
{

    /**
     * Tracks old URIs before element save for auto-redirect creation.
     * @var array<string, string> "elementId-siteId" => old URI
     */
    private array $_oldUris = [];

    // ── Request Matching ───────────────────────────────────────────────

    /**
     * Test if a redirect's `from` pattern matches a given URI.
     */
    public function matchesUri(Redirect $redirect, string $uri): bool
    {
        if ($redirect->regexMatch) {
            return (bool)preg_match('`^' . $redirect->from . '$`i', $uri);
        }

        if (str_contains($redirect->from, '<')) {
            $pattern = $this->toRegexPattern($redirect->from);
            return (bool)preg_match($pattern, $uri);
        }

        return strcasecmp($redirect->from, $uri) === 0;
    }

    /**
     * Test if a from pattern matches a test URI and resolve the destination.
     * Works offline — no request context needed.
     *
     * @return string|null The resolved destination URL, or null if no match
     */
    public function testMatch(string $from, string $to, string $testUri, bool $regexMatch = false): ?string
    {
        $from = Uri::strip($from);
        $testUri = Uri::strip($testUri);

        if ($regexMatch) {
            $pattern = '`^' . $from . '$`i';
            if (preg_match($pattern, $testUri, $matches)) {
                return preg_replace_callback('/\$(\d+)/', function ($m) use ($matches) {
                    return $matches[(int)$m[1]] ?? '';
                }, $to ?: '/');
            }
            return null;
        }

        if (str_contains($from, '<')) {
            $pattern = $this->toRegexPattern($from);
            if (preg_match($pattern, $testUri, $matches)) {
                // Replace <name> tokens in destination with matched values
                $params = Collection::make($matches)
                    ->mapWithKeys(fn($item, $key) => ["<$key>" => $item]);
                return strtr($to ?: '/', $params->all());
            }
            return null;
        }

        if (strcasecmp($from, $testUri) === 0) {
            return $to ?: '/';
        }

        return null;
    }

    // ── Write Operations ───────────────────────────────────────────────

    public function saveRedirect(Redirect $model): bool
    {
        // Normalize from — strip leading/trailing slashes
        $model->from = Uri::strip($model->from);

        // Strip the site's base path prefix if the user pasted a full path (e.g., en/old-blog → old-blog)
        if ($model->siteId) {
            $site = Craft::$app->getSites()->getSiteById($model->siteId);
            $basePath = $site ? trim(parse_url($site->getBaseUrl(), PHP_URL_PATH) ?? '', '/') : '';
            if ($basePath && str_starts_with($model->from, $basePath . '/')) {
                $model->from = substr($model->from, strlen($basePath) + 1);
            }
        }

        // 404 Block and 410 Gone have no destination
        if (in_array($model->statusCode, [404, 410])) {
            $model->to = '';
            $model->toElementId = null;
        }

        // Normalize destination based on type
        if ($model->toType === 'entry') {
            $element = $model->getToElement();
            if ($element && $element->uri) {
                $model->to = $element->uri === Element::HOMEPAGE_URI ? '' : Uri::strip($element->uri);
            }
        } elseif ($model->toType === 'url' && $model->to) {
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $siteUrl = rtrim($site->getBaseUrl(), '/');
                if ($siteUrl && str_starts_with($model->to, $siteUrl)) {
                    $model->to = ltrim(substr($model->to, strlen($siteUrl)), '/') ?: '/';
                    break;
                }
            }
            if (!preg_match('#^https?://#i', $model->to)) {
                $model->to = $model->to === '/' ? $model->to : Uri::strip($model->to);
            }
        }

        if (!$model->validate()) {
            return false;
        }

        // Resolve destination path for self-redirect and loop detection
        $destinationPath = $model->to;
        if ($model->toType === 'entry' && $model->toElementId) {
            $element = $model->getToElement();
            $destinationPath = $element ? Uri::strip($element->uri ?? '') : $model->to;
        }

        if ($destinationPath && strcasecmp($model->from, $destinationPath) === 0) {
            $errorField = $model->toType === 'entry' ? 'toElementId' : 'to';
            $model->addError($errorField, 'Redirect destination cannot be the same as the source.');
            return false;
        }

        if ($destinationPath) {
            $loopError = $this->detectLoop($model->from, Uri::strip($destinationPath));
            if ($loopError) {
                $errorField = $model->toType === 'entry' ? 'toElementId' : 'to';
                $model->addError($errorField, $loopError);
                return false;
            }
        }

        // Set createdById on new records
        if (!$model->id && !$model->createdById) {
            $user = Craft::$app->getUser()->getIdentity();
            $model->createdById = $user?->id;
        }

        $db = Craft::$app->getDb();
        $data = Db::prepareValuesForDb($model->toArray());

        if ($model->id) {
            // Try update first
            unset($data['dateCreated'], $data['uid']);
            $affected = $db->createCommand()->update(
                Table::REDIRECTS,
                $data,
                ['id' => $model->id],
            )->execute();

            // Record doesn't exist — insert with explicit ID
            if ($affected === 0) {
                $data['dateCreated'] = Db::prepareDateForDb(new DateTime());
                $db->createCommand()->insert(
                    Table::REDIRECTS,
                    $data,
                )->execute();
            }
        } else {
            unset($data['id']);
            $db->createCommand()->insert(
                Table::REDIRECTS,
                $data,
            )->execute();
            $model->id = (int)$db->getLastInsertID();
        }

        Craft::info("Redirect saved: #{$model->id} {$model->from} → {$model->to}", NotFoundRedirects::LOG);

        if ($model->enabled) {
            NotFoundRedirects::getInstance()->getNotFoundUriService()->markHandled($model);
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

        // 1. Flatten chains: update any existing redirects for this element to point to the new URI
        $updated = $db->createCommand()->update(
            Table::REDIRECTS,
            ['to' => $newUri],
            ['elementId' => $elementId],
        )->execute();

        if ($updated > 0) {
            Craft::info("Flattened {$updated} redirect chain(s) for element #{$elementId} → /{$newUri}", NotFoundRedirects::LOG);
        }

        // 2. Also update any redirect (manual or auto) whose `to` matches the old URI
        $db->createCommand()->update(
            Table::REDIRECTS,
            ['to' => $newUri],
            ['to' => $oldUri],
        )->execute();

        // 3. Clean up self-redirects created by chain flattening (from === to)
        $deleted = $db->createCommand()
            ->delete(Table::REDIRECTS, new Expression('[[from]] = [[to]]'))
            ->execute();

        if ($deleted > 0) {
            Craft::info("Removed {$deleted} self-redirect(s) after chain flattening", NotFoundRedirects::LOG);
        }

        // 4. Check if a redirect from oldUri already exists (could be from step 1)
        $existingQuery = RedirectQuery::find();
        $existingQuery->andWhere(['from' => $oldUri, 'siteId' => $siteId]);
        $existing = $existingQuery->one();

        if ($existing) {
            // Update existing redirect to point to new URI
            $db->createCommand()->update(
                Table::REDIRECTS,
                [
                    'to' => $newUri,
                    'elementId' => $elementId,
                    'systemGenerated' => true,
                ],
                ['id' => $existing->id],
            )->execute();

            NotFoundRedirects::getInstance()->getNoteService()->addNote(
                $existing->id,
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

            $this->saveRedirect($model);

            NotFoundRedirects::getInstance()->getNoteService()->addNote(
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

    public function deleteRedirectById(int $id): bool
    {
        NotFoundRedirects::getInstance()->getNotFoundUriService()->unmarkHandled($id);

        $rows = Craft::$app->getDb()->createCommand()
            ->delete(Table::REDIRECTS, ['id' => $id])
            ->execute();

        if ($rows > 0) {
            Craft::info("Redirect deleted: #{$id}", NotFoundRedirects::LOG);
        }

        return $rows > 0;
    }

    public function deleteAllRedirects(): int
    {
        $db = Craft::$app->getDb();

        // Unmark all 404s before deleting redirects
        $db->createCommand()->update(
            Table::NOT_FOUND_URIS,
            ['handled' => false, 'redirectId' => null],
            ['not', ['redirectId' => null]],
        )->execute();

        $count = $db->createCommand()
            ->delete(Table::REDIRECTS)
            ->execute();

        Craft::info("All redirects deleted ({$count})", NotFoundRedirects::LOG);

        return $count;
    }

    // ── Read Operations (return models/collections) ────────────────────

    /**
     * @return Redirect[]
     */
    public function findByToElementId(int $elementId): array
    {
        $query = RedirectQuery::find();
        $query->andWhere(['toElementId' => $elementId, 'toType' => 'entry']);

        return $query
            ->orderBy(['priority' => SORT_DESC])
            ->all();
    }

    // ── Table Data (VueAdminTable formatting) ──────────────────────────

    /**
     * @return array{pagination: array, data: array}
     */
    public function getRedirectTableData(
        int     $page = 1,
        int     $limit = 50,
        ?string $search = null,
        ?bool   $systemGenerated = null,
        ?int    $siteId = null,
        string  $sortField = 'priority',
        int     $sortDir = SORT_DESC
    ): array
    {
        $query = RedirectQuery::find();
        $query->search = $search;
        $query->systemGenerated = $systemGenerated;
        $query->siteId = $siteId;

        $totalCount = (clone $query)->count();

        $pagination = new Pagination([
            'totalCount' => $totalCount,
            'pageSize' => $limit,
            'page' => $page - 1,
        ]);

        $items = $query
            ->orderBy([$this->resolveSortColumn($sortField) => $sortDir])
            ->limit($pagination->limit)
            ->offset($pagination->offset)
            ->collect();

        // Batch-load destination elements to avoid N+1 queries
        $elementIds = $items
            ->filter(fn(Redirect $r) => $r->toType === 'entry' && $r->toElementId)
            ->pluck('toElementId')
            ->unique()
            ->all();

        if ($elementIds) {
            $elements = collect(Entry::find()->id($elementIds)->status(null)->all())
                ->keyBy('id');

            foreach ($items as $r) {
                if ($r->toType === 'entry' && $r->toElementId && $elements->has($r->toElementId)) {
                    $r->setToElement($elements->get($r->toElementId));
                }
            }
        }

        $sites = Craft::$app->getSites();

        $tableData = $items->map(fn(Redirect $r) => [
            'id' => $r->id,
            'title' => $r->from ?: '/',
            'url' => $r->getCpEditUrl(),
            'to' => match (true) {
                in_array($r->statusCode, [404, 410]) => '—',
                $r->toType === 'entry' && $r->getToElement() => Cp::elementChipHtml($r->getToElement()),
                $r->toType === 'entry' => ($r->to && $r->to !== Element::HOMEPAGE_URI ? $r->to : '/') . ' (entry deleted)',
                default => $r->to && $r->to !== Element::HOMEPAGE_URI ? $r->to : '/',
            },
            'statusCode' => $r->statusCode,
            'priority' => $r->priority,
            'enabled' => Cp::statusLabelHtml([
                'color' => $r->enabled ? Color::Teal : Color::Gray,
                'icon' => $r->enabled ? 'check' : 'xmark',
                'label' => $r->enabled ? Craft::t('app', 'Yes') : Craft::t('app', 'No'),
            ]),
            'redirectStatus' => Cp::statusLabelHtml([
                'color' => Color::tryFromStatus($r->getStatus()) ?? Color::Gray,
                'label' => ucfirst($r->getStatus()),
            ]),
            'hitCount' => $r->hitCount,
            'hitLastTime' => $r->hitLastTime ? Craft::$app->getFormatter()->asDatetime($r->hitLastTime, 'short') : '-',
            'siteName' => $r->siteId ? ($sites->getSiteById($r->siteId)?->name ?? '') : Craft::t('app', 'All'),
        ])->all();

        return [
            'pagination' => AdminTable::paginationLinks($page, $totalCount, $limit),
            'data' => $tableData,
        ];
    }

    // ── Private ────────────────────────────────────────────────────────

    private function resolveSortColumn(string $sortField): string
    {
        return match ($sortField) {
            '__slot:title', 'from' => 'from',
            'to' => 'to',
            'statusCode' => 'statusCode',
            'enabled' => 'enabled',
            'hitCount' => 'hitCount',
            default => 'priority',
        };
    }

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

        $query = RedirectQuery::find();
        $query->enabled = true;
        $allRedirects = $query->all();

        for ($i = 0; $i < $maxDepth; $i++) {
            // Find any redirect whose `from` pattern matches the current destination
            $next = null;
            foreach ($allRedirects as $redirect) {
                if ($this->matchesUri($redirect, $current)) {
                    $next = $redirect;
                    break;
                }
            }

            if (!$next) {
                return null; // Chain ends, no loop
            }

            $nextTo = Uri::strip($next->to ?? '');
            $chain[] = $next->to;

            // Check if the next destination matches any URI we've already visited
            foreach ($visited as $v) {
                if (strcasecmp($v, $nextTo) === 0 || strcasecmp($nextTo, $v) === 0) {
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

        $tokenizedPattern = preg_replace_callback('/<([\w._-]+):?([^>]+)?>/', function ($match) use (&$regexTokens) {
            $name = $match[1];
            $pattern = strtr($match[2] ?? '[^\/]+', UrlRule::regexTokens());
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

    public function getEntrySidebarHtml(int $entryId): ?string
    {

        $user = Craft::$app->getUser()->getIdentity();
        $canCreate = $user && $user->can('not-found-redirects:manageRedirects');
        $redirects = $this->findByToElementId($entryId);

        if (!$redirects && !$canCreate) {
            return null;
        }

        $id = Html::id('not-found-redirects-' . $entryId);

        $view = Craft::$app->getView();
        $view->registerAssetBundle(EntrySidebarAsset::class);
        $view->registerJs("new Newism.notFoundRedirects.EntrySidebar('#$id');");

        return $view->renderTemplate(
            'not-found-redirects/redirects/_entry-sidebar',
            [
                'id' => $id,
                'redirects' => $redirects,
                'canCreate' => $canCreate,
                'entryId' => $entryId,
            ],
        );
    }
}
