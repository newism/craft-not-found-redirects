<?php

namespace newism\notfoundredirects;

use Craft;
use craft\base\Event;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\ElementEvent;
use craft\events\ExceptionEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\log\MonologTarget;
use craft\services\Elements;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\ErrorHandler;
use craft\web\UrlManager;
use Monolog\Formatter\LineFormatter;
use newism\notfoundredirects\gql\interfaces\NotFoundInterface;
use newism\notfoundredirects\gql\interfaces\RedirectInterface;
use newism\notfoundredirects\gql\queries\RedirectsQuery;
use newism\notfoundredirects\models\Settings;
use craft\base\Element;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use newism\notfoundredirects\jobs\UpdateDestinationUris;
use newism\notfoundredirects\services\CsvService;
use newism\notfoundredirects\services\NoteService;
use newism\notfoundredirects\services\NotFoundService;
use newism\notfoundredirects\services\RedirectService;
use Psr\Log\LogLevel;

/**
 * 404 Redirects plugin
 *
 * @method static NotFoundRedirects getInstance()
 * @method Settings getSettings()
 * @property-read CsvService $csv
 * @property-read NoteService $notes
 * @property-read NotFoundService $notFound
 * @property-read RedirectService $redirects
 */
class NotFoundRedirects extends Plugin
{
    public const LOG = 'not-found-redirects';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'csv' => CsvService::class,
                'notes' => NoteService::class,
                'notFound' => NotFoundService::class,
                'redirects' => RedirectService::class,
            ],
        ];
    }

    public function getCpNavItem(): ?array
    {
        $navItem = parent::getCpNavItem();
        $navItem['label'] = '404 Redirects';
        $navItem['icon'] = 'diamond-turn-right';

        $user = Craft::$app->getUser()->getIdentity();
        $subnav = [];

        if ($user && $user->can('not-found-redirects:view404s')) {
            $subnav['404s'] = [
                'label' => '404s',
                'url' => 'not-found-redirects/404s',
            ];
        }

        if ($user && ($user->can('not-found-redirects:manageRedirects') || $user->can('not-found-redirects:deleteRedirects'))) {
            $subnav['redirects'] = [
                'label' => 'Redirects',
                'url' => 'not-found-redirects/redirects',
            ];
        }

        if ($user && $user->can('not-found-redirects:viewLogs')) {
            $subnav['logs'] = [
                'label' => 'Logs',
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
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
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
            function(RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'not-found-redirects' => 'not-found-redirects/not-found-uris/index',
                    'not-found-redirects/404s' => 'not-found-redirects/not-found-uris/index',
                    'not-found-redirects/404s/detail/<notFoundId:\d+>' => 'not-found-redirects/not-found-uris/detail',
                    'not-found-redirects/redirects' => 'not-found-redirects/redirects/index',
                    'not-found-redirects/redirects/import' => 'not-found-redirects/redirects/import',
                    'not-found-redirects/redirects/new' => 'not-found-redirects/redirects/edit',
                    'not-found-redirects/redirects/edit/<redirectId:\d+>' => 'not-found-redirects/redirects/edit',
                    'not-found-redirects/logs' => 'not-found-redirects/logs/index',
                    'not-found-redirects/notes/new/<redirectId:\d+>' => 'not-found-redirects/notes/edit',
                    'not-found-redirects/notes/edit/<noteId:\d+>' => 'not-found-redirects/notes/edit',
                ]);
            }
        );

        // 404 handler — site requests only
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            Event::on(
                ErrorHandler::class,
                ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION,
                function(ExceptionEvent $event) {
                    $this->notFound->handleException($event);
                }
            );
        }

        // GraphQL types
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            static function(RegisterGqlTypesEvent $event) {
                $event->types[] = NotFoundInterface::class;
                $event->types[] = RedirectInterface::class;
            }
        );

        // GraphQL queries
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            static function(RegisterGqlQueriesEvent $event) {
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
            static function(RegisterGqlSchemaComponentsEvent $event) {
                $event->queries = array_merge($event->queries, [
                    Craft::t('app', '404 Redirects') => [
                        'not-found-redirects.all:read' => ['label' => Craft::t('app', 'Query for 404 Redirects data')],
                    ],
                ]);
            }
        );

        // Auto-create redirects when element URIs change (normal saves)
        // Auto-create redirects when element URIs change
        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, function(ElementEvent $event) {
            $this->redirects->stashOldUri($event->element);
        });
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $this->redirects->createRedirectIfUriChanged($event->element);
            $this->pushUpdateDestinationUrisJob($event->element);
        });

        // Structure moves, parent renames — fire outside normal save lifecycle
        Event::on(Elements::class, Elements::EVENT_BEFORE_UPDATE_SLUG_AND_URI, function(ElementEvent $event) {
            $this->redirects->stashOldUri($event->element);
        });
        Event::on(Elements::class, Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI, function(ElementEvent $event) {
            $this->redirects->createRedirectIfUriChanged($event->element);
            $this->pushUpdateDestinationUrisJob($event->element);
        });

        // Handle element deletion — add notes before FK SET NULL clears toElementId
        Event::on(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, function(ElementEvent $event) {
            $element = $event->element;
            if (!$element->id) {
                return;
            }

            $redirects = $this->redirects->findByToElementId($element->id);
            foreach ($redirects as $redirect) {
                $title = method_exists($element, 'getUiLabel') ? $element->getUiLabel() : "#{$element->id}";
                $this->notes->addNote(
                    $redirect->id,
                    "Destination element {$title} was deleted. Redirect using cached URI: /{$redirect->to}",
                    systemGenerated: true,
                );
            }
        });

        // Entry sidebar — show incoming redirects
        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                if (!$entry->id) {
                    return;
                }

                $redirects = $this->redirects->findByToElementId($entry->id);
                if ($redirects->isEmpty()) {
                    return;
                }

                $event->html .= Craft::$app->getView()->renderTemplate(
                    'not-found-redirects/_entry-sidebar',
                    ['redirects' => $redirects],
                );
            }
        );

        // Register user permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('app', '404 Redirects'),
                    'permissions' => [
                        'not-found-redirects:view404s' => [
                            'label' => Craft::t('app', 'View 404s'),
                        ],
                        'not-found-redirects:delete404s' => [
                            'label' => Craft::t('app', 'Delete 404s'),
                        ],
                        'not-found-redirects:manageRedirects' => [
                            'label' => Craft::t('app', 'Create and edit redirects'),
                        ],
                        'not-found-redirects:deleteRedirects' => [
                            'label' => Craft::t('app', 'Delete redirects'),
                        ],
                        'not-found-redirects:viewLogs' => [
                            'label' => Craft::t('app', 'View logs'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Push a queue job to update cached destination URIs for entry-type redirects.
     */
    private function pushUpdateDestinationUrisJob(\craft\base\ElementInterface $element): void
    {
        if (!$element->id || !$element->uri) {
            return;
        }

        // Only push if there are redirects pointing to this element
        if ($this->redirects->findByToElementId($element->id)->isEmpty()) {
            return;
        }

        Craft::$app->getQueue()->push(new UpdateDestinationUris([
            'elementId' => $element->id,
            'siteId' => $element->siteId,
            'newUri' => $element->uri,
        ]));
    }
}
