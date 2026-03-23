<?php

namespace newism\notfoundredirects\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use newism\notfoundredirects\NotFoundRedirects;
use yii\console\ExitCode;

/**
 * CLI commands for 404 Redirects.
 *
 * Usage:
 *   craft not-found-redirects/redirects/export-404s                                        Table to stdout
 *   craft not-found-redirects/redirects/export-404s --output-format=json                   JSON to stdout
 *   craft not-found-redirects/redirects/export-404s --output-file=404s.csv                 CSV to file
 *   craft not-found-redirects/redirects/export-redirects --output-file=redirects.csv       CSV to file
 *   craft not-found-redirects/redirects/import-redirects --input-file=r.csv                Import from CSV
 *   craft not-found-redirects/redirects/import-redirects --input-file=r.csv --dry-run      Dry run import
 *   craft not-found-redirects/redirects/reprocess --dry-run --output-format=json           Dry run reprocess
 *   craft not-found-redirects/redirects/purge-404s --last-seen="-90 days" --dry-run        Dry run purge
 */
class RedirectsController extends Controller
{
    use OutputResultTrait;

    public bool $dryRun = false;
    public ?string $inputFile = null;
    public ?string $outputFile = null;
    public string $outputFormat = 'table';
    public ?string $lastSeen = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'outputFormat';

        if (in_array($actionID, ['import-redirects', 'reprocess', 'purge-404s'])) {
            $options[] = 'dryRun';
        }

        if (in_array($actionID, ['export-404s', 'export-redirects'])) {
            $options[] = 'outputFile';
        }

        if ($actionID === 'import-redirects') {
            $options[] = 'inputFile';
        }

        if ($actionID === 'purge-404s') {
            $options[] = 'lastSeen';
        }

        return $options;
    }

    /**
     * Export all 404 records.
     */
    public function actionExport404s(): int
    {
        if ($this->outputFile) {
            $count = NotFoundRedirects::getInstance()->csv->export404s($this->outputFile);
            $this->stdout("{$count} 404 record(s) exported to {$this->outputFile}\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        if ($this->outputFormat === 'csv') {
            NotFoundRedirects::getInstance()->csv->export404s('php://output');
            return ExitCode::OK;
        }

        $items = NotFoundRedirects::getInstance()->notFound->find(limit: 10000);
        $data = $items->map(fn($nf) => [
            'uri' => $nf->uri,
            'fullUrl' => $nf->fullUrl,
            'hitCount' => $nf->hitCount,
            'hitLastTime' => $nf->hitLastTime?->format('Y-m-d H:i:s'),
            'handled' => $nf->handled,
        ])->all();

        return $this->outputResult(
            ['dryRun' => false, 'summary' => ['total' => count($data)], 'items' => $data],
            ['URI', 'Full URL', 'Hits', 'Last Hit', 'Handled'],
            fn($item) => [$item['uri'], $item['fullUrl'], $item['hitCount'], $item['hitLastTime'] ?? '-', $item['handled'] ? 'Yes' : 'No'],
            ['total' => '404(s)'],
        );
    }

    /**
     * Export all redirect rules.
     */
    public function actionExportRedirects(): int
    {
        if ($this->outputFile) {
            $count = NotFoundRedirects::getInstance()->csv->exportRedirects($this->outputFile);
            $this->stdout("{$count} redirect(s) exported to {$this->outputFile}\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        if ($this->outputFormat === 'csv') {
            NotFoundRedirects::getInstance()->csv->exportRedirects('php://output');
            return ExitCode::OK;
        }

        $items = NotFoundRedirects::getInstance()->redirects->find(limit: 10000);
        $data = $items->map(fn($r) => [
            'from' => $r->from,
            'to' => $r->to,
            'toType' => $r->toType,
            'statusCode' => $r->statusCode,
            'enabled' => $r->enabled,
            'hitCount' => $r->hitCount,
        ])->all();

        return $this->outputResult(
            ['dryRun' => false, 'summary' => ['total' => count($data)], 'items' => $data],
            ['From', 'To', 'Type', 'Status', 'Enabled', 'Hits'],
            fn($item) => [$item['from'], $item['to'], $item['toType'], $item['statusCode'], $item['enabled'] ? 'Yes' : 'No', $item['hitCount']],
            ['total' => 'Redirect(s)'],
        );
    }

    /**
     * Import redirect rules from a CSV file.
     */
    public function actionImportRedirects(): int
    {
        if (!$this->inputFile) {
            $this->stderr("--input-file is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!file_exists($this->inputFile)) {
            $this->stderr("File not found: {$this->inputFile}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $result = NotFoundRedirects::getInstance()->csv->importRedirects($this->inputFile, $this->dryRun);

        $labels = $this->dryRun
            ? ['imported' => 'Would import', 'skipped' => 'Would skip', 'errors' => 'Errors']
            : ['imported' => 'Imported', 'skipped' => 'Skipped', 'errors' => 'Errors'];

        return $this->outputResult(
            $result,
            ['From', 'To', 'Status', 'Result', 'Error'],
            fn($item) => [$item['from'], $item['to'], $item['statusCode'], $item['result'], $item['error'] ?? ''],
            $labels,
        );
    }

    /**
     * Reprocess unhandled 404s against enabled redirect rules.
     */
    public function actionReprocess(): int
    {
        $result = NotFoundRedirects::getInstance()->notFound->reprocess($this->dryRun);

        $labels = $this->dryRun
            ? ['matched' => 'Would match 404(s)', 'redirects' => 'Matching redirect(s)']
            : ['matched' => 'Matched 404(s)', 'redirects' => 'Matching redirect(s)'];

        return $this->outputResult(
            $result,
            ['Redirect #', 'From', 'Matched'],
            fn($item) => [$item['redirectId'], $item['from'], $item['matchedCount']],
            $labels,
        );
    }

    /**
     * Purge 404s (and their referrers) not seen since the --last-seen threshold.
     */
    public function actionPurge404s(): int
    {
        if (!$this->lastSeen) {
            $this->stderr("--last-seen is required (e.g. --last-seen=\"-90 days\").\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $threshold = DateTimeHelper::toDateTime($this->lastSeen);
        if (!$threshold) {
            $this->stderr("Invalid --last-seen value: {$this->lastSeen}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $result = NotFoundRedirects::getInstance()->notFound->purge($threshold, $this->dryRun);

        $labels = $this->dryRun
            ? ['notFound' => 'Would delete 404(s)', 'referrers' => 'Would delete referrer(s)', 'redirects' => 'Would delete redirect(s)']
            : ['notFound' => 'Deleted 404(s)', 'referrers' => 'Deleted referrer(s)', 'redirects' => 'Deleted redirect(s)'];

        return $this->outputResult(
            $result,
            ['URI', 'Hits', 'Last Seen'],
            fn($item) => [$item['uri'], $item['hitCount'], $item['hitLastTime']],
            $labels,
        );
    }

}
