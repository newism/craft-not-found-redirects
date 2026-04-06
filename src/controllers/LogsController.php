<?php

namespace newism\notfoundredirects\controllers;

use Craft;
use craft\web\Controller;
use newism\notfoundredirects\NotFoundRedirects;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LogsController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function actionIndex(): Response
    {
        $this->requirePermission('not-found-redirects:viewLogs');

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
                throw new NotFoundHttpException('Log file not found.');
            }
            $content = file_get_contents($logsPath . DIRECTORY_SEPARATOR . $selected);
        } elseif ($logFiles) {
            $selected = $logFiles[0];
            $content = file_get_contents($logsPath . DIRECTORY_SEPARATOR . $selected);
        }

        return $this->asCpScreen()
            ->title(Craft::t('not-found-redirects', 'Logs'))
            ->selectedSubnavItem('logs')
            ->addCrumb(NotFoundRedirects::getInstance()->name, 'not-found-redirects')
            ->addCrumb(Craft::t('not-found-redirects', 'Logs'), 'not-found-redirects/logs')
            ->contentTemplate('not-found-redirects/logs/index', [
                'logFiles' => $logFiles,
                'selected' => $selected,
                'content' => $content,
            ]);
    }
}
