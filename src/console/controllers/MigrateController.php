<?php

namespace newism\notfoundredirects\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\NotFoundRedirects;
use yii\console\ExitCode;

/**
 * Migrate data from Retour plugin.
 *
 * Usage:
 *   craft not-found-redirects/migrate/retour                           Migrate both
 *   craft not-found-redirects/migrate/retour --dry-run                 Dry run
 *   craft not-found-redirects/migrate/retour --output-format=json      JSON output
 *   craft not-found-redirects/migrate/retour-404s                      Migrate 404s only
 *   craft not-found-redirects/migrate/retour-redirects                 Migrate redirects only
 */
class MigrateController extends Controller
{
    use OutputResultTrait;

    public bool $dryRun = false;
    public bool $skipExisting = true;
    public string $outputFormat = 'table';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'dryRun',
            'skipExisting',
            'outputFormat',
        ]);
    }

    /**
     * Migrate both 404 stats and redirect rules from Retour.
     */
    public function actionRetour(): int
    {
        if (!$this->tableExists('retour_stats') && !$this->tableExists('retour_static_redirects')) {
            $this->stderr("Retour tables not found. Is the Retour plugin installed?\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Migrating data from Retour...\n\n", Console::FG_CYAN);

        $result1 = $this->actionRetour404s();
        $this->stdout("\n");
        $result2 = $this->actionRetourRedirects();

        return ($result1 === ExitCode::OK && $result2 === ExitCode::OK) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Migrate 404 stats from Retour's retour_stats table.
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

        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $imported = 0;
        $skipped = 0;
        $items = [];

        foreach ($rows as $row) {
            $uri = Uri::strip($row['redirectSrcUrl'] ?? '');
            $siteId = (int) ($row['siteId'] ?? Craft::$app->getSites()->getPrimarySite()->id);

            if (!$uri) {
                $skipped++;
                continue;
            }

            if ($this->skipExisting) {
                $exists = (new Query())
                    ->from('{{%notfoundredirects_404s}}')
                    ->where(['uri' => $uri, 'siteId' => $siteId])
                    ->exists();

                if ($exists) {
                    $skipped++;
                    $items[] = ['uri' => $uri, 'hits' => (int) ($row['hitCount'] ?? 1), 'result' => 'skipped'];
                    continue;
                }
            }

            if (!$this->dryRun) {
                $site = Craft::$app->getSites()->getSiteById($siteId);
                $baseUrl = $site ? rtrim($site->getBaseUrl(), '/') : '';
                $fullUrl = $baseUrl . '/' . $uri;
                $hitLastTime = $row['hitLastTime'] ?? $now;
                $dateCreated = $row['dateCreated'] ?? $now;
                $handled = !empty($row['handledByRetour']);

                $db->createCommand()->upsert(
                    '{{%notfoundredirects_404s}}',
                    [
                        'uri' => $uri,
                        'siteId' => $siteId,
                        'fullUrl' => $fullUrl,
                        'hitCount' => (int) ($row['hitCount'] ?? 1),
                        'hitLastTime' => $hitLastTime,
                        'handled' => $handled,
                        'dateCreated' => $dateCreated,
                        'dateUpdated' => $now,
                    ],
                    [
                        'hitCount' => new \yii\db\Expression(
                            '{{%notfoundredirects_404s}}.[[hitCount]] + :hits',
                            [':hits' => (int) ($row['hitCount'] ?? 1)]
                        ),
                        'hitLastTime' => $hitLastTime,
                        'dateUpdated' => $now,
                    ],
                )->execute();

                $referrer = $row['referrerUrl'] ?? '';
                if ($referrer) {
                    $notFoundId = (new Query())
                        ->select(['id'])
                        ->from('{{%notfoundredirects_404s}}')
                        ->where(['uri' => $uri, 'siteId' => $siteId])
                        ->scalar();

                    if ($notFoundId) {
                        $db->createCommand()->upsert(
                            '{{%notfoundredirects_referrers}}',
                            [
                                'notFoundId' => $notFoundId,
                                'referrer' => $referrer,
                                'hitCount' => (int) ($row['hitCount'] ?? 1),
                                'hitLastTime' => $hitLastTime,
                                'dateCreated' => $dateCreated,
                                'dateUpdated' => $now,
                            ],
                            [
                                'hitCount' => new \yii\db\Expression(
                                    '{{%notfoundredirects_referrers}}.[[hitCount]] + :hits',
                                    [':hits' => (int) ($row['hitCount'] ?? 1)]
                                ),
                                'hitLastTime' => $hitLastTime,
                                'dateUpdated' => $now,
                            ],
                        )->execute();
                    }
                }
            }

            $imported++;
            $items[] = ['uri' => $uri, 'hits' => (int) ($row['hitCount'] ?? 1), 'result' => $this->dryRun ? 'would import' : 'imported'];
        }

        $labels = $this->dryRun
            ? ['imported' => 'Would import 404(s)', 'skipped' => 'Would skip']
            : ['imported' => 'Imported 404(s)', 'skipped' => 'Skipped'];

        return $this->outputResult(
            ['dryRun' => $this->dryRun, 'summary' => ['imported' => $imported, 'skipped' => $skipped], 'items' => $items],
            ['URI', 'Hits', 'Result'],
            fn($item) => [$item['uri'], $item['hits'], $item['result']],
            $labels,
        );
    }

    /**
     * Migrate static redirect rules from Retour.
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

        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $imported = 0;
        $skipped = 0;
        $items = [];

        foreach ($rows as $row) {
            $from = Uri::strip($row['redirectSrcUrl'] ?? '');
            $to = $row['redirectDestUrl'] ?? '';

            if (!$from) {
                $skipped++;
                continue;
            }

            if ($this->skipExisting) {
                $exists = (new Query())
                    ->from('{{%notfoundredirects_redirects}}')
                    ->where(['from' => $from])
                    ->exists();

                if ($exists) {
                    $skipped++;
                    $items[] = ['from' => $from, 'to' => $to, 'statusCode' => (int) ($row['redirectHttpCode'] ?? 302), 'result' => 'skipped'];
                    continue;
                }
            }

            $statusCode = (int) ($row['redirectHttpCode'] ?? 302);
            if (!in_array($statusCode, [301, 302, 307, 410])) {
                $statusCode = 302;
            }

            if (!$this->dryRun) {
                $db->createCommand()->insert('{{%notfoundredirects_redirects}}', [
                    'siteId' => $row['siteId'] ?: null,
                    'from' => $from,
                    'to' => Uri::strip($to),
                    'statusCode' => $statusCode,
                    'priority' => (int) ($row['priority'] ?? 0),
                    'enabled' => !empty($row['enabled']),
                    'hitCount' => (int) ($row['hitCount'] ?? 0),
                    'hitLastTime' => $row['hitLastTime'] ?? null,
                    'dateCreated' => $row['dateCreated'] ?? $now,
                    'dateUpdated' => $now,
                ])->execute();
            }

            $imported++;
            $items[] = ['from' => $from, 'to' => $to, 'statusCode' => $statusCode, 'result' => $this->dryRun ? 'would import' : 'imported'];
        }

        $labels = $this->dryRun
            ? ['imported' => 'Would import redirect(s)', 'skipped' => 'Would skip']
            : ['imported' => 'Imported redirect(s)', 'skipped' => 'Skipped'];

        return $this->outputResult(
            ['dryRun' => $this->dryRun, 'summary' => ['imported' => $imported, 'skipped' => $skipped], 'items' => $items],
            ['From', 'To', 'Status', 'Result'],
            fn($item) => [$item['from'], $item['to'], $item['statusCode'], $item['result']],
            $labels,
        );
    }

    // ── Private ───────────────────────────────────────────────────────

    private function tableExists(string $table): bool
    {
        return Craft::$app->getDb()->tableExists("{{%{$table}}}");
    }

}
