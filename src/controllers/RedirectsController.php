<?php

namespace newism\notfoundredirects\controllers;

use Craft;
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use newism\notfoundredirects\actions\ExportRedirects;
use newism\notfoundredirects\actions\ImportRedirects;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\models\ImportForm;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\NotFoundRedirects;
use newism\notfoundredirects\query\RedirectQuery;
use newism\notfoundredirects\web\assets\ImportFormAsset;
use newism\notfoundredirects\web\assets\RedirectFormAsset;
use yii\helpers\FileHelper;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

class RedirectsController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    // ── CP Screen Actions ──────────────────────────────────────────────

    public function actionIndex(): Response
    {
        $this->requirePermission('not-found-redirects:viewRedirects');

        // Site filtering — supports "All Sites" (siteId=null) and individual sites
        $sitesService = Craft::$app->getSites();
        $allSites = $sitesService->getAllSites();
        $siteHandle = $this->request->getQueryParam('site');

        if ($siteHandle && $siteHandle !== '*') {
            $site = $sitesService->getSiteByHandle($siteHandle);
            $siteId = $site?->id;
            $siteLabel = $site ? Craft::t('site', $site->name) : Craft::t('app', 'All Sites');
        } else {
            $site = null;
            $siteId = null;
            $siteLabel = Craft::t('app', 'All Sites');
        }

        $systemGeneratedParam = $this->request->getQueryParam('systemGenerated');

        if ($systemGeneratedParam === '1') {
            $selectedItem = 'auto';
        } elseif ($systemGeneratedParam === '0') {
            $selectedItem = 'manual';
        } else {
            $selectedItem = 'allRedirects';
        }

        $user = Craft::$app->getUser()->getIdentity();

        $actionMenuItems = [];

        if ($user->can('not-found-redirects:importRedirects')) {
            $actionMenuItems[] = [
                'label' => Craft::t('not-found-redirects', 'Import Redirects'),
                'url' => UrlHelper::cpUrl('not-found-redirects/redirects/import'),
            ];
        }

        if ($user->can('not-found-redirects:exportRedirects')) {
            $actionMenuItems[] = [
                'label' => Craft::t('not-found-redirects', 'Export Redirects'),
                'url' => UrlHelper::cpUrl('not-found-redirects/redirects/export'),
            ];
        }

        if ($user->can('not-found-redirects:deleteRedirects')) {
            $actionMenuItems[] = ['hr' => true];
            $actionMenuItems[] = [
                'label' => Craft::t('not-found-redirects', 'Delete all redirects'),
                'action' => 'not-found-redirects/redirects/delete-all',
                'confirm' => Craft::t('not-found-redirects', 'Are you sure you want to delete all redirect rules? This cannot be undone.'),
                'destructive' => true,
            ];
        }

        // Build site breadcrumb with "All Sites" option
        $isMultiSite = Craft::$app->getIsMultiSite();
        $siteCrumbItems = [];
        if ($isMultiSite) {
            $path = $this->request->getPathInfo();
            $params = $this->request->getQueryParamsWithoutPath();
            unset($params['site']);

            $siteCrumbItems[] = [
                'label' => Craft::t('app', 'All Sites'),
                'url' => UrlHelper::cpUrl($path, ['site' => '*'] + $params),
                'selected' => $siteId === null,
            ];
            foreach ($allSites as $s) {
                $siteCrumbItems[] = [
                    'label' => Craft::t('site', $s->name),
                    'url' => UrlHelper::cpUrl($path, ['site' => $s->handle] + $params),
                    'selected' => $s->id === $siteId,
                ];
            }
        }

        $response = $this->asCpScreen()
            ->title(Craft::t('not-found-redirects', 'Redirects'))
            ->selectedSubnavItem('redirects');

        if ($isMultiSite) {
            $response->crumbs([
                [
                    'id' => 'site-crumb',
                    'icon' => Cp::earthIcon(),
                    'label' => $siteLabel,
                    'menu' => [
                        'label' => Craft::t('app', 'Select site'),
                        'items' => $siteCrumbItems,
                    ],
                ],
            ]);
        }

        $response
            ->pageSidebarTemplate('_includes/nav.twig', [
                'selectedItem' => $selectedItem,
                'items' => [
                    'allRedirects' => [
                        'label' => Craft::t('app', 'All'),
                        'url' => 'not-found-redirects/redirects',
                    ],
                    'manual' => [
                        'label' => Craft::t('not-found-redirects', 'Manual'),
                        'url' => 'not-found-redirects/redirects?systemGenerated=0',
                    ],
                    'auto' => [
                        'label' => Craft::t('not-found-redirects', 'System Generated'),
                        'url' => 'not-found-redirects/redirects?systemGenerated=1',
                    ],
                ],
            ])
            ->actionMenuItems(fn() => $actionMenuItems)
            ->contentTemplate('not-found-redirects/redirects/index', [
                'systemGenerated' => $systemGeneratedParam,
                'siteId' => $siteId,
                'canDeleteRedirects' => $user->can('not-found-redirects:deleteRedirects'),
            ]);

        if ($user->can('not-found-redirects:manageRedirects')) {
            $response->additionalButtonsHtml(
                Html::a(Craft::t('not-found-redirects', 'New Redirect'), UrlHelper::cpUrl('not-found-redirects/redirects/new'), [
                    'class' => ['btn', 'submit', 'add', 'icon'],
                ])
            );
        }

        return $response;
    }

    public function actionEdit(?int $redirectId = null, ?Redirect $redirect = null): Response
    {
        $this->requirePermission('not-found-redirects:viewRedirects');

        // Support both route params and query params (slideouts use action URLs)
        $redirectId = $redirectId ?? $this->request->getParam('redirectId');
        $from = $this->request->getQueryParam('from');
        $toType = $this->request->getQueryParam('toType');
        $toElementId = $this->request->getQueryParam('toElementId');
        $siteIdParam = $this->request->getQueryParam('siteId');

        if (!$redirect) {
            if ($redirectId) {
                $redirectQuery = RedirectQuery::find();
                $redirectQuery->id = $redirectId;
                $redirect = $redirectQuery->one();
                if (!$redirect) {
                    throw new NotFoundHttpException('Redirect not found.');
                }
            } else {
                $redirect = new Redirect();
                $redirect->from = $from;
                $redirect->toType = $toType;
                $redirect->toElementId = $toElementId ? (int)$toElementId : null;
                $redirect->siteId = $siteIdParam ? (int)$siteIdParam : null;
            }
        }

        $title = $redirect->id
            ? Craft::t('not-found-redirects', 'Edit Redirect')
            : Craft::t('not-found-redirects', 'New Redirect');

        $view = Craft::$app->getView();
        $view->registerAssetBundle(RedirectFormAsset::class);
        $formId = sprintf('redirect-edit-form-%s', mt_rand());
        $vars = [
            $view->namespaceInputId($formId)
        ];
        $view->registerJsWithVars(fn($formId) => <<<JS
new Newism.notFoundRedirects.RedirectForm('[data-redirect-form=$formId]');
JS, $vars);

        $response = $this->asCpScreen()
            ->title($title)
            ->selectedSubnavItem('redirects')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb(Craft::t('not-found-redirects', 'Redirects'), 'not-found-redirects/redirects')
            ->addCrumb($title, $redirectId ? "not-found-redirects/redirects/edit/{$redirectId}" : 'not-found-redirects/redirects/new')
            ->formAttributes(
                ['data-redirect-form' => $formId]
            )
            ->action('not-found-redirects/redirects/save')
            ->redirectUrl('not-found-redirects/redirects')
            ->saveShortcutRedirectUrl('not-found-redirects/redirects/edit/{id}')
            ->addAltAction(Craft::t('app', 'Save and continue editing'), [
                'redirect' => 'not-found-redirects/redirects/edit/{id}',
                'shortcut' => true,
                'retainScroll' => true,
            ])
            ->contentTemplate('not-found-redirects/redirects/_form', [
                'redirect' => $redirect,
                'sites' => Craft::$app->getSites()->getAllSites(),
                'notes' => $redirect->id
                    ? $redirect->getNotes()
                    : collect(),
            ])
            ->metaSidebarTemplate('not-found-redirects/redirects/_meta', [
                'redirect' => $redirect,
            ]);

        if ($redirect->id) {
            $response->editUrl("not-found-redirects/redirects/edit/{$redirect->id}");

            $user = Craft::$app->getUser()->getIdentity();
            if ($user && $user->can('not-found-redirects:deleteRedirects')) {
                $response->actionMenuItems(fn() => [
                    [
                        'label' => Craft::t('not-found-redirects', 'Delete redirect'),
                        'action' => 'not-found-redirects/redirects/delete',
                        'params' => ['id' => $redirect->id],
                        'confirm' => Craft::t('not-found-redirects', 'Are you sure you want to delete this redirect?'),
                        'redirect' => 'not-found-redirects/redirects',
                        'destructive' => true,
                        'showInChips' => true,
                    ],
                ]);
            }
        }

        return $response;
    }

    public function actionPatternReference(): Response
    {
        $this->requirePermission('not-found-redirects:viewRedirects');

        return $this->asCpScreen()
            ->title(Craft::t('not-found-redirects', 'Pattern Matching'))
            ->contentTemplate('not-found-redirects/redirects/_pattern-reference');
    }

    // ── Table Data Endpoints (JSON) ────────────────────────────────────

    public function actionTableData(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:viewRedirects');

        $systemGeneratedParam = $this->request->getParam('systemGenerated');
        $systemGenerated = match ($systemGeneratedParam) {
            '1' => true,
            '0' => false,
            default => null,
        };

        $siteIdParam = $this->request->getParam('siteId');
        // Empty string or absent = all sites (null), numeric = specific site
        $siteId = ($siteIdParam !== null && $siteIdParam !== '') ? (int)$siteIdParam : null;

        return $this->asSuccess(data: NotFoundRedirects::getInstance()->getRedirectService()->getRedirectTableData(
            page: (int)$this->request->getParam('page', 1),
            limit: (int)$this->request->getParam('per_page', 50),
            search: $this->request->getParam('search'),
            systemGenerated: $systemGenerated,
            siteId: $siteId,
            sortField: $this->request->getParam('sort.0.field', 'priority'),
            sortDir: $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC,
        ));
    }

    // ── Mutation Actions (POST) ────────────────────────────────────────

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:manageRedirects');

        $redirect = Craft::createObject(Redirect::class);
        $redirect->id = (int)$this->request->getBodyParam('redirectId') ?: null;
        $redirect->siteId = (int)$this->request->getBodyParam('siteId') ?: null;
        $redirect->from = $this->request->getBodyParam('from');
        $redirect->to = $this->request->getBodyParam('to', '');
        $redirect->toType = $this->request->getBodyParam('toType', 'url');
        $redirect->statusCode = (int)$this->request->getBodyParam('statusCode', 302);
        $redirect->priority = (int)$this->request->getBodyParam('priority', 0);
        $redirect->enabled = (bool)$this->request->getBodyParam('enabled', true);
        $redirect->regexMatch = (bool)$this->request->getBodyParam('regexMatch', false);
        $redirect->startDate = DateTimeHelper::toDateTime($this->request->getBodyParam('startDate')) ?: null;
        $redirect->endDate = DateTimeHelper::toDateTime($this->request->getBodyParam('endDate')) ?: null;

        // Element select posts as array for single select
        $toElementId = $this->request->getBodyParam('toElementId');
        if (is_array($toElementId)) {
            $toElementId = reset($toElementId) ?: null;
        }
        $redirect->toElementId = $toElementId ? (int)$toElementId : null;

        // Clear irrelevant fields based on type
        if ($redirect->toType !== 'entry') {
            $redirect->toElementId = null;
        }

        if (!NotFoundRedirects::getInstance()->getRedirectService()->saveRedirect($redirect)) {
            return $this->asModelFailure(
                $redirect,
                Craft::t('not-found-redirects', 'Could not save redirect.'),
                'redirect',
            );
        }

        // Create initial note if provided (new redirects or existing)
        $newNote = trim($this->request->getBodyParam('newNote', ''));
        if ($newNote && $redirect->id) {
            NotFoundRedirects::getInstance()->getNoteService()->addNote($redirect->id, $newNote);
        }

        return $this->asModelSuccess(
            $redirect,
            Craft::t('not-found-redirects', 'Redirect saved.'),
            'redirect'
        );
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:deleteRedirects');

        $id = (int)$this->request->getRequiredBodyParam('id');
        NotFoundRedirects::getInstance()->getRedirectService()->deleteRedirectById($id);

        return $this->asSuccess();
    }

    public function actionDeleteAll(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:deleteRedirects');

        $count = NotFoundRedirects::getInstance()->getRedirectService()->deleteAllRedirects();

        return $this->asSuccess(Craft::t('not-found-redirects', '{count} redirect(s) deleted.', ['count' => $count]));
    }

    public function actionTestMatch(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:manageRedirects');

        $from = $this->request->getRequiredParam('from');
        $to = $this->request->getParam('to', '');
        $toType = $this->request->getParam('toType', 'url');
        $toElementId = $this->request->getParam('toElementId');
        $testUris = $this->request->getRequiredParam('testUris');
        $regexMatch = (bool)$this->request->getParam('regexMatch', false);

        // Resolve entry URL if toType is entry
        if ($toType === 'entry' && $toElementId) {
            $element = Craft::$app->getElements()->getElementById((int)$toElementId);
            if ($element) {
                $to = $element->getUrl() ?? $to;
            }
        }

        $service = NotFoundRedirects::getInstance()->redirectService;
        $results = [];

        $lines = array_filter(array_map('trim', preg_split('/\r?\n/', $testUris)));

        foreach ($lines as $uri) {
            $uri = Uri::strip($uri);
            $destination = $service->testMatch($from, $to, $uri, $regexMatch);
            $matched = $destination !== null;
            $results[] = [
                'uri' => $uri ?: '/',
                'matchedHtml' => Cp::statusLabelHtml([
                    'color' => $matched ? Color::Teal : Color::Gray,
                    'icon' => $matched ? 'check' : 'xmark',
                    'label' => $matched ? Craft::t('app', 'Yes') : Craft::t('app', 'No'),
                ]),
                'destination' => $destination !== null ? ($destination ?: '/') : null,
            ];
        }

        $html = Craft::$app->getView()->renderTemplate(
            'not-found-redirects/redirects/_test-results',
            ['results' => $results],
        );

        return $this->asSuccess(data: ['html' => $html]);
    }

    public function actionElementUrl(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:manageRedirects');

        $elementId = (int)$this->request->getRequiredParam('elementId');
        $element = Craft::$app->getElements()->getElementById($elementId);

        return $this->asSuccess(data: [
            'url' => $element?->getUrl() ?? '',
            'uri' => $element?->uri ? '/' . $element->uri : '',
        ]);
    }

    public function actionRenderEntrySidebar(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:viewRedirects');

        $entryId = (int)$this->request->getRequiredParam('entryId');
        $view = Craft::$app->getView();

        $html = NotFoundRedirects::getInstance()->getRedirectService()->getEntrySidebarHtml($entryId);
        $headHtml = $view->getHeadHtml();
        $bodyHtml = $view->getBodyHtml();

        return $this->asSuccess(data: [
            'html' => $html,
            'headHtml' => $headHtml,
            'bodyHtml' => $bodyHtml,
        ]);
    }

    // ── Export/Import ──────────────────────────────────────────────────

    public function actionImport(?ImportForm $importForm = null): Response
    {
        $this->requirePermission('not-found-redirects:importRedirects');

        if (!$importForm) {
            $importForm = new ImportForm();
        }

        return $this->asCpScreen()
            ->title(Craft::t('not-found-redirects', 'Import Redirects'))
            ->selectedSubnavItem('redirects')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb(Craft::t('not-found-redirects', 'Redirects'), 'not-found-redirects/redirects')
            ->addCrumb(Craft::t('app', 'Import'), 'not-found-redirects/redirects/import')
            ->action('not-found-redirects/redirects/do-import')
            ->redirectUrl('not-found-redirects/redirects')
            ->submitButtonLabel(Craft::t('app', 'Import'))
            ->formAttributes(['enctype' => 'multipart/form-data'])
            ->contentTemplate('not-found-redirects/redirects/import', [
                'importForm' => $importForm,
            ]);
    }

    public function actionDoImport(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:importRedirects');

        $importForm = new ImportForm();
        $importForm->file = UploadedFile::getInstanceByName('file');
        $importForm->inputSource = $this->request->getBodyParam('inputSource') ?: null;

        if (!$importForm->validate()) {
            return $this->asModelFailure($importForm, Craft::t('not-found-redirects', 'Import failed.'), 'importForm');
        }

        $content = file_get_contents($importForm->file->tempName);
        $importForm->result = (new ImportRedirects())->handle($content, source: $importForm->inputSource);

        $this->view->registerAssetBundle(ImportFormAsset::class);

        return $this->asCpScreen()
            ->title(Craft::t('not-found-redirects', 'Import Results'))
            ->selectedSubnavItem('redirects')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb(Craft::t('not-found-redirects', 'Redirects'), 'not-found-redirects/redirects')
            ->addCrumb(Craft::t('app', 'Import'), 'not-found-redirects/redirects/import')
            ->contentTemplate('not-found-redirects/import-results', [
                'importForm' => $importForm,
            ]);
    }

    public function actionExport(): Response
    {
        $this->requirePermission('not-found-redirects:exportRedirects');

        if ($this->request->getIsPost()) {
            $format = $this->request->getBodyParam('format', 'csv');
            $result = (new ExportRedirects())->handle($format);
            Craft::$app->getResponse()->sendContentAsFile(
                $result->content,
                $result->filename,
                ['mimeType' => FileHelper::getMimeTypeByExtension($result->filename)],
            );
            Craft::$app->end();
        }

        return $this->asCpScreen()
            ->title(Craft::t('not-found-redirects', 'Export Redirects'))
            ->selectedSubnavItem('redirects')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb(Craft::t('not-found-redirects', 'Redirects'), 'not-found-redirects/redirects')
            ->addCrumb(Craft::t('app', 'Export'), 'not-found-redirects/redirects/export')
            ->action('not-found-redirects/redirects/export')
            ->redirectUrl('not-found-redirects/redirects')
            ->submitButtonLabel(Craft::t('app', 'Export'))
            ->contentTemplate('not-found-redirects/redirects/export');
    }

}
