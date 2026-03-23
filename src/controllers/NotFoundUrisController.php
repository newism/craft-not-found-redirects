<?php

namespace newism\notfoundredirects\controllers;

use Craft;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\NotFoundRedirects;
use yii\web\Response;

class NotFoundUrisController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function beforeAction($action): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('not-found-redirects:view404s')) {
            throw new \yii\web\ForbiddenHttpException('User not authorized to view 404s.');
        }

        return parent::beforeAction($action);
    }

    // ── CP Screen Actions ──────────────────────────────────────────────

    public function actionIndex(): Response
    {
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

        $actionMenuItems = [
            [
                'label' => Craft::t('app', 'Reprocess 404s'),
                'action' => 'not-found-redirects/not-found-uris/reprocess',
            ],
            [
                'label' => Craft::t('app', 'Export 404s'),
                'action' => 'not-found-redirects/not-found-uris/export',
            ],
        ];

        if ($user->can('not-found-redirects:delete404s')) {
            $actionMenuItems[] = ['hr' => true];
            $actionMenuItems[] = [
                'label' => Craft::t('app', 'Delete all 404s'),
                'action' => 'not-found-redirects/not-found-uris/delete-all',
                'confirm' => 'Are you sure you want to delete all 404 records?',
                'destructive' => true,
            ];
        }

        $response = $this->asCpScreen()
            ->title('404s')
            ->selectedSubnavItem('404s')
            ->pageSidebarTemplate('not-found-redirects/_sidebar', [
                'selectedItem' => $selectedItem,
            ])
            ->actionMenuItems(fn() => $actionMenuItems)
            ->contentTemplate('not-found-redirects/_404s', [
                'handled' => $handled,
                'canDelete404s' => $user->can('not-found-redirects:delete404s'),
                'canManageRedirects' => $user->can('not-found-redirects:manageRedirects'),
            ]);

        if ($user->can('not-found-redirects:manageRedirects')) {
            $response->additionalButtonsHtml(
                Html::a(Craft::t('app', 'New Redirect'), UrlHelper::cpUrl('not-found-redirects/redirects/new'), [
                    'class' => ['btn', 'submit', 'add', 'icon'],
                ])
            );
        }

        return $response;
    }

    public function actionDetail(int $notFoundId): Response
    {
        $plugin = NotFoundRedirects::getInstance();

        $notFound = $plugin->notFound->findById($notFoundId);
        if (!$notFound) {
            throw new \yii\web\NotFoundHttpException('404 record not found.');
        }

        $backUrl = $notFound->handled ? 'not-found-redirects/404s?handled=1' : 'not-found-redirects/404s';

        $redirectInfo = $notFound->redirectId
            ? $plugin->redirects->findById($notFound->redirectId)
            : null;

        return $this->asCpScreen()
            ->title(Uri::display($notFound->uri))
            ->selectedSubnavItem('404s')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb('404s', $backUrl)
            ->addCrumb(Uri::display($notFound->uri), 'not-found-redirects/404s/detail/' . $notFoundId)
            ->pageSidebarTemplate('not-found-redirects/_sidebar', [
                'selectedItem' => $notFound->handled ? 'handled' : 'unhandled',
            ])
            ->additionalButtonsHtml(
                $notFound->handled && $notFound->redirectId
                    ? Html::a(Craft::t('app', 'Edit Redirect'), UrlHelper::cpUrl('not-found-redirects/redirects/edit/' . $notFound->redirectId), [
                        'class' => ['btn'],
                    ])
                    : Html::a(Craft::t('app', 'Create Redirect'), UrlHelper::cpUrl('not-found-redirects/redirects/new', ['from' => $notFound->uri]), [
                        'class' => ['btn', 'submit'],
                    ])
            )
            ->actionMenuItems(fn() => [
                [
                    'label' => Craft::t('app', 'Delete all referrers'),
                    'action' => 'not-found-redirects/not-found-uris/delete-all-referrers',
                    'params' => ['notFoundId' => $notFoundId],
                    'confirm' => 'Are you sure you want to delete all referrers for this 404?',
                    'destructive' => true,
                ],
                [
                    'label' => Craft::t('app', 'Delete this 404'),
                    'action' => 'not-found-redirects/not-found-uris/delete',
                    'params' => ['id' => $notFoundId],
                    'redirect' => $backUrl,
                    'confirm' => 'Are you sure you want to delete this 404 record?',
                    'destructive' => true,
                ],
            ])
            ->contentTemplate('not-found-redirects/_detail', [
                'notFound' => $notFound,
                'redirectInfo' => $redirectInfo,
                'notFoundId' => $notFoundId,
            ]);
    }

    // ── Table Data Endpoints (JSON) ────────────────────────────────────

    public function actionTableData(): Response
    {
        $this->requireAcceptsJson();

        return $this->asSuccess(data: NotFoundRedirects::getInstance()->notFound->getTableData(
            handled: $this->request->getParam('handled', '0'),
            page: (int) $this->request->getParam('page', 1),
            limit: (int) $this->request->getParam('per_page', 50),
            search: $this->request->getParam('search'),
            sortField: $this->request->getParam('sort.0.field', 'hitCount'),
            sortDir: $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC,
        ));
    }

    public function actionReferrersTableData(): Response
    {
        $this->requireAcceptsJson();

        return $this->asSuccess(data: NotFoundRedirects::getInstance()->notFound->getReferrersTableData(
            notFoundId: (int) $this->request->getRequiredParam('notFoundId'),
            page: (int) $this->request->getParam('page', 1),
            limit: (int) $this->request->getParam('per_page', 50),
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

        $id = (int) $this->request->getRequiredBodyParam('id');
        NotFoundRedirects::getInstance()->notFound->deleteById($id);

        return $this->asSuccess();
    }

    public function actionDeleteAll(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:delete404s');

        $count = NotFoundRedirects::getInstance()->notFound->deleteAll();

        return $this->asSuccess("{$count} 404 record(s) deleted.");
    }

    public function actionDeleteReferrer(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:delete404s');

        $id = (int) $this->request->getRequiredBodyParam('id');
        Craft::$app->getDb()->createCommand()
            ->delete('{{%notfoundredirects_referrers}}', ['id' => $id])
            ->execute();

        return $this->asSuccess();
    }

    public function actionDeleteAllReferrers(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:delete404s');

        $notFoundId = (int) $this->request->getRequiredBodyParam('notFoundId');
        $count = Craft::$app->getDb()->createCommand()
            ->delete('{{%notfoundredirects_referrers}}', ['notFoundId' => $notFoundId])
            ->execute();

        return $this->asSuccess("{$count} referrer(s) deleted.");
    }

    public function actionReprocess(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:manageRedirects');

        $result = NotFoundRedirects::getInstance()->notFound->reprocess();

        return $this->asSuccess("{$result['summary']['matched']} 404(s) matched and marked as handled.");
    }

    // ── Export ─────────────────────────────────────────────────────────

    public function actionExport(): void
    {
        $this->requirePostRequest();

        NotFoundRedirects::getInstance()->csv->export404s();
        Craft::$app->end();
    }
}
