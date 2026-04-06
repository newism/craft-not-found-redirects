<?php

namespace newism\notfoundredirects\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use newism\notfoundredirects\actions\ImportNotFoundUris;
use newism\notfoundredirects\actions\ImportRedirects;
use newism\notfoundredirects\models\NotFoundUri;
use newism\notfoundredirects\models\Redirect;
use yii\console\ExitCode;

/**
 * Migrate data from Retour plugin.
 *
 * Reads from Retour database tables and imports via action classes.
 */
class MigrateController extends Controller
{

    /**
     * Migrate both 404 stats and redirect rules from Retour.
     *
     * ```
     * craft not-found-redirects/migrate/retour
     * ```
     */
    public function actionRetour(): int
    {
        $this->stdout("Migrating data from Retour...\n\n", Console::FG_CYAN);

        $result1 = $this->runAction('retour-404s');
        $this->stdout("\n");
        $result2 = $this->runAction('retour-redirects');

        return ($result1 === ExitCode::OK && $result2 === ExitCode::OK) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Migrate 404 stats from Retour's retour_stats table.
     *
     * ```
     * craft not-found-redirects/migrate/retour-404s
     * ```
     */
    public function actionRetour404s(): int
    {
        if (!$this->tableExists('retour_stats')) {
            $this->stderr("Table retour_stats not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $rows = (new Query())
            ->from('{{%retour_stats}}')
            ->all();

        $models = array_map(function ($row) {
            return NotFoundUri::fromRetourDbRow($row);
        }, $rows);

        (new ImportNotFoundUris())->handle($models, source: 'retour-migration');

        return ExitCode::OK;
    }

    /**
     * Migrate static redirect rules from Retour.
     *
     * ```
     * craft not-found-redirects/migrate/retour-redirects
     * ```
     */
    public function actionRetourRedirects(): int
    {
        if (!$this->tableExists('retour_static_redirects')) {
            $this->stderr("Table retour_static_redirects not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $rows = (new Query())
            ->from('{{%retour_static_redirects}}')
            ->all();

        $models = array_map(function ($row) {
            return Redirect::fromRetourDbRow($row);
        }, $rows);

        (new ImportRedirects())->handle($models, source: 'retour-migration');

        return ExitCode::OK;
    }

    // ── Private ───────────────────────────────────────────────────────

    private function tableExists(string $table): bool
    {
        return Craft::$app->getDb()->tableExists("{{%{$table}}}");
    }
}
