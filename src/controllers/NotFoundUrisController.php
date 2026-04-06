<?php

namespace newism\notfoundredirects\controllers;

use Craft;
use craft\db\Query;
use craft\enums\Color;
use craft\helpers\ChartHelper;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTime;
use DateTimeZone;
use newism\notfoundredirects\actions\ExportNotFoundUris;
use newism\notfoundredirects\actions\ImportNotFoundUris;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\models\ImportForm;
use newism\notfoundredirects\models\NotFoundUri;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\models\Referrer;
use newism\notfoundredirects\NotFoundRedirects;
use newism\notfoundredirects\query\NotFoundUriQuery;
use newism\notfoundredirects\query\RedirectQuery;
use yii\helpers\FileHelper;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

class NotFoundUrisController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    // ── CP Screen Actions ──────────────────────────────────────────────

    public function actionIndex(): Response
    {
        $this->requirePermission('not-found-redirects:view404s');

        // Site filtering
        $sitesService = Craft::$app->getSites();
        $allSites = $sitesService->getAllSites();
        $siteHandle = $this->request->getQueryParam('site');
        $site = $siteHandle
            ? $sitesService->getSiteByHandle($siteHandle)
            : $allSites[0] ?? $sitesService->getPrimarySite();

        $handledParam = $this->request->getQueryParam('handled');

        if ($handledParam === '1' || $handledParam === 'true') {
            $handled = true;
            $selectedItem = 'handled';
        } elseif ($handledParam === '0' || $handledParam === 'false') {
            $handled = false;
            $selectedItem = 'unhandled';
        } else {
            $handled = null;
            $selectedItem = 'all404s';
        }

        $user = Craft::$app->getUser()->getIdentity();

        $actionMenuItems = [];

        if ($user->can('not-found-redirects:import404s')) {
            $actionMenuItems[] = [
                'label' => Craft::t('not-found-redirects', 'Import 404s'),
                'url' => UrlHelper::cpUrl('not-found-redirects/404s/import'),
            ];
        }

        if ($user->can('not-found-redirects:export404s')) {
            $actionMenuItems[] = [
                'label' => Craft::t('not-found-redirects', 'Export 404s'),
                'url' => UrlHelper::cpUrl('not-found-redirects/404s/export'),
            ];
        }

        if ($user->can('not-found-redirects:manageRedirects')) {
            $actionMenuItems[] = ['hr' => true];
            $actionMenuItems[] = [
                'label' => Craft::t('not-found-redirects', 'Reprocess 404s'),
                'action' => 'not-found-redirects/not-found-uris/reprocess',
            ];
        }

        if ($user->can('not-found-redirects:delete404s')) {
            $actionMenuItems[] = [
                'label' => Craft::t('not-found-redirects', 'Reset hit counts'),
                'action' => 'not-found-redirects/not-found-uris/reset-hit-counts',
                'confirm' => Craft::t('not-found-redirects', 'Are you sure you want to reset all 404 and referrer hit counts to zero?'),
            ];
            $actionMenuItems[] = ['hr' => true];
            $actionMenuItems[] = [
                'label' => Craft::t('not-found-redirects', 'Delete all 404s'),
                'action' => 'not-found-redirects/not-found-uris/delete-all',
                'confirm' => Craft::t('not-found-redirects', 'Are you sure you want to delete all 404 records?'),
                'destructive' => true,
            ];
        }

        $response = $this->asCpScreen()
            ->title(Craft::t('not-found-redirects', '404s'))
            ->selectedSubnavItem('404s')
            ->site($site)
            ->selectableSites($allSites)
            ->pageSidebarTemplate('_includes/nav.twig', [
                'selectedItem' => $selectedItem,
                'items' => [
                    'all404s' => [
                        'label' => Craft::t('app', 'All'),
                        'url' => 'not-found-redirects/404s?handled=all',
                    ],
                    'unhandled' => [
                        'label' => Craft::t('not-found-redirects', 'Unhandled'),
                        'url' => 'not-found-redirects/404s?handled=0',
                    ],
                    'handled' => [
                        'label' => Craft::t('not-found-redirects', 'Handled'),
                        'url' => 'not-found-redirects/404s?handled=1',
                    ],
                ],
            ])
            ->actionMenuItems(fn() => $actionMenuItems)
            ->contentTemplate('not-found-redirects/404s/index', [
                'handled' => $handled,
                'siteId' => $site->id,
                'canDelete404s' => $user->can('not-found-redirects:delete404s'),
                'canManageRedirects' => $user->can('not-found-redirects:manageRedirects'),
            ]);

        return $response;
    }

    public function actionDetail(int $notFoundId): Response
    {
        $this->requirePermission('not-found-redirects:view404s');

        $notFoundQuery = NotFoundUriQuery::find();
        $notFoundQuery->id = $notFoundId;
        $notFoundQuery->withReferrerCount = true;
        $notFound = $notFoundQuery->one();
        if (!$notFound) {
            throw new NotFoundHttpException('404 record not found.');
        }

        $backUrl = $notFound->handled ? 'not-found-redirects/404s?handled=1' : 'not-found-redirects/404s';

        $redirectInfo = null;
        if ($notFound->redirectId) {
            $redirectQuery = RedirectQuery::find();
            $redirectQuery->id = $notFound->redirectId;
            $redirectInfo = $redirectQuery->one();
        }

        $user = Craft::$app->getUser()->getIdentity();
        $canManageRedirects = $user?->can('not-found-redirects:manageRedirects') ?? false;
        $canDelete404s = $user?->can('not-found-redirects:delete404s') ?? false;

        $uriLabel = $notFound->uri ?: '/';

        $response = $this->asCpScreen()
            ->title($uriLabel)
            ->selectedSubnavItem('404s')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb(Craft::t('not-found-redirects', '404s'), $backUrl)
            ->addCrumb($uriLabel, 'not-found-redirects/404s/detail/' . $notFoundId)
            ->pageSidebarTemplate('_includes/nav.twig', [
                'selectedItem' => $notFound->handled ? 'handled' : 'unhandled',
                'items' => [
                    'all404s' => [
                        'label' => Craft::t('app', 'All'),
                        'url' => 'not-found-redirects/404s?handled=all',
                    ],
                    'unhandled' => [
                        'label' => Craft::t('not-found-redirects', 'Unhandled'),
                        'url' => 'not-found-redirects/404s?handled=0',
                    ],
                    'handled' => [
                        'label' => Craft::t('not-found-redirects', 'Handled'),
                        'url' => 'not-found-redirects/404s?handled=1',
                    ],
                ],
            ])
            ->contentTemplate('not-found-redirects/404s/detail', [
                'notFound' => $notFound,
                'redirectInfo' => $redirectInfo,
                'notFoundId' => $notFoundId,
                'canDelete404s' => $canDelete404s,
                'handledHtml' => Cp::statusLabelHtml([
                    'color' => $notFound->handled ? Color::Teal : Color::Red,
                    'icon' => $notFound->handled ? 'check' : 'xmark',
                    'label' => $notFound->handled ? Craft::t('app', 'Yes') : Craft::t('app', 'No'),
                ]),
            ]);

        if ($canManageRedirects) {
            $response->additionalButtonsHtml(
                $notFound->handled && $notFound->redirectId
                    ? Html::a(Craft::t('not-found-redirects', 'Edit Redirect'), UrlHelper::cpUrl('not-found-redirects/redirects/edit/' . $notFound->redirectId), [
                    'class' => ['btn', 'submit'],
                ])
                    : Html::a(Craft::t('not-found-redirects', 'Create Redirect'), UrlHelper::cpUrl('not-found-redirects/redirects/new', ['from' => $notFound->uri, 'siteId' => $notFound->siteId]), [
                    'class' => ['btn', 'submit'],
                ])
            );
        }

        if ($canDelete404s) {
            $response->actionMenuItems(fn() => [
                [
                    'label' => Craft::t('not-found-redirects', 'Delete all referrers'),
                    'action' => 'not-found-redirects/not-found-uris/delete-all-referrers',
                    'params' => ['notFoundId' => $notFoundId],
                    'confirm' => Craft::t('not-found-redirects', 'Are you sure you want to delete all referrers for this 404?'),
                    'destructive' => true,
                ],
                [
                    'label' => Craft::t('not-found-redirects', 'Delete this 404'),
                    'action' => 'not-found-redirects/not-found-uris/delete',
                    'params' => ['id' => $notFoundId],
                    'redirect' => $backUrl,
                    'confirm' => Craft::t('not-found-redirects', 'Are you sure you want to delete this 404 record?'),
                    'destructive' => true,
                ],
            ]);
        }

        return $response;
    }

    // ── Table Data Endpoints (JSON) ────────────────────────────────────

    public function actionTableData(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:view404s');

        $siteId = $this->request->getParam('siteId');

        return $this->asSuccess(data: NotFoundRedirects::getInstance()->getNotFoundUriService()->getNotFoundUriTableData(
            handled: $this->request->getParam('handled', '0'),
            siteId: $siteId ? (int)$siteId : null,
            page: (int)$this->request->getParam('page', 1),
            limit: (int)$this->request->getParam('per_page', 50),
            search: $this->request->getParam('search'),
            sortField: $this->request->getParam('sort.0.field', 'hitCount'),
            sortDir: $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC,
        ));
    }

    public function actionReferrersTableData(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:view404s');

        return $this->asSuccess(data: NotFoundRedirects::getInstance()->getNotFoundUriService()->getReferrerTableData(
            notFoundId: (int)$this->request->getRequiredParam('notFoundId'),
            page: (int)$this->request->getParam('page', 1),
            limit: (int)$this->request->getParam('per_page', 50),
            sortField: $this->request->getParam('sort.0.field', 'hitCount'),
            sortDir: $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC,
        ));
    }

    // ── Mutation Actions (POST) ────────────────────────────────────────

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:delete404s');

        $id = (int)$this->request->getRequiredBodyParam('id');
        NotFoundRedirects::getInstance()->getNotFoundUriService()->deleteNotFoundUriById($id);

        return $this->asSuccess();
    }

    public function actionDeleteAll(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:delete404s');

        $count = NotFoundRedirects::getInstance()->getNotFoundUriService()->deleteAllNotFoundUris();

        return $this->asSuccess(Craft::t('not-found-redirects', '{count} 404 record(s) deleted.', ['count' => $count]));
    }

    public function actionDeleteReferrer(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:delete404s');

        $id = (int)$this->request->getRequiredBodyParam('id');
        Craft::$app->getDb()->createCommand()
            ->delete(Table::REFERRERS, ['id' => $id])
            ->execute();

        return $this->asSuccess();
    }

    public function actionDeleteAllReferrers(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:delete404s');

        $notFoundId = (int)$this->request->getRequiredBodyParam('notFoundId');
        $count = Craft::$app->getDb()->createCommand()
            ->delete(Table::REFERRERS, ['notFoundId' => $notFoundId])
            ->execute();

        return $this->asSuccess(Craft::t('not-found-redirects', '{count} referrer(s) deleted.', ['count' => $count]));
    }

    public function actionReprocess(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:manageRedirects');

        $result = NotFoundRedirects::getInstance()->getNotFoundUriService()->reprocess();

        return $this->asSuccess(Craft::t('not-found-redirects', '{count} 404(s) matched and marked as handled.', ['count' => $result['summary']['matched']]));
    }

    public function actionResetHitCounts(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:delete404s');

        $result = NotFoundRedirects::getInstance()->getNotFoundUriService()->resetHitCounts();

        return $this->asSuccess(Craft::t('not-found-redirects', 'Reset hit counts for {notFound} 404(s) and {referrers} referrer(s).', [
            'notFound' => $result['summary']['notFound'],
            'referrers' => $result['summary']['referrers'],
        ]));
    }

    // ── Import / Export ─────────────────────────────────────────────────────────

    public function actionImport(?ImportForm $importForm = null): Response
    {
        $this->requirePermission('not-found-redirects:import404s');

        if (!$importForm) {
            $importForm = new ImportForm();
        }

        return $this->asCpScreen()
            ->title(Craft::t('not-found-redirects', 'Import 404s'))
            ->selectedSubnavItem('404s')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb(Craft::t('not-found-redirects', '404s'), 'not-found-redirects/404s')
            ->addCrumb(Craft::t('app', 'Import'), 'not-found-redirects/404s/import')
            ->action('not-found-redirects/not-found-uris/do-import')
            ->redirectUrl('not-found-redirects/404s')
            ->submitButtonLabel(Craft::t('app', 'Import'))
            ->formAttributes(['enctype' => 'multipart/form-data'])
            ->contentTemplate('not-found-redirects/404s/import', [
                'importForm' => $importForm,
            ]);
    }

    public function actionDoImport(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:import404s');

        $importForm = new ImportForm();
        $importForm->file = UploadedFile::getInstanceByName('file');
        $importForm->inputSource = $this->request->getBodyParam('inputSource') ?: null;

        if (!$importForm->validate()) {
            return $this->asModelFailure($importForm, Craft::t('not-found-redirects', 'Import failed.'), 'importForm');
        }

        $content = file_get_contents($importForm->file->tempName);
        $importForm->result = (new ImportNotFoundUris())->handle($content, source: $importForm->inputSource);

        return $this->asCpScreen()
            ->title(Craft::t('not-found-redirects', 'Import Results'))
            ->selectedSubnavItem('404s')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb(Craft::t('not-found-redirects', '404s'), 'not-found-redirects/404s')
            ->addCrumb(Craft::t('app', 'Import'), 'not-found-redirects/404s/import')
            ->contentTemplate('not-found-redirects/import-results', [
                'importForm' => $importForm,
            ]);
    }

    public function actionExport(): Response
    {
        $this->requirePermission('not-found-redirects:export404s');

        if ($this->request->getIsPost()) {
            $format = $this->request->getBodyParam('format', 'csv');
            $result = (new ExportNotFoundUris())->handle($format);
            Craft::$app->getResponse()->sendContentAsFile(
                $result->content,
                $result->filename,
                ['mimeType' => FileHelper::getMimeTypeByExtension($result->filename)],
            );
            Craft::$app->end();
        }

        return $this->asCpScreen()
            ->title(Craft::t('not-found-redirects', 'Export 404s'))
            ->selectedSubnavItem('404s')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb(Craft::t('not-found-redirects', '404s'), 'not-found-redirects/404s')
            ->addCrumb(Craft::t('app', 'Export'), 'not-found-redirects/404s/export')
            ->action('not-found-redirects/not-found-uris/export')
            ->redirectUrl('not-found-redirects/404s')
            ->submitButtonLabel(Craft::t('app', 'Export'))
            ->contentTemplate('not-found-redirects/404s/export');
    }

    // ── Chart Data ─────────────────────────────────────────────────────────

    public function actionChartData(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:view404s');

        $dateRange = $this->request->getRequiredBodyParam('dateRange');

        $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
        $now = new DateTime('now', $timeZone);

        // End date is tomorrow midnight in app timezone.
        // ChartHelper converts to UTC via Db::prepareDateForDb(), so
        // "tomorrow midnight AEST" becomes "today 13:00 UTC" — this ensures
        // records created today (in app timezone) are included.
        $tomorrow = (clone $now)->modify('+1 day')->setTime(0, 0);

        [$startDate, $endDate] = match ($dateRange) {
            'd7' => [
                (clone $now)->modify('-6 days')->setTime(0, 0),
                $tomorrow,
            ],
            'd30' => [
                (clone $now)->modify('-30 days')->setTime(0, 0),
                $tomorrow,
            ],
            'lastweek' => [
                (clone $now)->modify('-13 days')->setTime(0, 0),
                (clone $now)->modify('-6 days')->setTime(0, 0),
            ],
            'lastmonth' => [
                (clone $now)->modify('-60 days')->setTime(0, 0),
                (clone $now)->modify('-30 days')->setTime(0, 0),
            ],
            default => throw new BadRequestHttpException('Invalid date range'),
        };

        $display = $this->request->getBodyParam('display', 'perDay');

        $query = (new Query())
            ->from(Table::NOT_FOUND_URIS);

        $valueLabel = $display === 'cumulative'
            ? Craft::t('not-found-redirects', '404s Created (cumulative)')
            : Craft::t('not-found-redirects', '404s Created');

        $dataTable = ChartHelper::getRunChartDataFromQuery(
            $query,
            $startDate,
            $endDate,
            Table::NOT_FOUND_URIS . '.dateCreated',
            'count',
            '*',
            [
                'intervalUnit' => 'day',
                'valueLabel' => $valueLabel,
            ],
        );

        $total = 0;
        foreach ($dataTable['rows'] as $row) {
            $total += $row[1];
        }

        if ($display === 'cumulative') {
            $cumulative = 0;
            foreach ($dataTable['rows'] as &$row) {
                $cumulative += $row[1];
                $row[1] = $cumulative;
            }
            unset($row);
        }

        return $this->asJson([
            'dataTable' => $dataTable,
            'total' => $total,
            'formats' => ChartHelper::formats(),
            'orientation' => Craft::$app->getLocale()->getOrientation(),
            'scale' => 'day',
        ]);
    }

    public function actionCoverageChartData(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:view404s');

        $dateRange = $this->request->getRequiredBodyParam('dateRange');

        $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
        $now = new DateTime('now', $timeZone);
        $tomorrow = (clone $now)->modify('+1 day')->setTime(0, 0);

        [$startDate, $endDate] = match ($dateRange) {
            'd7' => [
                (clone $now)->modify('-6 days')->setTime(0, 0),
                $tomorrow,
            ],
            'd30' => [
                (clone $now)->modify('-30 days')->setTime(0, 0),
                $tomorrow,
            ],
            'lastweek' => [
                (clone $now)->modify('-13 days')->setTime(0, 0),
                (clone $now)->modify('-6 days')->setTime(0, 0),
            ],
            'lastmonth' => [
                (clone $now)->modify('-60 days')->setTime(0, 0),
                (clone $now)->modify('-30 days')->setTime(0, 0),
            ],
            default => throw new BadRequestHttpException('Invalid date range'),
        };

        $valueLabel = Craft::t('not-found-redirects', 'Unhandled 404s');

        $rows = [];
        $cursor = clone $startDate;
        $endTimestamp = $endDate->getTimestamp();

        while ($cursor->getTimestamp() < $endTimestamp) {
            $cursorEnd = (clone $cursor)->modify('+1 day');
            $endTs = Db::prepareDateForDb($cursorEnd);

            $totalQuery = NotFoundUriQuery::find();
            $totalQuery->andWhere(['<', Table::NOT_FOUND_URIS . '.[[dateCreated]]', $endTs]);
            $total = (int)$totalQuery->count();

            $handled = (int)(new Query())
                ->from(Table::NOT_FOUND_URIS)
                ->innerJoin(
                    Table::REDIRECTS,
                    Table::NOT_FOUND_URIS . '.[[redirectId]] = ' . Table::REDIRECTS . '.[[id]]'
                )
                ->andWhere(['<', Table::NOT_FOUND_URIS . '.[[dateCreated]]', $endTs])
                ->andWhere(['<', Table::REDIRECTS . '.[[dateCreated]]', $endTs])
                ->count();

            $rows[] = [$cursor->format('Y-m-d'), $total - $handled];
            $cursor = $cursorEnd;
        }

        $total = $rows ? end($rows)[1] : 0;

        return $this->asJson([
            'dataTable' => [
                'columns' => [
                    ['type' => 'date', 'label' => Craft::t('app', 'Date')],

                    ['type' => 'number', 'label' => $valueLabel],
                ],
                'rows' => $rows,
            ],
            'total' => $total,
            'formats' => ChartHelper::formats(),
            'orientation' => Craft::$app->getLocale()->getOrientation(),
            'scale' => 'day',
        ]);
    }
}
