<?php

namespace newism\notfoundredirects\controllers;

use Craft;
use craft\web\Controller;
use newism\notfoundredirects\NotFoundRedirects;
use yii\web\Response;

class LogsController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function beforeAction($action): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('not-found-redirects:viewLogs')) {
            throw new \yii\web\ForbiddenHttpException('User not authorized to view logs.');
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $logsPath = Craft::$app->getPath()->getLogPath();
        $prefix = NotFoundRedirects::LOG;

        // Find all log files matching our prefix
        $files = glob($logsPath . DIRECTORY_SEPARATOR . $prefix . '-*.log');
        rsort($files); // newest first

        $logFiles = array_map(fn(string $path) => basename($path), $files);

        // Selected file (default to newest)
        $selected = $this->request->getQueryParam('file');
        $content = '';

        if ($selected !== null) {
            if (!str_starts_with($selected, $prefix . '-') || !in_array($selected, $logFiles, true)) {
                throw new \yii\web\NotFoundHttpException('Log file not found.');
            }
            $content = file_get_contents($logsPath . DIRECTORY_SEPARATOR . $selected);
        } elseif (!empty($logFiles)) {
            $selected = $logFiles[0];
            $content = file_get_contents($logsPath . DIRECTORY_SEPARATOR . $selected);
        }

        return $this->asCpScreen()
            ->title('Logs')
            ->selectedSubnavItem('logs')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb('Logs', 'not-found-redirects/logs')
            ->contentTemplate('not-found-redirects/_logs', [
                'logFiles' => $logFiles,
                'selected' => $selected,
                'content' => $content,
            ]);
    }
}
