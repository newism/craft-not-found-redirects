<?php

namespace newism\notfoundredirects\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ExceptionEvent;
use craft\helpers\AdminTable;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use Illuminate\Support\Collection;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\models\NotFoundUri;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\models\Referrer;
use newism\notfoundredirects\NotFoundRedirects;
use yii\web\HttpException;

class NotFoundService extends Component
{
    // ── Pipeline ───────────────────────────────────────────────────────

    /**
     * 404 Redirects Pipeline:
     *
     * 1. shouldHandle($request) → bool                    [CONFIG]
     *    false → minimal "404 Not Found" text + end()
     *
     * 2. normalizeUrlFromRequest($request) → string $uri  [CONFIG]
     *
     * 3. DB lookup: match $uri against redirect rules     [PLUGIN]
     *    Match → log, normalizeRedirectUrl, redirect
     *    No match → log, let Craft render 404
     *
     * 4. normalizeRedirectUrl($url, $matchedUri, $redirect) [CONFIG]
     */
    public function handleException(ExceptionEvent $event): void
    {
        $exception = $event->exception;

        if ($exception instanceof \Twig\Error\RuntimeError && $exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }

        if (!($exception instanceof HttpException) || $exception->statusCode !== 404) {
            return;
        }

        $request = Craft::$app->getRequest();
        if (!$request->getIsSiteRequest()) {
            return;
        }

        $settings = NotFoundRedirects::getInstance()->getSettings();
        $dryRun = $settings->dryRun;
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        // 1. Gate
        $shouldHandle = $settings->shouldHandle;
        if (is_callable($shouldHandle) && !$shouldHandle($request)) {
            Craft::info("{$prefix}shouldHandle: false for {$request->getFullPath()}", NotFoundRedirects::LOG);
            if (!$dryRun) {
                Craft::$app->getResponse()->setStatusCode(404);
                Craft::$app->getResponse()->format = \yii\web\Response::FORMAT_RAW;
                Craft::$app->getResponse()->data = '404 Not Found';
                Craft::$app->end();
            }
            return;
        }

        // 2. Normalize
        $uri = $this->normalizeUrl();
        $fullUrl = $request->getAbsoluteUrl();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $referrer = $request->getReferrer();

        Craft::info("{$prefix}Normalized URI: {$uri}", NotFoundRedirects::LOG);

        // 3. DB lookup
        $redirectService = NotFoundRedirects::getInstance()->redirects;
        $redirect = $redirectService->findMatch($uri, $siteId);

        if ($redirect !== null) {
            // 4. Normalize redirect URL
            $destinationUrl = $redirect['_matchedUrl'];
            $normalizeRedirectUrl = $settings->normalizeRedirectUrl;
            if (is_callable($normalizeRedirectUrl)) {
                $destinationUrl = $normalizeRedirectUrl(
                    $destinationUrl,
                    $uri,
                    Redirect::fromRow($redirect),
                );
            }

            Craft::info("{$prefix}Matched redirect #{$redirect['id']}: {$redirect['from']} → {$destinationUrl} ({$redirect['statusCode']})", NotFoundRedirects::LOG);

            // Always log, only redirect if not dry-run
            $this->log($uri, $fullUrl, $siteId, $referrer, true, (int) $redirect['id']);

            if (!$dryRun) {
                $redirectService->doRedirect($redirect, $destinationUrl);
            }
        } else {
            Craft::info("{$prefix}No redirect match for: {$uri}", NotFoundRedirects::LOG);

            // Always log
            $this->log($uri, $fullUrl, $siteId, $referrer, false);
        }
    }

    // ── Normalization ──────────────────────────────────────────────────

    public function normalizeUrl(): string
    {
        $request = Craft::$app->getRequest();
        $callback = NotFoundRedirects::getInstance()->getSettings()->normalizeUrlFromRequest;

        if (is_callable($callback)) {
            return $callback($request);
        }

        // Default: use path only, no query params
        return $request->getFullPath();
    }

    // ── Write Operations ───────────────────────────────────────────────

    public function log(string $uri, string $fullUrl, int $siteId, ?string $referrer = null, bool $handled = false, ?int $redirectId = null): void
    {
        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $db->createCommand()->upsert(
            '{{%notfoundredirects_404s}}',
            [
                'uri' => $uri,
                'siteId' => $siteId,
                'fullUrl' => $fullUrl,
                'hitCount' => 1,
                'hitLastTime' => $now,
                'handled' => $handled,
                'redirectId' => $redirectId,
                'source' => 'request',
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ],
            [
                'fullUrl' => $fullUrl,
                'hitCount' => new \yii\db\Expression('{{%notfoundredirects_404s}}.[[hitCount]] + 1'),
                'hitLastTime' => $now,
                'handled' => $handled,
                'redirectId' => $redirectId,
                'dateUpdated' => $now,
            ],
        )->execute();

        if ($referrer) {
            $notFoundId = (new Query())
                ->select(['id'])
                ->from('{{%notfoundredirects_404s}}')
                ->where(['uri' => $uri, 'siteId' => $siteId])
                ->scalar();

            if ($notFoundId) {
                $db->createCommand()->upsert(
                    '{{%notfoundredirects_referrers}}',
                    [
                        'notFoundId' => $notFoundId,
                        'referrer' => $referrer,
                        'hitCount' => 1,
                        'hitLastTime' => $now,
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                    ],
                    [
                        'hitCount' => new \yii\db\Expression('{{%notfoundredirects_referrers}}.[[hitCount]] + 1'),
                        'hitLastTime' => $now,
                        'dateUpdated' => $now,
                    ],
                )->execute();
            }
        }

        Craft::info("404 logged: {$uri} (handled: " . ($handled ? 'yes' : 'no') . ')', NotFoundRedirects::LOG);
    }

    public function markHandled(int $redirectId, ?int $siteId, string $from): int
    {
        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // Fast path: exact-match redirects can use a single SQL update
        if (!str_contains($from, '<')) {
            $condition = ['uri' => $from, 'handled' => false];
            if ($siteId !== null) {
                $condition['siteId'] = $siteId;
            }

            $count = $db->createCommand()->update(
                '{{%notfoundredirects_404s}}',
                ['handled' => true, 'redirectId' => $redirectId, 'dateUpdated' => $now],
                $condition,
            )->execute();

            if ($count > 0) {
                Craft::info("Marked {$count} 404(s) as handled by redirect #{$redirectId}", NotFoundRedirects::LOG);
            }

            return $count;
        }

        // Pattern match: iterate and test each 404
        $redirectService = NotFoundRedirects::getInstance()->redirects;

        $query = (new Query())
            ->from('{{%notfoundredirects_404s}}')
            ->where(['handled' => false]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $count = 0;

        foreach ($query->each() as $row) {
            if ($redirectService->matchesUri($from, $row['uri'])) {
                $db->createCommand()->update(
                    '{{%notfoundredirects_404s}}',
                    ['handled' => true, 'redirectId' => $redirectId, 'dateUpdated' => $now],
                    ['id' => $row['id']],
                )->execute();
                $count++;
            }
        }

        if ($count > 0) {
            Craft::info("Marked {$count} 404(s) as handled by redirect #{$redirectId}", NotFoundRedirects::LOG);
        }

        return $count;
    }

    public function unmarkHandled(int $redirectId): int
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        return Craft::$app->getDb()->createCommand()->update(
            '{{%notfoundredirects_404s}}',
            ['handled' => false, 'redirectId' => null, 'dateUpdated' => $now],
            ['redirectId' => $redirectId],
        )->execute();
    }

    public function deleteById(int $id): bool
    {
        return Craft::$app->getDb()->createCommand()
            ->delete('{{%notfoundredirects_404s}}', ['id' => $id])
            ->execute() > 0;
    }

    public function deleteAll(?int $siteId = null): int
    {
        $condition = $siteId !== null ? ['siteId' => $siteId] : [];

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%notfoundredirects_404s}}', $condition)
            ->execute();
    }

    /**
     * Purge 404s not seen since the given date.
     * Also cleans up orphaned system-generated redirects.
     *
     * @return array{dryRun: bool, summary: array, items: array}
     */
    public function purge(\DateTime $before, bool $dryRun = false): array
    {
        $db = Craft::$app->getDb();
        $cutoff = $before->format('Y-m-d H:i:s');

        // Find 404s to purge
        $staleRows = (new Query())
            ->select(['id', 'uri', 'hitCount', 'hitLastTime', 'redirectId'])
            ->from('{{%notfoundredirects_404s}}')
            ->where(['<', 'hitLastTime', $cutoff])
            ->all();

        if (empty($staleRows)) {
            return [
                'dryRun' => $dryRun,
                'summary' => ['notFound' => 0, 'referrers' => 0, 'redirects' => 0],
                'items' => [],
            ];
        }

        $ids = array_column($staleRows, 'id');

        // Count referrers
        $referrerCount = (int) (new Query())
            ->from('{{%notfoundredirects_referrers}}')
            ->where(['notFoundId' => $ids])
            ->count();

        // Build items list
        $items = array_map(fn(array $row) => [
            'uri' => $row['uri'],
            'hitCount' => (int) $row['hitCount'],
            'hitLastTime' => $row['hitLastTime'],
            'redirectId' => $row['redirectId'] ? (int) $row['redirectId'] : null,
        ], $staleRows);

        $deletedCount = count($staleRows);
        $redirectsDeleted = 0;

        if (!$dryRun) {
            // Delete 404s (referrers cascade via FK)
            $db->createCommand()
                ->delete('{{%notfoundredirects_404s}}', ['id' => $ids])
                ->execute();

            // Clean up orphaned system-generated redirects
            $redirectIds = array_filter(array_unique(array_column($staleRows, 'redirectId')));
            $redirectService = NotFoundRedirects::getInstance()->redirects;

            foreach ($redirectIds as $redirectId) {
                $remaining = (int) (new Query())
                    ->from('{{%notfoundredirects_404s}}')
                    ->where(['redirectId' => $redirectId])
                    ->count();

                if ($remaining === 0) {
                    $isSystemGenerated = (new Query())
                        ->from('{{%notfoundredirects_redirects}}')
                        ->where(['id' => $redirectId, 'systemGenerated' => true])
                        ->exists();

                    if ($isSystemGenerated) {
                        $redirectService->deleteById((int) $redirectId);
                        $redirectsDeleted++;
                    }
                }
            }
        } else {
            // Dry run: count redirects that would be deleted
            $redirectIds = array_filter(array_unique(array_column($staleRows, 'redirectId')));
            foreach ($redirectIds as $redirectId) {
                $allHandledByStale = !(new Query())
                    ->from('{{%notfoundredirects_404s}}')
                    ->where(['redirectId' => $redirectId])
                    ->andWhere(['not in', 'id', $ids])
                    ->exists();

                if ($allHandledByStale) {
                    $isSystemGenerated = (new Query())
                        ->from('{{%notfoundredirects_redirects}}')
                        ->where(['id' => $redirectId, 'systemGenerated' => true])
                        ->exists();

                    if ($isSystemGenerated) {
                        $redirectsDeleted++;
                    }
                }
            }
        }

        return [
            'dryRun' => $dryRun,
            'summary' => [
                'notFound' => $deletedCount,
                'referrers' => $referrerCount,
                'redirects' => $redirectsDeleted,
            ],
            'items' => $items,
        ];
    }

    /**
     * Reprocess unhandled 404s against enabled redirect rules.
     *
     * @return array{dryRun: bool, summary: array, items: array}
     */
    public function reprocess(bool $dryRun = false): array
    {
        $redirectService = NotFoundRedirects::getInstance()->redirects;
        $redirects = $redirectService->find(limit: 10000);
        $items = [];
        $totalMatched = 0;

        foreach ($redirects as $redirect) {
            if (!$redirect->enabled) {
                continue;
            }

            if ($dryRun) {
                // Count matches without marking handled
                $count = 0;
                $query = (new Query())
                    ->from('{{%notfoundredirects_404s}}')
                    ->where(['handled' => false]);

                if ($redirect->siteId !== null) {
                    $query->andWhere(['siteId' => $redirect->siteId]);
                }

                foreach ($query->each() as $row) {
                    if ($redirectService->matchesUri($redirect->from, $row['uri'])) {
                        $count++;
                    }
                }
            } else {
                $count = $this->markHandled($redirect->id, $redirect->siteId, $redirect->from);
            }

            if ($count > 0) {
                $items[] = [
                    'redirectId' => $redirect->id,
                    'from' => $redirect->from,
                    'matchedCount' => $count,
                ];
                $totalMatched += $count;
            }
        }

        return [
            'dryRun' => $dryRun,
            'summary' => [
                'matched' => $totalMatched,
                'redirects' => count($items),
            ],
            'items' => $items,
        ];
    }

    // ── Read Operations (return models/collections) ────────────────────

    /**
     * @return Collection<int, NotFoundUri>
     */
    public function find(?bool $handled = null, ?string $search = null, string $sortField = 'hitCount', int $sortDir = SORT_DESC, int $offset = 0, int $limit = 50): Collection
    {
        $sortColumn = match ($sortField) {
            'hitCount' => '{{%notfoundredirects_404s}}.[[hitCount]]',
            'hitLastTime' => '{{%notfoundredirects_404s}}.[[hitLastTime]]',
            'referrers', 'referrerCount' => 'referrerCount',
            default => '{{%notfoundredirects_404s}}.[[hitCount]]',
        };

        $referrerCountSubquery = (new Query())
            ->select([new \yii\db\Expression('COUNT(*)')])
            ->from('{{%notfoundredirects_referrers}}')
            ->where(new \yii\db\Expression('{{%notfoundredirects_referrers}}.[[notFoundId]] = {{%notfoundredirects_404s}}.[[id]]'));

        $query = (new Query())
            ->select(['{{%notfoundredirects_404s}}.*', 'referrerCount' => $referrerCountSubquery])
            ->from('{{%notfoundredirects_404s}}');

        if ($handled !== null) {
            $query->andWhere(['handled' => $handled]);
        }

        if ($search) {
            $query->andWhere(['like', 'uri', $search]);
        }

        $rows = $query
            ->orderBy([$sortColumn => $sortDir])
            ->offset($offset)
            ->limit($limit)
            ->all();

        return Collection::make($rows)->map(fn(array $row) => NotFoundUri::fromRow($row));
    }

    public function findById(int $id): ?NotFoundUri
    {
        $referrerCountSubquery = (new Query())
            ->select([new \yii\db\Expression('COUNT(*)')])
            ->from('{{%notfoundredirects_referrers}}')
            ->where(new \yii\db\Expression('{{%notfoundredirects_referrers}}.[[notFoundId]] = {{%notfoundredirects_404s}}.[[id]]'));

        $row = (new Query())
            ->select(['{{%notfoundredirects_404s}}.*', 'referrerCount' => $referrerCountSubquery])
            ->from('{{%notfoundredirects_404s}}')
            ->where(['id' => $id])
            ->one();

        return $row ? NotFoundUri::fromRow($row) : null;
    }

    public function count(?bool $handled = null, ?string $search = null): int
    {
        $query = (new Query())->from('{{%notfoundredirects_404s}}');

        if ($handled !== null) {
            $query->andWhere(['handled' => $handled]);
        }

        if ($search) {
            $query->andWhere(['like', 'uri', $search]);
        }

        return (int) $query->count();
    }

    /**
     * @return Collection<int, Referrer>
     */
    public function findReferrers(int $notFoundId, string $sortField = 'hitCount', int $sortDir = SORT_DESC, int $offset = 0, int $limit = 50): Collection
    {
        $sortColumn = match ($sortField) {
            'referrer', '__slot:title' => 'referrer',
            'hitLastTime' => 'hitLastTime',
            default => 'hitCount',
        };

        $rows = (new Query())
            ->from('{{%notfoundredirects_referrers}}')
            ->where(['notFoundId' => $notFoundId])
            ->orderBy([$sortColumn => $sortDir])
            ->offset($offset)
            ->limit($limit)
            ->all();

        return Collection::make($rows)->map(fn(array $row) => Referrer::fromRow($row));
    }

    public function countReferrers(int $notFoundId): int
    {
        return (int) (new Query())
            ->from('{{%notfoundredirects_referrers}}')
            ->where(['notFoundId' => $notFoundId])
            ->count();
    }

    // ── Table Data (VueAdminTable formatting) ──────────────────────────

    /**
     * @return array{pagination: array, data: array}
     */
    public function getTableData(string $handled = '0', int $page = 1, int $limit = 50, ?string $search = null, string $sortField = 'hitCount', int $sortDir = SORT_DESC): array
    {
        $handledFilter = match ($handled) {
            '1' => true,
            '0' => false,
            default => null,
        };

        $total = $this->count(handled: $handledFilter, search: $search);
        $offset = ($page - 1) * $limit;

        $items = $this->find(
            handled: $handledFilter,
            search: $search,
            sortField: $sortField,
            sortDir: $sortDir,
            offset: $offset,
            limit: $limit,
        );

        $tableData = $items->map(fn(NotFoundUri $nf) => [
            'id' => $nf->id,
            'title' => Uri::display($nf->uri),
            'url' => $nf->getCpEditUrl(),
            'hitCount' => $nf->hitCount,
            'hitLastTime' => $nf->hitLastTime ? Craft::$app->getFormatter()->asDatetime($nf->hitLastTime, 'short') : '-',
            'handled' => $nf->handled,
            'referrers' => $nf->referrerCount > 0
                ? Html::a(
                    Craft::t('app', '{count, number} {count, plural, =1{referrer} other{referrers}}', ['count' => $nf->referrerCount]),
                    $nf->getCpEditUrl()
                )
                : '<span class="light">' . Craft::t('app', 'None') . '</span>',
            'redirect' => $nf->redirectId
                ? Html::a('#' . $nf->redirectId, UrlHelper::cpUrl('not-found-redirects/redirects/edit/' . $nf->redirectId))
                : Html::a(Craft::t('app', 'Redirect'), UrlHelper::cpUrl('not-found-redirects/redirects/new', ['from' => $nf->uri]), ['class' => 'btn small']),
        ])->all();

        return [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $tableData,
        ];
    }

    /**
     * @return array{pagination: array, data: array}
     */
    public function getReferrersTableData(int $notFoundId, int $page = 1, int $limit = 50, string $sortField = 'hitCount', int $sortDir = SORT_DESC): array
    {
        $total = $this->countReferrers($notFoundId);
        $offset = ($page - 1) * $limit;

        $items = $this->findReferrers($notFoundId, $sortField, $sortDir, $offset, $limit);

        $tableData = $items->map(fn(Referrer $ref) => [
            'id' => $ref->id,
            'title' => $ref->referrer,
            'url' => $ref->referrer,
            'hitCount' => $ref->hitCount,
            'hitLastTime' => $ref->hitLastTime ? Craft::$app->getFormatter()->asDatetime($ref->hitLastTime, 'short') : '-',
        ])->all();

        return [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $tableData,
        ];
    }
}
