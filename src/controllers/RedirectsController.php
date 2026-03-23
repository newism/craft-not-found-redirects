<?php

namespace newism\notfoundredirects\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\NotFoundRedirects;
use newism\notfoundredirects\web\assets\RedirectFormAsset;
use yii\web\Response;

class RedirectsController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function beforeAction($action): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !($user->can('not-found-redirects:manageRedirects') || $user->can('not-found-redirects:deleteRedirects'))) {
            throw new \yii\web\ForbiddenHttpException('User not authorized to manage redirects.');
        }

        return parent::beforeAction($action);
    }

    // ── CP Screen Actions ──────────────────────────────────────────────

    public function actionIndex(): Response
    {
        $systemGeneratedParam = $this->request->getQueryParam('systemGenerated');

        if ($systemGeneratedParam === '1') {
            $selectedItem = 'auto';
        } elseif ($systemGeneratedParam === '0') {
            $selectedItem = 'manual';
        } else {
            $selectedItem = 'allRedirects';
        }

        $user = Craft::$app->getUser()->getIdentity();

        $actionMenuItems = [
            [
                'label' => Craft::t('app', 'Export Redirects'),
                'id' => 'export-redirects-btn',
            ],
        ];

        if ($user->can('not-found-redirects:manageRedirects')) {
            $actionMenuItems[] = [
                'label' => Craft::t('app', 'Import Redirects'),
                'url' => UrlHelper::cpUrl('not-found-redirects/redirects/import'),
            ];
        }

        if ($user->can('not-found-redirects:deleteRedirects')) {
            $actionMenuItems[] = ['hr' => true];
            $actionMenuItems[] = [
                'label' => Craft::t('app', 'Delete all redirects'),
                'action' => 'not-found-redirects/redirects/delete-all',
                'confirm' => 'Are you sure you want to delete all redirect rules? This cannot be undone.',
                'destructive' => true,
            ];
        }

        $response = $this->asCpScreen()
            ->title('Redirects')
            ->selectedSubnavItem('redirects')
            ->pageSidebarTemplate('not-found-redirects/_redirects-sidebar', [
                'selectedItem' => $selectedItem,
            ])
            ->actionMenuItems(fn() => $actionMenuItems)
            ->contentTemplate('not-found-redirects/_rules', [
                'systemGenerated' => $systemGeneratedParam,
                'canDeleteRedirects' => $user->can('not-found-redirects:deleteRedirects'),
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

    public function actionImport(): Response
    {
        $this->requirePermission('not-found-redirects:manageRedirects');

        return $this->asCpScreen()
            ->title('Import Redirects')
            ->selectedSubnavItem('redirects')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb('Redirects', 'not-found-redirects/redirects')
            ->addCrumb('Import', 'not-found-redirects/redirects/import')
            ->action('not-found-redirects/redirects/do-import')
            ->redirectUrl('not-found-redirects/redirects')
            ->submitButtonLabel('Import')
            ->formAttributes(['enctype' => 'multipart/form-data'])
            ->contentTemplate('not-found-redirects/_import');
    }

    /**
     * Create or edit a redirect rule.
     *
     * Validation failure flow:
     * 1. Form POSTs to action 'not-found-redirects/redirects/save'
     * 2. actionSave() validates and calls asModelFailure($model, 'message', 'redirect')
     * 3. asModelFailure() sets route param 'redirect' and returns null
     * 4. Craft re-renders this action with the failed model injected as $redirect
     */
    public function actionEdit(?int $redirectId = null, ?Redirect $redirect = null): Response
    {
        if (!$redirect) {
            $plugin = NotFoundRedirects::getInstance();
            if ($redirectId) {
                $redirect = $plugin->redirects->findById($redirectId);
                if (!$redirect) {
                    throw new \yii\web\NotFoundHttpException('Redirect not found.');
                }
            } else {
                $redirect = new Redirect();
                $redirect->from = $this->request->getQueryParam('from');
            }
        }

        $title = $redirect->id ? 'Edit Redirect' : 'New Redirect';

        Craft::$app->getView()->registerAssetBundle(RedirectFormAsset::class);

        return $this->asCpScreen()
            ->title($title)
            ->selectedSubnavItem('redirects')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb('Redirects', 'not-found-redirects/redirects')
            ->addCrumb($title, $redirectId ? "not-found-redirects/redirects/edit/{$redirectId}" : 'not-found-redirects/redirects/new')
            ->action('not-found-redirects/redirects/save')
            ->redirectUrl('not-found-redirects/redirects')
            ->saveShortcutRedirectUrl('not-found-redirects/redirects/edit/{id}')
            ->addAltAction(Craft::t('app', 'Save and continue editing'), [
                'redirect' => 'not-found-redirects/redirects/edit/{id}',
                'shortcut' => true,
                'retainScroll' => true,
            ])
            ->contentTemplate('not-found-redirects/_redirect-form', [
                'redirect' => $redirect,
                'sites' => Craft::$app->getSites()->getAllSites(),
                'toElementSelectHtml' => Cp::elementSelectHtml([
                    'elementType' => Entry::class,
                    'id' => 'toElementId',
                    'name' => 'toElementId',
                    'elements' => $redirect->toElement ? [$redirect->toElement] : [],
                    'single' => true,
                    'sources' => '*',
                ]),
                'notes' => $redirect->id
                    ? NotFoundRedirects::getInstance()->notes->findByRedirectId($redirect->id)
                    : collect(),
            ])
            ->metaSidebarTemplate('not-found-redirects/_redirect-meta', [
                'redirect' => $redirect,
            ]);
    }

    public function actionPatternReference(): Response
    {
        return $this->asCpScreen()
            ->title('Pattern Matching')
            ->contentTemplate('not-found-redirects/_pattern-reference');
    }

    // ── Table Data Endpoints (JSON) ────────────────────────────────────

    public function actionTableData(): Response
    {
        $this->requireAcceptsJson();

        $systemGeneratedParam = $this->request->getParam('systemGenerated');
        $systemGenerated = match ($systemGeneratedParam) {
            '1' => true,
            '0' => false,
            default => null,
        };

        return $this->asSuccess(data: NotFoundRedirects::getInstance()->redirects->getTableData(
            page: (int) $this->request->getParam('page', 1),
            limit: (int) $this->request->getParam('per_page', 50),
            search: $this->request->getParam('search'),
            systemGenerated: $systemGenerated,
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
        $redirect->id = (int) $this->request->getBodyParam('redirectId') ?: null;
        $redirect->siteId = (int) $this->request->getBodyParam('siteId') ?: null;
        $redirect->from = $this->request->getBodyParam('from');
        $redirect->to = $this->request->getBodyParam('to', '');
        $redirect->toType = $this->request->getBodyParam('toType', 'url');
        $redirect->statusCode = (int) $this->request->getBodyParam('statusCode', 302);
        $redirect->priority = (int) $this->request->getBodyParam('priority', 0);
        $redirect->enabled = (bool) $this->request->getBodyParam('enabled', true);
        $redirect->startDate = DateTimeHelper::toDateTime($this->request->getBodyParam('startDate')) ?: null;
        $redirect->endDate = DateTimeHelper::toDateTime($this->request->getBodyParam('endDate')) ?: null;

        // Element select posts as array for single select
        $toElementId = $this->request->getBodyParam('toElementId');
        if (is_array($toElementId)) {
            $toElementId = reset($toElementId) ?: null;
        }
        $redirect->toElementId = $toElementId ? (int) $toElementId : null;

        // Clear irrelevant fields based on type
        if ($redirect->toType !== 'entry') {
            $redirect->toElementId = null;
        }

        if (!NotFoundRedirects::getInstance()->redirects->save($redirect)) {
            return $this->asModelFailure(
                $redirect,
                'Could not save redirect.',
                'redirect',
            );
        }

        // Create initial note if provided (new redirects or existing)
        $newNote = trim($this->request->getBodyParam('newNote', ''));
        if ($newNote && $redirect->id) {
            NotFoundRedirects::getInstance()->notes->addNote($redirect->id, $newNote);
        }

        return $this->asModelSuccess(
            $redirect,
            'Redirect saved.',
            'redirect'
        );
    }

    public function actionElementUrl(): Response
    {
        $this->requireAcceptsJson();

        $elementId = (int) $this->request->getRequiredParam('elementId');
        $element = Craft::$app->getElements()->getElementById($elementId);

        return $this->asSuccess(data: [
            'url' => $element?->getUrl() ?? '',
            'uri' => $element?->uri ? '/' . $element->uri : '',
        ]);
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('not-found-redirects:deleteRedirects');

        $id = (int) $this->request->getRequiredBodyParam('id');
        NotFoundRedirects::getInstance()->redirects->deleteById($id);

        return $this->asSuccess();
    }

    public function actionDeleteAll(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:deleteRedirects');

        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // Unmark all 404s before deleting redirects (FK will SET NULL redirectId,
        // but handled flag would remain true without this)
        $db->createCommand()->update(
            '{{%notfoundredirects_404s}}',
            ['handled' => false, 'redirectId' => null, 'dateUpdated' => $now],
            ['not', ['redirectId' => null]],
        )->execute();

        $count = $db->createCommand()
            ->delete('{{%notfoundredirects_redirects}}')
            ->execute();

        return $this->asSuccess("{$count} redirect(s) deleted.");
    }

    // ── Export/Import ──────────────────────────────────────────────────

    public function actionExport(): void
    {
        $this->requirePostRequest();

        NotFoundRedirects::getInstance()->csv->exportRedirects();
        Craft::$app->end();
    }

    public function actionDoImport(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('not-found-redirects:manageRedirects');

        $file = \yii\web\UploadedFile::getInstanceByName('file');
        if (!$file) {
            return $this->asFailure('No file uploaded.');
        }

        $imported = NotFoundRedirects::getInstance()->csv->importRedirects($file->tempName);

        return $this->asSuccess("{$imported} redirect(s) imported.");
    }
}
