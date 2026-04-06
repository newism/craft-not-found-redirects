<?php

namespace newism\notfoundredirects;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Event;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\ExceptionEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\log\MonologTarget;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\ErrorHandler;
use craft\web\UrlManager;
use Monolog\Formatter\LineFormatter;
use newism\notfoundredirects\gql\interfaces\NotFoundInterface;
use newism\notfoundredirects\gql\interfaces\RedirectInterface;
use newism\notfoundredirects\gql\queries\RedirectsQuery;
use newism\notfoundredirects\jobs\UpdateDestinationUris;
use newism\notfoundredirects\models\Settings;
use newism\notfoundredirects\services\NoteService;
use newism\notfoundredirects\services\NotFoundUriService;
use newism\notfoundredirects\services\RedirectService;
use newism\notfoundredirects\widgets\NotFoundChartWidget;
use newism\notfoundredirects\widgets\NotFoundCoverageWidget;
use newism\notfoundredirects\widgets\NotFoundWidget;
use Psr\Log\LogLevel;

/**
 * 404 Redirects plugin
 *
 * @method static NotFoundRedirects getInstance()
 * @method Settings getSettings()
 */
class NotFoundRedirects extends Plugin
{
    public const LOG = 'not-found-redirects';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;

    public function getNoteService(): NoteService
    {
        return $this->get('noteService');
    }

    public function getNotFoundUriService(): NotFoundUriService
    {
        return $this->get('notFoundUriService');
    }

    public function getRedirectService(): RedirectService
    {
        return $this->get('redirectService');
    }

    public static function config(): array
    {
        return [
            'components' => [
                'noteService' => NoteService::class,
                'notFoundUriService' => NotFoundUriService::class,
                'redirectService' => RedirectService::class,
            ],
        ];
    }

    public function getCpNavItem(): ?array
    {
        $navItem = parent::getCpNavItem();
        $navItem['label'] = Craft::t('not-found-redirects', '404 Redirects');
        $navItem['icon'] = 'diamond-turn-right';

        $user = Craft::$app->getUser()->getIdentity();
        $subnav = [];

        if ($user && $user->can('not-found-redirects:view404s')) {
            $subnav['404s'] = [
                'label' => Craft::t('not-found-redirects', '404s'),
                'url' => 'not-found-redirects/404s',
            ];
        }

        if ($user && $user->can('not-found-redirects:viewRedirects')) {
            $subnav['redirects'] = [
                'label' => Craft::t('not-found-redirects', 'Redirects'),
                'url' => 'not-found-redirects/redirects',
            ];
        }

        if ($user && $user->can('not-found-redirects:viewLogs')) {
            $subnav['logs'] = [
                'label' => Craft::t('not-found-redirects', 'Logs'),
                'url' => 'not-found-redirects/logs',
            ];
        }

        if (empty($subnav)) {
            return null;
        }

        $navItem['subnav'] = $subnav;

        return $navItem;
    }

    public function init(): void
    {
        // Set console controller namespace
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'newism\\notfoundredirects\\console\\controllers';
        }

        parent::init();

        $this->registerLogTarget();

        // Defer event registration until Craft is fully initialized
        // so all plugins are loaded and migrations have run
        Craft::$app->onInit(function () {
            $this->attachEventHandlers();

            // Register JS translations for CP requests so Craft.t('not-found-redirects', ...) works
            if (Craft::$app->getRequest()->getIsCpRequest()) {
                $this->registerJsTranslations();
            }
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }


    private function registerJsTranslations(): void
    {
        Craft::$app->getView()->registerTranslations('not-found-redirects', [
            // VueAdminTable columns + messages (404s/index.twig)
            'URI',
            'Handled',
            'Hits',
            'First Seen',
            'Last Seen',
            'Referrers',
            'Redirect',
            'No 404s recorded yet.',
            'Search URIs…',
            'Are you sure you want to delete this 404 record?',
            // VueAdminTable columns + messages (redirects/index.twig)
            'From',
            'To',
            'Status Code',
            'Priority',
            'No redirect rules yet.',
            'Search redirects…',
            'Are you sure you want to delete this redirect?',
            // Referrers table (404s/detail.twig)
            'Referrer',
            'Last Hit',
            'Are you sure you want to delete this referrer?',
            // Redirect model action menu JS (Redirect.php)
            'Redirect deleted.',
            'Could not delete redirect.',
            // Entry sidebar (entry-sidebar.js)
            'Could not refresh redirects.',
            // Redirect form (redirect-form.js)
            'Delete this note?',
            'Note deleted.',
            'Could not delete note.',
            'Could not refresh notes.',
            // Chart widgets
            'No 404s recorded in this period.',
        ]);
    }

    private function registerLogTarget(): void
    {
        $formatter = new LineFormatter(
            format: "%datetime% [%level_name%] %message%\n",
            dateFormat: 'Y-m-d H:i:s',
        );

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG,
            'categories' => [self::LOG],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
            'maxFiles' => 14,
            'formatter' => $formatter,
        ]);
    }

    private function attachEventHandlers(): void
    {
        // CP URL rules
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'not-found-redirects' => 'not-found-redirects/dashboard/index',
                    'not-found-redirects/404s' => 'not-found-redirects/not-found-uris/index',
                    'not-found-redirects/404s/detail/<notFoundId:\d+>' => 'not-found-redirects/not-found-uris/detail',
                    'not-found-redirects/404s/import' => 'not-found-redirects/not-found-uris/import',
                    'not-found-redirects/404s/export' => 'not-found-redirects/not-found-uris/export',
                    'not-found-redirects/redirects' => 'not-found-redirects/redirects/index',
                    'not-found-redirects/redirects/new' => 'not-found-redirects/redirects/edit',
                    'not-found-redirects/redirects/edit/<redirectId:\d+>' => 'not-found-redirects/redirects/edit',
                    'not-found-redirects/redirects/import' => 'not-found-redirects/redirects/import',
                    'not-found-redirects/redirects/export' => 'not-found-redirects/redirects/export',
                    'not-found-redirects/logs' => 'not-found-redirects/logs/index',
                ]);
            }
        );

        // 404 handler — site requests only
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            Event::on(
                ErrorHandler::class,
                ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION,
                function (ExceptionEvent $event) {
                    $this->getNotFoundUriService()->handleException($event);
                }
            );
        }

        // Garbage collection
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function () {
                $this->getNotFoundUriService()->gc();
            }
        );

        // GraphQL types
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            static function (RegisterGqlTypesEvent $event) {
                $event->types[] = NotFoundInterface::class;
                $event->types[] = RedirectInterface::class;
            }
        );

        // GraphQL queries
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            static function (RegisterGqlQueriesEvent $event) {
                $event->queries = array_merge(
                    $event->queries,
                    RedirectsQuery::getQueries(),
                );
            }
        );

        // GraphQL schema components (permissions)
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            static function (RegisterGqlSchemaComponentsEvent $event) {
                $event->queries = array_merge($event->queries, [
                    Craft::t('not-found-redirects', '404 Redirects') => [
                        'not-found-redirects.all:read' => ['label' => Craft::t('not-found-redirects', 'Query for 404 Redirects data')],
                    ],
                ]);
            }
        );

        // Auto-create redirects when element URIs change
        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, function (ElementEvent $event) {
            $this->getRedirectService()->stashOldUri($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function (ElementEvent $event) {
            $this->getRedirectService()->createRedirectIfUriChanged($event->element);
            $this->pushUpdateDestinationUrisJob($event->element);
        });

        // Structure moves, parent renames — fire outside normal save lifecycle
        Event::on(Elements::class, Elements::EVENT_BEFORE_UPDATE_SLUG_AND_URI, function (ElementEvent $event) {
            $this->getRedirectService()->stashOldUri($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI, function (ElementEvent $event) {
            $this->getRedirectService()->createRedirectIfUriChanged($event->element);
            $this->pushUpdateDestinationUrisJob($event->element);
        });

        // Handle element deletion — add notes before FK SET NULL clears toElementId
        Event::on(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, function (ElementEvent $event) {
            $element = $event->element;
            if (!$element->id) {
                return;
            }

            $redirects = $this->getRedirectService()->findByToElementId($element->id);
            foreach ($redirects as $redirect) {
                $title = method_exists($element, 'getUiLabel') ? $element->getUiLabel() : "#{$element->id}";
                $this->getNoteService()->addNote(
                    $redirect->id,
                    "Destination element {$title} was deleted. Redirect using cached URI: /{$redirect->to}",
                    systemGenerated: true,
                );
            }
        });

        // Entry sidebar — show incoming redirects + quick redirect form
        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function (DefineHtmlEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                if (!$entry->id) {
                    return;
                }

                $event->html .= $this->getRedirectService()->getEntrySidebarHtml($entry->getCanonicalId());
            }
        );

        // Register dashboard widget
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function (RegisterComponentTypesEvent $event) {
                $event->types[] = NotFoundWidget::class;
                $event->types[] = NotFoundChartWidget::class;
                $event->types[] = NotFoundCoverageWidget::class;
            }
        );

        // Register user permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('not-found-redirects', '404 Redirects'),
                    'permissions' => [
                        'not-found-redirects:view404s' => [
                            'label' => Craft::t('not-found-redirects', 'View 404s'),
                            'nested' => [
                                'not-found-redirects:delete404s' => [
                                    'label' => Craft::t('not-found-redirects', 'Delete 404s'),
                                ],
                                'not-found-redirects:import404s' => [
                                    'label' => Craft::t('not-found-redirects', 'Import 404s'),
                                ],
                                'not-found-redirects:export404s' => [
                                    'label' => Craft::t('not-found-redirects', 'Export 404s'),
                                ],
                            ],
                        ],
                        'not-found-redirects:viewRedirects' => [
                            'label' => Craft::t('not-found-redirects', 'View redirects'),
                            'nested' => [
                                'not-found-redirects:manageRedirects' => [
                                    'label' => Craft::t('not-found-redirects', 'Save redirects'),
                                ],
                                'not-found-redirects:deleteRedirects' => [
                                    'label' => Craft::t('not-found-redirects', 'Delete redirects'),
                                ],
                                'not-found-redirects:importRedirects' => [
                                    'label' => Craft::t('not-found-redirects', 'Import redirects'),
                                ],
                                'not-found-redirects:exportRedirects' => [
                                    'label' => Craft::t('not-found-redirects', 'Export redirects'),
                                ],
                            ],
                        ],
                        'not-found-redirects:viewLogs' => [
                            'label' => Craft::t('not-found-redirects', 'View logs'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Push a queue job to update cached destination URIs for entry-type redirects.
     */
    private function pushUpdateDestinationUrisJob(ElementInterface $element): void
    {
        if (!$element->id || !$element->uri) {
            return;
        }

        // Only push if there are redirects pointing to this element
        $redirects = $this->getRedirectService()->findByToElementId($element->id);
        if (!$redirects) {
            return;
        }

        Craft::$app->getQueue()->push(new UpdateDestinationUris([
            'elementId' => $element->id,
            'siteId' => $element->siteId,
            'newUri' => $element->uri,
        ]));
    }
}
