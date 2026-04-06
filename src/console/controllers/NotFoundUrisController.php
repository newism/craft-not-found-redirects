<?php

namespace newism\notfoundredirects\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use newism\notfoundredirects\actions\ExportNotFoundUris;
use newism\notfoundredirects\actions\ImportNotFoundUris;
use newism\notfoundredirects\NotFoundRedirects;
use yii\console\ExitCode;

/**
 * CLI commands for 404 records.
 */
class NotFoundUrisController extends Controller
{
    use ReadInputTrait;

    /** @var string|null File format override: "csv" or "json". Auto-detected from file extension or content if omitted. */
    public ?string $format = null;

    /** @var string|null Data source: "native" or "retour". Auto-detected from column headers if omitted. */
    public ?string $source = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if (in_array($actionID, ['import', 'export'])) {
            $options[] = 'format';
        }

        if ($actionID === 'import') {
            $options[] = 'source';
        }

        return $options;
    }

    /**
     * Import 404 records from a CSV or JSON file, or stdin.
     *
     * ```
     * craft not-found-redirects/not-found-uris/import 404s.csv
     * craft not-found-redirects/not-found-uris/import 404s.json
     * craft not-found-redirects/not-found-uris/import - --format=csv --source=retour
     * ```
     *
     * @param string $path File path, or '-' to read from stdin.
     */
    public function actionImport(string $path): int
    {
        $input = $this->readInput($path);
        $result = (new ImportNotFoundUris())->handle($input, $this->format, $this->source);

        $this->stdout("\n");
        $this->stdout("Imported: " . count($result->imported) . "\n", Console::FG_GREEN);
        $this->stdout("Skipped: " . count($result->skipped) . "\n");
        if ($result->errors) {
            $this->stdout("Errors: " . count($result->errors) . "\n", Console::FG_RED);
            $this->stdout("\n");
            $this->table(
                ['Index', 'Error'],
                array_map(
                    fn($i, $model) => [$i, implode(', ', $model->getErrorSummary(true))],
                    array_keys($result->errors),
                    $result->errors,
                ),
            );
        }
        $this->stdout("\n");

        return $result->errors ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Export all 404 records.
     *
     * ```
     * craft not-found-redirects/not-found-uris/export                  # json to stdout
     * craft not-found-redirects/not-found-uris/export 404s.csv         # csv to file
     * craft not-found-redirects/not-found-uris/export - --format=csv   # csv to stdout
     * ```
     *
     * @param string $path File path, or '-' for stdout.
     */
    public function actionExport(string $path = '-'): int
    {
        $format = $this->format
            ?? ($path !== '-' ? pathinfo($path, PATHINFO_EXTENSION) : null)
            ?? 'json';

        $result = (new ExportNotFoundUris())->handle($format);

        if ($path !== '-') {
            $this->writeToFile($path, $result->content);
            $this->stdout("\nExported {$result->count} records\n");
        } else {
            $this->stdout($result->content);
            $this->stderr("Exported {$result->count} records\n");
        }

        return ExitCode::OK;
    }

    /**
     * Reprocess unhandled 404s against enabled redirect rules.
     *
     * ```
     * craft not-found-redirects/not-found-uris/reprocess
     * ```
     */
    public function actionReprocess(): int
    {
        $result = NotFoundRedirects::getInstance()->getNotFoundUriService()->reprocess();

        $this->stdout("Matched: {$result['matched']} 404(s)\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Purge stale 404 records older than the given threshold.
     *
     * ```
     * craft not-found-redirects/not-found-uris/purge "-90 days"
     * ```
     *
     * @param string $lastSeen Any strtotime()-compatible value (e.g. "-90 days").
     */
    public function actionPurge(string $lastSeen): int
    {
        $threshold = DateTimeHelper::toDateTime($lastSeen);
        if (!$threshold) {
            $this->stderr("Invalid last-seen value: {$lastSeen}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $result = NotFoundRedirects::getInstance()->getNotFoundUriService()->purge($threshold);

        $this->stdout("Purged: {$result['notFound']} 404(s)\n", Console::FG_GREEN);
        $this->stdout("Referrers: {$result['referrers']}\n");
        $this->stdout("Redirects: {$result['redirects']}\n");

        return ExitCode::OK;
    }

    /**
     * Reset hit counts to zero for all 404s and referrers.
     *
     * ```
     * craft not-found-redirects/not-found-uris/reset-hit-counts
     * ```
     */
    public function actionResetHitCounts(): int
    {
        $result = NotFoundRedirects::getInstance()->getNotFoundUriService()->resetHitCounts();

        $this->stdout("Reset 404(s): {$result['notFound']}\n", Console::FG_GREEN);
        $this->stdout("Reset referrer(s): {$result['referrers']}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
