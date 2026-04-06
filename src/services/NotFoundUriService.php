<?php

namespace newism\notfoundredirects\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\enums\Color;
use craft\events\ExceptionEvent;
use craft\helpers\AdminTable;
use craft\helpers\ConfigHelper;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\RedirectRule;
use DateTime;
use newism\notfoundredirects\events\AfterRedirectEvent;
use newism\notfoundredirects\events\BeforeRedirectEvent;
use newism\notfoundredirects\events\DefineNotFoundUriEvent;
use newism\notfoundredirects\events\NotFoundUriEvent;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\models\NotFoundUri;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\models\Referrer;
use newism\notfoundredirects\NotFoundRedirects;
use newism\notfoundredirects\query\NotFoundUriQuery;
use newism\notfoundredirects\query\RedirectQuery;
use newism\notfoundredirects\query\ReferrerQuery;
use Twig\Error\RuntimeError;
use yii\data\Pagination;
use yii\db\Expression;
use yii\web\HttpException;
use yii\web\Response;

class NotFoundUriService extends Component
{
    // ── Pipeline Events ──────────────────────────────────────────────

    const EVENT_BEFORE_HANDLE = 'beforeHandle';
    const EVENT_DEFINE_URI = 'defineUri';
    const EVENT_BEFORE_REDIRECT = 'beforeRedirect';
    const EVENT_AFTER_REDIRECT = 'afterRedirect';

    public function handleException(ExceptionEvent $event): void
    {
        $exception = $event->exception;

        if ($exception instanceof RuntimeError && $exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }

        if (!($exception instanceof HttpException) || $exception->statusCode !== 404) {
            return;
        }

        $request = Craft::$app->getRequest();
        if (!$request->getIsSiteRequest()) {
            return;
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        // 1. EVENT_BEFORE_HANDLE — cancelable gate
        if ($this->hasEventHandlers(self::EVENT_BEFORE_HANDLE)) {
            $beforeHandleEvent = new NotFoundUriEvent([
                'request' => $request,
                'siteId' => $siteId,
            ]);
            $this->trigger(self::EVENT_BEFORE_HANDLE, $beforeHandleEvent);
            if (!$beforeHandleEvent->isValid) {
                Craft::debug("EVENT_BEFORE_HANDLE canceled for {$request->getFullPath()}", NotFoundRedirects::LOG);
                return;
            }
        }

        // 2. EVENT_DEFINE_URI — value mutation
        // getPathInfo() strips the site base URL prefix (e.g., /en/) for multi-site subfolder setups
        $uri = $request->getPathInfo();
        if ($this->hasEventHandlers(self::EVENT_DEFINE_URI)) {
            $defineUriEvent = new DefineNotFoundUriEvent([
                'request' => $request,
                'uri' => $uri,
                'siteId' => $siteId,
            ]);
            $this->trigger(self::EVENT_DEFINE_URI, $defineUriEvent);
            $uri = $defineUriEvent->uri;
        }

        Craft::debug("URI: {$uri}", NotFoundRedirects::LOG);

        $referrer = $request->getReferrer();
        $redirectService = NotFoundRedirects::getInstance()->getRedirectService();

        // ── 3. Find matching redirect ─────────────────────────────────
        // Phase 1: exact match via SQL (fast, indexed)
        // Phase 2: pattern/regex — load candidates and test each
        $redirectQuery = RedirectQuery::find();
        $redirectQuery->siteId = $siteId;
        $redirectQuery->enabled = true;
        $redirectQuery->activeNow = true;
        $redirectQuery->orderBy(['priority' => SORT_DESC, 'id' => SORT_ASC]);

        $exactQuery = clone $redirectQuery;
        $exactQuery->andWhere(['from' => $uri]);
        $redirect = $exactQuery->one();

        if (!$redirect) {
            $patternQuery = clone $redirectQuery;
            $patternQuery->andWhere(['or', ['regexMatch' => true], ['like', 'from', '<']]);
            foreach ($patternQuery->each() as $candidate) {
                if ($redirectService->matchesUri($candidate, $uri)) {
                    $redirect = $candidate;
                    break;
                }
            }
        }

        // ── No match → log unhandled, let Craft render 404 ───────────
        if (!$redirect) {
            Craft::debug("No redirect match for: {$uri}", NotFoundRedirects::LOG);
            $this->log($uri, $siteId, $referrer, false);
            return;
        }

        // ── 4. Resolve destination URL ────────────────────────────────
        // Entry-type: resolve live URL from element
        // Regex: apply $1/$2 backreferences
        // Pattern tokens: use Craft's RedirectRule for <param> substitution
        // Exact: return `to` as-is
        $destinationUrl = $redirect->to ?: '/';

        if ($redirect->toType === 'entry' && $redirect->toElementId) {
            $entry = Craft::$app->getElements()->getElementById($redirect->toElementId, null, $siteId);
            if ($entry?->getUrl()) {
                $destinationUrl = $entry->getUrl();
            }
        } elseif ($redirect->regexMatch) {
            if (preg_match('`^' . $redirect->from . '$`i', $uri, $matches)) {
                $destinationUrl = preg_replace_callback('/\$(\d+)/', fn($m) => $matches[(int)$m[1]] ?? '', $destinationUrl);
            }
        } elseif (str_contains($redirect->from, '<')) {
            $rule = new RedirectRule([
                'from' => $redirect->from,
                'to' => $destinationUrl,
                'statusCode' => $redirect->statusCode,
            ]);
            $destinationUrl = $rule->getMatch() ?? $destinationUrl;
        }

        // ── 5. EVENT_BEFORE_REDIRECT — cancelable + value mutation ───
        $shouldRedirect = true;
        if ($this->hasEventHandlers(self::EVENT_BEFORE_REDIRECT)) {
            $beforeRedirectEvent = new BeforeRedirectEvent([
                'request' => $request,
                'uri' => $uri,
                'redirect' => $redirect,
                'destinationUrl' => $destinationUrl,
            ]);
            $this->trigger(self::EVENT_BEFORE_REDIRECT, $beforeRedirectEvent);
            $destinationUrl = $beforeRedirectEvent->destinationUrl;
            $shouldRedirect = $beforeRedirectEvent->isValid;
        }

        Craft::debug("Matched redirect #{$redirect->id}: {$redirect->from} → {$destinationUrl} ({$redirect->statusCode})", NotFoundRedirects::LOG);

        // Always log the 404 as handled
        $this->log($uri, $siteId, $referrer, true, $redirect->id);

        if (!$shouldRedirect) {
            return;
        }

        // ── 6. Execute redirect ───────────────────────────────────────
        // Increment hit count
        Craft::$app->getDb()->createCommand()->update(
            Table::REDIRECTS,
            [
                'hitCount' => new Expression(Table::REDIRECTS . '.[[hitCount]] + 1'),
                'hitLastTime' => Db::prepareDateForDb(new DateTime()),
            ],
            ['id' => $redirect->id],
        )->execute();

        // 404 Block — raw text response, skip template rendering
        if ($redirect->statusCode === 404) {
            $response = Craft::$app->getResponse();
            $response->setStatusCode(404);
            $response->format = Response::FORMAT_RAW;
            $response->data = '404 Not Found';
            Craft::info("404 Block: {$redirect->from}", NotFoundRedirects::LOG);
            Craft::$app->end();
            return; // @phpstan-ignore deadCode.unreachable
        }

        // 410 Gone — set status, let Craft render error template
        if ($redirect->statusCode === 410) {
            Craft::$app->getResponse()->setStatusCode(410);
            Craft::info("410 Gone: {$redirect->from}", NotFoundRedirects::LOG);
            return;
        }

        // Resolve relative paths to full site URLs (multi-site subfolder prefixes)
        if (!preg_match('#^(https?:)?//#i', $destinationUrl)) {
            $destinationUrl = UrlHelper::siteUrl($destinationUrl, null, null, $redirect->siteId ?? $siteId);
        }

        // Guard against self-redirect at runtime
        $destinationPath = Uri::strip(parse_url($destinationUrl, PHP_URL_PATH));
        if (strcasecmp($request->getFullPath(), $destinationPath) === 0) {
            Craft::warning("Redirect loop detected: {$redirect->from} → {$destinationUrl} (same as current path)", NotFoundRedirects::LOG);
            return;
        }

        // Send redirect
        Craft::info("Redirecting ({$redirect->statusCode}): {$redirect->from} → {$destinationUrl}", NotFoundRedirects::LOG);
        Craft::$app->getResponse()->redirect($destinationUrl, $redirect->statusCode);
        Craft::$app->end();

        if ($this->hasEventHandlers(self::EVENT_AFTER_REDIRECT)) { // @phpstan-ignore deadCode.unreachable
            $this->trigger(self::EVENT_AFTER_REDIRECT, new AfterRedirectEvent([
                'request' => $request,
                'uri' => $uri,
                'redirect' => $redirect,
                'destinationUrl' => $destinationUrl,
                'statusCode' => $redirect->statusCode,
            ]));
        }
    }

    // ── Write Operations ───────────────────────────────────────────────

    public function log(string $uri, int $siteId, ?string $referrer = null, bool $handled = false, ?int $redirectId = null): void
    {
        $model = new NotFoundUri();
        $model->uri = $uri;
        $model->siteId = $siteId;
        $model->hitCount = 1;
        $model->hitLastTime = new DateTime();
        $model->handled = $handled;
        $model->redirectId = $redirectId;
        $model->source = 'request';

        $this->saveNotFoundUri($model, $referrer);
    }

    /**
     * Upsert a NotFoundUri model.
     *
     * On conflict (uri + siteId), merges hitCount additively and updates hitLastTime.
     * Optionally upserts a referrer record linked to the resolved notFoundId.
     */
    public function saveNotFoundUri(NotFoundUri $model, ?string $referrer = null): bool
    {
        $model->uri = Uri::strip($model->uri ?? '');
        $model->hitCount = max(1, $model->hitCount);
        $model->hitLastTime ??= new DateTime();

        if (!$model->validate()) {
            return false;
        }

        $db = Craft::$app->getDb();
        $data = Db::prepareValuesForDb($model->toArray());
        unset($data['id'], $data['referrerCount']);

        $db->createCommand()->upsert(
            Table::NOT_FOUND_URIS,
            $data,
            [
                'hitCount' => new Expression(
                    Table::NOT_FOUND_URIS . '.[[hitCount]] + :hits',
                    [':hits' => $data['hitCount']]
                ),
                'hitLastTime' => $data['hitLastTime'],
                'handled' => $data['handled'],
                'redirectId' => $data['redirectId'],
            ],
        )->execute();

        // Resolve the ID after upsert
        $notFoundQuery = NotFoundUriQuery::find();
        $notFoundQuery->andWhere(['uri' => $model->uri, 'siteId' => $model->siteId]);
        $notFoundQuery->select(['id']);
        $model->id = (int)$notFoundQuery->scalar();

        if ($referrer && $model->id) {
            $db->createCommand()->upsert(
                Table::REFERRERS,
                [
                    'notFoundId' => $model->id,
                    'referrer' => $referrer,
                    'hitCount' => $data['hitCount'],
                    'hitLastTime' => $data['hitLastTime'],
                ],
                [
                    'hitCount' => new Expression(
                        Table::REFERRERS . '.[[hitCount]] + :hits',
                        [':hits' => $data['hitCount']]
                    ),
                    'hitLastTime' => $data['hitLastTime'],
                ],
            )->execute();

            // Probabilistic trim — 1% chance to cap referrers for this 404
            if (mt_rand(1, 100) === 1) {
                $cap = NotFoundRedirects::getInstance()->getSettings()->maxReferrersPerNotFoundUri;
                if ($cap > 0) {
                    $this->trimReferrersForNotFoundUri($model->id, $cap);
                }
            }
        }

        Craft::debug("404 logged: {$model->uri} (handled: " . ($model->handled ? 'yes' : 'no') . ')', NotFoundRedirects::LOG);

        return true;
    }

    public function markHandled(Redirect $redirect): int
    {
        $db = Craft::$app->getDb();
        $redirectService = NotFoundRedirects::getInstance()->getRedirectService();

        // Fast path: exact-match redirects can use a single SQL update
        if (!$redirect->regexMatch && !str_contains($redirect->from, '<')) {
            $condition = ['uri' => $redirect->from, 'handled' => false];
            if ($redirect->siteId !== null) {
                $condition['siteId'] = $redirect->siteId;
            }

            $count = $db->createCommand()->update(
                Table::NOT_FOUND_URIS,
                ['handled' => true, 'redirectId' => $redirect->id],
                $condition,
            )->execute();

            if ($count > 0) {
                Craft::info("Marked {$count} 404(s) as handled by redirect #{$redirect->id}", NotFoundRedirects::LOG);
            }

            return $count;
        }

        // Pattern/regex match: iterate, collect matches, batch update
        $query = NotFoundUriQuery::find();
        $query->handled = false;
        $query->siteId = $redirect->siteId;

        $matchingIds = [];
        foreach ($query->each() as $notFoundUri) {
            if ($redirectService->matchesUri($redirect, $notFoundUri->uri)) {
                $matchingIds[] = $notFoundUri->id;
            }
        }

        $count = 0;
        if ($matchingIds) {
            $count = $db->createCommand()->update(
                Table::NOT_FOUND_URIS,
                ['handled' => true, 'redirectId' => $redirect->id],
                ['id' => $matchingIds],
            )->execute();
        }

        if ($count > 0) {
            Craft::info("Marked {$count} 404(s) as handled by redirect #{$redirect->id}", NotFoundRedirects::LOG);
        }

        return $count;
    }

    public function unmarkHandled(int $redirectId): int
    {
        return Craft::$app->getDb()->createCommand()->update(
            Table::NOT_FOUND_URIS,
            ['handled' => false, 'redirectId' => null],
            ['redirectId' => $redirectId],
        )->execute();
    }

    public function deleteNotFoundUriById(int $id): bool
    {
        return Craft::$app->getDb()->createCommand()
                ->delete(Table::NOT_FOUND_URIS, ['id' => $id])
                ->execute() > 0;
    }

    public function deleteAllNotFoundUris(?int $siteId = null): int
    {
        $condition = $siteId !== null ? ['siteId' => $siteId] : [];

        return Craft::$app->getDb()->createCommand()
            ->delete(Table::NOT_FOUND_URIS, $condition)
            ->execute();
    }

    /**
     * Purge 404s not seen since the given date.
     * Also cleans up orphaned system-generated redirects.
     *
     */
    public function purge(DateTime $before): array
    {
        $db = Craft::$app->getDb();
        $cutoff = $before->format('Y-m-d H:i:s');

        // Find 404s to purge
        $staleRows = (new Query())
            ->select(['id', 'uri', 'hitCount', 'hitLastTime', 'redirectId'])
            ->from(Table::NOT_FOUND_URIS)
            ->where(['<', 'hitLastTime', $cutoff])
            ->all();

        if (!$staleRows) {
            return ['notFound' => 0, 'referrers' => 0, 'redirects' => 0];
        }

        $ids = array_column($staleRows, 'id');

        // Count referrers
        $referrerCountQuery = ReferrerQuery::find();
        $referrerCountQuery->andWhere(['notFoundId' => $ids]);
        $referrerCount = (int)$referrerCountQuery->count();

        // Delete 404s (referrers cascade via FK)
        $db->createCommand()
            ->delete(Table::NOT_FOUND_URIS, ['id' => $ids])
            ->execute();

        // Clean up orphaned system-generated redirects
        $redirectIds = array_filter(array_unique(array_column($staleRows, 'redirectId')));
        $redirectService = NotFoundRedirects::getInstance()->getRedirectService();
        $redirectsDeleted = 0;

        foreach ($redirectIds as $redirectId) {
            $remainingQuery = NotFoundUriQuery::find();
            $remainingQuery->andWhere(['redirectId' => $redirectId]);

            if ((int)$remainingQuery->count() === 0) {
                $sysGenQuery = RedirectQuery::find();
                $sysGenQuery->id = (int)$redirectId;
                $sysGenQuery->systemGenerated = true;

                if ($sysGenQuery->exists()) {
                    $redirectService->deleteRedirectById((int)$redirectId);
                    $redirectsDeleted++;
                }
            }
        }

        return [
            'notFound' => count($staleRows),
            'referrers' => $referrerCount,
            'redirects' => $redirectsDeleted,
        ];
    }

    /**
     * Reset hit counts to zero for all 404s and referrers.
     */
    public function resetHitCounts(): array
    {
        $db = Craft::$app->getDb();

        $nfCountQuery = NotFoundUriQuery::find();
        $nfCountQuery->andWhere(['>', 'hitCount', 0]);
        $notFoundCount = (int)$nfCountQuery->count();

        $refCountQuery = ReferrerQuery::find();
        $refCountQuery->andWhere(['>', 'hitCount', 0]);
        $referrerCount = (int)$refCountQuery->count();

        $db->createCommand()->update(Table::NOT_FOUND_URIS, ['hitCount' => 0])->execute();
        $db->createCommand()->update(Table::REFERRERS, ['hitCount' => 0])->execute();

        Craft::info("Reset hit counts: {$notFoundCount} 404(s), {$referrerCount} referrer(s)", NotFoundRedirects::LOG);

        return ['notFound' => $notFoundCount, 'referrers' => $referrerCount];
    }

    /**
     * Reprocess unhandled 404s against enabled redirect rules.
     */
    public function reprocess(): array
    {
        $totalMatched = 0;

        $redirectQuery = RedirectQuery::find();
        $redirectQuery->enabled = true;
        $redirectQuery->orderBy(['priority' => SORT_DESC]);

        foreach ($redirectQuery->each() as $redirect) {
            $totalMatched += $this->markHandled($redirect);
        }

        return ['matched' => $totalMatched];
    }

    // ── Garbage Collection ──────────────────────────────────────────────

    /**
     * Run all GC tasks. Called from Gc::EVENT_RUN.
     */
    public function gc(): void
    {
        $settings = NotFoundRedirects::getInstance()->getSettings();

        // Trim referrers per 404 to cap
        if ($settings->maxReferrersPerNotFoundUri > 0) {
            $this->trimReferrers($settings->maxReferrersPerNotFoundUri);
        }

        // Purge stale 404s
        $duration = ConfigHelper::durationInSeconds($settings->purgeStaleNotFoundUriDuration);
        if ($duration > 0) {
            $cutoff = (new DateTime())->modify("-{$duration} seconds");
            $this->purge($cutoff);
        }

        // Purge stale referrers
        $duration = ConfigHelper::durationInSeconds($settings->purgeStaleReferrerDuration);
        if ($duration > 0) {
            $cutoff = (new DateTime())->modify("-{$duration} seconds");
            $this->purgeReferrers($cutoff);
        }
    }

    /**
     * Trim referrers for all 404s that exceed the cap.
     */
    public function trimReferrers(int $cap): void
    {
        // Find 404s with more referrers than the cap
        $overCap = (new Query())
            ->select(['notFoundId'])
            ->from(Table::REFERRERS)
            ->groupBy('notFoundId')
            ->having(['>', 'COUNT(*)', $cap])
            ->column();

        foreach ($overCap as $notFoundId) {
            $this->trimReferrersForNotFoundUri((int)$notFoundId, $cap);
        }
    }

    /**
     * Keep only the newest $cap referrers for a single 404.
     */
    public function trimReferrersForNotFoundUri(int $notFoundId, int $cap): void
    {
        $keepIds = (ReferrerQuery::find())
            ->select(['id'])
            ->andWhere(['notFoundId' => $notFoundId])
            ->orderBy(['hitLastTime' => SORT_DESC])
            ->limit($cap)
            ->column();

        if (!$keepIds) {
            return;
        }

        Craft::$app->getDb()->createCommand()->delete(
            Table::REFERRERS,
            ['and', ['notFoundId' => $notFoundId], ['not in', 'id', $keepIds]],
        )->execute();
    }

    /**
     * Delete referrers not seen since the given date.
     */
    public function purgeReferrers(DateTime $before): void
    {
        $cutoff = Db::prepareDateForDb($before);

        Craft::$app->getDb()->createCommand()->delete(
            Table::REFERRERS,
            ['<', 'hitLastTime', $cutoff],
        )->execute();
    }

    // ── Table Data (VueAdminTable formatting) ──────────────────────────

    /**
     * @return array{pagination: array, data: array}
     */
    public function getNotFoundUriTableData(string $handled = '0', ?int $siteId = null, int $page = 1, int $limit = 50, ?string $search = null, string $sortField = 'hitCount', int $sortDir = SORT_DESC): array
    {
        $handledFilter = match ($handled) {
            '1' => true,
            '0' => false,
            default => null,
        };

        $query = NotFoundUriQuery::find();
        $query->handled = $handledFilter;
        $query->siteId = $siteId;
        $query->search = $search;

        $totalCount = (clone $query)->count();

        $query->withReferrerCount = true;

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

        $sites = Craft::$app->getSites();

        $tableData = $items->map(fn(NotFoundUri $nf) => [
            'id' => $nf->id,
            'title' => $nf->uri ?: '/',
            'url' => $nf->getCpEditUrl(),
            'hitCount' => $nf->hitCount,
            'firstSeen' => $nf->dateCreated ? Craft::$app->getFormatter()->asDatetime($nf->dateCreated, 'short') : '-',
            'lastSeen' => $nf->hitLastTime ? Craft::$app->getFormatter()->asDatetime($nf->hitLastTime, 'short') : '-',
            'handled' => Cp::statusLabelHtml([
                'color' => $nf->handled ? Color::Teal : Color::Red,
                'icon' => $nf->handled ? 'check' : 'xmark',
                'label' => $nf->handled ? Craft::t('app', 'Yes') : Craft::t('app', 'No'),
            ]),
            'referrers' => $nf->referrerCount > 0
                ? Html::a(
                    Craft::t('not-found-redirects', '{count, number} {count, plural, =1{referrer} other{referrers}}', ['count' => $nf->referrerCount]),
                    $nf->getCpEditUrl()
                )
                : '<span class="light">' . Craft::t('app', 'None') . '</span>',
            'redirect' => $nf->redirectId
                ? Html::a('#' . $nf->redirectId, UrlHelper::cpUrl('not-found-redirects/redirects/edit/' . $nf->redirectId))
                : Html::a(Craft::t('app', 'Redirect'), UrlHelper::cpUrl('not-found-redirects/redirects/new', ['from' => $nf->uri, 'siteId' => $nf->siteId]), ['class' => 'btn small']),
            'siteName' => $sites->getSiteById($nf->siteId)?->name ?? '',
        ])->all();

        return [
            'pagination' => AdminTable::paginationLinks($page, $totalCount, $limit),
            'data' => $tableData,
        ];
    }

    /**
     * @return array{pagination: array, data: array}
     */
    public function getReferrerTableData(int $notFoundId, int $page = 1, int $limit = 50, string $sortField = 'hitCount', int $sortDir = SORT_DESC): array
    {
        $query = ReferrerQuery::find();
        $query->notFoundId = $notFoundId;

        $totalCount = (clone $query)->count();

        $pagination = new Pagination([
            'totalCount' => $totalCount,
            'pageSize' => $limit,
            'page' => $page - 1,
        ]);

        $sortColumn = match ($sortField) {
            'referrer', '__slot:title' => 'referrer',
            'hitLastTime' => 'hitLastTime',
            default => 'hitCount',
        };

        $items = $query
            ->orderBy([$sortColumn => $sortDir])
            ->limit($pagination->limit)
            ->offset($pagination->offset)
            ->collect();

        $tableData = $items->map(fn(Referrer $ref) => [
            'id' => $ref->id,
            'title' => $ref->referrer,
            'url' => $ref->referrer,
            'hitCount' => $ref->hitCount,
            'hitLastTime' => $ref->hitLastTime ? Craft::$app->getFormatter()->asDatetime($ref->hitLastTime, 'short') : '-',
        ])->all();

        return [
            'pagination' => AdminTable::paginationLinks($page, $totalCount, $limit),
            'data' => $tableData,
        ];
    }

    // ── Private Helpers ───────────────────────────────────────────────

    private function resolveSortColumn(string $sortField): string
    {
        return match ($sortField) {
            'hitCount' => Table::NOT_FOUND_URIS . '.[[hitCount]]',
            'hitLastTime' => Table::NOT_FOUND_URIS . '.[[hitLastTime]]',
            'dateCreated' => Table::NOT_FOUND_URIS . '.[[dateCreated]]',
            'referrers', 'referrerCount' => 'referrerCount',
            default => Table::NOT_FOUND_URIS . '.[[hitCount]]',
        };
    }
}
