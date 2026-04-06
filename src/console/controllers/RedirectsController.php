<?php

namespace newism\notfoundredirects\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use newism\notfoundredirects\actions\ExportRedirects;
use newism\notfoundredirects\actions\ImportRedirects;
use yii\console\ExitCode;

/**
 * CLI commands for redirect rules.
 */
class RedirectsController extends Controller
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
     * Import redirect rules from a CSV or JSON file, or stdin.
     *
     * ```
     * craft not-found-redirects/redirects/import redirects.csv
     * craft not-found-redirects/redirects/import redirects.json
     * craft not-found-redirects/redirects/import - --format=csv --source=retour
     * ```
     *
     * @param string $path File path, or '-' to read from stdin.
     */
    public function actionImport(string $path): int
    {
        $input = $this->readInput($path);
        $result = (new ImportRedirects())->handle($input, $this->format, $this->source);

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
     * Export all redirect rules.
     *
     * ```
     * craft not-found-redirects/redirects/export                       # json to stdout
     * craft not-found-redirects/redirects/export redirects.csv         # csv to file
     * craft not-found-redirects/redirects/export - --format=csv        # csv to stdout
     * craft not-found-redirects/redirects/export redirects.txt --format=json
     * ```
     *
     * @param string $path File path, or '-' for stdout.
     */
    public function actionExport(string $path = '-'): int
    {
        $format = $this->format
            ?? ($path !== '-' ? pathinfo($path, PATHINFO_EXTENSION) : null)
            ?? 'json';

        $result = (new ExportRedirects())->handle($format);

        if ($path !== '-') {
            $this->writeToFile($path, $result->content);
            $this->stdout("\nExported {$result->count} records\n");
        } else {
            $this->stdout($result->content);
            $this->stderr("Exported {$result->count} records\n");
        }

        return ExitCode::OK;
    }
}
